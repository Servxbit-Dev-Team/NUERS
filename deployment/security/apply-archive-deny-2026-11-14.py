import boto3,json,pathlib
p=pathlib.Path.home()/'nuers-vapt-20261114';assert json.loads((p/'production-rollout.json').read_text())['complete'] is True
role='nuers-prod-web';name='NuersDenyRuntimeMigrationArchives';iam=boto3.client('iam');s3=boto3.client('s3',region_name='ap-southeast-1')
policy={'Version':'2012-10-17','Statement':[{'Sid':'DenyRuntimeMigrationArchiveAccess','Effect':'Deny','Action':'s3:*','Resource':'arn:aws:s3:::nuers-289829301267-prod-documents-sg/migration/*'}]}
previous={}
for n in iam.list_role_policies(RoleName=role)['PolicyNames']:previous[n]=iam.get_role_policy(RoleName=role,PolicyName=n)['PolicyDocument']
(p/'production-role-policies-before.json').write_text(json.dumps(previous,indent=2))
if name in previous:assert previous[name]==policy,'Existing policy name has different semantics'
# The independent operator retains recovery access to the exact reviewed artifact.
h=s3.head_object(Bucket='nuers-289829301267-prod-documents-sg',Key='migration/vapt-20261114/release-payload.tar.gz');assert h['ContentLength']==111334363
out=iam.put_role_policy(RoleName=role,PolicyName=name,PolicyDocument=json.dumps(policy))
r={'role':role,'policy_name':name,'policy':policy,'applied':out['ResponseMetadata']['HTTPStatusCode']==200,'operator_artifact_head':200,'ordinary_document_permissions_unchanged':True}
(p/'production-runtime-archive-deny.json').write_text(json.dumps(r,indent=2));print('ARCHIVE_DENY_APPLIED',r['applied'])
# Read-only access probes run as each host's existing role and application DB identity.
cmd='''set -eu
cd /srv/nuers/current
runuser -u nuers -- php -r 'require "vendor/autoload.php";$a=require "bootstrap/app.php";$a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();$db=Illuminate\\Support\\Facades\\DB::connection();$db->select("SELECT 1 FROM users LIMIT 0");$denied=false;try{$db->select("SELECT 1 FROM nuers_rehearsal_20261106.users LIMIT 0");}catch(Illuminate\\Database\\QueryException $e){$denied=in_array($e->errorInfo[1]??0,[1044,1142]);}echo json_encode(["production_read_allowed"=>true,"rehearsal_access_denied"=>$denied]).PHP_EOL;if(!$denied)exit(1);'
python3 - <<'PROBE'
import boto3,time,json
from botocore.exceptions import ClientError
s=boto3.client('s3',region_name='ap-southeast-1');denied=False
for _ in range(12):
 try:s.head_object(Bucket='nuers-289829301267-prod-documents-sg',Key='migration/vapt-20261114/release-payload.tar.gz')
 except ClientError as e:
  denied=e.response['ResponseMetadata']['HTTPStatusCode']==403
  if denied:break
 time.sleep(5)
assert denied,'Runtime archive boundary was not enforced'
print(json.dumps({'runtime_archive_head_denied':denied}))
PROBE
curl --fail --silent http://127.0.0.1:8080/infrastructure-health >/dev/null
'''
s=boto3.client('ssm',region_name='ap-southeast-1');c=s.send_command(InstanceIds=['i-0694f0738afd178ce','i-0fe793f3af7b0c49b'],DocumentName='AWS-RunShellScript',Parameters={'commands':[cmd],'executionTimeout':['120']})['Command']['CommandId'];(p/'production-permission-probe-command.json').write_text(json.dumps({'command_id':c}));print('PERMISSION_PROBE',c)
