import { AdminFinancialSummary, type FinancialSummary } from '@/components/admin/financial-summary';
import { formatTin } from "@/lib/tin";
import { useEffect, useRef, useState, type ElementType } from "react";
import { Link, useSearchParams } from "react-router-dom";
import { Bar, CartesianGrid, Cell, ComposedChart, Line, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { AlertTriangle, ArrowDown, ArrowRight, ArrowUp, CheckCircle2, ChevronDown, ChevronLeft, ChevronRight, Clock3, Columns3, Download, FileText, Maximize2, Minimize2, Pause, Play, RefreshCw, ShieldCheck, Users, XCircle, Zap } from "lucide-react";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import "./monitoring.css";

type Totals = { total: number; successful: number; failed: number; pending: number; other: number };
type Transaction = { id: string; reference: string | null; created_at: string; receipt_number: string | null; tin: string | null; business_name: string | null; type: string; amount: number; status: string; state: string; transmission_id: string | null; bir_status: string; test: boolean; bir_reference: string | null; response_ms: number | null; response_code: string | null; response_message: string | null; due_at: string | null; recovery_due_at: string | null; payload_hash: string | null };
type MonitorData = { financial_summary: FinancialSummary | null; generated_at: string; today: Totals; yesterday: Totals; tps: number; tps_count: number; queue: { production: number; certification: number; overdue: number }; flow: { time: string; successful: number; failed: number; pending: number; other: number }[]; top_taxpayers: { merchant_id: string; business_name: string | null; total: number }[]; transactions: Transaction[]; pagination: { page: number; pages: number; per_page: number; total: number }; activities: { id: string; title: string; detail: string | null; at: string; kind: string }[]; production: { ready: boolean; blockers: string[]; environment: string }; health: { application: string; bir_gateway: string }; solution: { name: string; version: string } };
const fmt = new Intl.NumberFormat("en-PH");
const currency = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" });
const titleCase = (value: string) => value.replaceAll("_", " ").replace(/\b\w/g, letter => letter.toUpperCase());
const datetime = (value: string, options?: Intl.DateTimeFormatOptions) => new Date(value).toLocaleString("en-PH", { timeZone: "Asia/Manila", ...(options || { month: "short", day: "numeric", year: "numeric", hour: "2-digit", minute: "2-digit", second: "2-digit", hour12: false }) });
const colors: Record<string, string> = { successful: "#007bff", failed: "#f01337", pending: "#ffb900", other: "#98aecb" };
const columns = [
  { key: "date", label: "Date & Time" }, { key: "receipt", label: "Receipt No." }, { key: "tin", label: "TIN" }, { key: "business", label: "Business Name" },
  { key: "type", label: "Transaction Type" }, { key: "amount", label: "Amount" }, { key: "status", label: "Status" }, { key: "bir", label: "BIR Status" }, { key: "latency", label: "Response Time" },
];

export function TransmissionMonitoringCenter() {
  const [params, setParams] = useSearchParams();
  const search = params.get("search") || "";
  const [data, setData] = useState<MonitorData | null>(null);
  const [paused, setPaused] = useState(false);
  const [connected, setConnected] = useState(false);
  const [error, setError] = useState("");
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [status, setStatus] = useState("all");
  const [revision, setRevision] = useState(0);
  const [selectedColumns, setSelectedColumns] = useState(columns.map(column => column.key));
  const [expanded, setExpanded] = useState(false);
  const [selected, setSelected] = useState<Transaction | null>(null);
  const [downloading, setDownloading] = useState(false);
  const [clock, setClock] = useState(new Date());
  const searchScope = useRef(search);
  const lastQuery = useRef("");
  useEffect(() => { const timer = setInterval(() => setClock(new Date()), 1000); return () => clearInterval(timer); }, []);
  useEffect(() => { setPage(1); }, [search, status, perPage]);
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => { if (event.key === "Escape") setExpanded(false); };
    window.addEventListener("keydown", onKey); return () => window.removeEventListener("keydown", onKey);
  }, []);
  useEffect(() => {
    const controller = new AbortController();
    let busy = false;
    if (searchScope.current !== search) { setData(null); searchScope.current = search; }
    const signal = (live: boolean, payload?: MonitorData) => window.dispatchEvent(new CustomEvent("nuers:monitor-meta", { detail: { live, status: live ? "live" : paused ? "paused" : "offline", generatedAt: payload?.generated_at || null, gateway: payload?.health.bir_gateway || "not_connected", version: payload?.solution.version || "" } }));
    async function load() {
      if (busy) return;
      busy = true;
      try {
        const query = new URLSearchParams({ search, status, page: String(page), per_page: String(perPage) });
        const response = await apiFetch(`/api/live-monitoring?${query}`, { signal: controller.signal, cache: "no-store" });
        const payload = await readJsonResponse<MonitorData>(response, "Unable to refresh the transaction monitor.");
        if (!controller.signal.aborted) { setData(payload); setConnected(true); setError(""); signal(!paused, payload); }
      } catch (err) { if (!controller.signal.aborted) { setError(err instanceof Error ? err.message : "Connection interrupted."); setConnected(false); signal(false); } }
      finally { busy = false; }
    }
    const queryKey = JSON.stringify([search, page, perPage, status, revision]);
    const queryChanged = queryKey !== lastQuery.current;
    lastQuery.current = queryKey;
    if (!paused || queryChanged) void load();
    if (paused) signal(false);
    const timer = !paused ? setInterval(load, 5000) : undefined;
    return () => { controller.abort(); if (timer) clearInterval(timer); };
  }, [search, page, perPage, status, paused, revision]);
  useEffect(() => () => { window.dispatchEvent(new CustomEvent("nuers:monitor-meta", { detail: { live: false, status: "offline", generatedAt: null, gateway: "not_connected", version: "" } })); }, []);

  async function download(format: "csv" | "json") {
    setDownloading(true);
    try {
      const query = new URLSearchParams({ format, ...(format === "csv" ? { search, status } : {}) });
      const response = await apiFetch(`/api/live-monitoring/export?${query}`);
      if (!response.ok) { await readJsonResponse(response, "Export failed."); return; }
      const blob = await response.blob(); const url = URL.createObjectURL(blob); const link = document.createElement("a");
      link.href = url; link.download = `nuers-${format === "json" ? "audit-extract" : "transaction-stream"}-${new Date().toISOString().slice(0, 10)}.${format}`; link.click(); URL.revokeObjectURL(url);
    } catch (err) { setError(err instanceof Error ? err.message : "Export failed."); }
    finally { setDownloading(false); }
  }
  const today = data?.today;
  const total = today?.total || 0;
  const distribution = ["successful", "failed", "pending", "other"].map(key => ({ name: titleCase(key), key, value: today?.[key as keyof Totals] || 0 })).filter(row => row.key !== "other" || row.value > 0);
  const metrics: { label: string; value: string; icon: ElementType; color: string; note: React.ReactNode }[] = [
    { label: "Total Transactions (Today)", value: today ? fmt.format(total) : "—", icon: FileText, color: "blue", note: <Change current={today?.total} previous={data?.yesterday.total} /> },
    { label: "Successful Transactions", value: today ? fmt.format(today.successful) : "—", icon: CheckCircle2, color: "green", note: total ? `${(today!.successful / total * 100).toFixed(1)}% of today's transactions` : "No transactions today" },
    { label: "Failed Transactions", value: today ? fmt.format(today.failed) : "—", icon: XCircle, color: "red", note: total ? `${(today!.failed / total * 100).toFixed(1)}% of today's transactions` : "No failures recorded today" },
    { label: "Pending Transmission", value: data ? fmt.format(data.queue.production) : "—", icon: Clock3, color: "yellow", note: data ? `${data.queue.certification} test queued · ${data.queue.overdue} overdue` : "Production queue" },
    { label: "Current TPS", value: data ? fmt.format(data.tps) : "—", icon: Zap, color: "purple", note: "transactions/second · last 60s" },
  ];
  const visible = columns.filter(column => selectedColumns.includes(column.key));
  return <div className="ne-monitor">
    <section className="lm-heading"><div className="lm-heading-art" /><div className="lm-breadcrumb"><Link to="/super-admin">Dashboard</Link><ChevronRight size={13} /><strong>Live Transaction Monitoring</strong></div><div className="lm-clock"><span>{clock.toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric", timeZone: "Asia/Manila" })}</span><strong>{clock.toLocaleTimeString("en-PH", { timeZone: "Asia/Manila", hour12: true })}</strong></div><h1>Live Transaction Monitoring</h1><p>Real-time tracking of electronic receipts and transactions across all connected merchants.</p><div className="lm-motto">DIGITAL TRANSACTIONS.<br />A STRONGER PHILIPPINES.</div></section>
    {error && <div className="ne-error" role="alert"><span>{error} {data ? "Last loaded records remain visible." : ""}</span><button onClick={() => { setPaused(false); setRevision(value => value + 1); }}><RefreshCw size={14} />Retry</button></div>}
    <section className="lm-metrics" aria-label="Live monitoring totals">{metrics.map(metric => <article key={metric.label} className="lm-metric"><span className={`lm-metric-icon ne-${metric.color}`}><metric.icon size={29} strokeWidth={1.8} /></span><div><h2>{metric.label}</h2><strong>{metric.value}</strong><div className="lm-metric-note">{metric.note}</div></div></article>)}</section>
    <AdminFinancialSummary summary={data?.financial_summary} />
    <div className="lm-chart-grid"><section className="ne-panel lm-flow"><div className="ne-panel-title"><h2>Transaction Flow (Last 60 Minutes)</h2><div className="lm-legend">{["successful", "failed", "pending"].map(key => <span key={key}><i style={{ background: colors[key] }} />{titleCase(key)}</span>)}</div></div><div className="lm-flow-chart"><ResponsiveContainer width="100%" height="100%"><ComposedChart data={data?.flow || []} margin={{ top: 12, right: 5, bottom: 2, left: -15 }}><defs><linearGradient id="lm-flow-blue" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#007bff" /><stop offset="100%" stopColor="#48b6ff" /></linearGradient></defs><CartesianGrid stroke="#e6edf7" /><XAxis dataKey="time" tick={{ fontSize: 12, fill: "#4d6791" }} minTickGap={32} tickLine={false} axisLine={false} /><YAxis allowDecimals={false} tick={{ fontSize: 12, fill: "#4d6791" }} tickLine={false} axisLine={false} /><Tooltip contentStyle={{ borderRadius: 8, borderColor: "#dae5f4", fontSize: 13 }} /><Bar dataKey="successful" name="Successful" fill="url(#lm-flow-blue)" maxBarSize={8} isAnimationActive={false} /><Line dataKey="failed" name="Failed" stroke={colors.failed} dot={false} strokeWidth={2} isAnimationActive={false} /><Line dataKey="pending" name="Pending" stroke={colors.pending} dot={false} strokeWidth={2} isAnimationActive={false} /></ComposedChart></ResponsiveContainer>{data && !data.flow.some(point => point.successful + point.failed + point.pending + point.other) && <p className="lm-chart-empty">No transactions in the last 60 minutes</p>}</div></section>
      <section className="ne-panel lm-distribution"><div className="ne-panel-title"><h2>Transaction Distribution</h2></div><div className="lm-donut-body"><div className="lm-donut"><ResponsiveContainer width="100%" height="100%"><PieChart><Pie data={total ? distribution : [{ key: "empty", value: 1 }]} dataKey="value" innerRadius="69%" outerRadius="92%" paddingAngle={0} stroke="none" isAnimationActive={false}>{(total ? distribution : [{ key: "empty" }]).map(item => <Cell key={item.key} fill={colors[item.key] || "#e7eef8"} />)}</Pie></PieChart></ResponsiveContainer><div><strong>{fmt.format(total)}</strong><span>Total Today</span></div></div><div className="lm-distribution-legend">{distribution.map(row => <div key={row.key}><i style={{ background: colors[row.key] }} /><div>{row.name}<strong>{fmt.format(row.value)} ({total ? (row.value / total * 100).toFixed(1) : "0.0"}%)</strong></div></div>)}</div></div></section>
      <section className="ne-panel lm-top"><div className="ne-panel-title"><h2>Top Merchants (Today)</h2><Link to="/super-admin/merchants">View All <ArrowRight size={13} /></Link></div>{data?.top_taxpayers.length ? <ol>{data.top_taxpayers.map((row, index) => <li key={row.merchant_id || index}><span>{index + 1}</span><Link to={`/monitoring?search=${encodeURIComponent(row.business_name || "")}`}>{row.business_name || "Unassigned taxpayer"}</Link><strong>{fmt.format(row.total)}</strong></li>)}</ol> : <div className="lm-empty"><Users size={27} /><p>No taxpayer transactions today</p></div>}</section></div>
    <div className="lm-stream-grid"><section className={`ne-panel lm-stream ${expanded ? "lm-expanded" : ""}`}><div className="ne-panel-title"><div className="lm-stream-title"><h2>Live Transaction Stream</h2><span className={connected && !paused ? "lm-auto" : "lm-paused"}><i />{paused ? "Paused" : connected ? "Auto-refreshing…" : "Connecting…"}</span></div><div className="lm-stream-controls"><button onClick={() => setPaused(!paused)} aria-label={paused ? "Resume live refresh" : "Pause live refresh"} title={paused ? "Resume" : "Pause"}>{paused ? <Play size={16} /> : <Pause size={16} />}</button><details><summary><Columns3 size={14} />Columns<ChevronDown size={13} /></summary><div className="lm-menu">{columns.map(column => <label key={column.key}><input type="checkbox" checked={selectedColumns.includes(column.key)} disabled={selectedColumns.length === 1 && selectedColumns.includes(column.key)} onChange={() => setSelectedColumns(current => current.includes(column.key) ? current.filter(key => key !== column.key) : [...current, column.key])} />{column.label}</label>)}</div></details><details><summary><Download size={14} />Export<ChevronDown size={13} /></summary><div className="lm-menu"><button disabled={downloading} onClick={() => download("csv")}>Filtered stream (CSV)</button><button disabled={downloading} onClick={() => download("json")}>Complete audit extract (JSON)</button></div></details><button onClick={() => setExpanded(!expanded)} aria-label={expanded ? "Restore stream size" : "Expand transaction stream"}>{expanded ? <Minimize2 size={16} /> : <Maximize2 size={16} />}</button></div></div>
        <div className="lm-filter-row"><span>Latest recorded transactions{search ? ` matching “${search}”` : ""}</span><label><span className="sr-only">Filter transaction status</span><select value={status} onChange={event => setStatus(event.target.value)}><option value="all">All statuses</option><option value="successful">Successful</option><option value="failed">Failed</option><option value="pending">Pending</option></select></label>{search && <button onClick={() => { setParams({}); setPage(1); }}>Clear search</button>}</div>
        <div className="lm-table-wrap"><table><thead><tr><th>#</th>{visible.map(column => <th key={column.key}>{column.label}</th>)}</tr></thead><tbody>{data?.transactions.map((row, index) => <tr key={row.id}><td>{((data?.pagination.page || 1) - 1) * (data?.pagination.per_page || perPage) + index + 1}</td>{visible.map(column => <td key={column.key} title={column.key === "business" ? row.business_name || "" : undefined}>{column.key === "date" ? datetime(row.created_at) : column.key === "receipt" ? <button className="lm-receipt-link" onClick={() => setSelected(row)}>{row.receipt_number || row.reference || row.id.slice(0, 8)}</button> : column.key === "tin" ? formatTin(row.tin) || "—" : column.key === "business" ? row.business_name || "—" : column.key === "type" ? titleCase(row.type) : column.key === "amount" ? currency.format(row.amount) : column.key === "status" ? <span className={`lm-badge lm-${row.state}`}>{titleCase(row.status)}</span> : column.key === "bir" ? <span className={`lm-badge ${row.test ? "lm-test" : row.bir_status === "acknowledged" ? "lm-successful" : ["rejected", "failed"].includes(row.bir_status) ? "lm-failed" : "lm-other"}`}>{row.test ? "Test: " : ""}{titleCase(row.bir_status)}</span> : row.response_ms === null ? "—" : `${(row.response_ms / 1000).toFixed(2)}s`}</td>)}</tr>)}</tbody></table>{!data?.transactions.length && <div className="lm-table-empty">{data ? search ? "No transactions match your search." : "No transaction records available." : "Loading transaction records…"}</div>}</div><div data-slot="table-pagination" className="lm-pagination"><span>{fmt.format(data?.pagination.total || 0)} {data?.pagination.total === 1 ? "record" : "records"}</span><div><label>Rows <select value={perPage} onChange={event => setPerPage(Number(event.target.value))}><option>10</option><option>25</option><option>50</option></select></label><button onClick={() => setPage(value => Math.max(1, value - 1))} disabled={page <= 1} aria-label="Previous page"><ChevronLeft size={16} /></button><span>{page} / {data?.pagination.pages || 1}</span><button onClick={() => setPage(value => value + 1)} disabled={!data || page >= data.pagination.pages} aria-label="Next page"><ChevronRight size={16} /></button></div></div></section>
      <aside className="ne-panel lm-activities"><div className="ne-panel-title"><h2>Recent Activities</h2><Link to="/super-admin/audit">View All <ArrowRight size={13} /></Link></div>{data?.activities.length ? <ol>{data.activities.map(event => { const Icon = event.kind === "danger" ? AlertTriangle : event.kind === "test" ? ShieldCheck : Users; return <li key={event.id}><span className={`lm-activity-icon lm-${event.kind}`}><Icon size={18} /></span><div><strong>{titleCase(event.title)}</strong><p>{event.detail || "Recorded system event"}</p><time dateTime={event.at}>{datetime(event.at, { month: "short", day: "numeric", hour: "2-digit", minute: "2-digit", hour12: false })}</time></div></li>; })}</ol> : <p className="lm-empty">No recorded activities yet</p>}<Link className="lm-compliance-link" to="/accreditation"><ShieldCheck size={17} />Accreditation & compliance <ArrowRight size={13} /></Link>{data && !data.production.ready && <div className="lm-gate"><AlertTriangle size={17} /><div><strong>Production authorization pending</strong><p>Review accreditation, taxpayer permits and transmission readiness.</p></div></div>}</aside></div>
    <Dialog open={Boolean(selected)} onOpenChange={open => { if (!open) setSelected(null); }}><DialogContent className="lm-detail-dialog"><DialogHeader><DialogTitle>Transaction trace</DialogTitle></DialogHeader>{selected && <div className="lm-detail"><dl>{[["Receipt / reference", selected.receipt_number || selected.reference || selected.id], ["Business", selected.business_name], ["Recorded at", datetime(selected.created_at)], ["Amount", currency.format(selected.amount)], ["Transaction status", titleCase(selected.status)], ["BIR status", `${selected.test ? "Synthetic certification: " : ""}${titleCase(selected.bir_status)}`], ["BIR reference", selected.bir_reference], ["Response", selected.response_message], ["Original transmission due", selected.due_at ? datetime(selected.due_at) : null], ["Restoration recovery due", selected.recovery_due_at ? datetime(selected.recovery_due_at) : null], ["SHA-256 payload hash", selected.payload_hash]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || "Not recorded"}</dd></div>)}</dl>{selected.transmission_id && <Link to="/super-admin/eis-transmissions">Open transmission center <ArrowRight size={14} /></Link>}</div>}</DialogContent></Dialog>
  </div>;
}

function Change({ current, previous }: { current?: number; previous?: number }) {
  if (current === undefined || previous === undefined || previous === 0) return <span className="lm-neutral-note">{previous === 0 ? "No prior-period baseline" : "Same time yesterday"}</span>;
  const delta = (current - previous) / previous * 100;
  const Icon = delta < 0 ? ArrowDown : ArrowUp;
  return <span className={delta < 0 ? "lm-change-down" : "lm-change-up"}><Icon size={13} />{Math.abs(delta).toFixed(1)}%<small>vs. same time yesterday</small></span>;
}
