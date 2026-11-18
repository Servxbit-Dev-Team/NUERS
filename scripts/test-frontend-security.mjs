import assert from 'node:assert/strict';
import { test } from 'node:test';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
import { randomUUID } from 'node:crypto';
function fixture() {
  const values=new Map([['nuers_api_token','session-A']]), cache=new Map(), redirects=[], events=[];
  const storage=map=>({getItem:k=>map.get(k)??null,setItem:(k,v)=>map.set(k,v),removeItem:k=>map.delete(k),key:i=>[...map.keys()][i]??null,get length(){return map.size}});
  const window={localStorage:storage(values),sessionStorage:storage(cache),location:{origin:'https://nuers.test',pathname:'/invoices',search:'',hash:'',assign:u=>redirects.push(u)},dispatchEvent:e=>events.push(e.type)};
  let fetcher=()=>Promise.resolve(new Response('{}',{headers:{'Content-Type':'application/json'}}));
  const modules=new Map();
  function load(name) {
    if(modules.has(name))return modules.get(name);
    const exports={};modules.set(name,exports);
    const source=fs.readFileSync('src/lib/'+name+'.ts','utf8').replaceAll('import.meta.env.VITE_API_BASE_URL','undefined');
    const code=ts.transpileModule(source,{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText;
    vm.runInNewContext(code,{exports,require:id=>load(id.replace('./','')),window,crypto:{randomUUID},Event,Headers,Response,FormData,Blob,URL,DOMException,AbortController,clearTimeout,setTimeout,fetch:(...args)=>fetcher(...args)});
    return exports;
  }
  return {values,cache,redirects,events,window,load,setFetch:fn=>fetcher=fn};
}
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return {promise,resolve};}
const json=data=>new Response(JSON.stringify(data),{headers:{'Content-Type':'application/json'}});
test('credentialed transport rejects network-path and backslash URL escapes before fetch',async()=>{
  const f=fixture();let calls=0;f.setFetch(()=>{calls++;return Promise.resolve(json({}));});
  const api=f.load('api-url');
  for(const path of ['//attacker.invalid/api','/\\attacker.invalid/api','/\t/attacker.invalid','https://attacker.invalid/api']) await assert.rejects(()=>api.apiFetch(path));
  assert.equal(calls,0);
});
test('credentialed API requests do not follow redirects or permit cache override',async()=>{
  const f=fixture();let sent;f.setFetch((url,init)=>{sent={url,init};return Promise.resolve(json({}));});
  await f.load('api-url').apiFetch('/api/health',{redirect:'follow',cache:'force-cache'});
  assert.equal(sent.init.redirect,'error');assert.equal(sent.init.cache,'no-store');assert.equal(sent.init.headers.get('Authorization'),'Bearer session-A');
});
test('delayed successful response cannot cross an account/session change',async()=>{
  const f=fixture(),d=deferred();f.setFetch(()=>d.promise);const p=f.load('api-url').apiFetch('/api/dashboards/merchant');
  f.values.set('nuers_api_token','session-B');d.resolve(json({private:'ACCOUNT-A'}));
  await assert.rejects(p,/session changed/i);assert.equal(f.values.get('nuers_api_token'),'session-B');
});
test('delayed MFA challenge cannot redirect a replacement session',async()=>{
  const f=fixture(),d=deferred();f.setFetch(()=>d.promise);const p=f.load('api-url').apiFetch('/api/private/export');
  f.values.set('nuers_api_token','session-B');d.resolve(new Response(JSON.stringify({code:'MFA_REQUIRED'}),{status:403,headers:{'Content-Type':'application/json'}}));
  await p;assert.deepEqual(f.redirects,[]);
});
test('body and clone reads remain bound to the requesting session',async()=>{
  for(const method of ['json','text','blob','arrayBuffer','clone']) {
    const f=fixture();f.setFetch(()=>Promise.resolve(json({private:'ACCOUNT-A'})));const r=await f.load('api-url').apiFetch('/api/private');
    f.values.set('nuers_api_token','session-B');
    await assert.rejects(async()=>method==='clone'?r.clone().json():r[method](),/session changed/i);
  }
});
test('session change during JSON body streaming discards the result',async()=>{
  const f=fixture(),body=deferred();const r=json({});r.json=()=>body.promise;f.setFetch(()=>Promise.resolve(r));const api=f.load('api-url');
  const result=api.readJsonResponse(await api.apiFetch('/api/private'));await Promise.resolve();f.values.set('nuers_api_token','session-B');body.resolve({private:'ACCOUNT-A'});
  await assert.rejects(result,/session changed/i);
});
test('logout removes legacy dashboard caches but preserves unrelated preferences',()=>{
  const f=fixture();f.cache.set('nuers_dashboard_merchant_session-A','private financial payload');f.cache.set('other-preference','keep');
  f.load('session-store').invalidateToken('session-A');assert.equal(f.cache.size,1);assert.equal(f.cache.get('other-preference'),'keep');
});
test('successful login purges the previous dashboard cache',async()=>{
  const f=fixture();f.cache.set('nuers_dashboard_merchant_old','private');f.setFetch(()=>Promise.resolve(json({session:{access_token:'session-B',user:{id:'B'}},user:{id:'B'}})));
  await f.load('supabase').supabase.auth.signInWithPassword({email:'synthetic@example.test',password:'synthetic'});assert.equal(f.cache.size,0);
});

test('malformed session responses do not invalidate an existing login',async()=>{
  for(const payload of ['{', '{}', '{"message":"proxy response"}']) {
    const f=fixture();f.setFetch(()=>Promise.resolve(new Response(payload,{headers:{'Content-Type':'application/json'}})));
    const result=await f.load('supabase').supabase.auth.getSession();assert.ok(result.error);assert.equal(f.values.get('nuers_api_token'),'session-A');assert.deepEqual(f.events,[]);
  }
});
test('mutation timeouts report unknown outcome and never automatically replay',async()=>{
  const f=fixture();let calls=0;f.setFetch((_url,init)=>{calls++;return new Promise((_resolve,reject)=>init.signal.addEventListener('abort',()=>reject(new DOMException('Aborted','AbortError'))));});
  await assert.rejects(()=>f.load('api-url').apiJson('/api/private',{method:'POST',body:'{}',timeoutMs:5}),/outcome is unknown/);assert.equal(calls,1);assert.equal(f.values.get('nuers_api_token'),'session-A');
});
test('ordinary JSON and blob responses remain usable in the same session',async()=>{
  const f=fixture(),api=f.load('api-url');f.setFetch(()=>Promise.resolve(json({ok:true})));
  assert.deepEqual(await api.apiJson('/api/health'),{ok:true});const r=await api.apiFetch('/api/private/download');assert.equal(await (await r.blob()).text(),'{"ok":true}');
});

test('anonymous login completion cannot replace a session established in another tab',async()=>{
  for(const action of ['signInWithPassword','completeGoogleSignIn','signUp']) {
    const f=fixture(),d=deferred();f.values.delete('nuers_api_token');f.setFetch(()=>d.promise);
    const p=f.load('supabase').supabase.auth[action]({email:'synthetic@example.test',password:'synthetic'});
    f.values.set('nuers_api_token','session-B');d.resolve(json({session:{access_token:'session-A',user:{id:'A'}},user:{id:'A'},role:'merchant'}));
    const result=await p;assert.ok(result.error);assert.equal(f.values.get('nuers_api_token'),'session-B');
  }
});
test('invalid non-null session shapes preserve the existing valid token',async()=>{
  for(const session of [{},false,'invalid',{user:{id:'A'}},{access_token:'session-A',user:null}]) {
    const f=fixture();f.setFetch(()=>Promise.resolve(json({session})));
    const result=await f.load('supabase').supabase.auth.getSession();assert.ok(result.error);assert.equal(f.values.get('nuers_api_token'),'session-A');
  }
});
