"""Local health only; no command execution from dashboard input."""
import json,os,platform,shutil,subprocess,time,urllib.request
from datetime import datetime,timezone
from pathlib import Path
import boto3

config=json.loads(Path('/etc/nuers/infrastructure/host.json').read_text())
def cpu():
    v=[int(x) for x in Path('/proc/stat').read_text().splitlines()[0].split()[1:9]]
    return sum(v),v[3]+v[4]
a=cpu();time.sleep(0.25);b=cpu();total=b[0]-a[0]
mem={l.split(':')[0]:int(l.split()[1])*1024 for l in Path('/proc/meminfo').read_text().splitlines()}
# Count each mounted filesystem once; /srv itself is on the root volume.
paths=['/', '/srv/nuers'];devices={}
for path in paths:devices.setdefault(os.stat(path).st_dev,shutil.disk_usage(path))
disks=list(devices.values());disk_total=sum(d.total for d in disks);disk_used=sum(d.used for d in disks)
services={}
for key,unit in {'nginx':'nginx','php-fpm':'php8.4-fpm','queue-worker':'nuers-notifications','scheduler':'nuers-scheduler.timer','log-rotation':'logrotate.timer'}.items():
    state=subprocess.run(['systemctl','is-active',unit],capture_output=True,text=True,timeout=5).stdout.strip()
    services[key]={'active':'running','inactive':'stopped','failed':'failed'}.get(state,'unknown')
try:
    request=urllib.request.Request('http://127.0.0.1:8080/api/health',headers={'Host':'nuers.net'})
    services['nuers-api']='running' if urllib.request.urlopen(request,timeout=3).status==200 else 'failed'
except Exception:
    services['nuers-api']='failed'
row=dict(instance_id=config['instance_id'],observed_at=datetime.now(timezone.utc).isoformat(),os='Ubuntu 24.04 LTS',kernel=platform.release(),uptime_seconds=int(float(Path('/proc/uptime').read_text().split()[0])),cpu_cores=os.cpu_count(),cpu_percent=round((1-(b[1]-a[1])/total)*100,2) if total else 0,memory_bytes=mem['MemTotal'],memory_percent=round((1-mem['MemAvailable']/mem['MemTotal'])*100,2),disk_total_bytes=disk_total,disk_used_bytes=disk_used,disk_percent=round(disk_used/disk_total*100,2),services=services)
s3=boto3.client('s3',region_name='ap-southeast-1')
s3.put_object(Bucket='nuers-289829301267-prod-documents-sg',Key='infrastructure/hosts/'+config['instance_id']+'.json',Body=json.dumps(row).encode(),ServerSideEncryption='AES256')
response=s3.get_object(Bucket='nuers-289829301267-prod-documents-sg',Key='infrastructure/observations/current.json')
raw=response['Body'].read(1048577)
assert len(raw)<=1048576
folder=Path('/run/nuers-infrastructure');folder.mkdir(mode=0o755,exist_ok=True);folder.chmod(0o755)
temp=folder/'snapshot.pending';temp.write_bytes(raw);temp.chmod(0o644);temp.replace(folder/'snapshot.json')
if config['sync']:
    subprocess.run(['runuser','-u','nuers','--','php','artisan','infrastructure:sync'],cwd='/srv/nuers/current',check=True,timeout=25)
