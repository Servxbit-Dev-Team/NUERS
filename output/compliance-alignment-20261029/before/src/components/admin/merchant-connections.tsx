import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { Building2, ChevronLeft, ChevronRight, Link2, RefreshCw, Search } from 'lucide-react';
import { apiJson, publicAssetUrl } from '@/lib/api-url';
import { formatTin } from '@/lib/tin';
import { SearchableSelect } from '@/components/registration/searchable-select';
import './merchant-connections.css';

type Connection = { merchant_id: string; business_name: string; tin: string; logo_url: string | null; business_status: string; environment: string; active_keys: number; total_keys: number; requests: number; successful_requests: number; last_request_at: string | null; last_success_at: string | null; connection_status: string };
type Overview = { data: Connection[]; summary: { merchants: number; connections: number; connected_merchants: number; active_keys: number }; pagination: { total: number; page: number; per_page: number; last_page: number }; generated_at: string };
const labels: Record<string, string> = { connected: 'Connected', awaiting_connection: 'Awaiting first success', no_active_keys: 'No active keys', account_inactive: 'Account inactive' };
function date(value: string | null) { return value ? new Date(value.includes('T') ? value : value.replace(' ', 'T') + 'Z').toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' }) : 'Not recorded'; }

export function AdminMerchantConnections({ dashboard = false }: { dashboard?: boolean }) {
  const [data, setData] = useState<Overview | null>(null);
  const [search, setSearch] = useState(''), [applied, setApplied] = useState(''), [environment, setEnvironment] = useState('all');
  const [page, setPage] = useState(1), [size, setSize] = useState(dashboard ? '5' : '10'), [refresh, setRefresh] = useState(0);
  const [busy, setBusy] = useState(true), [error, setError] = useState('');
  useEffect(() => {
    const controller = new AbortController(); let running = false;
    async function load(quiet = false) {
      if (running || controller.signal.aborted) return; running = true; if (!quiet) setBusy(true);
      try {
        const result = await apiJson<Overview>('/api/admin/merchant-connections?' + new URLSearchParams({ search: applied, environment, page: String(page), per_page: size }), { signal: controller.signal });
        if (!controller.signal.aborted) { setData(result); setError(''); }
      } catch (e) { if (!controller.signal.aborted) setError(e instanceof Error ? e.message : 'Unable to load merchant connections.'); }
      finally { running = false; if (!controller.signal.aborted) setBusy(false); }
    }
    setData(null); void load();
    const stop = subscribeRealtimeRefresh(() => load(true));
    return () => { controller.abort(); stop(); };
  }, [applied, environment, page, size, refresh]);
  const pagination = data?.pagination;
  return <section className="amc-panel" aria-label="Merchant API connections" aria-busy={busy}>
    <header className="amc-heading"><span className="amc-icon"><Link2 size={23}/></span><div><h2>Merchant API Connections</h2><p>Merchant systems linked through API keys, with separate Live and Sandbox activity.</p></div><div className="amc-actions">{dashboard && <Link to="/super-admin/api-platform">View source profiles <ChevronRight size={14}/></Link>}<button type="button" disabled={busy} onClick={() => setRefresh(v => v + 1)} aria-label="Refresh merchant connections"><RefreshCw size={15}/>Refresh</button></div></header>
    {error && <p className="amc-error" role="alert">{error}{data ? ' Showing the last loaded records.' : ''}</p>}
    <div className="amc-summary"><div><span>Merchants with API keys</span><strong>{data?.summary.merchants ?? '—'}</strong></div><div><span>Connected merchants</span><strong>{data?.summary.connected_merchants ?? '—'}</strong></div><div><span>Active, unexpired keys</span><strong>{data?.summary.active_keys ?? '—'}</strong></div></div>
    <form className="amc-filters" onSubmit={e => { e.preventDefault(); setApplied(search.trim()); setPage(1); setRefresh(v => v + 1); }}><label><span>Search merchants</span><div className="amc-search"><Search size={16}/><input value={search} onChange={e => setSearch(e.target.value)} maxLength={160} placeholder="Business name or TIN"/></div></label><SearchableSelect label="Connection environment" required={false} value={environment} onChange={v => { setEnvironment(v); setPage(1); }} items={[{ value: 'all', label: 'Live and Sandbox' }, { value: 'live', label: 'Live' }, { value: 'sandbox', label: 'Sandbox' }]}/><button className="amc-primary" type="submit">Search</button><button type="button" onClick={() => { setSearch(''); setApplied(''); setEnvironment('all'); setPage(1); }}>Reset</button></form>
    <div className="amc-scroll"><table><thead><tr><th>Merchant / TIN</th><th>Environment</th><th>Connection status</th><th>Active keys</th><th>Successful requests</th><th>Last success (PHT)</th><th>Last request (PHT)</th></tr></thead><tbody>{data?.data.map(row => <tr key={`${row.merchant_id}-${row.environment}`}><td><div className="amc-business"><span className="amc-avatar">{row.logo_url ? <img src={publicAssetUrl(row.logo_url)} alt=""/> : <Building2 size={20}/>}</span><div><Link to={`/super-admin/merchants?search=${encodeURIComponent(row.tin)}`}>{row.business_name}</Link><small>{formatTin(row.tin)} · {row.business_status}</small></div></div></td><td><span className={`amc-badge amc-${row.environment}`}>{row.environment === 'live' ? 'Live' : 'Sandbox'}</span></td><td><span className={`amc-badge amc-${row.connection_status}`}>{labels[row.connection_status] || row.connection_status}</span></td><td>{row.active_keys}<small>of {row.total_keys} keys</small></td><td>{row.successful_requests.toLocaleString()}<small>of {row.requests.toLocaleString()} intake attempts</small></td><td>{date(row.last_success_at)}</td><td>{date(row.last_request_at)}</td></tr>)}{!data?.data.length && <tr><td colSpan={7} className="amc-empty">{busy ? 'Loading merchant connections…' : error ? 'Connections could not be loaded. Refresh to try again.' : 'No merchant API keys match these filters. Merchants appear here after generating an API key.'}</td></tr>}</tbody></table></div>
    <footer className="amc-pagination"><span>{pagination ? `Showing ${pagination.total ? (pagination.page - 1) * pagination.per_page + 1 : 0}–${Math.min(pagination.page * pagination.per_page, pagination.total)} of ${pagination.total} merchant environments` : 'Loading connections…'}</span><div><button aria-label="Previous connection page" disabled={busy || !pagination || pagination.page <= 1} onClick={() => setPage((pagination?.page || 1) - 1)}><ChevronLeft size={16}/></button><span>Page {pagination?.page || 1} of {pagination?.last_page || 1}</span><button aria-label="Next connection page" disabled={busy || !pagination || pagination.page >= pagination.last_page} onClick={() => setPage((pagination?.page || 1) + 1)}><ChevronRight size={16}/></button><label>Rows <select aria-label="Connection rows per page" value={size} onChange={e => { setSize(e.target.value); setPage(1); }}>{['5', '10', '25', '50'].map(v => <option key={v}>{v}</option>)}</select></label></div></footer>
    <p className="amc-note">Connected means an active merchant has an unexpired key and a recorded successful API intake request in that environment. It is not a real-time online check or BIR connection status. Counts follow the selected filters. {data && <span>Updated {date(data.generated_at)} PHT.</span>}</p>
  </section>;
}
