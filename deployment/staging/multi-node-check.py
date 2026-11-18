#!/usr/bin/env python3
"""Verified TLS, synthetic fixtures only; output excludes credentials and tokens."""
import base64,hashlib,hmac,json,os,ssl,struct,subprocess,time,urllib.request,urllib.error,uuid
from pathlib import Path
ROOT=Path('/srv/nuers-vapt')
assert ROOT.exists()
fixtures=json.loads((ROOT/'private/fixtures.json').read_text())
context=ssl.create_default_context(cafile=str(ROOT/'private/ca.crt'))
A='https://10.85.0.70:8080'; B='https://10.85.0.208:8080'
results=[]
def request(node,path,method='GET',payload=None,session=None):
    headers={'Accept':'application/json','Content-Type':'application/json'}
    if session: headers.update({'Authorization':'Bearer '+session['token'],'X-NUERS-Device':session['device']})
    req=urllib.request.Request(node+path,data=None if payload is None else json.dumps(payload).encode(),headers=headers,method=method)
    try: r=urllib.request.urlopen(req,context=context,timeout=25)
    except urllib.error.HTTPError as e: r=e
    raw=r.read();code=r.code
    try: data=json.loads(raw)
    except ValueError: data={}
    return code,data,dict(r.headers)
def check(name,condition):
    results.append({'check':name,'pass':bool(condition)})
    print(name+': '+('PASS' if condition else 'FAIL'),flush=True)
    (ROOT/'evidence/multi-node.json').write_text(json.dumps(results,indent=2))
    if not condition: raise RuntimeError(name)
def code(secret):
    key=base64.b32decode(secret+'='*((8-len(secret)%8)%8)); digest=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest();offset=digest[-1]&15
    return str((struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000).zfill(6)
def login(name,node,verify=True):
    f=fixtures[name]; device=str(uuid.uuid4())
    req=urllib.request.Request(node+'/api/auth/login',data=json.dumps({'email':f['email'],'password':f['password']}).encode(),headers={'Accept':'application/json','Content-Type':'application/json','X-NUERS-Device':device},method='POST')
    with urllib.request.urlopen(req,context=context,timeout=25) as r: body=json.load(r)
    s={'token':body['session']['access_token'],'device':device}
    if verify:
        status,_,_=request(B if node==A else A,'/api/auth/mfa/verify','POST',{'code':s.setdefault('verified_code',code(f['totp_secret']))},s)
        check(name+' MFA on peer',status==200)
    return s
def alter(name):
    subprocess.run(['docker','cp',str(ROOT/'harness/synthetic-fixtures.php'),'nuers-app:/app/deployment/staging/synthetic-fixtures-current.php'],check=True,stdout=subprocess.DEVNULL)
    subprocess.run(['docker','exec','nuers-app','php','deployment/staging/synthetic-fixtures-current.php',name],check=True,stdout=subprocess.DEVNULL)
for node in [A,B]:
    status,_,headers=request(node,'/api/tables/merchants')
    check(node+' anonymous authorization',status==401)
    check(node+' version disclosure absent','PHP' not in headers.get('X-Powered-By',''))
s=login('admin',A)
check('Shared session accepted by both nodes',all(request(n,'/api/tables/merchants',session=s)[0]==200 for n in [A,B]))
wrong=dict(s,device=str(uuid.uuid4()))
check('Wrong device rejected on peer',request(B,'/api/tables/merchants',session=wrong)[0]==401)
replay=request(A,'/api/auth/mfa/verify','POST',{'code':s['verified_code']},s)[0]
check('TOTP replay rejected across nodes',replay==422)
new=login('admin',B,verify=False)
check('Replacement login invalidates old session on other node',request(A,'/api/auth/session',session=s)[1].get('session') is None)
request(A,'/api/auth/logout','POST',{},s)
check('Stale logout preserves replacement session',request(B,'/api/auth/session',session=new)[1].get('session') is not None)
check('Replacement login requires MFA',request(A,'/api/tables/merchants',session=new)[0]==403)
for name in ['idle','absolute','stepup']:
    ss=login(name,A);alter(name)
    if name=='stepup':
        check('Stale MFA permits ordinary reads before challenge',request(B,'/api/tables/merchants',session=ss)[0]==200)
        check('Stale MFA blocks writes on peer',request(B,'/api/tables/profiles','PATCH',{'eq':{'id':fixtures[name]['id']},'values':{'organization':'synthetic'}},ss)[0]==403)
        check('Pending MFA challenge enforced on both nodes',all(request(n,'/api/tables/merchants',session=ss)[0]==403 for n in [A,B]))
    else: check(name+' expiry denied on peer',request(B,'/api/tables/merchants',session=ss)[0]==401)
ma=login('merchant_a',A);mb=login('merchant_b',B)
for node in [A,B]:
    status,data,_=request(node,'/api/tables/merchants',session=ma)
    check(node+' tenant scope',status==200 and [r['id'] for r in data.get('data',[])]==['merchant_a'])
    check(node+' merchant cannot use admin readiness',request(node,'/api/operations/readiness',session=ma)[0]==403)
request(A,'/api/auth/logout','POST',{},ma)
check('Logout propagated to peer',request(B,'/api/tables/merchants',session=ma)[0]==401)
from concurrent.futures import ThreadPoolExecutor
with ThreadPoolExecutor(max_workers=2) as pool:
    races=list(pool.map(lambda node:login('admin',node,verify=False),[A,B]))
valid=[request(A,'/api/auth/session',session=token)[1].get('session') is not None for token in races]
check('Simultaneous cross-node login leaves exactly one active session',sum(valid)==1)
statuses=[request(node,'/api/auth/mfa/verify','POST',{'code':'invalid'},mb)[0] for node in [A,A,A,B,B,B]]
check('MFA rate limit shared across nodes',statuses[-1]==429 and all(x in [422,429] for x in statuses))
print('MULTI_NODE_PASS',len(results))
