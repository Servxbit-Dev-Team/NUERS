import { useEffect, useState, type ElementType, type FormEvent } from "react";
import { Link, Outlet, useLocation, useNavigate } from "react-router-dom";
import { Headphones, Activity, Bell, Building2, ChevronDown, ChevronRight, CircleHelp, CircleX, CloudUpload, Database, FileCheck2, FileText, Files, Fingerprint, Home, LogOut, Menu, Network, ReceiptText, Search, Server, Settings, ShieldCheck, ShieldAlert, Users, Wallet, X, ChartColumn, Plug, BadgeCheck, Barcode } from "lucide-react";
import "./enterprise.css";

export type EnterprisePortal = "admin" | "merchant" | "citizen";
type Item = { label: string; href: string; icon: ElementType; bir?: boolean; children?: { label: string; href: string }[] };
type Group = { label: string; items: Item[] };

function navigation(portal: EnterprisePortal, root: string, active: boolean): Group[] {
  const item = (label: string, href: string, icon: ElementType, children?: Item["children"], bir = false): Item => ({ label, href, icon, children, bir });
  if (portal === "admin") return [
    { label: "Overview", items: [item("Dashboard", root, Home), item("Live Transaction Monitor", "/monitoring", Activity)] },
    { label: "E-Invoicing Operations", items: [
      item("Electronic Invoices & Receipts", "/invoices", ReceiptText, [{ label: "Invoice Management", href: "/invoices" }, { label: "Electronic Invoicing Engine", href: "/engine" }]),
      item("Transactions", "/super-admin/transactions", Files, [{ label: "All Transactions", href: "/super-admin/transactions" }, { label: "Reconciliation", href: "/super-admin/reconciliation" }]),
      item("BIR Transmission Center", "/super-admin/eis-transmissions", CloudUpload, undefined, true),
      item("Reconciliation & Exceptions", "/super-admin/reconciliation", CircleX), item("Summary List of Sales", "/super-admin/tax-filings", FileText),
    ] },
    { label: "Taxpayer Management", items: [
      item("Registered Businesses", "/super-admin/merchants", Building2, [{ label: "Business Registry", href: "/super-admin/merchants" }, { label: "Business Registration", href: "/taxpayer/businesses" }]),
      item("Taxpayer Onboarding", "/super-admin/taxpayer-onboarding", Users, [{ label: "Taxpayer Applications", href: "/super-admin/taxpayer-onboarding" }, { label: "Citizen Approvals", href: "/super-admin/citizen-approval" }, { label: "Client Accounts", href: "/super-admin/client-accounts" }]),
      item("PTI / PTT Management", "/super-admin/pti-ptt", ShieldCheck, [{ label: "EIS Readiness", href: "/super-admin/eis-readiness" }, { label: "Certificates", href: "/super-admin/certificates" }]),
      item("API Integrations", "/super-admin/api-platform", Plug),
    ] },
    { label: "Compliance & Security", items: [
      item("ESP Accreditation", "/accreditation", BadgeCheck, [{ label: "Accreditation Center", href: "/accreditation" }, { label: "EIS Compliance", href: "/super-admin/eis-readiness" }]),
      item("Compliance Management", "/super-admin/compliance", FileCheck2, [{ label: "Compliance Overview", href: "/super-admin/compliance" }, { label: "Tax Filings", href: "/super-admin/tax-filings" }]),
      item("Security & Incidents", "/super-admin/security", ShieldAlert, [{ label: "Security Overview", href: "/super-admin/security" }, { label: "SOC Operations", href: "/super-admin/soc-operations" }]),
      item("Immutable Audit Logs", "/super-admin/audit", FileText, [{ label: "Audit Log", href: "/super-admin/audit" }]), item("Data Governance & Portability", "/super-admin/data-archive", Database),
    ] },
    { label: "Reports", items: [
      item("Analytics & Reports", "/super-admin/reports", ChartColumn, [{ label: "Reports Center", href: "/super-admin/reports" }, { label: "Revenue Analytics", href: "/super-admin/revenue-analytics" }]),
      item("BIR Reports & Exports", "/super-admin/tax-filings", Files, [{ label: "BIR Tax Filings", href: "/super-admin/tax-filings" }, { label: "Export Reports", href: "/super-admin/reports" }], true),
    ] },
    { label: "System Administration", items: [
      item("Infrastructure Health", "/super-admin/system-health", Server, [{ label: "System Health", href: "/super-admin/system-health" }, { label: "API Monitoring", href: "/api/monitoring" }]), item("Backup & Disaster Recovery", "/super-admin/backups", Database),
      item("Configurations & Changes", "/system", Settings, [{ label: "System Configuration", href: "/system" }, { label: "API Keys", href: "/super-admin/api-keys" }]),
      item("Users & Permissions", "/super-admin/users-rbac", Users, [{ label: "User Management", href: "/super-admin/users-rbac" }, { label: "BIR Accounts", href: "/super-admin/bir-accounts" }]),
      item("Support Inbox", "/super-admin/support", Headphones),
      item("Website Subscriptions", "/super-admin/subscriptions", Wallet),
      item("Client Support & SLA", "/super-admin/support", CircleHelp, [{ label: "Support Inbox", href: "/super-admin/support" }, { label: "Notification Center", href: "/super-admin/notifications" }, { label: "Service Monitoring", href: "/super-admin/system-health" }]),
    ] },
  ];
  if (portal === "merchant") return [
    { label: "Overview", items: [item("Dashboard", "/merchant", Home), item("Transaction Monitor", "/merchant/transactions", Activity)] },
    { label: "E-Invoicing Operations", items: [item("Electronic Invoices & Receipts", "/merchant/invoices", ReceiptText, [{ label: "Invoices", href: "/merchant/invoices" }, { label: "Create Invoice", href: "/merchant/invoices/create" }, { label: "Receipts", href: "/merchant/receipts" }]), item("Transactions", "/merchant/transactions", Files), item("BIR Tax Center", "/merchant/b2b-tax", CloudUpload, undefined, true), item("Expenses", "/merchant/expenses", FileText)] },
    { label: "Business Management", items: [item("Business Profile", "/merchant/profile", Building2), item("Branches & Locations", "/merchant/branches", Network), item("POS Configuration", "/merchant/pos-settings", Settings), item("API Integrations", "/merchant/api-keys", Plug)] },
    { label: "Reports", items: [item("VAT Reports & Exports", "/merchant/vat-reports", ChartColumn, undefined, true), item("Billing & Subscription", "/merchant/billing", Wallet)] },
    { label: "Account Administration", items: [item("Notifications", "/merchant/notifications", Bell), item("Account Settings", "/merchant/profile", Settings)] },
  ];
  return [
    { label: "Overview", items: [item("Dashboard", "/client", Home), item("My Transactions", "/client/transactions", Activity)] },
    { label: "Digital Receipts", items: [item("Receipt Vault", "/client/receipts", ReceiptText), item("Receipt Verification", "/verify", FileCheck2), ...(active ? [item("My TIN Barcode", "/client/barcode", Barcode)] : [])] },
    { label: "Citizen Account", items: [item("My Wallet", "/client/wallet", Wallet), item("Profile & TIN Registration", "/client/profile", Users), item("Notifications", "/client/notifications", Bell)] },
    { label: "Security & Settings", items: [item("Account Security", "/client/security", Fingerprint), item("Account Settings", "/client/profile", Settings)] },
  ];
}

type Props = { portal: EnterprisePortal; root?: string; accountName: string; accountEmail?: string; accountLogo?: string | null; initials: string; roleLabel: string; profilePath: string; notificationPath: string; accountActive: boolean; onSignOut: () => Promise<void> };

export function EnterpriseShell({ portal, root = "/super-admin", accountName, accountEmail, accountLogo, initials, roleLabel, profilePath, notificationPath, accountActive, onSignOut }: Props) {
  const location = useLocation();
  const navigate = useNavigate();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [expanded, setExpanded] = useState<string[]>([]);
  const [search, setSearch] = useState("");
  const [notifications, setNotifications] = useState(0);
  const isMonitor = location.pathname === "/monitoring";
  const isOperations = /\/(transactions|reconciliation|tax-filings|eis-transmissions|merchants|business-accounts|taxpayer-onboarding|pti-ptt|api-platform)$/.test(location.pathname) && portal === "admin";
  const isInvoices = location.pathname === "/invoices";
  const [monitorMeta, setMonitorMeta] = useState({ live: false, status: "loading", generatedAt: "", gateway: "not_connected", version: "" });
  useEffect(() => {
    const listener = (event: Event) => { const detail = (event as CustomEvent<typeof monitorMeta>).detail; setMonitorMeta(current => ({ ...detail, generatedAt: detail.generatedAt || current.generatedAt, version: detail.version || current.version })); };
    window.addEventListener("nuers:monitor-meta", listener);
    return () => window.removeEventListener("nuers:monitor-meta", listener);
  }, []);
  useEffect(() => { setMobileOpen(false); }, [location.pathname]);
  useEffect(() => { setSearch(new URLSearchParams(location.search).get("search") || ""); }, [location.search, location.pathname]);
  useEffect(() => {
    const listener = (event: Event) => setNotifications((event as CustomEvent<number>).detail || 0);
    window.addEventListener("nuers:enterprise-notifications", listener);
    return () => window.removeEventListener("nuers:enterprise-notifications", listener);
  }, []);
  useEffect(() => {
    const escape = (event: KeyboardEvent) => { if (event.key === "Escape") setMobileOpen(false); };
    window.addEventListener("keydown", escape);
    return () => window.removeEventListener("keydown", escape);
  }, []);
  function submitSearch(event: FormEvent) {
    event.preventDefault();
    const target = isOperations ? location.pathname : isInvoices ? "/invoices" : isMonitor ? "/monitoring" : portal === "admin" ? "/super-admin/transactions" : portal === "merchant" ? "/merchant/transactions" : "/client/transactions";
    navigate(`${target}?search=${encodeURIComponent(search.trim())}`);
  }
  const groups = navigation(portal, root, accountActive);
  const overview = ["/super-admin", "/admin", "/merchant", "/client", "/consumer"].includes(location.pathname);
  return <div className={`nuers-enterprise ${isInvoices ? "ne-invoices" : ""} ${isOperations ? "ne-operations" : ""} ${collapsed ? "ne-collapsed" : ""} ${mobileOpen ? "ne-mobile-open" : ""}`}>
    {mobileOpen && <button className="ne-scrim" aria-label="Close navigation" onClick={() => setMobileOpen(false)} />}
    <aside className="ne-sidebar" aria-label={`${portal} navigation`}>
      <div className="ne-brand"><Link to={portal === "admin" ? root : portal === "merchant" ? "/merchant" : "/client"} aria-label="NUERS dashboard"><span className="ne-brand-wide"><img src="/auth-assets/nuers-digital-white.png" alt="NUERS — National Unified Electronic Receipt System" /></span><img className="ne-brand-small" src="/nuers-favicon.png" alt="NUERS" /></Link><button title="Collapse navigation" aria-label="Collapse navigation" onClick={() => setCollapsed(!collapsed)}><ChevronRight size={14} /></button><button className="ne-mobile-close" aria-label="Close navigation" onClick={() => setMobileOpen(false)}><X size={20} /></button></div>
      <nav className="ne-nav">{groups.map(group => <section key={group.label} className="ne-nav-group"><h2>{group.label}</h2>{group.items.map(item => {
        const isRoot = item.href === root || ["/merchant", "/client"].includes(item.href);
        const current = isRoot ? location.pathname === item.href || (portal === "citizen" && location.pathname === "/consumer") : location.pathname === item.href || location.pathname.startsWith(`${item.href}/`);
        const open = expanded.includes(item.label);
        const Icon = item.icon;
        return <div key={item.label} className="ne-nav-item"><div className={`ne-nav-row ${current ? "ne-active" : ""}`}><Link to={item.href} title={item.label} aria-current={current ? "page" : undefined}>{item.bir ? <img className="ne-bir-icon" src="/dashboard-assets/bir-official-logo.png" alt="" /> : <Icon size={18} strokeWidth={1.7} />}<span>{item.label}</span></Link>{item.children && <button aria-label={`${open ? "Collapse" : "Expand"} ${item.label}`} aria-expanded={open} onClick={() => setExpanded(current => current.includes(item.label) ? current.filter(label => label !== item.label) : [...current, item.label])}><ChevronRight size={13} className={open ? "ne-rotated" : ""} /></button>}</div>{open && item.children && <div className="ne-nav-children">{item.children.map(child => <Link key={child.href} to={child.href}>{child.label}</Link>)}</div>}</div>;
      })}</section>)}</nav>
      <div className="ne-sidebar-footer"><p>DIGITAL PHILIPPINES.<br />BRIGHTER TOMORROW.</p><div className="ne-tricolor" /></div>
    </aside>
    <div className="ne-workspace">
      <header className="ne-topbar">
        <button className="ne-menu" aria-label="Toggle navigation" aria-expanded={window.matchMedia("(max-width: 900px)").matches ? mobileOpen : !collapsed} onClick={() => { if (window.matchMedia("(max-width: 900px)").matches) { setCollapsed(false); setMobileOpen(!mobileOpen); } else setCollapsed(!collapsed); }}><Menu size={24} /></button>
        <form className="ne-search" onSubmit={submitSearch}><Search size={19} /><input type="search" aria-label="Search transactions" placeholder={isInvoices ? "Search receipts, TIN, business name, OR number..." : isMonitor ? "Search transactions, TIN, receipt number, business name..." : portal === "admin" ? "Search transactions, taxpayers, receipts, etc..." : "Search your transactions and receipts..."} value={search} onChange={event => setSearch(event.target.value)} /></form>
        <div className="ne-topbar-actions">{isMonitor && <span className={`lm-header-live ${monitorMeta.live ? "" : "lm-header-paused"}`}><i />{monitorMeta.live ? "LIVE" : monitorMeta.status === "paused" ? "PAUSED" : monitorMeta.status === "loading" ? "CONNECTING" : "OFFLINE"}</span>}<Link className="ne-notifications" to={notificationPath} aria-label={`Notifications${notifications ? `, ${notifications} unread` : ""}`}><Bell size={23} />{notifications > 0 && <span>{notifications > 99 ? "99+" : notifications}</span>}</Link><Link className="ne-settings" to={portal === "admin" ? "/system" : profilePath} aria-label="Settings"><Settings size={23} /></Link>
          <details className="ne-account"><summary><span className="ne-avatar">{accountLogo ? <img src={accountLogo} alt="" /> : initials}</span><span className="ne-account-text"><strong>{accountName}</strong><small>{roleLabel}</small></span><ChevronDown size={18} /></summary><div className="ne-account-menu"><strong>{accountName}</strong><small>{accountEmail}</small><Link to={profilePath}><Users size={16} />Account profile</Link><Link to={notificationPath}><Bell size={16} />Notifications</Link><button onClick={onSignOut}><LogOut size={16} />Sign out</button></div></details>
        </div>
      </header>
      <div className="ne-scroll"><main className={isMonitor ? "ne-monitor-main" : overview ? "ne-main" : "ne-subpage"}><Outlet /></main><footer className="ne-footer">{isMonitor ? <div className="lm-footer-status"><span className={monitorMeta.live ? "lm-footer-good" : "lm-footer-neutral"}><i />{monitorMeta.live ? "Monitor Online" : monitorMeta.status === "paused" ? "Refresh Paused" : "Monitor Offline"}</span><span className="lm-footer-neutral"><i />BIR Gateway: Not Connected</span><span>Last Sync: {monitorMeta.generatedAt ? new Date(monitorMeta.generatedAt).toLocaleTimeString("en-PH", { timeZone: "Asia/Manila", hour12: false }) : "—"} PHT</span><span className="lm-footer-version">NUERS v{monitorMeta.version || "0.0.1"}</span></div> : <span>© {new Date().getFullYear()} NUERS. All rights reserved.</span>}<div><span>Powered By</span><img src="/auth-assets/ok-technology-logo.png" alt="OK Technology Software Solutions" /></div></footer></div>
    </div>
  </div>;
}
