#!/usr/bin/python3
"""Chrome-compatible private PDF bridge for the NUERS PHP renderer."""
import os
import pathlib
import socket
import struct
import sys
import urllib.parse

ROOT = pathlib.Path('/var/www/vhosts/nuers.net/httpdocs/storage/app/private')
SOCKET = '/run/nuers-pdf.sock'
MAX_PDF = 32 * 1024 * 1024


def read_exact(conn, size):
    chunks = bytearray()
    while len(chunks) < size:
        part = conn.recv(min(65536, size - len(chunks)))
        if not part:
            raise RuntimeError('Incomplete PDF response')
        chunks.extend(part)
    return bytes(chunks)


def main():
    outputs = [x.split('=', 1)[1] for x in sys.argv[1:] if x.startswith('--print-to-pdf=')]
    inputs = [x for x in sys.argv[1:] if x.startswith('file://')]
    if len(outputs) != 1 or len(inputs) != 1:
        raise ValueError('A single private document is required')
    source = pathlib.Path(urllib.parse.unquote(urllib.parse.urlparse(inputs[0]).path)).resolve(strict=True)
    output = pathlib.Path(outputs[0]).resolve()
    parent = source.parent
    if parent.parent != ROOT.resolve() or not parent.name.startswith('pdf-') or output.parent != parent:
        raise ValueError('Document must be in the private PDF work directory')
    if source.is_symlink() or not source.is_file() or source.stat().st_uid != os.getuid():
        raise ValueError('Document ownership mismatch')
    size = source.stat().st_size
    if not 0 < size <= 16 * 1024 * 1024:
        raise ValueError('Document size limit exceeded')
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.settimeout(30)
        conn.connect(SOCKET)
        conn.sendall(struct.pack('!I', size) + source.read_bytes())
        status = read_exact(conn, 1)
        length = struct.unpack('!I', read_exact(conn, 4))[0]
        if not 0 < length <= MAX_PDF:
            raise RuntimeError('Invalid PDF response length')
        data = read_exact(conn, length)
        if status != b'S' or not data.startswith(b'%PDF-'):
            raise RuntimeError('PDF worker could not render the document')
    with output.open('xb') as file:
        os.chmod(output, 0o600)
        file.write(data)


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print('NUERS PDF bridge: ' + str(error), file=sys.stderr)
        sys.exit(1)
