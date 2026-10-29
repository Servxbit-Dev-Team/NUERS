NUERS private PDF runtime — deployed 29 October 2026

Purpose
PHP-FPM's RestrictNamespaces=true prevents Chromium's own sandbox from starting.
Do not disable that protection or pass --no-sandbox. The PDF bridge sends the
already escaped, server-rendered HTML over a private Unix socket to an isolated
unprivileged renderer. No TCP listener or external rendering provider is used.

Installed components
/usr/local/lib/nuers-pdf/worker.py and client.py (root controlled)
/etc/systemd/system/nuers-pdf.socket and nuers-pdf.service
/run/nuers-pdf.sock (0600, owned by the NUERS website account)
/var/www/vhosts/nuers.net/.nuers-runtime/chrome (bridge wrapper)

Controls
- Dedicated nuers-pdf account, no login, no database/application files.
- PrivateNetwork, PrivateTmp, PrivateDevices, ProtectSystem, ProtectHome,
  NoNewPrivileges and explicit /var/www and /opt/plesk access denial.
- Chromium sandbox enabled; embedded-content CSP blocks scripts and remote assets.
- Socket file ownership and kernel peer UID check permit only NUERS UID 10011.
- Two render jobs at most, 16 MiB HTML, 32 MiB PDF, 25-second browser deadline.
- 768 MiB service memory, 512 tasks, 150% CPU; excess requests fail without queue growth.
- Private temporary HTML, profiles, logs and PDFs removed after each request.
- Diagnostics exclude receipt HTML/content; API continues role/MFA/rate-limit checks.

Validation
The synthetic probe exercises real PDF generation, oversized input rejection,
and invalid document rejection. The separate bridge probe checks private path
ownership and PDF output. Live application testing verifies receipt rendering and
download through PHP-FPM. Application preview and PDF access tests remain in
AdminMerchantReceiptTest.php.

Operations
systemctl status nuers-pdf.socket nuers-pdf.service
journalctl -u nuers-pdf.service
The socket starts at boot and activates the worker on demand. Socket permission
or service errors fail closed. Review resource use before changing concurrency.
If the website UID changes, update SocketUser and NUERS_PDF_CLIENT_UID together.

Recovery
Original runtime wrapper is stored privately in:
/var/www/vhosts/nuers.net/nuers-pdf-runtime-backup-20261029-013335/chrome
Restore this wrapper if rolling the worker back; leave app data unchanged. The
old direct Chromium invocation requires a runtime permitting its sandbox.
Application releases have separate private file/database recovery backups.
