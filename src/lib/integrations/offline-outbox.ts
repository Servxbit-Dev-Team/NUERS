/** Optional merchant connector SDK. Queueing an existing finalized source event is not permission to issue offline. */
export type BufferedEvent={schema_version:"NUERS-ingestion-1"|"NUERS-portal-invoice-1";source_system:string;source_event_id:string;external_reference:string;branch_code:string;[field:string]:unknown};
type Envelope={id:string;merchant:string;iv:Uint8Array;ciphertext:ArrayBuffer;hash:string;state:"pending"|"accepted"|"review"|"funding_required";attempts:number;availableAt:number;queuedAt?:number;response?:{iv:Uint8Array;ciphertext:ArrayBuffer}};
const encoder=new TextEncoder();
export function canonical(value:unknown):string {
 if(Array.isArray(value))return "["+value.map(canonical).join(",")+"]";
 if(value&&typeof value==="object")return "{"+Object.keys(value).sort().map(k=>JSON.stringify(k)+":"+canonical((value as Record<string,unknown>)[k])).join(",")+"}";
 if(value===undefined||typeof value==="function"||typeof value==="number"&&!Number.isFinite(value))throw new Error("Source event must contain finite JSON values.");
 return JSON.stringify(value);
}
export async function digest(value:string){return [...new Uint8Array(await crypto.subtle.digest("SHA-256",encoder.encode(value)))].map(x=>x.toString(16).padStart(2,"0")).join("");}
export async function deriveKey(passphrase:string,salt:Uint8Array):Promise<CryptoKey>{if(passphrase.length<16)throw new Error("Use a private offline recovery passphrase of at least 16 characters.");const base=await crypto.subtle.importKey("raw",encoder.encode(passphrase),"PBKDF2",false,["deriveKey"]);return crypto.subtle.deriveKey({name:"PBKDF2",salt:salt as BufferSource,iterations:600000,hash:"SHA-256"},base,{name:"AES-GCM",length:256},false,["encrypt","decrypt"]);}
export async function encryptEvent(key:CryptoKey,merchant:string,event:BufferedEvent):Promise<Envelope>{const raw=canonical(event);const id=await digest(canonical([merchant,event.branch_code,event.source_system,event.external_reference,event.source_event_id]));const iv=crypto.getRandomValues(new Uint8Array(12));const ciphertext=await crypto.subtle.encrypt({name:"AES-GCM",iv,additionalData:encoder.encode(merchant+"|"+id)},key,encoder.encode(raw));return {id,merchant,iv,ciphertext,hash:await digest(raw),state:"pending",attempts:0,availableAt:Date.now(),queuedAt:Date.now()};}
export async function decryptEvent(key:CryptoKey,e:Envelope):Promise<BufferedEvent>{const raw=new TextDecoder().decode(await crypto.subtle.decrypt({name:"AES-GCM",iv:e.iv as BufferSource,additionalData:encoder.encode(e.merchant+"|"+e.id)},key,e.ciphertext));if(await digest(raw)!==e.hash)throw new Error("Offline source hash mismatch; preserve for review.");return JSON.parse(raw) as BufferedEvent;}
function result<T>(request:IDBRequest<T>):Promise<T>{return new Promise((resolve,reject)=>{request.onsuccess=()=>resolve(request.result);request.onerror=()=>reject(request.error);});}
function committed(tx:IDBTransaction){return new Promise<void>((resolve,reject)=>{tx.oncomplete=()=>resolve();tx.onerror=()=>reject(tx.error);tx.onabort=()=>reject(tx.error);});}
export class MerchantOfflineOutbox {
 private db:IDBDatabase;private merchant:string;private key:CryptoKey;
 private constructor(db:IDBDatabase,merchant:string,key:CryptoKey){this.db=db;this.merchant=merchant;this.key=key;}
 static async unlock(merchant:string,passphrase:string){
  if(passphrase.length<16)throw Error("Use a private recovery passphrase of at least 16 characters.");
  const request=indexedDB.open("nuers-merchant-offline-1",1);request.onupgradeneeded=()=>{request.result.createObjectStore("events",{keyPath:"id"});request.result.createObjectStore("salts");};
  const db=await result(request);const tx=db.transaction("salts","readwrite"),done=committed(tx),store=tx.objectStore("salts");
  let credential=await result(store.get(merchant)) as {salt:Uint8Array;check?:Envelope}|undefined;
  if(!credential){credential={salt:crypto.getRandomValues(new Uint8Array(16))};store.put(credential,merchant);}await done;
  const key=await deriveKey(passphrase,credential.salt);
  const candidate=await encryptEvent(key,merchant,{schema_version:"NUERS-ingestion-1",source_system:"key-check",source_event_id:"key-check",external_reference:"key-check",branch_code:"00000"});
  const checkTx=db.transaction("salts","readwrite"),checkDone=committed(checkTx),checkStore=checkTx.objectStore("salts");
  const chosen=await result(checkStore.get(merchant)) as {salt:Uint8Array;check?:Envelope};
  if(!chosen.check){chosen.check=candidate;checkStore.put(chosen,merchant);}await checkDone;
  try{await decryptEvent(key,chosen.check);}catch(e){db.close();throw new Error("Offline passphrase does not unlock this merchant queue. Original encrypted events are preserved.");}
  return new MerchantOfflineOutbox(db,merchant,key);
 }
 async enqueue(event:BufferedEvent){assertNoCredentials(event);const e=await encryptEvent(this.key,this.merchant,event);const tx=this.db.transaction("events","readwrite"),done=committed(tx),store=tx.objectStore("events");const old=await result(store.get(e.id)) as Envelope|undefined;if(old&&old.hash!==e.hash){tx.abort();await done.catch(()=>{});throw new Error("Stable source identity was reused with different content.");}if(!old)store.add(e);await done;return e.id;}

 private async envelopes(limit=Number.POSITIVE_INFINITY,predicate:(e:Envelope)=>boolean=()=>true):Promise<Envelope[]>{const tx=this.db.transaction('events','readonly');const rows:Envelope[]=[];await new Promise<void>((resolve,reject)=>{const q=tx.objectStore('events').openCursor();q.onerror=()=>reject(q.error);q.onsuccess=()=>{const c=q.result;if(!c||rows.length>=limit){resolve();return}if(c.value.merchant===this.merchant&&predicate(c.value))rows.push(c.value);c.continue()}});return rows;}
 private async save(e:Envelope){const tx=this.db.transaction('events','readwrite'),done=committed(tx);tx.objectStore('events').put(e);await done;}
 private async record(e:Envelope,status:number,body:unknown){e.attempts++;const iv=crypto.getRandomValues(new Uint8Array(12));e.response={iv,ciphertext:await crypto.subtle.encrypt({name:'AES-GCM',iv,additionalData:encoder.encode(e.merchant+'|'+e.id+'|response')},this.key,encoder.encode(JSON.stringify(body??null)))};e.state=retryDisposition(status);e.availableAt=e.state==='funding_required'?Number.MAX_SAFE_INTEGER:Date.now()+Math.min(3600000,1000*2**Math.min(12,e.attempts));await this.save(e);}
 async list(){const rows=await this.envelopes();return Promise.all(rows.sort((a,b)=>(b.queuedAt??0)-(a.queuedAt??0)).slice(0,1000).map(async e=>{const event=await decryptEvent(this.key,e);let message='';if(e.response){const raw=await crypto.subtle.decrypt({name:'AES-GCM',iv:e.response.iv as BufferSource,additionalData:encoder.encode(e.merchant+'|'+e.id+'|response')},this.key,e.response.ciphertext);const body=JSON.parse(new TextDecoder().decode(raw));message=typeof body?.message==='string'?body.message.slice(0,500):e.state==='accepted'?'Durable response verified':'Review the source response with your connector operator';}return{id:e.id,reference:event.external_reference,source:event.source_system,state:e.state,attempts:e.attempts,queuedAt:e.queuedAt??0,schema:event.schema_version,last_message:message}}));}
 async resumeFunding(includeReview=false){for(const e of await this.envelopes())if(e.state==='funding_required'||includeReview&&e.state==='review'){e.state='pending';e.availableAt=Date.now();await this.save(e)}}
 private async due(limit:number,schema?:BufferedEvent['schema_version'],branch?:string){const rows=[];for(const envelope of await this.envelopes(Number.POSITIVE_INFINITY,e=>e.state==='pending'&&e.availableAt<=Date.now())){const event=await decryptEvent(this.key,envelope);if((!schema||event.schema_version===schema)&&(!branch||event.branch_code===branch))rows.push({envelope,event})}rows.sort((a,b)=>{if(a.event.schema_version==='NUERS-ingestion-1'&&b.event.schema_version==='NUERS-ingestion-1'&&a.event.branch_code===b.event.branch_code&&a.event.source_system===b.event.source_system){const x=String(a.event.receipt_number??''),y=String(b.event.receipt_number??'');return x.localeCompare(y,undefined,{numeric:true})}return(a.envelope.queuedAt??0)-(b.envelope.queuedAt??0)});return rows.slice(0,Math.max(1,Math.min(100,limit)));}
 /** Caller supplies a credential-aware sender. Keys and login tokens stay outside IndexedDB. */
 async replay(send:(event:BufferedEvent)=>Promise<{status:number;body:unknown}>,limit=20,schema?:BufferedEvent['schema_version'],branch?:string){let accepted=0,attempted=0,fundingRequired=false;for(const {envelope:e,event}of await this.due(limit,schema,branch)){try{const r=await send(event);attempted++;const valid=[200,201].includes(r.status)&&acceptedResponse(event,r.body);const status=[200,201].includes(r.status)&&!valid?502:r.status;await this.record(e,status,r.body);if(valid)accepted++;if(status===402)fundingRequired=true;if(!valid)break}catch{e.attempts++;e.availableAt=Date.now()+Math.min(3600000,1000*2**Math.min(12,e.attempts));await this.save(e);break}}return{attempted,accepted,fundingRequired};}
 /** External sales batches commit atomically on NUERS; a failed response preserves every source event. */
 async replayBatch(send:(events:BufferedEvent[])=>Promise<{status:number;body:unknown}>,limit=50,branch?:string){const due=await this.due(Math.min(50,limit),'NUERS-ingestion-1',branch);if(!due.length)return{attempted:0,accepted:0,fundingRequired:false};try{const r=await send(due.map(x=>x.event));const body=r.body as {atomic?:boolean;results?:unknown[]};const valid=[200,201].includes(r.status)&&body.atomic===true&&body.results?.length===due.length&&due.every((x,i)=>acceptedResponse(x.event,body.results?.[i]));const status=[200,201].includes(r.status)&&!valid?502:r.status;for(const{envelope}of due)await this.record(envelope,status,r.body);return{attempted:due.length,accepted:valid?due.length:0,fundingRequired:r.status===402}}catch{for(const{envelope:e}of due){e.attempts++;e.availableAt=Date.now()+5000;await this.save(e)}return{attempted:due.length,accepted:0,fundingRequired:false}}}
 close(){this.db.close();}
}
export function retryDisposition(status:number):Envelope['state']{if([200,201].includes(status))return'accepted';if(status===402)return'funding_required';if(status>=400&&status<500&&![401,403,408,429].includes(status))return'review';return'pending';}

/** A 2xx proxy/login page is not proof of durable acceptance. */
export function acceptedResponse(event:BufferedEvent,body:unknown):boolean{
 if(!body||typeof body!=='object')return false;
 const b=body as Record<string,unknown>;
 if(event.schema_version==='NUERS-portal-invoice-1'){
  const invoice=b.invoice as Record<string,unknown>|undefined;
  return b.request_id===event.source_event_id&&typeof invoice?.id==='string'&&['draft','issued'].includes(String(invoice.status));
 }
 return b.source_event_id===event.source_event_id&&b.external_reference===event.external_reference&&typeof b.receipt_id==='string'&&b.receipt_id.trim().length>0&&typeof b.id==='string'&&b.id.trim().length>0&&b.status==='durably_recorded'&&b.nuers_status==='success';
}
export function assertNoCredentials(value:unknown):void{
 if(!value||typeof value!=='object')return;
 for(const[key,item]of Object.entries(value)){
  if(/^(authorization|password|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|private[_-]?key|secret)$/i.test(key))throw Error('Remove credentials from source payloads. Supply the API key in memory only.');
  assertNoCredentials(item);
 }
}
