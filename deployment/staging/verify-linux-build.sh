#!/bin/bash
set -euo pipefail
cd /srv/nuers-vapt/candidate
DOCKER_BUILDKIT=1 docker build --target frontend -t nuers-vapt-frontend:20261114 -f deployment/staging/Dockerfile . > ../evidence/frontend-stage.log 2>&1
docker run --rm --network none nuers-vapt-frontend:20261114 node -e 'const fs=require("fs"),crypto=require("crypto"),out={}; function walk(dir){for(const n of fs.readdirSync(dir)){const p=dir+"/"+n;if(fs.statSync(p).isDirectory())walk(p);else out[p]=crypto.createHash("sha256").update(fs.readFileSync(p)).digest("hex")}} walk("public/build");walk("bootstrap/ssr");process.stdout.write(JSON.stringify(out))' > ../evidence/linux-frontend-inventory.json
python3 - <<'PY'
import json,pathlib
r=pathlib.Path('/srv/nuers-vapt');baseline=json.loads((r/'candidate/staging-file-inventory.json').read_text())['files']; baseline={k:v for k,v in baseline.items() if k.startswith(('public/build/','bootstrap/ssr/'))};actual=json.loads((r/'evidence/linux-frontend-inventory.json').read_text());diff=[k for k in sorted(set(baseline)|set(actual)) if baseline.get(k)!=actual.get(k)]; report={'expected':len(baseline),'actual':len(actual),'mismatches':diff};(r/'evidence/linux-build-comparison.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));assert not diff
PY
docker image inspect nuers-vapt:20261114 > ../evidence/app-image.json
docker image inspect node:24.21.0-bookworm-slim php:8.4.27-fpm-bookworm composer:2 > ../evidence/base-images.json
docker run --rm --network none nuers-vapt:20261114 sh -c 'php -v; node -v; clamscan --version; chromium --version; dpkg-query -W' > ../evidence/linux-versions.txt
