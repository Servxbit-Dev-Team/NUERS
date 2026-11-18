import json
import socket
import struct


def receive(conn, size):
    data = b''
    while len(data) < size:
        part = conn.recv(size - len(data))
        if not part:
            raise RuntimeError('Unexpected closed socket')
        data += part
    return data


def probe(body, size=None):
    with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as conn:
        conn.settimeout(35)
        conn.connect('/run/nuers-pdf.sock')
        conn.sendall(struct.pack('!I', len(body) if size is None else size) + body)
        status = receive(conn, 1)
        size = struct.unpack('!I', receive(conn, 4))[0]
        data = receive(conn, size)
        return {'success': status == b'S', 'bytes': len(data), 'pdf': data.startswith(b'%PDF-')}


result = {'synthetic': probe(b'<!doctype html><html><head><meta charset="utf-8"></head><body><h1>NUERS synthetic isolated renderer test</h1><p>PHP 112.00</p></body></html>'),
          'oversized_rejected': not probe(b'', 16777217)['success'],
          'invalid_markup_rejected': not probe(b'missing head')['success']}
print(json.dumps(result))
raise SystemExit(0 if result['synthetic']['pdf'] and result['oversized_rejected'] and result['invalid_markup_rejected'] else 1)
