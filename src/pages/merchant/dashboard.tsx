import { useCallback, useEffect, useState, type ElementType } from "react";
import { Link, Navigate, useSearchParams } from "react-router-dom";
import { ArrowDown, ArrowRight, ArrowUp, CalendarDays, ChartNoAxesCombined, CheckCircle2, CircleHelp, Eye, FileCheck2, FileText, List, Loader2, MoreVertical, RefreshCw, Send, ShoppingCart, TriangleAlert, Users } from "lucide-react";
import { Bar, CartesianGrid, Cell, ComposedChart, Line, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { apiJson, publicAssetUrl } from "@/lib/api-url";
import "./merchant-dashboard.css";

type Totals = { sales: number; invoices: number; transactions: number; attention: number };
type Recent = { id: string; document_id: string; source: string; number: string; customer: string; date: string; amount: number; status: string; bir_status: string };
type Overview = {
  account: { id: string; name: string; tin: string; logo: string | null; rdo: string | null; rdo_name: string | null; vat_registered: boolean; status: string; plan: string | null };
  current: Totals; previous: Totals; days: number; today: string; sales: { date: string; label: string; sales: number; documents: number }[];
  documents: Record<string, number>; bir: Record<string, number>; recent: Recent[]; draft_count: number;
  alerts: { id: string; title: string; message: string; href: string }[]; updated_at: string; notice: string;
};
const money = (value: number) => new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", minimumFractionDigits: 2 }).format(value);
const number = (value: number) => new Intl.NumberFormat("en-PH").format(value);
const labels: Record<string, string> = { acknowledged: "Acknowledged", queued: "Queued locally", needs_attention: "Needs attention", not_verified: "Not verified", issued: "Issued", for_review: "For review", cancelled: "Cancelled", pending: "Pending" };
const documentColors = ["#00ae72", "#ffbc10", "#ff243d", "#94a7c6"];
const birColors = ["#00ae72", "#ffbc10", "#ff243d", "#94a7c6"];
function documentLink(row: Recent) { return row.source === "invoice" ? `/merchant/invoices/${encodeURIComponent(row.document_id)}` : `/merchant/invoices?search=${encodeURIComponent(row.number || row.id)}`; }
function Difference({ current, previous, lowerIsBetter = false }: { current: number; previous: number; lowerIsBetter?: boolean }) {
  if (!previous) return <small className="md-comparison">{current ? "No prior-day baseline" : "No activity yesterday"}</small>;
  const percent = (current - previous) / previous * 100;
  const Icon = percent < 0 ? ArrowDown : ArrowUp;
  return <small className="md-comparison"><b className={(lowerIsBetter ? percent <= 0 : percent >= 0) ? "md-positive" : "md-negative"}><Icon size={13} />{Math.abs(percent).toFixed(1)}%</b><span>vs. yesterday</span></small>;
}
function Ring({ values, colors, center, caption, empty }: { values: Record<string, number>; colors: string[]; center: string; caption: string; empty: string }) {
  const total = Object.values(values).reduce((a, b) => a + b, 0);
  const rows = Object.entries(values).map(([key, value], index) => ({ key, value, fill: colors[index] }));
  return <div className="md-ring-body"><div className="md-ring" role="img" aria-label={total ? rows.map(r => `${labels[r.key]}: ${r.value}`).join(", ") : empty}>
    <ResponsiveContainer width="100%" height="100%"><PieChart><Pie data={total ? rows : [{ key: "empty", value: 1, fill: "#e9eff8" }]} dataKey="value" innerRadius="74%" outerRadius="97%" startAngle={90} endAngle={-270} stroke="none" isAnimationActive={false}>{(total ? rows : [{ key: "empty", fill: "#e9eff8" }]).map(r => <Cell key={r.key} fill={r.fill} />)}</Pie></PieChart></ResponsiveContainer>
    <div className="md-ring-label"><strong>{total ? center : "—"}</strong><small>{total ? caption : empty}</small></div></div>
    <ul className="md-ring-legend">{rows.map(r => <li key={r.key}><i style={{ background: r.fill }} /><span>{labels[r.key]}</span><b>{number(r.value)}</b></li>)}</ul></div>;
}
function Stat({ title, icon: Icon, color, children, footer }: { title: string; icon: ElementType; color: string; children: React.ReactNode; footer: React.ReactNode }) {
  return <section className="md-stat"><span className={`md-stat-icon md-${color}`}><Icon size={28} strokeWidth={1.7} /></span><div><h2>{title}</h2><strong>{children}</strong>{footer}</div></section>;
}
export function MerchantDashboard() {
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState("");
  const [days, setDays] = useState(7);
  const [loading, setLoading] = useState(true);
  const [clock, setClock] = useState(new Date());
  const [params, setParams] = useSearchParams();
  const [customerSearch, setCustomerSearch] = useState("");
  const panel = params.get("panel");
  useEffect(() => { if (panel === "support") { window.dispatchEvent(new Event("nuers:open-support")); const next = new URLSearchParams(params); next.delete("panel"); setParams(next, { replace: true }); } }, [panel, params, setParams]);
  const load = useCallback(async (signal?: AbortSignal) => {
    setLoading(true);
    try {
      const value = await apiJson<Overview>(`/api/merchant/overview?days=${days}`, { signal });
      setData(value); setError("");
      window.dispatchEvent(new CustomEvent("nuers:enterprise-notifications", { detail: value.alerts.length }));
      window.dispatchEvent(new CustomEvent("nuers:merchant-connection", { detail: { connected: true, updatedAt: value.updated_at } }));
    } catch (e) {
      if (signal?.aborted) return;
      setError(e instanceof Error ? e.message : "Unable to load merchant records.");
      window.dispatchEvent(new CustomEvent("nuers:merchant-connection", { detail: { connected: false, updatedAt: "" } }));
    } finally { if (!signal?.aborted) setLoading(false); }
  }, [days]);
  useEffect(() => { const controller = new AbortController(); void load(controller.signal); const timer = setInterval(() => { if (!document.hidden) void load(controller.signal); }, 60000); return () => { controller.abort(); clearInterval(timer); }; }, [load]);
  useEffect(() => { const timer = setInterval(() => setClock(new Date()), 1000); return () => clearInterval(timer); }, []);
  const hour = Number(new Intl.DateTimeFormat("en", { timeZone: "Asia/Manila", hour: "numeric", hourCycle: "h23" }).format(clock));
  const reportedDays = data?.days ?? days;
  const totalDocs = data ? Object.values(data.documents).reduce((a, b) => a + b, 0) : 0;
  const totalBir = data ? Object.values(data.bir).reduce((a, b) => a + b, 0) : 0;
  const ackRate = totalBir && data ? (data.bir.acknowledged / totalBir * 100).toFixed(1) : null;
  const customers = Array.from(new Set((data?.recent || []).map(row => row.customer))).filter(name => name.toLowerCase().includes(customerSearch.toLowerCase()));
  function closePanel() { const next = new URLSearchParams(params); next.delete("panel"); next.delete("document"); setParams(next); }
  const selected = data?.recent.find(row => row.id === params.get("document"));
  const quick = [
    { label: "Create Invoice", href: "/merchant/invoices/create", icon: FileText, color: "blue" },
    { label: "Invoices & Receipts", href: "/merchant/invoices", icon: FileCheck2, color: "green" },
    { label: "Payment Activity", href: "/merchant/invoices?view=payments", icon: List, color: "orange" },
    { label: "Sales Reports", href: "/merchant/sales-reports", icon: ChartNoAxesCombined, color: "purple" },
    { label: "BIR Submission Status", href: "/merchant?panel=bir", icon: Send, color: "cyan" },
    { label: "View Customers", href: "/merchant/customers", icon: Users, color: "pink" },
  ];
  if (panel === "customers") return <Navigate to="/merchant/customers" replace />;
  return <div className="merchant-overview">
    <section className="md-hero"><div className="md-welcome"><p>Good {hour < 12 ? "morning" : hour < 18 ? "afternoon" : "evening"},</p><h1>{data?.account.name || "Your business"}</h1><h2>Welcome to NUERS Merchant Portal</h2><span>Manage your electronic invoices, monitor transactions, and prepare for compliance.</span></div><p className="md-motto">Digital Transactions.<br />A Stronger Philippines.</p><div className="md-clock"><CalendarDays size={39} /><div><span>{clock.toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric", timeZone: "Asia/Manila" })}</span><strong>{clock.toLocaleTimeString("en-PH", { hour12: true, timeZone: "Asia/Manila" })}</strong><small className={error ? "md-negative" : "md-positive"}><i />{error ? "Refresh unavailable" : data ? "NUERS responding" : "Connecting…"}</small></div></div></section>
    {error && <div className="md-error" role="alert"><TriangleAlert size={17} /><span>{error} {data && "Showing the last successful response."}</span><button onClick={() => void load()}>Retry</button></div>}
    {!data && loading ? <div className="md-loading" role="status"><Loader2 className="ne-spinning" size={24} />Loading your merchant dashboard…</div> : data && <>
    {data.account.status==='pending'&&<div className="md-error" role="status"><TriangleAlert size={20}/><span><strong>Account verification pending.</strong> Upload the required business documents and wait for NUERS administrator review before activation.</span><Link to="/merchant/profile?tab=documents">Upload documents →</Link></div>}
    <div className="md-stats">
      <Stat title="Total Sales Today" icon={FileText} color="blue" footer={<Difference current={data.current.sales} previous={data.previous.sales} />}>{money(data.current.sales)}</Stat>
      <Stat title="Invoices Issued Today" icon={FileCheck2} color="green" footer={<Difference current={data.current.invoices} previous={data.previous.invoices} />}>{number(data.current.invoices)}</Stat>
      <Stat title="Transactions Today" icon={ShoppingCart} color="yellow" footer={<Difference current={data.current.transactions} previous={data.previous.transactions} />}>{number(data.current.transactions)}</Stat>
      <Stat title="Failed / Pending Today" icon={TriangleAlert} color="red" footer={<Difference current={data.current.attention} previous={data.previous.attention} lowerIsBetter />}>{number(data.current.attention)}</Stat>
      <Stat title="BIR Acknowledgments" icon={Send} color="purple" footer={<small className="md-comparison">{ackRate === null ? "No documents in period" : `${ackRate}% verified · ${reportedDays} days`}</small>}>{number(data.bir.acknowledged)}</Stat>
    </div>
    <div className="md-chart-grid">
      <section className="md-panel md-sales"><header><h2>Sales Overview</h2><select aria-label="Sales overview period" value={days} onChange={e => setDays(Number(e.target.value))}><option value={7}>Last 7 Days</option><option value={30}>Last 30 Days</option></select></header><div className="md-sales-chart" role="img" aria-label={`Daily reconciled sales and document counts for the last ${reportedDays} days`}><ResponsiveContainer width="100%" height="100%"><ComposedChart data={data.sales} margin={{ top: 12, right: 6, left: 0, bottom: 0 }}><defs><linearGradient id="merchant-sales" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#1688ff" stopOpacity={0.85} /><stop offset="100%" stopColor="#1381fa" stopOpacity={0.55} /></linearGradient></defs><CartesianGrid stroke="#e7effa" /><XAxis dataKey="label" axisLine={{ stroke: "#bed1ef" }} tickLine={false} tick={{ fontSize: 13, fill: "#46609a" }} minTickGap={18} /><YAxis yAxisId="sales" width={78} tickFormatter={n => `₱ ${n >= 1000 ? `${n / 1000}k` : n}`} axisLine={false} tickLine={false} tick={{ fontSize: 13, fill: "#46609a" }} /><YAxis yAxisId="count" orientation="right" allowDecimals={false} hide /><Tooltip formatter={(value, name) => name === "Sales amount" ? money(Number(value)) : number(Number(value))} contentStyle={{ borderRadius: 8, border: "1px solid #dce8fa", fontSize: 14 }} /><Bar yAxisId="sales" dataKey="sales" name="Sales amount" fill="url(#merchant-sales)" maxBarSize={36} isAnimationActive={false} /><Line yAxisId="count" dataKey="documents" name="Documents" stroke="#ff873d" strokeWidth={2} dot={{ r: reportedDays === 7 ? 3 : 1.5, fill: "#ff873d" }} isAnimationActive={false} /></ComposedChart></ResponsiveContainer></div><div className="md-chart-legend"><span><i className="md-square" />Sales amount</span><span><i className="md-line" />Documents · separate scale</span></div></section>
      <section className="md-panel"><header><h2>Document Status</h2><span className="md-period">{reportedDays} days</span></header><Ring values={data.documents} colors={documentColors} center={number(totalDocs)} caption="Source documents" empty="No documents" /><p className="md-panel-note">Internal document review; BIR status is separate.</p></section>
      <section className="md-panel"><header><h2>BIR Transmission Status</h2><Link to="?panel=bir">View Details <ArrowRight size={13} /></Link></header><Ring values={data.bir} colors={birColors} center={ackRate === null ? "—" : `${ackRate}%`} caption="Verified acknowledgment" empty="No submissions" /><p className="md-panel-note">Official connection and response verification pending.</p></section>
    </div>
    <div className="md-bottom-grid"><section className="md-panel md-recent"><header><h2>Recent Documents</h2><Link to="/merchant/invoices">View All <ArrowRight size={13} /></Link></header><div className="md-table-scroll"><table><thead><tr><th>#</th><th>Invoice / Receipt No.</th><th>Business Date</th><th>Customer</th><th>Amount</th><th>Status</th><th>BIR Status</th><th>Actions</th></tr></thead><tbody>{data.recent.map((row, i) => <tr key={row.id}><td>{i + 1}</td><td><Link to={documentLink(row)}>{row.number || "Unlinked record"}</Link></td><td>{new Date(`${row.date}T00:00:00`).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })}</td><td title={row.customer}>{row.customer}</td><td>{money(row.amount)}</td><td><span className={`md-badge ${["issued", "paid", "sent", "validated", "completed"].includes(row.status) ? "md-badge-good" : "md-badge-neutral"}`}><CheckCircle2 size={10} />{row.status === "sent" ? "Issued (legacy)" : row.status.replaceAll("_", " ")}</span></td><td><span className={`md-badge ${row.bir_status === "acknowledged" ? "md-badge-good" : row.bir_status === "needs_attention" ? "md-badge-bad" : "md-badge-neutral"}`}>{labels[row.bir_status]}</span></td><td><div className="md-row-actions"><Link to={documentLink(row)} aria-label={`View ${row.number}`}><Eye size={15} /></Link><button aria-label={`Details for ${row.number}`} onClick={() => setParams({ panel: "document", document: row.id })}><MoreVertical size={16} /></button></div></td></tr>)}</tbody></table>{!data.recent.length && <div className="md-empty"><FileText size={28} /><strong>No issued documents yet</strong><span>Start with a draft invoice. Issuance requires verified authority and an approved series.</span><Link to="/merchant/invoices/create">Create a draft <ArrowRight size={14} /></Link></div>}</div><p className="md-panel-note">Original business dates shown; payments are not counted again as invoice sales.</p></section>
      <div className="md-right-stack"><section className="md-panel md-quick"><header><h2>Quick Actions</h2><button className="md-refresh" aria-label="Refresh merchant dashboard" disabled={loading} onClick={() => void load()}><RefreshCw size={14} className={loading ? "ne-spinning" : ""} /></button></header><div>{quick.map(({ label, href, icon: Icon, color }) => <Link key={label} to={href}><span className={`md-${color}`}><Icon size={25} strokeWidth={1.7} /></span>{label}</Link>)}</div></section>
      <section className="md-panel md-account-info"><header><h2>Account Information</h2><Link to="/merchant/profile">Edit Profile <ArrowRight size={13} /></Link></header><div className="md-business"><span>{data.account.logo ? <img src={publicAssetUrl(data.account.logo)} alt="" /> : data.account.name.split(/\s+/).map(s => s[0]).slice(0, 3).join("")}</span><div><strong>{data.account.name}</strong><p>TIN: {data.account.tin || "Not recorded"}</p><p>RDO: {data.account.rdo ? `${data.account.rdo}${data.account.rdo_name ? ` · ${data.account.rdo_name}` : ""}` : "Not recorded"}</p></div></div><dl><dt>Business Type:</dt><dd>{data.account.vat_registered ? "VAT Registered" : "Non-VAT / verify COR"}</dd><dt>Pricing:</dt><dd>Current published fee per eligible sale<Link to="/merchant/wallet">Wallet & Current Fee</Link></dd><dt>Status:</dt><dd><span className={data.account.status === "active" ? "md-badge md-badge-good" : "md-badge md-badge-neutral"}>{data.account.status}</span></dd><dt>Account ID:</dt><dd className="md-account-id" title={data.account.id}>{data.account.id}</dd></dl></section></div>
    </div>
    <div className="md-disclosure"><CircleHelp size={14} /><span title={data.notice}>Internal operational totals · BIR connection not verified.</span><button onClick={() => setParams({ panel: "help" })}>Learn more</button><span className="md-updated">Last refreshed {new Date(data.updated_at).toLocaleTimeString("en-PH", { timeZone: "Asia/Manila" })} PHT · Refreshes every minute</span></div>
    </>}
    <Dialog open={Boolean(panel && panel !== "support")} onOpenChange={open => { if (!open) closePanel(); }}><DialogContent className="md-dialog sm:max-w-2xl"><DialogHeader><DialogTitle>{panel === "bir" ? "BIR Submission Status" : panel === "customers" ? "Customers from recent documents" : panel === "document" ? "Original Document Details" : panel === "alerts" ? "Merchant Notices" : "Merchant Help & Documentation"}</DialogTitle><DialogDescription>{panel === "bir" ? "Local delivery state and verified acknowledgment are separate. This view cannot submit to BIR." : panel === "customers" ? "Derived from your last eight source records. This is not an independently verified customer registry." : "Recorded information for your own business account."}</DialogDescription></DialogHeader>
    {panel === "bir" ? <div className="md-dialog-content"><p>Official API access, an implemented verified adapter, taxpayer permissions and source evidence are required before live transmission.</p>{data && <ul>{Object.entries(data.bir).map(([key, count]) => <li key={key}><span>{labels[key]}</span><strong>{number(count)}</strong></li>)}</ul>}<p>These counts cover canonical documents in the selected {reportedDays}-day period. A saved invoice, QR/PDF, local queue entry or HTTP success does not establish BIR acceptance.</p><Link to="/merchant/api-keys">Review API integration <ArrowRight size={14} /></Link></div> : panel === "customers" ? <div className="md-dialog-content"><label htmlFor="md-customer-search">Search recent customers</label><input id="md-customer-search" type="search" value={customerSearch} onChange={e => setCustomerSearch(e.target.value)} placeholder="Customer name…" /><ul>{customers.map(name => <li key={name}><Users size={16} /><span>{name}</span><Link to={`/merchant/invoices?search=${encodeURIComponent(name)}`}>View records</Link></li>)}</ul>{!customers.length && <p>No matching customer names in recent documents.</p>}</div> : panel === "document" ? <div className="md-dialog-content">{selected ? <><h3>{selected.number}</h3><p>{selected.customer} · {money(selected.amount)}</p><p>Original business date: {selected.date}</p><p>Local status: {selected.status} · BIR: {labels[selected.bir_status]}</p><Link to={documentLink(selected)}>Open original document <ArrowRight size={14} /></Link></> : <p>The record is no longer in this recent list. Find it in your transaction records.</p>}</div> : panel === "alerts" ? <div className="md-dialog-content">{data?.alerts.map(a => <article key={a.id}><h3>{a.title}</h3><p>{a.message}</p><Link to={a.href}>View details <ArrowRight size={14} /></Link></article>)}{!data?.alerts.length && <p>No current notices from this dashboard. This does not establish continuous monitoring.</p>}</div> : <div className="md-dialog-content"><article><h3>1. Prepare and review</h3><p>Confirm your business identity, branch, PTI/PTT, accreditation and authorized numbering evidence with the reviewing team. Start invoices as drafts while approvals are pending.</p><Link to="/merchant/profile">Business profile</Link></article><article><h3>2. Issue and preserve originals</h3><p>Issuance validates source amounts and reserves an authorized series atomically. Issued originals are preserved; corrections use linked documents and independent review.</p><Link to="/merchant/invoices">Invoices & receipts</Link></article><article><h3>3. Connect and reconcile</h3><p>Use expiring scoped API keys, stable source event IDs and status checks. BIR acceptance needs authentic verified evidence. Sales summaries are internal drafts and do not replace tax returns.</p><Link to="/merchant/api-keys">API integration</Link></article><button className="md-support-button" onClick={() => { closePanel(); window.dispatchEvent(new Event("nuers:open-support")); }}>Open support request</button></div>}
    </DialogContent></Dialog>
  </div>;
}
