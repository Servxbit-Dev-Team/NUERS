import { Navigate, useLocation, useSearchParams } from 'react-router-dom';
import {EnvironmentSwitch,SandboxRecords} from '@/components/merchant/environment-records';
import { MerchantRecordsWorkspace } from '@/components/merchant/records-workspace';
export function MerchantInvoices() { const [params]=useSearchParams(); const mode=params.get('view')==='payments'?'transactions':'documents'; const environment=params.get('environment')==='sandbox'?'sandbox':'live';return <><EnvironmentSwitch environment={environment}/>{environment==='sandbox'?<SandboxRecords/>:<MerchantRecordsWorkspace key={mode} mode={mode}/>}</>; }
/** Keep old bookmarks working without a second merchant Transactions page. */
export function MerchantLegacyTransactions() { const location=useLocation();const params=new URLSearchParams(location.search);params.set('view','payments');return <Navigate to={`/merchant/invoices?${params}`} replace/>; }
