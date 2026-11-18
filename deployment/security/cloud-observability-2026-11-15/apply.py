"""Apply the explicitly approved 2026-11-15 logging plan from CloudShell."""
import boto3,json,pathlib,datetime,time
p=pathlib.Path.home()/'nuers-cloud-vapt-20261115';c=json.loads((p/'logging-plan.json').read_text());r={'started':datetime.datetime.now(datetime.timezone.utc).isoformat(),'complete':False}
assert boto3.client('sts').get_caller_identity()['Account']==c['account']
s=boto3.client('s3',region_name=c['region']);ec=boto3.client('ec2',region_name=c['region']);elb=boto3.client('elbv2',region_name=c['region']);b=boto3.client('budgets',region_name='us-east-1')
def save():(p/'logging-apply.json').write_text(json.dumps(r,indent=2,default=str))
assert not json.loads((p/'logging-validation.json').read_text())['findings']
r['old_alb']=elb.describe_load_balancer_attributes(LoadBalancerArn=c['alb'])['Attributes'];r['old_budget']=b.describe_budget(AccountId=c['account'],BudgetName='nuers-account-monthly')['Budget'];save()
# Dedicated bucket must not already contain any data from another use.
existing=[x['Name'] for x in s.list_buckets()['Buckets']]
if c['bucket'] in existing:
 tags=s.get_bucket_tagging(Bucket=c['bucket'])['TagSet'];assert {'Key':'Purpose','Value':'nuers-vapt-network-audit-20261115'} in tags
else:
 s.create_bucket(Bucket=c['bucket'],CreateBucketConfiguration={'LocationConstraint':c['region']},ObjectOwnership=c['ownership'])
 s.put_bucket_tagging(Bucket=c['bucket'],Tagging={'TagSet':[{'Key':'Purpose','Value':'nuers-vapt-network-audit-20261115'}]})
s.put_public_access_block(Bucket=c['bucket'],PublicAccessBlockConfiguration=c['public_access_block'])
s.put_bucket_encryption(Bucket=c['bucket'],ServerSideEncryptionConfiguration={'Rules':[{'ApplyServerSideEncryptionByDefault':{'SSEAlgorithm':'AES256'}}]})
s.put_bucket_policy(Bucket=c['bucket'],Policy=json.dumps(c['policy']))
s.put_bucket_lifecycle_configuration(Bucket=c['bucket'],LifecycleConfiguration=c['lifecycle'])
flows=ec.describe_flow_logs(Filters=[{'Name':'resource-id','Values':c['flow']['ResourceIds']}])['FlowLogs'];ours=[f for f in flows if f.get('LogDestination')==c['flow']['LogDestination']]
if not ours:
 x=ec.create_flow_logs(**c['flow'],TagSpecifications=[{'ResourceType':'vpc-flow-log','Tags':[{'Key':'Name','Value':'nuers-prod-security-audit'}]}]);r['flow_create']=x;save();assert not x.get('Unsuccessful'),x
else:r['existing_flow_ids']=[x['FlowLogId'] for x in ours]
# AWS may auto-attach delivery statements. Enforce the approved exact policy again.
s.put_bucket_policy(Bucket=c['bucket'],Policy=json.dumps(c['policy']))
assert json.loads(s.get_bucket_policy(Bucket=c['bucket'])['Policy'])==c['policy']
elb.modify_load_balancer_attributes(LoadBalancerArn=c['alb'],Attributes=[{'Key':'access_logs.s3.enabled','Value':'true'},{'Key':'access_logs.s3.bucket','Value':c['bucket']},{'Key':'access_logs.s3.prefix','Value':c['alb_prefix']}])
budget={k:v for k,v in r['old_budget'].items() if k in ['BudgetName','BudgetLimit','CostFilters','CostTypes','TimeUnit','TimePeriod','BudgetType','BillingViewArn','FilterExpression','Metrics']};budget['BudgetLimit']={'Amount':str(c['monthly_budget_usd']),'Unit':'USD'};b.update_budget(AccountId=c['account'],NewBudget=budget)
r['verification']={'alb':elb.describe_load_balancer_attributes(LoadBalancerArn=c['alb'])['Attributes'],'flow':ec.describe_flow_logs(Filters=[{'Name':'resource-id','Values':c['flow']['ResourceIds']}])['FlowLogs'],'policy':json.loads(s.get_bucket_policy(Bucket=c['bucket'])['Policy']),'public_block':s.get_public_access_block(Bucket=c['bucket'])['PublicAccessBlockConfiguration'],'encryption':s.get_bucket_encryption(Bucket=c['bucket'])['ServerSideEncryptionConfiguration'],'retention':s.get_bucket_lifecycle_configuration(Bucket=c['bucket'])['Rules'],'budget':b.describe_budget(AccountId=c['account'],BudgetName='nuers-account-monthly')['Budget']['BudgetLimit']}
r['configured']=True;r['delivery_verified']=False;save();print('LOGGING_CONFIGURED; DELIVERY_PENDING')
