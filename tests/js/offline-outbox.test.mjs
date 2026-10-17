import {test} from 'node:test';import assert from 'node:assert/strict';
import {canonical,deriveKey,encryptEvent,decryptEvent} from '../../src/lib/integrations/offline-outbox.ts';
const event={schema_version:'NUERS-ingestion-1',source_system:'synthetic',source_event_id:'e-1',external_reference:'doc-1',branch_code:'00000',gross_amount:'112.00'};
test('stable canonicalization and source identity; encrypted at rest and tenant AAD',async()=>{assert.equal(canonical({b:2,a:1}),'{"a":1,"b":2}');const key=await deriveKey('synthetic-private-recovery-key',new Uint8Array(16));assert.equal(key.extractable,false);const a=await encryptEvent(key,'tenant-1',event),b=await encryptEvent(key,'tenant-1',event);assert.equal(a.id,b.id);assert.notDeepEqual(a.iv,b.iv);assert.deepEqual(await decryptEvent(key,a),event);await assert.rejects(()=>decryptEvent(key,{...a,merchant:'tenant-2'}));const wrong=await deriveKey('a-different-private-recovery-key',new Uint8Array(16));await assert.rejects(()=>decryptEvent(wrong,a));});
test('tampering and malformed JSON values are rejected',async()=>{const key=await deriveKey('synthetic-private-recovery-key',new Uint8Array(16));const e=await encryptEvent(key,'m',event);new Uint8Array(e.ciphertext)[0]^=1;await assert.rejects(()=>decryptEvent(key,e));assert.throws(()=>canonical({bad:NaN}));assert.throws(()=>canonical({bad:undefined}));});

test('funding failures pause rather than lose client events; auth and throttles remain retryable', async()=>{
 const {retryDisposition}=await import('../../src/lib/integrations/offline-outbox.ts');
 for(const status of [200,201])assert.equal(retryDisposition(status),'accepted');
 assert.equal(retryDisposition(402),'funding_required');
 for(const status of [401,403,408,429,500,502,503])assert.equal(retryDisposition(status),'pending');
 for(const status of [404,409,413,422])assert.equal(retryDisposition(status),'review');
});
test('portal recovery copy keeps request identity and financial values encrypted',async()=>{
 const key=await deriveKey('synthetic-private-recovery-key',new Uint8Array(16));
 const payload={request_id:'stable-original-request',invoice_number:'CLIENT-1',line_items:[{unit_price:56000,quantity:1}],status:'draft'};
 const source={schema_version:'NUERS-portal-invoice-1',source_system:'nuers_portal',source_event_id:payload.request_id,external_reference:payload.invoice_number,branch_code:'',portal_invoice:payload};
 const encrypted=await encryptEvent(key,'merchant',source);
 assert.deepEqual((await decryptEvent(key,encrypted)).portal_invoice,payload);
 assert.equal(new TextDecoder().decode(encrypted.ciphertext).includes('CLIENT-1'),false);
});
test('only identity-matched durable responses acknowledge buffered sources',async()=>{
 const {acceptedResponse,assertNoCredentials}=await import('../../src/lib/integrations/offline-outbox.ts');
 const accepted={id:'inbox',receipt_id:'receipt',status:'durably_recorded',source_event_id:event.source_event_id,external_reference:event.external_reference};
 assert.equal(acceptedResponse(event,accepted),true);
 for(const body of [null,'<html>login</html>',{}, {...accepted,source_event_id:'wrong'}, {...accepted,receipt_id:undefined},{...accepted,status:'queued'}])assert.equal(acceptedResponse(event,body),false);
 const portal={...event,schema_version:'NUERS-portal-invoice-1'};
 assert.equal(acceptedResponse(portal,{request_id:event.source_event_id,invoice:{id:'document',status:'draft'}}),true);
 assert.equal(acceptedResponse(portal,{request_id:'another',invoice:{id:'document',status:'issued'}}),false);
 assert.throws(()=>assertNoCredentials({...event,metadata:{api_key:'never-store'}}));assertNoCredentials(event);
});
