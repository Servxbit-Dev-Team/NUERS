import boto3,time,pathlib,json,shlex,base64,gzip,hashlib
p=pathlib.Path.home()/'nuers-cloud-vapt-20261115';s=boto3.client('ssm',region_name='ap-southeast-1');s3=boto3.client('s3',region_name='ap-southeast-1');stage='i-0fd5169e6c271bf76';bucket='nuers-289829301267-staging-documents-sg';key='vapt-20261114/os-20261115/package-inventory.json'
def run(n,cmd,label,timeout=600):
 cid=s.send_command(InstanceIds=[n],DocumentName='AWS-RunShellScript',Parameters={'commands':[cmd],'executionTimeout':[str(timeout)]},TimeoutSeconds=timeout,Comment='Read-only OS package inventory scan; no application files or customer data')['Command']['CommandId']
 for _ in range(timeout//3+10):
  try:q=s.get_command_invocation(CommandId=cid,InstanceId=n)
  except s.exceptions.InvocationDoesNotExist:time.sleep(3);continue
  if q['Status']not in ['Pending','InProgress','Delayed']:break
  time.sleep(3)
 (p/(label+'.json')).write_text(json.dumps(q,default=str));assert q['Status']=='Success',q.get('StandardErrorContent','')[-1000:];return q['StandardOutputContent']

cmd='set -eu\nuname -r\npkgs=$(dpkg-query -W -f=\'${binary:Package} ${db:Status-Status}\\n\' \'linux*\' | awk \'$2 == "installed" && $1 ~ /7.0.0-1014/ {print $1}\')\nprintf \'%s\\n\' "$pkgs"\napt-get -s remove $pkgs\napt-cache policy linux-tools-common linux-tools-7.0.0-1017-aws\n'
r={}
for n in [stage,"i-0694f0738afd178ce","i-0fe793f3af7b0c49b"]:r[n]=run(n,cmd,"kernel-review-"+n)
(p/"kernel-review.json").write_text(json.dumps(r))
print(json.dumps(r))
