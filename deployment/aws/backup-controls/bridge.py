"""Fixed IAM transport for the on-demand backup controller."""
import json
import sys
import boto3
from botocore.config import Config

raw = sys.stdin.buffer.read(8193)
if len(raw) > 8192 or json.loads(raw).get('method') != 'backup':
    raise SystemExit('Invalid backup request')
client = boto3.client('lambda', region_name='ap-southeast-1',
    config=Config(connect_timeout=3, read_timeout=15, retries={'total_max_attempts': 1}))
response = client.invoke(FunctionName='nuers-on-demand-backup', InvocationType='RequestResponse', Payload=raw)
body = response['Payload'].read(16385)
if response.get('FunctionError') or len(body) > 16384:
    raise SystemExit('Backup request response could not be confirmed')
print(json.dumps(json.loads(body)))
