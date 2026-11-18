#!/bin/sh
set -eu
umask 077
src=/var/www/vhosts/nuers.net/nuers-pdf-runtime-stage
base=/var/www/vhosts/nuers.net
[ "$(id -u)" = 0 ]
[ "$(id -u nuers.net_6r9gw36fpkr)" = 10011 ]
[ -x /usr/bin/google-chrome ]
[ ! -e /etc/systemd/system/nuers-pdf.service ]
[ ! -e /etc/systemd/system/nuers-pdf.socket ]
backup="$base/nuers-pdf-runtime-backup-$(date -u +%Y%m%d-%H%M%S)"
mkdir -m 700 "$backup"
cp -p "$base/.nuers-runtime/chrome" "$backup/chrome"
if ! id nuers-pdf >/dev/null 2>&1; then useradd --system --user-group --home-dir /nonexistent --shell /usr/sbin/nologin nuers-pdf; fi
install -d -m 755 /usr/local/lib/nuers-pdf
install -m 644 "$src/worker.py" /usr/local/lib/nuers-pdf/worker.py
install -m 755 "$src/client.py" /usr/local/lib/nuers-pdf/client.py
install -m 644 "$src/nuers-pdf.service" /etc/systemd/system/nuers-pdf.service
install -m 644 "$src/nuers-pdf.socket" /etc/systemd/system/nuers-pdf.socket
systemd-analyze verify /etc/systemd/system/nuers-pdf.service /etc/systemd/system/nuers-pdf.socket
systemctl daemon-reload
systemctl enable --now nuers-pdf.socket
printf '%s\n' "$backup"
# The application runtime wrapper is switched only after the isolated worker passes its probes.
