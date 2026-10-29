import { useEffect, useState, type ElementType, type FormEvent } from "react";
import { Link, Outlet, useLocation, useNavigate } from "react-router-dom";
import { Headphones, Activity, Bell, Building2, ChevronDown, ChevronRight, CircleHelp, CircleX, CloudUpload, Database, FileCheck2, FileText, Files, Fingerprint, Home, LogOut, Menu, ReceiptText, Search, Server, Settings, ShieldCheck, ShieldAlert, Users, Wallet, X, ChartColumn, Plug, BadgeCheck, Barcode, CheckCircle2 } from "lucide-react";
import "./enterprise.css";
import { SoftwareIdentity } from "./software-identity";
import { apiFetch, apiJson } from "@/lib/api-url";
import { MerchantWorkContext } from "@/lib/merchant-access";

export type EnterprisePortal = "admin" | "merchant" | "citizen";
type Item = { label: string; href: string; icon: ElementType; bir?: boolean; children?: { label: string; href: string }[] };
type Group = { label: string; items: Item[] };

function navigation(portal: EnterprisePortal, root: string, active: boolean, roleIsSuperAdmin: boolean): Group[] {
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
      item("Registered Businesses", "/super-admin/merchants", Building2, [{ label: "Business Registry", href: "/super-admin/merchants" }, { label: "Branch Verification", href: "/super-admin/branch-reviews" }, { label: "Business Registration", href: "/taxpayer/businesses" }]),
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
      ...(roleIsSuperAdmin ? [item("Server Management", "/super-admin/server-management", Server)] : []),
      item("Infrastructure Health", "/super-admin/system-health", Server, [{ label: "System Health", href: "/super-admin/system-health" }, { label: "API Monitoring", href: "/api/monitoring" }]), item("Backup & Disaster Recovery", "/super-admin/backups", Database),
      item("Configurations & Changes", "/system", Settings, [{ label: "System Configuration", href: "/system" }, { label: "API Keys", href: "/super-admin/api-keys" }]),
      item("Users & Permissions", "/super-admin/users-rbac", Users, [{ label: "User Management", href: "/super-admin/users-rbac" }, { label: "BIR Accounts", href: "/super-admin/bir-accounts" }]),
      item("Support Inbox", "/super-admin/support", Headphones),
      item("Compliance Operations", "/super-admin/compliance-operations", FileCheck2),
      item("Wallets & Transaction Pricing", "/super-admin/wallets", Wallet),
    ] },
  ];
  if (portal === "merchant") return [
    { label: "Overview", items: [item("Dashboard", "/merchant", Home)] },
    { label: "Sales & Invoicing", items: [item("Create Invoice", "/merchant/invoices/create", FileText), item("Invoices & Receipts", "/merchant/invoices", ReceiptText, [{ label: "Invoices", href: "/merchant/invoices" }, { label: "Receipt Records", href: "/merchant/invoices?category=receipts" }]), item("Customers", "/merchant/customers", Users), item("Wallet", "/merchant/wallet", Wallet)] },
    { label: "Reports", items: [item("Sales Reports", "/merchant/sales-reports", ChartColumn)] },
    { label: "Settings", items: [item("Business Profile", "/merchant/profile", Building2), item("Compliance Operations", "/merchant/compliance-operations", FileCheck2), item("PTI Applications", "/merchant/pti-applications", FileCheck2), item("User Management", "/merchant/users", Users), item("Account Security", "/merchant/security", ShieldCheck), item("API Integration", "/merchant/api-keys", Plug), item("Invoice Settings", "/merchant/pos-settings", ReceiptText), item("Transaction Fees & Storage", "/merchant/billing", Settings)] },
    { label: "Support", items: [item("Help & Documentation", "/merchant?panel=help", CircleHelp), item("Submit a Request", "/merchant?panel=support", FileText)] },
  ];
  return [
    { label: "Overview", items: [item("Dashboard", "/client", Home), item("My Transactions", "/client/transactions", Activity)] },
    { label: "Digital Receipts", items: [item("Receipt Vault", "/client/receipts", ReceiptText), item("Receipt Verification", "/verify", FileCheck2), ...(active ? [item("My TIN Barcode", "/client/barcode", Barcode)] : [])] },
    { label: "Citizen Account", items: [item("My Wallet", "/client/wallet", Wallet), item("Profile & TIN Registration", "/client/profile", Users), item("Notifications", "/client/notifications", Bell)] },
    { label: "Security & Settings", items: [item("Account Security", "/client/security", Fingerprint), item("Account Settings", "/client/profile", Settings)] },
  ];
}

type Props = { portal: EnterprisePortal; root?: string; accountName: string; accountEmail?: string; accountLogo?: string | null; initials: string; roleLabel: string; profilePath: string; notificationPath: string; accountActive: boolean; onSignOut: () => Promise<void> };

export function EnterpriseShell({ portal, root = "/super-admin", accountName: suppliedName, accountEmail, accountLogo: suppliedLogo, initials: suppliedInitials, roleLabel, profilePath, notificationPath, accountActive, onSignOut }: Props) {
  const [adminIdentity,setAdminIdentity]=useState<{name:string;photo:string}|null>(null);
  useEffect(()=>{
    setAdminIdentity(null); if(portal!=="admin")return;
    let active=true,version=0,photoUrl="";
    async function refresh(){const current=++version;try{
      const value=await apiJson<{profile:{full_name:string};account:{has_avatar:boolean}}>("/api/admin/account-profile");
      let nextPhoto="";
      if(value.account.has_avatar){const response=await apiFetch("/api/admin/account-profile/avatar");if(response.ok){const blob=await response.blob();if(!active||current!==version)return;nextPhoto=URL.createObjectURL(blob)}}
      if(!active||current!==version){if(nextPhoto)URL.revokeObjectURL(nextPhoto);return}
      if(photoUrl)URL.revokeObjectURL(photoUrl);photoUrl=nextPhoto;setAdminIdentity({name:value.profile.full_name,photo:nextPhoto});
    }catch{/* The page exposes errors; the header retains the authenticated identity fallback. */}}
    const update=()=>void refresh();void refresh();window.addEventListener("nuers:admin-profile-updated",update);
    return()=>{active=false;version++;if(photoUrl)URL.revokeObjectURL(photoUrl);window.removeEventListener("nuers:admin-profile-updated",update)};
  },[portal,accountEmail]);
  const accountName=adminIdentity?.name||suppliedName;
  const accountLogo=portal==="admin"?adminIdentity?.photo||suppliedLogo:suppliedLogo;
  const initials=adminIdentity?.name?adminIdentity.name.split(/\s+/).slice(0,2).map(x=>x[0]).join("").toUpperCase():suppliedInitials;
  const [teamAccess,setTeamAccess]=useState<{owner:boolean;role:string;permissions:string[];scope:string;terminal_scope:string}|null>(null);
  const [accessError,setAccessError]=useState('');
  useEffect(()=>{if(portal!=="merchant")return;const c=new AbortController();apiJson<NonNullable<typeof teamAccess>>("/api/merchant/access",{signal:c.signal}).then(v=>{setTeamAccess(v);setAccessError('')}).catch(e=>{if(e.name!=="AbortError")setAccessError(e.message)});return()=>c.abort()},[portal]);
  const location = useLocation();
  const navigate = useNavigate();
  const [collapsed, setCollapsed] = useState(false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [expanded, setExpanded] = useState<string[]>([]);
  const [search, setSearch] = useState("");
  const [notifications, setNotifications] = useState(0);
  const [merchantConnection, setMerchantConnection] = useState({ connected: false, updatedAt: "" });
  useEffect(() => {
    const update = (event: Event) => setMerchantConnection((event as CustomEvent<typeof merchantConnection>).detail);
    window.addEventListener("nuers:merchant-connection", update);
    return () => window.removeEventListener("nuers:merchant-connection", update);
  }, []);
  useEffect(() => {
    if (portal !== "merchant") return;
    const key = (event: KeyboardEvent) => { if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === "k") { event.preventDefault(); document.querySelector<HTMLInputElement>(".ne-search input")?.focus(); } };
    window.addEventListener("keydown", key); return () => window.removeEventListener("keydown", key);
  }, [portal]);
  const isMonitor = location.pathname === "/monitoring";
  const isOperations = /\/(transactions|reconciliation|tax-filings|eis-transmissions|merchants|business-accounts|taxpayer-onboarding|pti-ptt|api-platform)$/.test(location.pathname) && portal === "admin";
  const isInvoices = location.pathname === "/invoices";
  const [monitorMeta, setMonitorMeta] = useState({ live: false, status: "loading", generatedAt: "", gateway: "not_connected", version: "" });
  useEffect(() => {
    const listener = (event: Event) => { const detail = (event as CustomEvent<typeof monitorMeta>).detail; setMonitorMeta(current => ({ ...detail, generatedAt: detail.generatedAt || current.generatedAt, version: detail.version || current.version })); };
    window.addEventListener("nuers:monitor-meta", listener);
    return () => window.removeEventListener("nuers:monitor-meta", listener);
  }, []);
  useEffect(() => { setMobileOpen(false); }, [location.pathname, location.search]);
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
    const target = portal === "merchant" && location.pathname === "/merchant/users" ? "/merchant/users" : isOperations ? location.pathname : isInvoices ? "/invoices" : isMonitor ? "/monitoring" : portal === "admin" ? "/super-admin/transactions" : portal === "merchant" ? "/merchant/invoices" : "/client/transactions";
    navigate(`${target}?search=${encodeURIComponent(search.trim())}`);
  }
  const staff=portal==='merchant'&&teamAccess&&!teamAccess.owner;
  const narrow=teamAccess?.scope!=='all'||teamAccess?.terminal_scope!=='all';
  const allowed=(path:string)=>{if(portal==='merchant'&&!teamAccess)return false;if(!staff)return true;if(path==='/merchant'||path==='/security/account'||path==='/merchant/security')return true;const p=teamAccess.permissions;return path==='/merchant/invoices'?p.includes('documents.view'):path==='/merchant/invoices/create'?!narrow&&p.includes('documents.create'):path.startsWith('/merchant/invoices/')?!narrow&&p.includes('documents.view'):path==='/merchant/customers'?!narrow&&p.includes('customers.view'):path==='/merchant/sales-reports'?!narrow&&p.includes('reports.view'):path==='/merchant/wallet'?!narrow&&p.includes('wallet.view'):path==='/merchant/api-keys'?!narrow&&p.includes('integrations.view'):false;};
  const groups = navigation(portal, root, accountActive, roleLabel === "Super Administrator").map(g=>({...g,items:g.items.filter(i=>allowed(i.href)).map(i=>staff?{...i,children:undefined}:i)})).filter(g=>g.items.length);
  const workLinks=groups.flatMap(g=>g.items).filter(i=>!['/merchant','/security/account','/merchant/security'].includes(i.href));
  const pageAllowed=allowed(location.pathname);
  const overview = ["/super-admin", "/admin", "/merchant", "/client", "/consumer"].includes(location.pathname);
  return <MerchantWorkContext.Provider value={teamAccess}><div className={`nuers-enterprise ${portal === "merchant" ? "ne-merchant" : ""} ${isInvoices ? "ne-invoices" : ""} ${isOperations ? "ne-operations" : ""} ${collapsed ? "ne-collapsed" : ""} ${mobileOpen ? "ne-mobile-open" : ""}`}>
    {mobileOpen && <button className="ne-scrim" aria-label="Close navigation" onClick={() => setMobileOpen(false)} />}
    <aside className="ne-sidebar" aria-label={`${portal} navigation`}>
      <div className="ne-brand"><Link to={portal === "admin" ? root : portal === "merchant" ? "/merchant" : "/client"} aria-label="NUERS dashboard"><span className="ne-brand-wide"><img src="/auth-assets/nuers-digital-white.png" alt="NUERS — National Unified Electronic Receipt System" /></span><img className="ne-brand-small" src="/nuers-favicon.png" alt="NUERS" /></Link><button title="Collapse navigation" aria-label="Collapse navigation" onClick={() => setCollapsed(!collapsed)}><ChevronRight size={14} /></button><button className="ne-mobile-close" aria-label="Close navigation" onClick={() => setMobileOpen(false)}><X size={20} /></button></div>
      <nav className="ne-nav">{groups.map(group => <section key={group.label} className="ne-nav-group"><h2>{group.label}</h2>{group.items.map(item => {
        const isRoot = item.href === root || ["/merchant", "/client"].includes(item.href);
        const target = new URL(item.href, window.location.origin);
        const current = target.search ? location.pathname === target.pathname && location.search === target.search : isRoot ? !location.search && location.pathname === item.href || (portal === "citizen" && location.pathname === "/consumer") : location.pathname === item.href && !(portal === "merchant" && item.href === "/merchant/invoices" && location.search) || location.pathname.startsWith(`${item.href}/`) && !(portal === "merchant" && item.href === "/merchant/invoices" && location.pathname === "/merchant/invoices/create");
        const open = expanded.includes(item.label);
        const Icon = item.icon;
        return <div key={item.label} className="ne-nav-item"><div className={`ne-nav-row ${current ? "ne-active" : ""}`}><Link to={item.href} title={item.label} aria-current={current ? "page" : undefined}>{item.bir ? <img className="ne-bir-icon" src="/dashboard-assets/bir-official-logo.png" alt="" /> : <Icon size={18} strokeWidth={1.7} />}<span>{item.label}</span></Link>{item.children && <button aria-label={`${open ? "Collapse" : "Expand"} ${item.label}`} aria-expanded={open} onClick={() => setExpanded(current => current.includes(item.label) ? current.filter(label => label !== item.label) : [...current, item.label])}><ChevronRight size={13} className={open ? "ne-rotated" : ""} /></button>}</div>{open && item.children && <div className="ne-nav-children">{item.children.map(child => <Link key={child.href} to={child.href}>{child.label}</Link>)}</div>}</div>;
      })}</section>)}</nav>
      {portal === "merchant" && <div className={`md-sidebar-connection ${merchantConnection.connected ? "connected" : ""}`}><CheckCircle2 size={29} /><div><strong>{merchantConnection.connected ? "Connected to NUERS" : "NUERS connection"}</strong><small>{merchantConnection.connected ? "Application response received" : "Open dashboard to check"}</small><small>BIR connection: not verified</small></div></div>}
      <div className="ne-sidebar-footer"><SoftwareIdentity /><p>DIGITAL PHILIPPINES.<br />BRIGHTER TOMORROW.</p><div className="ne-tricolor" /></div>
    </aside>
    <div className="ne-workspace">
      <header className="ne-topbar">
        <button className="ne-menu" aria-label="Toggle navigation" aria-expanded={window.matchMedia("(max-width: 900px)").matches ? mobileOpen : !collapsed} onClick={() => { if (window.matchMedia("(max-width: 900px)").matches) { setCollapsed(false); setMobileOpen(!mobileOpen); } else setCollapsed(!collapsed); }}><Menu size={24} /></button>
        <form className="ne-search" onSubmit={submitSearch}><Search size={19} /><input type="search" aria-label={location.pathname === "/merchant/users" ? "Search users" : "Search transactions"} placeholder={location.pathname === "/merchant/users" ? "Search users, roles, email, branch, or keyword… (Ctrl + K)" : isInvoices ? "Search receipts, TIN, business name, OR number..." : isMonitor ? "Search transactions, TIN, receipt number, business name..." : portal === "admin" ? "Search transactions, taxpayers, receipts, etc..." : portal === "merchant" ? "Search invoices, transactions, customers… (Ctrl + K)" : "Search your transactions and receipts..."} value={search} onChange={event => setSearch(event.target.value)} /></form>
        <div className="ne-topbar-actions">{portal === "merchant" && <Link className="md-header-help" to="/merchant?panel=help"><CircleHelp size={21} /><span>Help</span></Link>}{isMonitor && <span className={`lm-header-live ${monitorMeta.live ? "" : "lm-header-paused"}`}><i />{monitorMeta.live ? "LIVE" : monitorMeta.status === "paused" ? "PAUSED" : monitorMeta.status === "loading" ? "CONNECTING" : "OFFLINE"}</span>}<Link className="ne-notifications" to={portal === "merchant" ? "/merchant?panel=alerts" : notificationPath} aria-label={portal === "merchant" ? `Merchant notices${notifications ? `, ${notifications} current` : ""}` : `Notifications${notifications ? `, ${notifications} unread` : ""}`}><Bell size={23} />{notifications > 0 && <span>{notifications > 99 ? "99+" : notifications}</span>}</Link>{portal !== "merchant" && <Link className="ne-settings" to={portal === "admin" ? "/system" : profilePath} aria-label="Settings"><Settings size={23} /></Link>}
          <details className="ne-account"><summary><span className="ne-avatar">{accountLogo ? <img src={accountLogo} alt="" /> : initials}</span><span className="ne-account-text"><strong>{accountName}</strong><small>{staff?teamAccess.role:roleLabel}</small></span><ChevronDown size={18} /></summary><div className="ne-account-menu"><strong>{accountName}</strong><small>{accountEmail}</small><Link to={profilePath}><Users size={16} />Account profile</Link><Link to={portal==="admin"?"/super-admin/profile?tab=security":portal==="merchant"?"/merchant/security":"/security/account"}><ShieldCheck size={16} />Security & email</Link><Link to={portal === "merchant" ? "/merchant?panel=alerts" : notificationPath}><Bell size={16} />Notifications</Link><button onClick={onSignOut}><LogOut size={16} />Sign out</button></div></details>
        </div>
      </header>
      <div className="ne-scroll"><main className={isMonitor ? "ne-monitor-main" : overview ? "ne-main" : "ne-subpage"}>{portal==='merchant'&&!teamAccess?<div role="status" style={{padding:24}}>{accessError||'Checking merchant access…'}</div>:staff&&location.pathname==='/merchant'?<section style={{padding:24}}><h1 style={{fontSize:28,fontWeight:700}}>Your Merchant Workspace</h1><p>{teamAccess.role} · {narrow?'Assigned branch / terminal access':'Business-wide access'}</p><div style={{display:'flex',gap:12,flexWrap:'wrap',marginTop:24}}>{workLinks.map(i=><Link key={i.href} to={i.href} style={{padding:18,border:'1px solid #c4d9fa',borderRadius:8,background:'white',color:'#0063ff'}}>{i.label}</Link>)}</div><p style={{marginTop:24}}>Your business owner manages your role. MFA verification lasts for your current login session.</p></section>:!pageAllowed?<div role="alert" style={{padding:24}}>This page is outside your merchant access scope. <Link to="/merchant">Return to your workspace</Link>.</div>:<Outlet />}</main><footer className="ne-footer">{isMonitor ? <div className="lm-footer-status"><span className={monitorMeta.live ? "lm-footer-good" : "lm-footer-neutral"}><i />{monitorMeta.live ? "Monitor Online" : monitorMeta.status === "paused" ? "Refresh Paused" : "Monitor Offline"}</span><span className="lm-footer-neutral"><i />BIR Gateway: Not Connected</span><span>Last Sync: {monitorMeta.generatedAt ? new Date(monitorMeta.generatedAt).toLocaleTimeString("en-PH", { timeZone: "Asia/Manila", hour12: false }) : "—"} PHT</span><span className="lm-footer-version">NUERS v{monitorMeta.version || "0.0.1"}</span></div> : portal === "merchant" ? <div className="md-footer-brand"><img src="/auth-assets/nuers-digital-logo.png" alt="NUERS" /><span>© {new Date().getFullYear()} NUERS. All rights reserved.</span></div> : <span>© {new Date().getFullYear()} NUERS. All rights reserved.</span>}{portal === "merchant" && <nav className="md-footer-links" aria-label="Merchant legal and support"><Link to="/privacy-notice">Privacy Policy</Link><Link to="/terms-of-service">Terms of Service</Link><Link to="/merchant?panel=help">Support</Link></nav>}</footer></div>
    </div>
  </div></MerchantWorkContext.Provider>;
}
