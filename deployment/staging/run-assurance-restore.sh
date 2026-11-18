#!/bin/sh
# Requires the reviewed synthetic-only bundle under /srv/nuers-vapt/assurance-20261116.
set -eu
F=/srv/nuers-vapt/assurance-20261116
if ! docker inspect nuers-assurance-db-20261116 >/dev/null 2>&1; then
 docker run -d --name nuers-assurance-db-20261116 --network none -e MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1 -v "$F/drill:/drill" mariadb:10.11.19 --datadir=/drill/data --socket=/drill/mysql.sock --skip-networking --event-scheduler=OFF
fi
docker start nuers-assurance-db-20261116 >/dev/null
for n in $(seq 1 60); do if docker exec nuers-assurance-db-20261116 sh -c 'test "$(cat /proc/1/comm)" = mariadbd' && docker exec nuers-assurance-db-20261116 mariadb-admin --socket=/drill/mysql.sock -uroot ping >/dev/null 2>&1; then break; fi; sleep 2; done
if [ -f "$F/drill/restore-result.json" ]; then mv "$F/drill/restore-result.json" "$F/drill/restore-result-$(date +%s)-previous.json"; fi
KEY=$(openssl rand -base64 32)
docker run --rm --network none --name nuers-assurance-restore-20261116 -e APP_ENV=testing -e APP_KEY="base64:$KEY" -e APP_CONFIG_CACHE=/tmp/absent-config.php -e NUERS_SYNTHETIC_RESTORE=1 -e DB_CONNECTION=mysql -e MAIL_MAILER=array -e QUEUE_CONNECTION=sync -e LOG_CHANNEL=stderr -v "$F/drill:/drill" -v "$F/bundle/deployment/staging/assurance-restore.php:/tmp/restore.php:ro" -v "$F/bundle/database/migrations:/app/database/migrations:ro" -v "$F/bundle/app/Services/LocalRecoveryBackup.php:/app/app/Services/LocalRecoveryBackup.php:ro" -v "$F/bundle/app/Services/RecoveryArchive.php:/app/app/Services/RecoveryArchive.php:ro" -v "$F/bundle/app/Services/OperationsHealth.php:/app/app/Services/OperationsHealth.php:ro" -v "$F/bundle/config/operations.php:/app/config/operations.php:ro" nuers-vapt:20261114-v3 php /tmp/restore.php > "$F/restore.log" 2>&1 || { tail -c 5000 "$F/restore.log"; exit 1; }
cat "$F/drill/restore-result.json"
