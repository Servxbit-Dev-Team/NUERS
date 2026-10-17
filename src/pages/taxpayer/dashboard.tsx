import { useNavigate } from "react-router-dom";
import {
  Users, Building2, GitBranch, Clock, AlertTriangle,
  ArrowUpRight, ArrowDownRight, ChevronRight,
  ShieldCheck, CreditCard, FileText, UserPlus, Layers,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import {
  AreaChart, Area, BarChart, Bar,
  XAxis, YAxis, CartesianGrid, ResponsiveContainer, Tooltip,
} from "recharts";
import { cn } from "@/lib/utils";

const registrationTrend = [
  { month: "Dec", individual: 124, corporate: 38, verified: 142 },
  { month: "Jan", individual: 148, corporate: 52, verified: 168 },
  { month: "Feb", individual: 162, corporate: 61, verified: 189 },
  { month: "Mar", individual: 198, corporate: 74, verified: 231 },
  { month: "Apr", individual: 221, corporate: 88, verified: 268 },
  { month: "May", individual: 248, corporate: 102, verified: 312 },
];

const verificationFlow = [
  { month: "Jan", pending: 42, approved: 156, rejected: 12 },
  { month: "Feb", pending: 38, approved: 178, rejected: 9 },
  { month: "Mar", pending: 54, approved: 214, rejected: 14 },
  { month: "Apr", pending: 47, approved: 249, rejected: 11 },
  { month: "May", pending: 63, approved: 287, rejected: 16 },
];

const RECENT_REGISTRATIONS = [
  { name: "Juan dela Cruz", tin: "123-456-789-000", type: "Individual", status: "active", risk: "low", date: "2026-05-29" },
  { name: "ABC Trading Corp.", tin: "456-789-012-000", type: "Corporation", status: "under_review", risk: "medium", date: "2026-05-29" },
  { name: "XYZ Partners Inc.", tin: "789-012-345-000", type: "Corporation", status: "active", risk: "low", date: "2026-05-28" },
  { name: "Maria Santos", tin: "012-345-678-000", type: "Sole Proprietor", status: "pending", risk: "low", date: "2026-05-28" },
  { name: "Global Logistics PH", tin: "345-678-901-000", type: "Corporation", status: "suspended", risk: "high", date: "2026-05-27" },
];

const PENDING_VERIFICATIONS = [
  { id: "VR-0421", taxpayer: "ABC Trading Corp.", type: "SEC Verification", priority: "high", submitted: "2h ago" },
  { id: "VR-0420", taxpayer: "Maria Santos", type: "DTI Verification", priority: "normal", submitted: "4h ago" },
  { id: "VR-0419", taxpayer: "New Horizon Inc.", type: "Business Permit", priority: "urgent", submitted: "1d ago" },
  { id: "VR-0418", taxpayer: "Pedro Reyes", type: "Gov ID Verification", priority: "normal", submitted: "1d ago" },
];

const STATUS_META: Record<string, { color: string; label: string }> = {
  active:       { color: "text-success",          label: "Active" },
  pending:      { color: "text-warning",           label: "Pending" },
  under_review: { color: "text-primary",           label: "Under Review" },
  suspended:    { color: "text-destructive",       label: "Suspended" },
  registered:   { color: "text-success",           label: "Registered" },
};

const RISK_META: Record<string, { color: string; bg: string }> = {
  low:      { color: "text-success",     bg: "bg-success/10" },
  medium:   { color: "text-warning",     bg: "bg-warning/10" },
  high:     { color: "text-destructive", bg: "bg-destructive/10" },
  critical: { color: "text-destructive", bg: "bg-destructive/20" },
};

const PRIORITY_META: Record<string, string> = {
  urgent: "text-destructive",
  high:   "text-warning",
  normal: "text-muted-foreground",
  low:    "text-muted-foreground",
};

export function TaxpayerDashboard() {
  const navigate = useNavigate();

  const KPIs = [
    { label: "Total Taxpayers",     value: "14,820",  delta: "+248 this month",  up: true,  icon: Users },
    { label: "Active Businesses",   value: "8,412",   delta: "+102 this month",  up: true,  icon: Building2 },
    { label: "Pending Verification",value: "63",      delta: "-12 vs last week", up: true,  icon: Clock },
    { label: "Registered Branches", value: "2,284",   delta: "+18 this month",   up: true,  icon: GitBranch },
    { label: "Verified Taxpayers",  value: "91.4%",   delta: "+0.8% vs last month", up: true, icon: ShieldCheck },
    { label: "High-Risk Flagged",   value: "38",      delta: "+4 this week",     up: false, icon: AlertTriangle },
    { label: "Service Requests",    value: "127",     delta: "-14 vs last week", up: true,  icon: FileText },
    { label: "Business Accounts",value: "6,284",   delta: "+184 this month",  up: true,  icon: CreditCard },
  ];

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Taxpayer Management System</h1>
          <p className="text-sm text-muted-foreground mt-0.5">Module 3 — Full taxpayer lifecycle management</p>
        </div>
        <div className="flex gap-2 flex-wrap">
          <Button variant="outline" size="sm" className="gap-1.5" onClick={() => navigate("/taxpayer/register")}>
            <UserPlus className="h-3.5 w-3.5" /> Register Taxpayer
          </Button>
          <Button variant="outline" size="sm" className="gap-1.5" onClick={() => navigate("/taxpayer/verification")}>
            <ShieldCheck className="h-3.5 w-3.5" /> Verification Queue
          </Button>
          <Button size="sm" className="gap-1.5" onClick={() => navigate("/taxpayer/businesses")}>
            <Building2 className="h-3.5 w-3.5" /> Businesses
          </Button>
        </div>
      </div>

      {/* KPI Grid */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {KPIs.map(k => (
          <Card key={k.label}>
            <CardContent className="p-4">
              <div className="flex items-center justify-between mb-2">
                <span className="text-xs text-muted-foreground">{k.label}</span>
                <k.icon className="h-3.5 w-3.5 text-muted-foreground" />
              </div>
              <p className="text-xl font-bold text-foreground tabular-nums">{k.value}</p>
              <div className={cn("flex items-center gap-0.5 mt-1 text-xs", k.up ? "text-success" : "text-destructive")}>
                {k.up ? <ArrowUpRight className="h-3 w-3" /> : <ArrowDownRight className="h-3 w-3" />}
                {k.delta}
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Charts */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="px-5 pt-5 pb-2">
            <CardTitle className="text-sm font-semibold">Taxpayer Registrations — 6 Months</CardTitle>
          </CardHeader>
          <CardContent className="px-5 pb-5">
            <ResponsiveContainer width="100%" height={200}>
              <AreaChart data={registrationTrend}>
                <defs>
                  <linearGradient id="gInd" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="var(--chart-1)" stopOpacity={0.2} />
                    <stop offset="95%" stopColor="var(--chart-1)" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="gCorp" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="var(--chart-2)" stopOpacity={0.2} />
                    <stop offset="95%" stopColor="var(--chart-2)" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Area dataKey="individual" stroke="var(--chart-1)" fill="url(#gInd)" strokeWidth={2} name="Individual" />
                <Area dataKey="corporate" stroke="var(--chart-2)" fill="url(#gCorp)" strokeWidth={2} name="Corporate" />
              </AreaChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="px-5 pt-5 pb-2">
            <CardTitle className="text-sm font-semibold">Verification Results — 5 Months</CardTitle>
          </CardHeader>
          <CardContent className="px-5 pb-5">
            <ResponsiveContainer width="100%" height={200}>
              <BarChart data={verificationFlow}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Bar dataKey="approved" fill="var(--chart-2)" name="Approved" radius={[0,0,0,0]} />
                <Bar dataKey="pending" fill="var(--chart-4)" name="Pending" />
                <Bar dataKey="rejected" fill="var(--chart-5)" name="Rejected" radius={[4,4,0,0]} />
              </BarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
      </div>

      {/* Quick nav cards */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { title: "Taxpayer Registry", desc: "TIN management, status, RDO assignment", icon: Users, href: "/taxpayer/registry", badge: null },
          { title: "Business Registration", desc: "SEC/DTI entities, branches, hierarchy", icon: Building2, href: "/taxpayer/businesses", badge: null },
          { title: "Verification Center", desc: "Document review, risk assessment", icon: ShieldCheck, href: "/taxpayer/verification", badge: "63 pending" },
          { title: "Customer Portal", desc: "Self-registration, transaction fees, records", icon: CreditCard, href: "/taxpayer/portal", badge: null },
          { title: "User Management", desc: "Roles, permissions, company hierarchy", icon: Layers, href: "/taxpayer/users", badge: null },
          { title: "Service Requests", desc: "TIN inquiries, corrections, disputes", icon: FileText, href: "/taxpayer/portal", badge: "127 open" },
        ].map(c => (
          <button
            key={c.title}
            onClick={() => navigate(c.href)}
            className="flex items-start gap-3 p-4 border rounded-lg text-left hover:bg-muted/30 transition-colors"
          >
            <c.icon className="h-5 w-5 text-primary mt-0.5 flex-shrink-0" />
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-1.5">
                <p className="text-xs font-semibold text-foreground">{c.title}</p>
                {c.badge && <Badge variant="destructive" className="text-[9px] h-4">{c.badge}</Badge>}
              </div>
              <p className="text-[10px] text-muted-foreground mt-0.5">{c.desc}</p>
            </div>
            <ChevronRight className="h-3.5 w-3.5 text-muted-foreground mt-0.5" />
          </button>
        ))}
      </div>

      {/* Recent registrations + Pending verifications */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="px-5 pt-5 pb-2 flex-row items-center justify-between">
            <CardTitle className="text-sm font-semibold">Recent Registrations</CardTitle>
            <Button variant="ghost" size="sm" className="text-xs h-7" onClick={() => navigate("/taxpayer/registry")}>
              View all <ChevronRight className="h-3 w-3 ml-1" />
            </Button>
          </CardHeader>
          <CardContent className="px-5 pb-5">
            <div className="space-y-0">
              {RECENT_REGISTRATIONS.map(r => {
                const sm = STATUS_META[r.status] ?? { color: "text-muted-foreground", label: r.status };
                const rm = RISK_META[r.risk];
                return (
                  <div key={r.tin} className="flex items-center justify-between py-3 border-b last:border-0">
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center gap-2">
                        <p className="text-xs font-semibold text-foreground truncate">{r.name}</p>
                        <Badge variant="outline" className="text-[9px] shrink-0">{r.type}</Badge>
                      </div>
                      <p className="text-[10px] text-muted-foreground font-mono mt-0.5">{r.tin} • {r.date}</p>
                    </div>
                    <div className="flex items-center gap-2 ml-3">
                      <span className={cn("text-xs font-medium", sm.color)}>{sm.label}</span>
                      <span className={cn("text-[9px] font-medium px-1.5 py-0.5 rounded-full capitalize", rm.color, rm.bg)}>{r.risk}</span>
                    </div>
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="px-5 pt-5 pb-2 flex-row items-center justify-between">
            <CardTitle className="text-sm font-semibold">Verification Queue</CardTitle>
            <Button variant="ghost" size="sm" className="text-xs h-7" onClick={() => navigate("/taxpayer/verification")}>
              Review all <ChevronRight className="h-3 w-3 ml-1" />
            </Button>
          </CardHeader>
          <CardContent className="px-5 pb-5">
            <div className="space-y-0">
              {PENDING_VERIFICATIONS.map(v => (
                <div key={v.id} className="flex items-center justify-between py-3 border-b last:border-0">
                  <div className="flex items-center gap-2 flex-1 min-w-0">
                    <Clock className="h-3.5 w-3.5 text-warning flex-shrink-0" />
                    <div>
                      <div className="flex items-center gap-1.5">
                        <p className="text-xs font-semibold text-foreground">{v.taxpayer}</p>
                        <Badge variant="outline" className="text-[9px]">{v.id}</Badge>
                      </div>
                      <p className="text-[10px] text-muted-foreground mt-0.5">{v.type} • {v.submitted}</p>
                    </div>
                  </div>
                  <div className="flex items-center gap-2 ml-3">
                    <span className={cn("text-xs font-medium capitalize", PRIORITY_META[v.priority])}>{v.priority}</span>
                    <Button size="sm" variant="outline" className="h-6 text-[10px]" onClick={() => navigate("/taxpayer/verification")}>Review</Button>
                  </div>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Taxpayer type breakdown */}
      <Card>
        <CardHeader className="px-5 pt-5 pb-2">
          <CardTitle className="text-sm font-semibold">Taxpayer Type Distribution</CardTitle>
        </CardHeader>
        <CardContent className="px-5 pb-5">
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
            {[
              { label: "Individual",      count: 6842, pct: 46, color: "var(--chart-1)" },
              { label: "Sole Proprietor", count: 3218, pct: 22, color: "var(--chart-2)" },
              { label: "Corporation",     count: 2914, pct: 20, color: "var(--chart-3)" },
              { label: "Partnership",     count: 1124, pct: 8,  color: "var(--chart-4)" },
              { label: "Other",           count: 722,  pct: 4,  color: "var(--chart-5)" },
            ].map(t => (
              <div key={t.label} className="space-y-2">
                <div className="flex justify-between text-xs">
                  <span className="font-medium text-foreground">{t.label}</span>
                  <span className="tabular-nums text-muted-foreground">{t.count.toLocaleString()}</span>
                </div>
                <div className="h-2 bg-muted rounded-full overflow-hidden">
                  <div className="h-full rounded-full" style={{ width: `${t.pct}%`, background: t.color }} />
                </div>
                <p className="text-[10px] text-muted-foreground">{t.pct}% of total</p>
              </div>
            ))}
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
