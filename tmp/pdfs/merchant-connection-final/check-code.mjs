import assert from 'node:assert/strict';
import {sendNuers} from './sender.mjs';
const event={source_event_id:'test-event',external_reference:'test-ref'};
process.env.NUERS_API_KEY='synthetic-test-key';
process.env.NUERS_ENVIRONMENT='sandbox';
const accepted={id:'intake',transaction_id:'tx',receipt_id:'receipt',environment:'sandbox',status:'durably_recorded',...event};
let checked=0;
for(const [http,payload,pass] of [[201,accepted,true],[200,{...accepted,duplicate:true},true],[200,{...accepted,duplicate:false},false],[201,{...accepted,environment:'live'},false],[201,{...accepted,source_event_id:'wrong'},false],[403,{message:'denied'},false]]){
 globalThis.fetch=async(url,opts)=>{assert.equal(opts.redirect,'error');assert.equal(opts.body,JSON.stringify(event));return new Response(JSON.stringify(payload),{status:http});};
 if(pass)assert.equal((await sendNuers(JSON.stringify(event))).id,'intake');
 else await assert.rejects(sendNuers(JSON.stringify(event)));
 checked++;
}
globalThis.fetch=async()=>new Response('<html>login</html>',{status:200});
await assert.rejects(sendNuers(JSON.stringify(event)));checked++;
globalThis.fetch=async()=>{throw new Error('Synthetic network failure');};
await assert.rejects(sendNuers(JSON.stringify(event)));checked++;
console.log(JSON.stringify({mocked_sender_cases_passed:checked,no_network_requests:true}));
