from pathlib import Path
import hashlib,json,zipfile,subprocess,shlex,re
root=Path.cwd();out=root/'output/invoice-compliance-20261029';paths={}
for name in json.loads((out/'scope.json').read_text()):
    if name.startswith(('output/','tmp/','tests/')):continue
    paths[name]=name
    if name.startswith('public/guides/'):
        paths[name.removeprefix('public/')]=name
        paths['dist/'+name.removeprefix('public/')]=name
paths['bootstrap/ssr/document-renderer.mjs']='bootstrap/ssr/document-renderer.mjs'
for base in ['dist/assets','public/build']:
    for p in (root/base).rglob('*'):
        if p.is_file():
            name=str(p.relative_to(root));paths[name]=name
            if base=='dist/assets':paths['assets/'+str(p.relative_to(root/base))]=name
paths['index.html']='dist/index.html';paths['dist/index.html']='dist/index.html'
remote="import sys,json,hashlib,pathlib; r=pathlib.Path('/var/www/vhosts/nuers.net/httpdocs'); paths=json.load(sys.stdin); print(json.dumps({p:hashlib.sha256((r/p).read_bytes()).hexdigest() if (r/p).is_file() else None for p in paths}))"
result=subprocess.run(['ssh','-S','/private/tmp/nuers-deploy-20261028.sock','-o','BatchMode=yes','-o','ConnectTimeout=8','root@vmi2755926.contaboserver.net','python3 -c '+shlex.quote(remote)],input=json.dumps(list(paths)),capture_output=True,text=True,check=True)
live=json.loads(result.stdout);(out/'live-baseline.json').write_text(json.dumps(live,indent=2))
baseline=json.loads((out/'baseline.json').read_text())
for dest in paths:
    if dest in baseline and live[dest] is not None and live[dest] not in [baseline[dest],hashlib.sha256((root/paths[dest]).read_bytes()).hexdigest()]:
        raise RuntimeError('Unexpected source drift: '+dest)
changed={d:s for d,s in paths.items() if live[d]!=hashlib.sha256((root/s).read_bytes()).hexdigest()}
manifest={'release':'2026-10-29.invoice-documents.1','schema_migration':True,'files':{d:hashlib.sha256((root/s).read_bytes()).hexdigest() for d,s in changed.items()},'baseline':{d:live[d] for d in changed},'new_files':[d for d in changed if live[d] is None]}
(out/'audit-release-manifest.json').write_text(json.dumps(manifest,indent=2))
package=out/'nuers-invoice-documents-20261029.zip'
with zipfile.ZipFile(package,'w',zipfile.ZIP_DEFLATED) as z:
    for d,s in changed.items():z.write(root/s,d)
    z.write(out/'audit-release-manifest.json','audit-release-manifest.json')
sha=hashlib.sha256(package.read_bytes()).hexdigest()
s=(root/'output/compliance-alignment-20261029/release/nuers-compliance-install.php').read_text()
s=s.replace('nuers-compliance','nuers-invoice-documents').replace('3e342360ad83abe470375b90e0e98de09b5fe981bebe7f064fde8800e9abc4ff',sha).replace('2026_10_29_000001_align_taxpayer_and_compliance_workflows.php','2026_10_29_000001_snapshot_invoice_registration.php').replace("'taxpayer_authorizations'=>'id'","'taxpayer_authorizations'=>'id','invoice_series'=>'id'").replace('BIR technical alignment update','Invoice and receipt fields update')
(out/'nuers-invoice-documents-install.php').write_text(s)
(out/'package.sha256').write_text(sha+'\n')
print(json.dumps({'files':len(changed),'bytes':package.stat().st_size,'sha256':sha,'entry':re.search(r'src="(/assets/[^\"]+\.js)"',(root/'dist/index.html').read_text()).group(1)}))
