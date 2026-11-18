#!/bin/bash
# Approved rollout preparation only: does not switch traffic or mutate the database.
set -euo pipefail
BASE=/srv/nuers/releases/production-20261106-analytics-grouping2
NEXT=/srv/nuers/releases/production-20261114-vapt1
[ "$(readlink -f /srv/nuers/current)" = "$BASE" ]
[ ! -e "$NEXT" ]
install -d -m 0755 "$NEXT"
rsync -a --exclude=.env --exclude=vendor --exclude=node_modules --exclude=public/build --exclude=bootstrap/ssr --exclude=storage --exclude=bootstrap/cache "$BASE/" "$NEXT/"
install -o root -g nuers -m 0640 "$BASE/.env" "$NEXT/.env"
install -d -o nuers -g nuers -m 0750 "$NEXT/bootstrap/cache" "$NEXT/storage" "$NEXT/storage/app" "$NEXT/storage/framework" "$NEXT/storage/framework/cache" "$NEXT/storage/framework/cache/data" "$NEXT/storage/framework/sessions" "$NEXT/storage/framework/views" "$NEXT/storage/logs"
ln -s /srv/nuers/shared/storage/app/private "$NEXT/storage/app/private"
ln -s /srv/nuers/shared/storage/app/public "$NEXT/storage/app/public"
python3 - <<'PY'
import boto3,pathlib,hashlib,json,tarfile,os
r=pathlib.Path('/srv/nuers/releases/production-20261114-vapt1');s=boto3.client('s3',region_name='ap-southeast-1')
a=pathlib.Path('/srv/nuers/release-payload-20261114.tar.gz');i=pathlib.Path('/srv/nuers/release-inventory-20261114.json')
for key,dest in [('release-payload.tar.gz',a),('release-payload-inventory.json',i)]:s.download_file('nuers-289829301267-prod-documents-sg','migration/vapt-20261114/'+key,str(dest))
assert hashlib.sha256(a.read_bytes()).hexdigest()=='72fc77ae33ffd0dbc94b0069dc89db22a0fc181a15f082349a4ea56057bc362f'
inv=json.loads(i.read_text());assert inv['contains_env'] is False
with tarfile.open(a) as t:
 for m in t.getmembers():
  p=pathlib.PurePosixPath(m.name)
  assert not p.is_absolute() and '..' not in p.parts
  assert p.parts[0] not in ['.env','storage'] and not m.name.startswith('public/uploads')
 t.extractall(r,filter='data')
for name,h in inv['file_hashes'].items():
 p=r/name
 assert p.is_file() and hashlib.sha256(p.read_bytes()).hexdigest()==h,name
p=r/'.env';lines=p.read_text().splitlines();updates={'NUERS_PRIVILEGED_IDLE_MINUTES':'30','NUERS_PRIVILEGED_MAX_MINUTES':'480','NUERS_PRIVILEGED_MFA_MINUTES':'5'}
lines=[line for line in lines if line.split('=',1)[0] not in updates]
p.write_text('\n'.join(lines+[k+'='+v for k,v in updates.items()])+'\n');os.chmod(p,0o640)
print('RELEASE_PAYLOAD_HASHES_VERIFIED',len(inv['file_hashes']))
PY
# Only this inactive release is normalized; never follow shared storage symlinks.
find "$NEXT" -xdev -not -path "$NEXT/storage*" -not -path "$NEXT/bootstrap/cache*" -not -type l -exec chown root:root {} +
chown root:nuers "$NEXT/.env"
chmod 0640 "$NEXT/.env"
cd "$NEXT"
runuser -u nuers -- php artisan package:discover --no-ansi
runuser -u nuers -- php artisan config:cache --no-ansi
runuser -u nuers -- php artisan route:cache --no-ansi
runuser -u nuers -- php -r 'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo json_encode(["release"=>base_path(),"laravel"=>Illuminate\Foundation\Application::VERSION,"phpseclib"=>Composer\InstalledVersions::getPrettyVersion("phpseclib/phpseclib"),"timezone"=>config("app.timezone"),"idle"=>config("security.privileged_idle_minutes"),"maximum"=>config("security.privileged_absolute_minutes"),"mfa"=>config("security.privileged_mfa_minutes"),"budget"=>config("infrastructure.budget_ceiling_php")]).PHP_EOL;'
[ "$(readlink -f /srv/nuers/current)" = "$BASE" ]
echo INACTIVE_RELEASE_PREPARED
