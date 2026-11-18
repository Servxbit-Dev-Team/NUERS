"""Bounded, serial, non-destructive checks against the explicitly authorized NUERS host.
No authenticated customer session, account creation, delivery, uploads or financial mutations.
Response bodies/cookies are never persisted; findings contain metadata only.
"""
import urllib.request,urllib.error,urllib.parse,json,time,datetime,pathlib,re,hashlib
HOST='https://nuers.net';OUT=pathlib.Path.home()/'nuers-frontend-20261115'/'security-probes.json'
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
opener=urllib.request.build_opener(NoRedirect());records=[]
UA='NUERS-Authorized-Security-Retest/2026-11-15'
def probe(group,path,method='GET',headers=None,payload=None,rule='denied'):
 assert path.startswith('/') and not path.startswith('//')
 req=urllib.request.Request(HOST+path,method=method,headers={'User-Agent':UA,'Accept':'application/json' if path.startswith('/api/') else 'text/html',**(headers or {})},data=payload)
 started=time.monotonic()
 try:
  try:r=opener.open(req,timeout=15)
  except urllib.error.HTTPError as er:r=er
  status=r.code;h={k.lower():v for k,v in r.headers.items()};body=r.read(150000);text=body.decode('utf8','replace')
  edge=status==403 and ('cf-mitigated' in h or 'cloudflare' in text.lower() or 'cf-error' in text.lower() or '<html' in text.lower())
  if rule=='ok':passed=status==200
  elif rule=='private-file':passed=status in [400,403,404] or (status==200 and 'id="root"' in text and not re.search(r'APP_KEY=|DB_PASSWORD=|\[core\]|root:x:|<\?php|"require"\s*:',text))
  elif rule=='cors':passed=h.get('access-control-allow-origin') not in ['*','https://attacker.invalid','null']
  elif rule=='reflection':passed=status in [400,403,404,422] or (status==200 and '<svg/onload=alert(1337)>' not in text and '<script>alert(1337)</script>' not in text)
  elif rule=='redirect':passed=status in [200,400,403,404,422] and not h.get('location','').startswith(('https://attacker.invalid','//attacker.invalid'))
  elif rule=='normalized':passed=status in [400,401,403,404,405] or (status==200 and 'id="root"' in text)
  else:passed=status in [400,401,403,404,405,415,422]
  leak=bool(re.search(r'APP_KEY=|DB_PASSWORD=|root:x:|Stack trace:|SQLSTATE\[|/srv/nuers/releases/',text))
  result={'group':group,'method':method,'path':path,'status':status,'passed':passed and not leak,'edge_blocked':edge,'body_bytes_read':len(body),'body_sha256':hashlib.sha256(body).hexdigest(),'sensitive_signature_detected':leak,'seconds':round(time.monotonic()-started,3)}
  if rule=='ok':
   result['security_headers']={k:h.get(k) for k in ['content-security-policy','x-content-type-options','x-frame-options','strict-transport-security','referrer-policy','permissions-policy','cache-control']}
   result['php_version_exposed']='php' in h.get('x-powered-by','').lower()
   result['passed'] &= not result['php_version_exposed']
  if rule=='cors':result['allow_origin']=h.get('access-control-allow-origin');result['allow_credentials']=h.get('access-control-allow-credentials')
 except Exception as e:result={'group':group,'method':method,'path':path,'passed':False,'error':type(e).__name__}
 records.append(result);OUT.write_text(json.dumps({'started_at':started_at,'scope':HOST,'completed':False,'records':records},indent=2));print(group,method,path,result.get('status'),result['passed'],flush=True);time.sleep(.7)
started_at=datetime.datetime.now(datetime.timezone.utc).isoformat()
for path in ['/','/login','/api/health']:probe('baseline',path,rule='ok')
for path in ['/.env','/.env.backup','/.git/config','/composer.json','/composer.lock','/package.json','/storage/logs/laravel.log','/bootstrap/cache/config.php','/vendor/composer/installed.json','/deployment/reports/NUERS_Frontend_Gap_Provenance_2026-11-15.json','/src/lib/api-url.ts','/storage/../../.env','/%2e%2e/.env','/uploads/../../.env']:probe('private-file-or-path',path,rule='private-file')
for path in ['/api/tables/profiles','/api/tables/merchants','/api/dashboards/super-admin','/api/transactions/overview','/api/transaction-workspace','/api/merchant/api-keys','/api/admin/analytics','/api/merchant/wallet','/api/operations/readiness','/api/admin/support','/api/integrations/transactions/wallet','/api/merchant/sales-reports']:probe('anonymous-access',path)
for headers in [{'Authorization':'Bearer nuers-audit-invalid-token'},{'Authorization':'Bearer eyJhbGciOiJub25lIn0.eyJyb2xlIjoic3VwZXJfYWRtaW4ifQ.'},{'X-User-Role':'super_admin','X-User-Id':'1','X-Forwarded-User':'admin'},{'X-Original-URL':'/api/tables/profiles','X-Rewrite-URL':'/api/tables/profiles'},{'X-Forwarded-For':'127.0.0.1','X-Real-IP':'127.0.0.1'}]:probe('forged-auth-context','/api/tables/profiles',headers=headers)
for path in ['/api/%74ables/profiles','/%61pi/tables/profiles','/api//tables/profiles','/api/./tables/profiles','/api/tables/profiles/']:probe('path-normalization',path,rule='normalized')
for value in ["' OR '1'='1",'${7*7}','{{7*7}}','../../etc/passwd','<script>alert(1337)</script>']:
 probe('pre-auth-injection-boundary','/api/tables/profiles?search='+urllib.parse.quote(value))
for value in ['<svg/onload=alert(1337)>','<script>alert(1337)</script>','{{7*7}}']:
 probe('public-reflection','/login?q='+urllib.parse.quote(value),rule='reflection')
for value in ['https://attacker.invalid','//attacker.invalid','/%5cattacker.invalid']:
 probe('public-return-url','/login?returnTo='+urllib.parse.quote(value),rule='redirect')
for origin in ['https://attacker.invalid','null']:
 probe('cors','/api/tables/profiles',headers={'Origin':origin},rule='cors')
 probe('cors-preflight','/api/tables/profiles',method='OPTIONS',headers={'Origin':origin,'Access-Control-Request-Method':'POST','Access-Control-Request-Headers':'authorization,content-type'},rule='cors')
probe('method-control','/api/tables/profiles',method='TRACE')
# Empty malformed login bodies cannot identify an account or trigger mail delivery.
for body in [b'{',b'{"email":{"$ne":null},"password":{"$ne":null}}',b'{"email":"nuers-audit-nonexistent@example.test","password":"not-a-valid-password","role":"super_admin"}']:
 probe('login-validation','/api/auth/login',method='POST',headers={'Content-Type':'application/json'},payload=body)
probe('post-probe-availability','/api/health',rule='ok')
report={'started_at':started_at,'finished_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),'scope':HOST,'completed':True,'request_count':len(records),'passed':sum(x['passed'] for x in records),'failed':sum(not x['passed'] for x in records),'edge_blocked':sum(x.get('edge_blocked',False) for x in records),'records':records,'limits':['No live authenticated customer mutation or brute-force stress test','Edge-blocked requests do not establish backend payload coverage','Local suites cover authenticated tenant, upload, replay and mutation cases']};OUT.write_text(json.dumps(report,indent=2));print('PROBE_SUMMARY',json.dumps({k:v for k,v in report.items() if k not in ['records','limits']}))
