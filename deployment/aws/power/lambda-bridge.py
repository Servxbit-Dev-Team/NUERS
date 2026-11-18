"""Fixed IAM-authenticated Lambda transport; reads one bounded JSON request on stdin."""
import json
import sys
import boto3
from botocore.config import Config

raw = sys.stdin.buffer.read(32769)
if len(raw) > 32768:
    raise SystemExit('Controller request exceeds limit')
request = json.loads(raw)
if request.get('method') not in ('submit', 'result', 'preflight'):
    raise SystemExit('Unsupported controller method')
client = boto3.client('lambda', region_name='ap-southeast-1', config=Config(connect_timeout=3, read_timeout=15, retries={'total_max_attempts': 1}))
response = client.invoke(FunctionName='nuers-server-power', InvocationType='RequestResponse', Payload=raw)
body = response['Payload'].read(65537)
if response.get('FunctionError') or len(body) > 65536:
    raise SystemExit('Controller rejected or could not complete the request; reconcile by job ID')
print(json.dumps(json.loads(body)))
