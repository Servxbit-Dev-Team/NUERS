#!/bin/bash
set -euo pipefail
cd /srv/nuers-vapt
systemctl is-active --quiet nuers-pdf.socket
docker image inspect nuers-vapt:20261114-v3 >/dev/null
mkdir -p evidence/full-utc
chown 33:33 evidence/full-utc
# The application runs unprivileged. The native PDF worker has no network or app-directory access.
docker run --name nuers-linux-full-utc --network none \
 -e APP_ENV=testing -e APP_TIMEZONE=UTC -e APP_KEY="base64:$(openssl rand -base64 32)" \
 -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e QUEUE_CONNECTION=sync \
 -e NUERS_PDF_CHROME=/usr/local/lib/nuers-pdf/client.py \
 -v "$PWD/harness/tests/Recovered/Feature/BackupRecoveryTest.php:/app/tests/Recovered/Feature/BackupRecoveryTest.php:ro" \
 -v "$PWD/evidence/full-utc:/app/.local/vapt-remediation-2026-11-14" \
 -v /usr/local/lib/nuers-pdf/client.py:/usr/local/lib/nuers-pdf/client.py:ro \
 -v /run/nuers-pdf.sock:/run/nuers-pdf.sock \
 nuers-vapt:20261114-v3 sh -c '
 set -eu
 mkdir -p tests/Unit output .local/vapt-remediation-2026-11-14
 chown www-data:www-data output .local .local/vapt-remediation-2026-11-14
 runuser -u www-data -- php -r '\''require "vendor/autoload.php"; $a=require "bootstrap/app.php"; $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $p=app(App\Services\DocumentPdf::class)->renderHtml("<html><head></head><body>Synthetic Linux PDF test</body></html>"); if(!str_starts_with($p,"%PDF-"))exit(1);echo "SANDBOXED_PDF_PASS\n";'\''
 runuser -u www-data -- php vendor/bin/phpunit --do-not-cache-result --log-junit .local/vapt-remediation-2026-11-14/linux-full-utc.xml > .local/vapt-remediation-2026-11-14/linux-full-utc.log 2>&1
 '
