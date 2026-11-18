#!/bin/bash
# Keep the NUERS certificate current even while the Plesk installer is unavailable.
# Uses the existing Let's Encrypt account/client; no other domain is modified.
set -euo pipefail
umask 077
test "$(id -u)" = 0
exec 9>/run/lock/nuers-renew-tls.lock
flock -n 9 || exit 0
source_dir=/opt/psa/var/modules/letsencrypt/etc/live/nuers.net
target=/opt/psa/var/certificates/scfmtnb372v3jpc6UUseKH
recovery=/var/lib/nuers-tls
test ! -L "$recovery"
install -d -m 0700 "$recovery"
# Do not place another ACME order while a healthy certificate is available.
if ! openssl x509 -in "$source_dir/cert.pem" -checkend 2592000 -noout >/dev/null 2>&1; then
    plesk bin extension --exec letsencrypt cli.php -d nuers.net -d www.nuers.net -m siosonmarkdavid@gmail.com >"$recovery/acme-last.log" 2>&1 || true
fi
openssl x509 -in "$source_dir/cert.pem" -checkend 2592000 -noout >/dev/null
openssl x509 -in "$source_dir/cert.pem" -checkhost nuers.net -noout >/dev/null
openssl x509 -in "$source_dir/cert.pem" -checkhost www.nuers.net -noout >/dev/null
openssl verify -purpose sslserver -CAfile /etc/ssl/certs/ca-certificates.crt -untrusted "$source_dir/chain.pem" "$source_dir/cert.pem" >/dev/null
cert_key=$(openssl x509 -in "$source_dir/cert.pem" -pubkey -noout | openssl pkey -pubin -outform DER 2>/dev/null | sha256sum | cut -d' ' -f1)
private_key=$(openssl pkey -in "$source_dir/privkey.pem" -pubout -outform DER 2>/dev/null | sha256sum | cut -d' ' -f1)
test "$cert_key" = "$private_key"
# Refuse a stale target after a later Plesk reconfiguration.
grep -Fq "\"$target\"" /var/www/vhosts/system/nuers.net/conf/nginx.conf
test -f "$target" && test ! -L "$target"
if test "$(openssl x509 -in "$target" -noout -fingerprint -sha256)" = "$(openssl x509 -in "$source_dir/cert.pem" -noout -fingerprint -sha256)"; then
    echo 'NUERS TLS certificate is current.'
    exit 0
fi
stamp=$(date -u +%Y%m%dT%H%M%SZ)
cp -p "$target" "$recovery/origin-$stamp.pem"
chmod 0600 "$recovery/origin-$stamp.pem"
candidate=$(mktemp "${target}.nuers.XXXXXX")
trap 'rm -f "$candidate"' EXIT
cat "$source_dir/privkey.pem" "$source_dir/fullchain.pem" >"$candidate"
chmod --reference="$target" "$candidate"
chown --reference="$target" "$candidate"
mv "$candidate" "$target"
if ! nginx -t || ! apache2ctl configtest; then
    cp -p "$recovery/origin-$stamp.pem" "$target"
    echo 'Certificate validation failed; prior certificate restored.' >&2
    exit 1
fi
if ! systemctl reload nginx || ! systemctl reload apache2; then
    cp -p "$recovery/origin-$stamp.pem" "$target"
    systemctl reload nginx
    systemctl reload apache2
    echo 'Reload failed; prior certificate restored.' >&2
    exit 1
fi
openssl x509 -in "$target" -noout -dates -ext subjectAltName
echo 'NUERS certificate installed; nginx and Apache gracefully reloaded.'
