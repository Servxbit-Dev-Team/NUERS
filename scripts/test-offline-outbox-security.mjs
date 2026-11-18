import assert from 'node:assert/strict';
import {test} from 'node:test';
import {readFileSync} from 'node:fs';
import {createRequire} from 'node:module';
import vm from 'node:vm';
import {webcrypto,randomUUID} from 'node:crypto';
import ts from 'typescript';
const require=createRequire(import.meta.url);
const {indexedDB}=require(process.env.NUERS_INDEXEDDB_TEST_MODULE || '/private/tmp/nuers-readiness-test-tools/node_modules/fake-indexeddb');
const exports={};
const code=ts.transpileModule(readFileSync('src/lib/integrations/offline-outbox.ts','utf8'),{compilerOptions:{target:ts.ScriptTarget.ES2022,module:ts.ModuleKind.CommonJS}}).outputText;
vm.runInNewContext(code,{exports,indexedDB,crypto:webcrypto,TextEncoder,TextDecoder,Uint8Array,ArrayBuffer,Date,JSON,Error,Promise,Number,Math});
const {MerchantOfflineOutbox,encryptEvent,decryptEvent,deriveKey}=exports;
const passphrase='Synthetic-offline-passphrase-only';
const event=()=>({schema_version:'NUERS-ingestion-1',source_system:'synthetic-pos',source_event_id:randomUUID(),external_reference:randomUUID(),branch_code:'00000',receipt_number:'SYN-0001'});
const accepted=e=>({status:201,body:{id:randomUUID(),receipt_id:randomUUID(),source_event_id:e.source_event_id,external_reference:e.external_reference,status:'durably_recorded',nuers_status:'success'}});
function deferred(){let resolve;const promise=new Promise(r=>resolve=r);return{promise,resolve};}
for(const late of [502,402,409,'network-error']) test('late '+late+' cannot overwrite accepted result from another queue handle',async()=>{
 const merchant=randomUUID(),a=await MerchantOfflineOutbox.unlock(merchant,passphrase),b=await MerchantOfflineOutbox.unlock(merchant,passphrase),e=event(),started=deferred(),reply=deferred();
 try{
  await a.enqueue(e);const first=a.replay(async()=>{started.resolve();return reply.promise;});await started.promise;
  assert.equal((await b.replay(async x=>accepted(x))).accepted,1);
  reply.resolve(late==='network-error'?Promise.reject(Error('synthetic disconnect')):{status:late,body:{message:'Synthetic late failure'}});await first;
  assert.equal((await b.list())[0].state,'accepted');let sends=0;await b.replay(async x=>{sends++;return accepted(x)});assert.equal(sends,0);
 }finally{a.close();b.close();}
});
test('batch acceptance is not downgraded by a delayed single replay',async()=>{
 const m=randomUUID(),a=await MerchantOfflineOutbox.unlock(m,passphrase),b=await MerchantOfflineOutbox.unlock(m,passphrase),e=event(),started=deferred(),reply=deferred();
 try{await a.enqueue(e);const old=a.replay(async()=>{started.resolve();return reply.promise});await started.promise;
  assert.equal((await b.replayBatch(async events=>({status:201,body:{atomic:true,results:events.map(e=>accepted(e).body)}}))).accepted,1);
  reply.resolve({status:502,body:{message:'late'}});await old;assert.equal((await b.list())[0].state,'accepted');
 }finally{a.close();b.close();}
});
test('stable identity conflicts cannot replace encrypted original',async()=>{
 const box=await MerchantOfflineOutbox.unlock(randomUUID(),passphrase),e=event();try{await box.enqueue(e);await assert.rejects(box.enqueue({...e,receipt_number:'MODIFIED'}),/different content/);assert.equal((await box.list()).length,1);}finally{box.close();}
});
test('merchant and sandbox queues remain isolated and wrong passphrase preserves entries',async()=>{
 const m=randomUUID(),a=await MerchantOfflineOutbox.unlock(m,passphrase),b=await MerchantOfflineOutbox.unlock(m+'::sandbox',passphrase);
 try{await a.enqueue(event());await assert.rejects(MerchantOfflineOutbox.unlock(m,'Incorrect-synthetic-passphrase'),/does not unlock/);assert.equal((await a.list()).length,1);assert.equal((await b.list()).length,0);}finally{a.close();b.close();}
});
test('ciphertext and merchant associated-data tampering fail authentication',async()=>{
 const key=await deriveKey(passphrase,new Uint8Array(16)),e=await encryptEvent(key,'synthetic',event());
 await assert.rejects(decryptEvent(key,{...e,merchant:'foreign'}));const modified=new Uint8Array(e.ciphertext.slice(0));modified[0]^=1;await assert.rejects(decryptEvent(key,{...e,ciphertext:modified.buffer}));
});
test('credentials cannot be persisted and invalid success response remains retryable',async()=>{
 const b=await MerchantOfflineOutbox.unlock(randomUUID(),passphrase);try{await assert.rejects(b.enqueue({...event(),nested:{access_token:'synthetic-secret'}}),/Remove credentials/);assert.equal((await b.list()).length,0);await b.enqueue(event());assert.equal((await b.replay(async()=>({status:200,body:{html:'login'}}))).accepted,0);assert.equal((await b.list())[0].state,'pending');}finally{b.close();}
});
