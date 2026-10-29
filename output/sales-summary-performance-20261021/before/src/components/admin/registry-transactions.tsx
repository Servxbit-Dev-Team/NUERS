import {useState} from 'react';
import {Building2,FlaskConical,ShieldCheck} from 'lucide-react';
import {AdminTransactions} from '@/pages/operations/transactions';
import {SandboxRecords} from '@/components/merchant/environment-records';
import {formatTin} from '@/lib/tin';

export function RegistryTransactions({merchant}:{merchant:{id:string;name:string;tin:string}}){
 const [environment,setEnvironment]=useState<'live'|'sandbox'>('live');
 return <section className="br-transaction-workspace" aria-label={`Transactions for ${merchant.name}`}>
  <div className="br-transaction-context"><Building2 size={23}/><div><strong>{merchant.name}</strong><span>{formatTin(merchant.tin)} · Selected business only</span></div><nav aria-label="Business transaction environment">{(['live','sandbox'] as const).map(e=><button key={e} aria-pressed={environment===e} onClick={()=>setEnvironment(e)}>{e==='live'?<ShieldCheck size={16}/>:<FlaskConical size={16}/>} {e==='live'?'Live':'Sandbox'}</button>)}</nav></div>
  {environment==='live'?<AdminTransactions key={merchant.id} merchant={merchant}/>:<SandboxRecords key={merchant.id} admin merchantId={merchant.id}/>}
 </section>;
}
