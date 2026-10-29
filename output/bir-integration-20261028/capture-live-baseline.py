from pathlib import Path
import subprocess,json,shlex
out=Path(__file__).parent
paths=json.loads((out/'candidate-paths.json').read_text())
code='$root="/var/www/vhosts/nuers.net/httpdocs";$paths=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$r=[];foreach($paths as$p){if(str_starts_with($p,"/")||in_array("..",explode("/",$p),true))throw new RuntimeException("Unsafe path");$r[$p]=is_file($root."/".$p)?hash_file("sha256",$root."/".$p):null;}echo json_encode($r);'
cmd=['ssh','-S','/private/tmp/nuers-deploy-20261028.sock','-o','BatchMode=yes','-o','ConnectTimeout=8','root@vmi2755926.contaboserver.net','/opt/plesk/php/8.4/bin/php -r '+shlex.quote(code)]
r=subprocess.run(cmd,input=json.dumps(paths),capture_output=True,text=True,timeout=45)
if r.returncode:raise RuntimeError(r.stderr)
data=json.loads(r.stdout);assert set(data)==set(paths)
(out/'live-baseline.json').write_text(json.dumps(data,indent=2));print(json.dumps({'checked_paths':len(data),'existing_paths':sum(v is not None for v in data.values())}))
