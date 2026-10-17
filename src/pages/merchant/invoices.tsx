import { Navigate, useLocation, useSearchParams } from 'react-router-dom';
import { MerchantRecordsWorkspace } from '@/components/merchant/records-workspace';
export function MerchantInvoices() { const [params]=useSearchParams(); const mode=params.get('view')==='payments'?'transactions':'documents'; return <MerchantRecordsWorkspace key={mode} mode={mode}/>; }
/** Keep old bookmarks working without a second merchant Transactions page. */
export function MerchantLegacyTransactions() { const location=useLocation();const params=new URLSearchParams(location.search);params.set('view','payments');return <Navigate to={`/merchant/invoices?${params}`} replace/>; }
