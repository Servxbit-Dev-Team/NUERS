import boto3,json,pathlib,datetime
p=pathlib.Path.home()/'nuers-cloud-vapt-20261115';c=json.loads((p/'logging-plan.json').read_text());s=boto3.client('s3',region_name=c['region']);ec=boto3.client('ec2',region_name=c['region']);e=boto3.client('elbv2',region_name=c['region']);r={'observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat()}
for prefix in ['alb/','flow/']:
 objects=[]
 for page in s.get_paginator('list_objects_v2').paginate(Bucket=c['bucket'],Prefix=prefix):
  objects.extend(page.get('Contents',[]))
 real=[x for x in objects if x['Key'].endswith('.log.gz')]
 r[prefix]={'objects':len(objects),'traffic_log_objects':len(real),'bytes':sum(x['Size']for x in real),'latest':max((x['LastModified']for x in real),default=None)}
r['flow']=[{k:f.get(k)for k in ['FlowLogId','FlowLogStatus','DeliverLogsStatus','DeliverLogsErrorMessage','LogDestination']}for f in ec.describe_flow_logs(FlowLogIds=['fl-039b8d5a5fb354d7c'])['FlowLogs']]
r['policy_exact']=json.loads(s.get_bucket_policy(Bucket=c['bucket'])['Policy'])==c['policy']
r['cloudtrail']=boto3.client('cloudtrail',region_name=c['region']).get_trail_status(Name='nuers-security-management');r['targets']=[{'id':x['Target']['Id'],'state':x['TargetHealth']['State']}for x in e.describe_target_health(TargetGroupArn='arn:aws:elasticloadbalancing:ap-southeast-1:289829301267:targetgroup/nuers-prod-web/09a353254dddbd2a')['TargetHealthDescriptions']]
r['complete']=all(r[x]['traffic_log_objects']>0 for x in ['alb/','flow/']) and r['policy_exact'] and all(x['state']=='healthy'for x in r['targets'])
(p/'delivery.json').write_text(json.dumps(r,indent=2,default=str));print(json.dumps(r,default=str))
