"""Generate least-privilege logging configuration; no AWS calls or mutations."""
import json,pathlib
p=pathlib.Path(__file__).parent
account='289829301267';region='ap-southeast-1';bucket='nuers-289829301267-network-audit-sg';arn='arn:aws:s3:::'+bucket
policy={'Version':'2012-10-17','Statement':[
 {'Sid':'RequireTLS','Effect':'Deny','Principal':'*','Action':'s3:*','Resource':[arn,arn+'/*'],'Condition':{'Bool':{'aws:SecureTransport':'false'}}},
 {'Sid':'ALBWrite','Effect':'Allow','Principal':{'Service':'logdelivery.elasticloadbalancing.amazonaws.com'},'Action':'s3:PutObject','Resource':arn+'/alb/AWSLogs/'+account+'/*','Condition':{'ArnLike':{'aws:SourceArn':'arn:aws:elasticloadbalancing:'+region+':'+account+':loadbalancer/*'}}},
 {'Sid':'FlowWrite','Effect':'Allow','Principal':{'Service':'delivery.logs.amazonaws.com'},'Action':'s3:PutObject','Resource':arn+'/flow/AWSLogs/'+account+'/*','Condition':{'StringEquals':{'aws:SourceAccount':account,'s3:x-amz-acl':'bucket-owner-full-control'},'ArnLike':{'aws:SourceArn':'arn:aws:logs:'+region+':'+account+':*'}}},
 {'Sid':'FlowACL','Effect':'Allow','Principal':{'Service':'delivery.logs.amazonaws.com'},'Action':'s3:GetBucketAcl','Resource':arn,'Condition':{'StringEquals':{'aws:SourceAccount':account},'ArnLike':{'aws:SourceArn':'arn:aws:logs:'+region+':'+account+':*'}}}
]}
config={'region':region,'account':account,'bucket':bucket,'encryption':'AES256','public_access_block':dict.fromkeys(['BlockPublicAcls','IgnorePublicAcls','BlockPublicPolicy','RestrictPublicBuckets'],True),'ownership':'BucketOwnerEnforced','policy':policy,'lifecycle':{'Rules':[{'ID':'NewNetworkLogs90Days','Status':'Enabled','Filter':{'Prefix':''},'Expiration':{'Days':90}}]},'alb':'arn:aws:elasticloadbalancing:ap-southeast-1:289829301267:loadbalancer/app/nuers-prod/bafa8634a13ab2b9','alb_prefix':'alb','flow':{'ResourceIds':['vpc-06225f1a965755eb1'],'ResourceType':'VPC','TrafficType':'ALL','LogDestinationType':'s3','LogDestination':arn+'/flow','MaxAggregationInterval':600,'DestinationOptions':{'FileFormat':'plain-text','HiveCompatiblePartitions':False,'PerHourPartition':True}},'monthly_budget_usd':1300}
(p/'plan.json').write_text(json.dumps(config,indent=2)+'\n')
# Negative-scope checks: no reads of log bodies, no delete grants, no broad service access.
allows=[x for x in policy['Statement'] if x['Effect']=='Allow']
assert all(x['Action'] in ['s3:PutObject','s3:GetBucketAcl'] for x in allows)
assert all('Condition' in x and x['Principal']['Service'] in ['delivery.logs.amazonaws.com','logdelivery.elasticloadbalancing.amazonaws.com'] for x in allows)
assert all(account in str(x['Condition']) and region in str(x['Condition']) for x in allows)
assert all(bucket in x['Resource'] for x in allows)
assert 'security-audit-sg' not in json.dumps(config) and 'prod-documents-sg' not in json.dumps(config)
print('Logging configuration generated; 5 isolation assertions passed. Not applied.')
