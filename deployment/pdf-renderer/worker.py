#!/usr/bin/python3
"""Private socket-activated HTML-to-PDF worker. No application/database access."""
import concurrent.futures
import os
import pathlib
import signal
import socket
import struct
import subprocess
import tempfile
import threading

MAX_HTML = 16 * 1024 * 1024
MAX_PDF = 32 * 1024 * 1024
ALLOWED_UID = int(os.environ['NUERS_PDF_CLIENT_UID'])
SLOTS = threading.BoundedSemaphore(2)
CSP = "<meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; img-src data:; style-src 'unsafe-inline' data:; font-src data:; base-uri 'none'; form-action 'none'; object-src 'none'\">"


def read_exact(conn, size):
    chunks = bytearray()
    while len(chunks) < size:
        part = conn.recv(min(65536, size - len(chunks)))
        if not part:
            raise ValueError('Incomplete request')
        chunks.extend(part)
    return bytes(chunks)


def reply(conn, status, body):
    conn.sendall(status + struct.pack('!I', len(body)) + body)


def render(markup):
    text = markup.decode('utf-8')
    head = text.lower().find('<head>')
    if head < 0:
        raise ValueError('Document head is required')
    text = text[:head + 6] + CSP + text[head + 6:]
    with tempfile.TemporaryDirectory(prefix='nuers-pdf-') as directory:
        root = pathlib.Path(directory)
        (root / 'document.html').write_text(text, encoding='utf-8')
        os.chmod(root / 'document.html', 0o600)
        output = root / 'document.pdf'
        args = ['/usr/bin/google-chrome', '--headless=new', '--no-first-run', '--no-default-browser-check',
                '--disable-extensions', '--disable-background-networking', '--disable-component-update',
                '--disable-sync', '--no-proxy-server', '--host-resolver-rules=MAP * ~NOTFOUND',
                '--user-data-dir=' + directory + '/profile',
                '--no-pdf-header-footer', '--print-to-pdf=' + str(output), (root / 'document.html').as_uri()]
        environment = {'PATH': '/usr/bin:/bin', 'LANG': 'C.UTF-8', 'HOME': directory,
                       'XDG_CONFIG_HOME': directory + '/config', 'XDG_CACHE_HOME': directory + '/cache'}
        diagnostic_file = (root / "runtime.log").open("w+b")
        process = subprocess.Popen(args, env=environment, stdout=subprocess.DEVNULL,
                                   stderr=diagnostic_file, start_new_session=True)
        try:
            process.wait(timeout=25)
        finally:
            # Chromium child processes must not survive a failed or timed-out request.
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            process.wait()
            diagnostic_file.seek(0)
            diagnostics = diagnostic_file.read(16000)
            diagnostic_file.close()
        if process.returncode != 0 or not output.is_file() or output.stat().st_size > MAX_PDF:
            print('PDF process exit: ' + str(process.returncode) + '; output exists: ' + str(output.is_file()), flush=True)
            for line in diagnostics.decode('utf-8', errors='replace').splitlines():
                if any(word in line.lower() for word in ['fatal:', 'sandbox', 'operation not permitted', 'error', 'failed', 'denied']):
                    print(line[:1000], flush=True)
            raise RuntimeError('PDF runtime unavailable')
        data = output.read_bytes()
        if not data.startswith(b'%PDF-'):
            raise RuntimeError('Invalid PDF output')
        return data


def handle(conn):
    try:
        conn.settimeout(30)
        peer = struct.unpack('3i', conn.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
        if peer[1] != ALLOWED_UID:
            raise PermissionError('Unauthorized local caller')
        size = struct.unpack('!I', read_exact(conn, 4))[0]
        if not 0 < size <= MAX_HTML:
            raise ValueError('Document size limit exceeded')
        reply(conn, b'S', render(read_exact(conn, size)))
    except Exception as error:
        # Do not log financial content, HTML, credentials, or generated documents.
        print('PDF request failed: ' + type(error).__name__, flush=True)
        try:
            reply(conn, b'E', b'PDF generation failed. Please retry or contact an administrator.')
        except OSError:
            pass
    finally:
        conn.close()
        SLOTS.release()


if __name__ == '__main__':
    if int(os.environ.get('LISTEN_PID', '0')) != os.getpid() or os.environ.get('LISTEN_FDS') != '1':
        raise RuntimeError('Start through the private systemd socket')
    listener = socket.socket(fileno=3)
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        while True:
            conn, _ = listener.accept()
            if SLOTS.acquire(blocking=False):
                pool.submit(handle, conn)
            else:
                conn.settimeout(1)
                try:
                    reply(conn, b'E', b'PDF generation is busy. Please retry shortly.')
                except OSError:
                    pass
                conn.close()
