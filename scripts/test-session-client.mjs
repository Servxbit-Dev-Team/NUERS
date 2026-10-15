import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import ts from 'typescript';
import {randomUUID} from 'node:crypto';
const values=new Map(),events=[];
const window={localStorage:{getItem:k=>values.get(k)??null,setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k)},dispatchEvent:e=>events.push(e.type)};
let respond=()=>Promise.resolve(new Response('{}',{headers:{'Content-Type':'application/json'}}));
function module(file,session){const exports={};const code=ts.transpileModule(fs.readFileSync(file,'utf8').replaceAll('import.meta.env.VITE_API_BASE_URL','undefined'),{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText;vm.runInNewContext(code,{exports,require:id=>{if(id==='./session-store')return session;throw Error(id)},window,crypto:{randomUUID},Event,Headers,Response,FormData,fetch:(...args)=>respond(...args),setTimeout,URL});return exports;}
const session=module('src/lib/session-store.ts'),api=module('src/lib/api-url.ts',session),auth=module('src/lib/supabase.ts',session).supabase.auth;
const device=session.browserDevice();assert.equal(device,session.browserDevice());assert.match(device,/^[\w-]{36}$/);
values.set(session.TOKEN_KEY,'valid');respond=async()=>{throw Error('offline')};const offline=await auth.getSession();assert.ok(offline.error);assert.equal(session.storedToken(),'valid');assert.equal(events.length,0);
respond=async()=>new Response(JSON.stringify({session:null}),{headers:{'Content-Type':'application/json'}});await auth.getSession();assert.equal(session.storedToken(),null);assert.deepEqual(events,[session.SESSION_INVALID_EVENT]);
values.set(session.TOKEN_KEY,'old');respond=async()=>{values.set(session.TOKEN_KEY,'new');return new Response(JSON.stringify({session:null}),{headers:{'Content-Type':'application/json'}})};assert.equal((await auth.getSession()).stale,true);assert.equal(session.storedToken(),'new');
respond=async()=>new Response('{}',{status:401});await api.apiFetch('/api/private');assert.equal(session.storedToken(),null);
values.set(session.TOKEN_KEY,'valid');respond=async()=>new Response('{}',{status:403});await api.apiFetch('/api/private');assert.equal(session.storedToken(),'valid');
respond=async()=>{values.set(session.TOKEN_KEY,'replacement');return new Response('{}',{status:401})};await api.apiFetch('/api/private');assert.equal(session.storedToken(),'replacement');
respond=async()=>new Response(JSON.stringify({message:'Incorrect password'}),{status:422,headers:{'Content-Type':'application/json'}});await auth.signInWithPassword({email:'example@example.test',password:'not-a-secret-test'});assert.equal(session.storedToken(),'replacement');
console.log('Session client: 8 scenarios passed (device persistence, offline preservation, invalidation, stale responses, 401, 403, replacement-token race, failed login).');
