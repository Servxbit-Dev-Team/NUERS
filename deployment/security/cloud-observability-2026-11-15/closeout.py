import pathlib,json,time,boto3,datetime
p=pathlib.Path.home()/'nuers-cloud-vapt-20261115';ec=boto3.client('ec2',region_name='ap-southeast-1');ids=['i-0fd5169e6c271bf76','i-0c1a905d6656ce491']
for _ in range(120):
 log=(pathlib.Path.home()/'nuers-os-final-scan.log').read_text()
 if 'INVENTORY_SCAN_COMPLETE' in log:break
 if 'Traceback' in log:raise RuntimeError('Inventory scan incomplete; inspect log')
 time.sleep(3)
assert 'INVENTORY_SCAN_COMPLETE' in log
cleanup=json.loads((p/'kernel-cleanup.json').read_text());assert cleanup['complete']
scan=json.loads((p/'os-scan-summary.json').read_text());assert all(x['packages_scanned']>800 and x['metadata']['OS']['Family']=='ubuntu' for x in scan['nodes'].values())
r=ec.describe_instances(InstanceIds=ids);assert all(i['VpcId']=='vpc-050c7723c3bec78e0' for a in r['Reservations']for i in a['Instances'])
ec.stop_instances(InstanceIds=['i-0fd5169e6c271bf76']);ec.get_waiter('instance_stopped').wait(InstanceIds=ids,WaiterConfig={'Delay':5,'MaxAttempts':60})
states={i['InstanceId']:i['State']['Name']for a in ec.describe_instances(InstanceIds=ids)['Reservations']for i in a['Instances']};assert all(x=='stopped' for x in states.values())
elb=boto3.client('elbv2',region_name='ap-southeast-1');targets=[{'id':a['Target']['Id'],'state':a['TargetHealth']['State']}for a in elb.describe_target_health(TargetGroupArn='arn:aws:elasticloadbalancing:ap-southeast-1:289829301267:targetgroup/nuers-prod-web/09a353254dddbd2a')['TargetHealthDescriptions']];assert len(targets)==2 and all(x['state']=='healthy'for x in targets)
out={'time':datetime.datetime.now(datetime.timezone.utc).isoformat(),'cleanup':cleanup,'scan':scan,'staging':states,'targets':targets}
(p/'closeout.json').write_text(json.dumps(out,indent=2));print(json.dumps({'time':out['time'],'cleanup':True,'scan':{n:{k:v[k]for k in ['packages_scanned','severity','fixable','unique_cves']}for n,v in scan['nodes'].items()},'staging':states,'targets':targets},indent=2))
