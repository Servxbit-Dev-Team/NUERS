# Python 3: send.py - uses only the standard library
import json, os, urllib.request, urllib.error
from pathlib import Path
base = 'https://nuers.net/api/integrations/transactions'
key = os.environ['NUERS_API_KEY']
raw = Path('event.json').read_bytes()
event = json.loads(raw)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None
opener = urllib.request.build_opener(NoRedirect())
req = urllib.request.Request(base, data=raw, method='POST',
    headers={'Accept': 'application/json',
             'Content-Type': 'application/json',
             'X-NUERS-API-Key': key})
try:
    with opener.open(req, timeout=30) as response:
        status, body = response.status, response.read()
except urllib.error.HTTPError as error:
    status, body = error.code, error.read()
# Network/JSON exceptions propagate: keep original pending.
result = json.loads(body)
valid = (status in (200, 201) and isinstance(result, dict)
    and result.get('status') == 'durably_recorded'
    and result.get('environment') == os.environ['NUERS_ENVIRONMENT']
    and result.get('source_event_id') == event['source_event_id']
    and result.get('external_reference') == event['external_reference']
    and isinstance(result.get('id'), str) and result['id'].strip()
    and isinstance(result.get('receipt_id'), str)
    and result['receipt_id'].strip())
if not valid:
    raise RuntimeError('Keep pending/review: HTTP ' + str(status))
# Commit returned IDs + accepted state in one local DB transaction.
print(json.dumps({'accepted': True, 'id': result['id']}))
