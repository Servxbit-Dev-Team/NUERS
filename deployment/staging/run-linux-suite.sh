#!/bin/bash
set -euo pipefail
cd /srv/nuers-vapt
mkdir -p evidence mysql-isolated/data
# Disposable, socket-only database. Identical path inside both containers preserves test guards.
chmod 0777 mysql-isolated
[ "$(docker ps -aq --filter name='^nuers-isolated-db$' | wc -l)" -eq 0 ]
docker run -d --name nuers-isolated-db --network none \
 -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 \
 -v "$PWD/mysql-isolated:/app/.local/vapt-mariadb-2026-11-14" \
 mariadb:10.11.19 --datadir=/app/.local/vapt-mariadb-2026-11-14/data \
 --socket=/app/.local/vapt-mariadb-2026-11-14/mysql.sock --skip-networking --event-scheduler=OFF
for i in $(seq 1 60); do
 if docker exec nuers-isolated-db mariadb-admin --socket=/app/.local/vapt-mariadb-2026-11-14/mysql.sock -uroot ping --silent; then break; fi
 sleep 2
done
docker run --name nuers-linux-suite --network none \
 -e APP_ENV=testing -e APP_KEY="base64:$(openssl rand -base64 32)" \
 -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e QUEUE_CONNECTION=sync \
 -v "$PWD/mysql-isolated:/app/.local/vapt-mariadb-2026-11-14" \
 -v "$PWD/evidence:/app/.local/vapt-remediation-2026-11-14" \
 -v "$PWD/harness/database/factories:/app/database/factories:ro" \
 -v "$PWD/harness/database/seeders:/app/database/seeders:ro" \
 -v "$PWD/harness/docs/examples:/app/docs/examples:ro" \
 nuers-vapt:20261114 sh -c '
 set +e
 mkdir -p tests/Unit
 php vendor/bin/phpunit --log-junit .local/vapt-remediation-2026-11-14/linux-full.xml > .local/vapt-remediation-2026-11-14/linux-full.log 2>&1
 full=$?
 php tests/Support/run-isolated-mariadb.php security; security=$?
 php tests/Support/run-isolated-mariadb.php wallet; wallet=$?
 php tests/Support/run-isolated-mariadb.php registration; registration=$?
 printf "full=%s security=%s wallet=%s registration=%s\n" "$full" "$security" "$wallet" "$registration"
 test "$full$security$wallet$registration" = 0000
 '
