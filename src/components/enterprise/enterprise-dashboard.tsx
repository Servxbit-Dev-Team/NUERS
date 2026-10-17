import { useEffect, useState, type ElementType } from "react";
import { Link } from "react-router-dom";
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";
import { Activity, ArrowRight, Barcode, Bell, Building2, ChartColumn, CheckCircle2, CloudUpload, Database, FileCheck2, FileText, FileX2, Files, HardDrive, LockKeyhole, Network, ReceiptText, RefreshCw, Search, Server, Settings, ShieldCheck, Users, Wallet } from "lucide-react";
import { useAuth } from "@/lib/auth";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import type { EnterprisePortal } from "./enterprise-shell";
import "./enterprise.css";

type Metric = { label: string; value: number; color: string; icon: string; note: string; format?: string };
type Dashboard = { metrics: Metric[]; volume: { label: string; valid: number; invalid: number; pending: number }[]; regions: { name: string; count: number; percentage: number }[]; recent: { id: string; transaction_ref: string | null; created_at: string; amount: number; status: string; tin: string | null; business_name: string | null }[]; status: { name: string; status: string; checked_at?: string | null }[]; account_active: boolean; notification_count: number | null; updated_at: string };
const metricIcons: Record<string, ElementType> = { receipt: ReceiptText, "file-check": FileCheck2, "file-x": FileX2, users: Users, upload: CloudUpload, chart: ChartColumn, files: Files, wallet: Wallet };
const number = new Intl.NumberFormat("en-PH");
const money = new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP", maximumFractionDigits: 2 });
const periods = [{ key: "today", label: "Today" }, { key: "week", label: "This Week" }, { key: "month", label: "This Month" }, { key: "year", label: "This Year" }];

export function EnterpriseDashboard({ portal }: { portal: EnterprisePortal }) {
  const { profile } = useAuth();
  const [data, setData] = useState<Dashboard | null>(null);
  const [period, setPeriod] = useState("today");
  const [error, setError] = useState("");
  const [refresh, setRefresh] = useState(0);
  const [refreshing, setRefreshing] = useState(false);
  const [clock, setClock] = useState(new Date());
  useEffect(() => { const timer = setInterval(() => setClock(new Date()), 1000); return () => clearInterval(timer); }, []);
  useEffect(() => {
    const controller = new AbortController();
    let running = false;
    async function load() {
      if (running) return;
      running = true;
      setRefreshing(true);
      try {
        const response = await apiFetch(`/api/enterprise-dashboards/${portal}?period=${period}`, { signal: controller.signal, cache: "no-store" });
        const payload = await readJsonResponse<Dashboard>(response, "Unable to load dashboard.");
        if (!controller.signal.aborted) { setData(payload); setError(""); window.dispatchEvent(new CustomEvent("nuers:enterprise-notifications", { detail: payload.notification_count })); }
      } catch (err) { if (!controller.signal.aborted) setError(err instanceof Error ? err.message : "Unable to load dashboard."); }
      finally { running = false; if (!controller.signal.aborted) setRefreshing(false); }
    }
    // Clear the previous period immediately so its bars cannot be mistaken for the new selection.
    setData(null);
    load();
    const timer = setInterval(load, 15000);
    return () => { controller.abort(); clearInterval(timer); };
  }, [portal, period, refresh]);
  const name = portal === "admin" ? "Admin" : profile?.full_name?.split(" ")[0] || (portal === "merchant" ? "Merchant" : "Citizen");
  const root = portal === "admin" ? "/super-admin" : portal === "merchant" ? "/merchant" : "/client";
  const statusPath = portal === "admin" ? "/super-admin/system-health" : `${root}/profile`;
  const title = portal === "admin" ? "National Unified Electronic Receipt System" : portal === "merchant" ? "Your Business. Connected to a Digital Philippines." : "Your Digital Receipts. All in One Place.";
  const chartTotal = data?.volume.reduce((sum, row) => sum + row.valid + row.invalid + row.pending, 0) || 0;
  const actions: { label: string; href: string; icon: ElementType; bir?: boolean }[] = portal === "admin" ? [
    { label: "Issue Test Receipt", href: "/api/sandbox", icon: FileText }, { label: "Search Transaction", href: `${root}/transactions`, icon: Search }, { label: "Register Taxpayer", href: `${root}/citizen-approval`, icon: Building2 }, { label: "BIR Submission", href: `${root}/eis-transmissions`, icon: CloudUpload, bir: true },
    { label: "Generate Report", href: `${root}/reports`, icon: ChartColumn }, { label: "System Settings", href: "/system", icon: Settings }, { label: "User Management", href: `${root}/users-rbac`, icon: Users }, { label: "Backup Now", href: `${root}/backups`, icon: Database },
  ] : portal === "merchant" ? [
    { label: "Create Invoice", href: `${root}/invoices/create`, icon: FileText }, { label: "Search Transaction", href: `${root}/transactions`, icon: Search }, { label: "Business Profile", href: `${root}/profile`, icon: Building2 }, { label: "BIR Submission Status", href: `${root}?panel=bir`, icon: CloudUpload, bir: true },
    { label: "Sales Reports", href: `${root}/sales-reports`, icon: ChartColumn }, { label: "POS Settings", href: `${root}/pos-settings`, icon: Settings }, { label: "Manage Branches", href: `${root}/branches`, icon: Users }, { label: "Receipt Vault", href: `${root}/receipts`, icon: Database },
  ] : [
    { label: "Receipt Vault", href: `${root}/receipts`, icon: FileText }, { label: "Search Transaction", href: `${root}/transactions`, icon: Search }, { label: "My Profile", href: `${root}/profile`, icon: Users }, { label: "Verify Receipt", href: "/verify", icon: FileCheck2 },
    { label: "My Wallet", href: `${root}/wallet`, icon: Wallet }, { label: "Account Security", href: `${root}/security`, icon: LockKeyhole }, { label: "Notifications", href: `${root}/notifications`, icon: Bell }, { label: data?.account_active ? "My TIN Barcode" : "TIN Registration", href: data?.account_active ? `${root}/barcode` : `${root}/profile`, icon: Barcode },
  ];
  return <div className="ne-dashboard">
    <section className="ne-hero"><div className="ne-hero-art" aria-hidden="true"><img src="/dashboard-assets/bir-skyline-header.png" alt="" /><img className="ne-official-seal" src="/dashboard-assets/bir-official-logo.png" alt="" /></div><div className="ne-welcome"><h1>Welcome, {name}!</h1><h2>{title}</h2><p>Secure. Compliant. Real-Time. For a Better Tomorrow.</p></div><div className="ne-hero-right"><div className="ne-clock"><span>{clock.toLocaleDateString("en-PH", { weekday: "long", month: "long", day: "numeric", year: "numeric", timeZone: "Asia/Manila" })}</span><strong>{clock.toLocaleTimeString("en-PH", { hour12: true, timeZone: "Asia/Manila" })}</strong></div><p>DIGITAL<br />TRANSACTIONS.<br />A STRONGER<br />PHILIPPINES.</p></div></section>
    {error && <div className="ne-error" role="alert"><span>{error}{data ? " Showing the last loaded records." : ""}</span><button onClick={() => setRefresh(value => value + 1)}><RefreshCw size={14} />Retry</button></div>}
    <section className="ne-metrics" aria-label="Dashboard totals">{data ? data.metrics.map(metric => { const Icon = metricIcons[metric.icon] || ReceiptText; return <article className="ne-metric" key={metric.label}><span className={`ne-metric-icon ne-${metric.color}`}><Icon size={30} strokeWidth={1.7} /></span><div><h3>{metric.label}</h3><strong>{metric.format === "money" ? money.format(metric.value) : number.format(metric.value)}</strong><p>{metric.note}</p></div></article>; }) : Array.from({ length: 5 }, (_, index) => <article key={index} className="ne-metric ne-skeleton" aria-label="Loading totals"><span /><div /><div /></article>)}</section>
    <div className="ne-upper-grid">
      <section className="ne-panel ne-volume"><div className="ne-panel-title"><h2>Transaction Volume</h2><div className="ne-periods" role="group" aria-label="Transaction period">{periods.map(item => <button key={item.key} className={period === item.key ? "ne-selected" : ""} aria-pressed={period === item.key} onClick={() => setPeriod(item.key)}>{item.label}</button>)}</div></div><div className="ne-chart" aria-label={`${periods.find(item => item.key === period)?.label} transaction volume`}>
        <ResponsiveContainer width="100%" height="100%"><BarChart data={data?.volume || []} margin={{ top: 12, right: 8, bottom: 4, left: -23 }} barGap={1}><defs><linearGradient id="ne-valid-blue" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stopColor="#0077ff" /><stop offset="100%" stopColor="#34aaff" /></linearGradient></defs><CartesianGrid stroke="#e5edf7" vertical /><XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={32} tick={{ fontSize: 12, fill: "#40577f" }} /><YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 12, fill: "#40577f" }} /><Tooltip contentStyle={{ borderRadius: 9, borderColor: "#dce6f4", color: "#09234c", fontSize: 12 }} cursor={{ fill: "#f0f6fe" }} /><Bar dataKey="valid" name="Valid Transactions" fill="url(#ne-valid-blue)" maxBarSize={14} radius={[1, 1, 0, 0]} /><Bar dataKey="invalid" name="Invalid Transactions" fill="#ed1635" maxBarSize={14} radius={[1, 1, 0, 0]} /><Bar dataKey="pending" name="Pending Transactions" fill="#a6bddc" maxBarSize={14} radius={[1, 1, 0, 0]} /></BarChart></ResponsiveContainer>
        {data && !chartTotal && <div className="ne-chart-empty">No transactions in this period</div>}
      </div><div className="ne-chart-legend"><span><i className="ne-dot-blue" />Valid Transactions</span><span><i className="ne-dot-red" />Invalid Transactions</span>{data?.volume.some(row => row.pending > 0) && <span><i className="ne-dot-gray" />Pending</span>}</div></section>
      <section className="ne-panel ne-regions"><div className="ne-panel-title"><h2>Transactions by Region</h2><span className="ne-live"><i />{error ? "Paused" : "Live"}</span></div><div className="ne-region-body"><img className="ne-philippines" src="/dashboard-assets/philippines-map.png" alt="Map of the Philippines" /><div className="ne-region-list">{data?.regions.length ? data.regions.map(region => <div className="ne-region-row" key={region.name} title={`${number.format(region.count)} transactions`}><span>{region.name}</span><div><i style={{ width: `${region.percentage}%` }} /></div><strong>{region.percentage.toFixed(1)}%</strong></div>) : <p className="ne-empty">{data ? "No regional records yet" : "Loading regions…"}</p>}</div></div></section>
      <section className="ne-panel ne-status"><div className="ne-panel-title"><h2>{portal === "admin" ? "System Status" : "Account Status"}</h2><Link to={statusPath}>View Details <ArrowRight size={13} /></Link></div><div className="ne-status-list">{data?.status.map((service, index) => { const Icon = [ServerIcon, Database, ArchiveIcon, Activity, NetworkIcon, ShieldCheck, CheckCircle2][index % 7]; const good = ["online", "healthy", "operational", "ready", "good", "active", "linked", "bound", "available"].includes(service.status); return <div className="ne-status-row" key={service.name} title={service.checked_at ? `Recorded check: ${service.checked_at}` : undefined}><Icon size={17} strokeWidth={1.8} /><span>{service.name}</span><strong className={good ? "ne-status-good" : "ne-status-neutral"}><i />{service.status}</strong></div>; })}</div><small className="ne-status-note">{portal === "admin" ? "Latest recorded health checks" : "Your account’s current configuration"}</small></section>
    </div>
    <div className="ne-lower-grid"><section className="ne-panel ne-recent"><div className="ne-panel-title"><h2>Recent Transactions</h2><Link to={`${root}/transactions`}>View All <ArrowRight size={13} /></Link></div><div className="ne-table-scroll"><table><thead><tr><th>#</th><th>Date & Time</th><th>TIN</th><th>Business Name</th><th>Amount</th><th>Status</th></tr></thead><tbody>{data?.recent.map(row => <tr key={row.id}><td><Link to={`${root}/transactions?search=${encodeURIComponent(row.transaction_ref || row.id)}`}>{row.transaction_ref || row.id.slice(0, 8)}</Link></td><td>{formatDate(row.created_at)}</td><td>{row.tin || "—"}</td><td>{row.business_name || "—"}</td><td>{money.format(Number(row.amount))}</td><td><span className={`ne-badge ${["completed", "valid", "verified", "success"].includes(row.status) ? "ne-badge-good" : ["invalid", "failed", "rejected", "voided", "cancelled"].includes(row.status) ? "ne-badge-bad" : "ne-badge-pending"}`}>{row.status === "completed" ? "Valid" : row.status}</span></td></tr>)}</tbody></table>{!data?.recent.length && <div className="ne-table-empty">{data ? portal === "citizen" ? "No transactions linked to your TIN yet. Manage your registration in My Profile." : "No transactions recorded yet." : "Loading transactions…"}</div>}</div></section>
      <section className="ne-panel ne-quick"><div className="ne-panel-title"><h2>Quick Actions</h2><button className="ne-refresh" title="Refresh dashboard" aria-label="Refresh dashboard" onClick={() => setRefresh(value => value + 1)} disabled={refreshing}><RefreshCw size={14} className={refreshing ? "ne-spinning" : ""} /></button></div><div className="ne-action-grid">{actions.map(action => <Link key={action.label} to={action.href}>{action.bir ? <img src="/dashboard-assets/bir-official-logo.png" alt="" /> : <action.icon size={29} strokeWidth={1.8} />}<span>{action.label}</span></Link>)}</div></section></div>
  </div>;
}

const ServerIcon = Server;
const ArchiveIcon = HardDrive;
const NetworkIcon = Network;
function formatDate(value: string) {
  // API database timestamps are UTC unless an explicit offset is present.
  const iso = value.includes("T") ? value : `${value.replace(" ", "T")}Z`;
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString("en-PH", { month: "short", day: "numeric", year: "numeric", hour: "2-digit", minute: "2-digit", hour12: false, timeZone: "Asia/Manila" });
}
