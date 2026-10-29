import { useState } from "react";
import { useNavigate } from "react-router-dom";
import {
  FileText, Receipt, CreditCard, TrendingUp, TrendingDown, Truck, Wrench,
  Plus, Download, Upload, RefreshCw, Zap, QrCode, Shield, Clock,
  CheckCircle2, AlertTriangle, XCircle, BarChart3, Layers, Calendar,
  ChevronRight, ArrowRight, Repeat, Package, Settings, Eye,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Separator } from "@/components/ui/separator";
import {
  ChartContainer,
  ChartTooltip,
  ChartTooltipContent,
} from "@/components/ui/chart";
import {
  AreaChart, Area, BarChart, Bar, XAxis, YAxis, CartesianGrid,
} from "recharts";
import { DOC_TYPE_META, type DocumentType } from "@/lib/invoice-utils";
import { cn } from "@/lib/utils";

// ─── Mock data ──────────────────────────────────────────────────────────────

const volumeData = [
  { hour: "00:00", sales: 124, receipts: 87, credit: 12, debit: 8, service: 43, purchase: 31, delivery: 19 },
  { hour: "02:00", sales: 89,  receipts: 62, credit: 8,  debit: 5, service: 28, purchase: 21, delivery: 14 },
  { hour: "04:00", sales: 56,  receipts: 41, credit: 5,  debit: 3, service: 18, purchase: 12, delivery: 9  },
  { hour: "06:00", sales: 201, receipts: 143,credit: 22, debit: 14,service: 67, purchase: 48, delivery: 31 },
  { hour: "08:00", sales: 487, receipts: 341,credit: 54, debit: 31,service: 162,purchase: 119,delivery: 78 },
  { hour: "10:00", sales: 623, receipts: 441,credit: 71, debit: 42,service: 208,purchase: 151,delivery: 99 },
  { hour: "12:00", sales: 712, receipts: 502,credit: 81, debit: 48,service: 237,purchase: 172,delivery: 113},
  { hour: "14:00", sales: 689, receipts: 487,credit: 78, debit: 46,service: 230,purchase: 167,delivery: 110},
  { hour: "16:00", sales: 543, receipts: 383,credit: 62, debit: 37,service: 181,purchase: 131,delivery: 86 },
  { hour: "18:00", sales: 398, receipts: 281,credit: 45, debit: 27,service: 133,purchase: 96, delivery: 63 },
  { hour: "20:00", sales: 287, receipts: 202,credit: 33, debit: 19,service: 96, purchase: 69, delivery: 45 },
  { hour: "22:00", sales: 178, receipts: 125,credit: 20, debit: 12,service: 59, purchase: 43, delivery: 28 },
];

const weeklyData = [
  { day: "Mon", generated: 4821, validated: 4789, transmitted: 4751, failed: 12 },
  { day: "Tue", generated: 5234, validated: 5198, transmitted: 5161, failed: 8  },
  { day: "Wed", generated: 4912, validated: 4878, transmitted: 4842, failed: 15 },
  { day: "Thu", generated: 5567, validated: 5531, transmitted: 5494, failed: 19 },
  { day: "Fri", generated: 6021, validated: 5988, transmitted: 5952, failed: 23 },
  { day: "Sat", generated: 3841, validated: 3812, transmitted: 3789, failed: 7  },
  { day: "Sun", generated: 2987, validated: 2963, transmitted: 2944, failed: 5  },
];

const processingQueue = [
  { id: "Q-001", type: "sales_invoice" as DocumentType, count: 247, status: "processing", eta: "12s" },
  { id: "Q-002", type: "official_receipt" as DocumentType, count: 183, status: "queued", eta: "28s" },
  { id: "Q-003", type: "service_invoice" as DocumentType, count: 89, status: "signing", eta: "45s" },
  { id: "Q-004", type: "credit_memo" as DocumentType, count: 34, status: "validating", eta: "1m 2s" },
  { id: "Q-005", type: "purchase_invoice" as DocumentType, count: 121, status: "queued", eta: "1m 34s" },
];

const recentInvoices = [
  { id: "SI-2026-001842", type: "sales_invoice" as DocumentType, buyer: "Jollibee Food Corp.", amount: 248750, status: "validated", time: "2m ago", formats: ["json","xml","pdf"] },
  { id: "OR-2026-002341", type: "official_receipt" as DocumentType, buyer: "SM Prime Holdings", amount: 183200, status: "transmitted", time: "4m ago", formats: ["json","pdf"] },
  { id: "SI-2026-001841", type: "sales_invoice" as DocumentType, buyer: "Ayala Land Inc.", amount: 1247800, status: "validated", time: "6m ago", formats: ["json","xml","pdf","csv"] },
  { id: "CM-2026-000341", type: "credit_memo" as DocumentType, buyer: "BDO Unibank Inc.", amount: 54200, status: "pending", time: "9m ago", formats: ["json","xml"] },
  { id: "SVI-2026-000892", type: "service_invoice" as DocumentType, buyer: "PLDT Inc.", amount: 392100, status: "validated", time: "11m ago", formats: ["json","pdf"] },
  { id: "DM-2026-000124", type: "debit_memo" as DocumentType, buyer: "Globe Telecom", amount: 28450, status: "draft", time: "15m ago", formats: ["json"] },
  { id: "PI-2026-000721", type: "purchase_invoice" as DocumentType, buyer: "Petron Corporation", amount: 4812300, status: "transmitted", time: "18m ago", formats: ["json","xml","pdf"] },
  { id: "DR-2026-000198", type: "delivery_receipt" as DocumentType, buyer: "San Miguel Corp.", amount: 189000, status: "validated", time: "22m ago", formats: ["json","pdf"] },
];

const formatStats = [
  { format: "JSON",  count: 28471, pct: 100, color: "bg-primary" },
  { format: "XML",   count: 19832, pct: 70,  color: "bg-chart-2" },
  { format: "PDF",   count: 22104, pct: 78,  color: "bg-success" },
  { format: "CSV",   count: 8213,  pct: 29,  color: "bg-warning" },
];

const capabilities = [
  { id: "qr",        icon: QrCode,      label: "QR Code Generation",        desc: "BIR-compliant QR codes on every document",  active: true  },
  { id: "sign",      icon: Shield,      label: "Digital Signature",          desc: "RSA-2048 / ECDSA-P256 cryptographic signing", active: true  },
  { id: "uid",       icon: Layers,      label: "Unique Invoice Identifier",  desc: "Globally unique sequential IDs with prefix", active: true  },
  { id: "ts",        icon: Clock,       label: "Timestamping",               desc: "RFC 3161 compliant trusted timestamps",      active: true  },
  { id: "tax",       icon: BarChart3,   label: "Tax Computation Engine",     desc: "VAT, zero-rated, exempt with withholding",   active: true  },
  { id: "validate",  icon: CheckCircle2,label: "Invoice Validation",         desc: "BIR schema + business rule validation",      active: true  },
  { id: "version",   icon: GitBranchIcon,label: "Invoice Versioning",        desc: "Immutable version history & diff tracking",  active: true  },
  { id: "amend",     icon: RefreshCw,   label: "Amendment Workflow",         desc: "Correction with audit trail & BIR reporting",active: true  },
  { id: "cancel",    icon: XCircle,     label: "Cancellation Workflow",      desc: "BIR-compliant cancellation with reason codes",active: true  },
  { id: "bulk",      icon: Package,     label: "Bulk Processing",            desc: "Up to 50,000 invoices per batch job",        active: true  },
  { id: "recurring", icon: Repeat,      label: "Recurring Billing",          desc: "Auto-generation on defined schedules",       active: true  },
  { id: "scheduled", icon: Calendar,    label: "Scheduled Generation",       desc: "Cron-based future-dated invoice creation",   active: true  },
];

function GitBranchIcon(props: React.SVGProps<SVGSVGElement>) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round" {...props}>
      <line x1="6" y1="3" x2="6" y2="15" />
      <circle cx="18" cy="6" r="3" />
      <circle cx="6" cy="18" r="3" />
      <path d="M18 9a9 9 0 0 1-9 9" />
    </svg>
  );
}

const docTypeData: Array<{ type: DocumentType; icon: React.ElementType; count: number; total: number; growth: string }> = [
  { type: "sales_invoice",     icon: FileText, count: 14821, total: 2847109000, growth: "+8.4%" },
  { type: "official_receipt",  icon: Receipt,  count: 11234, total: 1923400000, growth: "+6.1%" },
  { type: "service_invoice",   icon: Wrench,   count: 6712,  total: 987230000,  growth: "+12.3%"},
  { type: "purchase_invoice",  icon: TrendingDown, count: 5847, total: 4812100000, growth: "+5.7%"},
  { type: "credit_memo",       icon: CreditCard, count: 2341, total: 341200000, growth: "-2.1%" },
  { type: "debit_memo",        icon: TrendingUp, count: 1893, total: 219800000,  growth: "+3.2%" },
  { type: "delivery_receipt",  icon: Truck,    count: 3421,  total: 512400000,  growth: "+9.8%" },
];

const statusColors: Record<string, string> = {
  validated:   "text-success border-success/30 bg-success/10",
  transmitted: "text-primary border-primary/30 bg-primary/10",
  pending:     "text-warning border-warning/30 bg-warning/10",
  draft:       "text-muted-foreground border-border bg-muted/50",
  failed:      "text-destructive border-destructive/30 bg-destructive/10",
  processing:  "text-primary border-primary/30 bg-primary/10",
  queued:      "text-muted-foreground border-border bg-muted/50",
  signing:     "text-warning border-warning/30 bg-warning/10",
  validating:  "text-chart-2 border-chart-2/30 bg-chart-2/10",
};

const chartConfig = {
  sales:     { label: "Sales Invoice",    color: "var(--primary)"   },
  receipts:  { label: "Official Receipt", color: "var(--chart-2)"   },
  service:   { label: "Service Invoice",  color: "var(--chart-3)"   },
  generated: { label: "Generated",        color: "var(--primary)"   },
  validated: { label: "Validated",        color: "var(--success)"   },
  transmitted:{ label: "Transmitted",     color: "var(--chart-2)"   },
  failed:    { label: "Failed",           color: "var(--destructive)"},
};

// ─── KPI Card ────────────────────────────────────────────────────────────────
function KpiCard({ label, value, sub, trend, trendUp }: {
  label: string; value: string; sub: string; trend: string; trendUp: boolean;
}) {
  return (
    <Card className="border border-border/60">
      <CardContent className="p-5">
        <p className="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-1">{label}</p>
        <p className="text-2xl font-bold text-foreground">{value}</p>
        <div className="flex items-center gap-2 mt-1">
          <span className={cn("text-xs font-semibold", trendUp ? "text-success" : "text-destructive")}>
            {trend}
          </span>
          <span className="text-xs text-muted-foreground">{sub}</span>
        </div>
      </CardContent>
    </Card>
  );
}

// ─── Main Component ──────────────────────────────────────────────────────────
export function ElectronicInvoicingEngine() {
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState("overview");

  return (
    <div className="max-w-full space-y-4 p-0 sm:space-y-6 xl:max-w-[1600px]">

      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
          <div className="flex items-center gap-2 mb-1">
            <Badge variant="outline" className="text-xs font-mono border-primary/40 text-primary bg-primary/5">MODULE 1</Badge>
            <Badge className="text-xs bg-success/15 text-success border-success/30 font-medium">ACTIVE</Badge>
          </div>
          <h1 className="text-2xl font-bold text-foreground">Electronic Invoicing Engine</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            Real-time invoice generation, digital signing, tax computation, and multi-format export for all BIR document types
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" className="gap-1.5">
            <Settings className="w-3.5 h-3.5" />
            Engine Config
          </Button>
          <Button size="sm" className="gap-1.5 bg-primary text-primary-foreground" onClick={() => navigate("/merchant/invoices/create")}>
            <Plus className="w-3.5 h-3.5" />
            New Invoice
          </Button>
        </div>
      </div>

      {/* KPIs */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <KpiCard label="Invoices Today" value="28,471" sub="vs yesterday" trend="+14.2%" trendUp={true} />
        <KpiCard label="Validated Rate" value="99.84%" sub="last 24h" trend="+0.12%" trendUp={true} />
        <KpiCard label="Avg Generation" value="84ms" sub="end-to-end" trend="-11ms" trendUp={true} />
        <KpiCard label="Total Amount" value="₱4.82B" sub="today" trend="+8.7%" trendUp={true} />
      </div>

      {/* Document Type Cards */}
      <div>
        <div className="flex items-center justify-between mb-3">
          <h2 className="text-sm font-semibold text-foreground">Document Types — Today</h2>
          <Button variant="ghost" size="sm" className="text-xs h-7 gap-1">
            View All <ChevronRight className="w-3 h-3" />
          </Button>
        </div>
        <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
          {docTypeData.map(({ type, icon: Icon, count, total, growth }) => {
            const meta = DOC_TYPE_META[type];
            const isNeg = growth.startsWith("-");
            return (
              <Card
                key={type}
                className="border border-border/60 hover:border-primary/40 hover:shadow-sm transition-all cursor-pointer group"
                onClick={() => navigate("/merchant/invoices")}
              >
                <CardContent className="p-4">
                  <div className="flex items-center justify-between mb-2">
                    <div className="w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                      <Icon className="w-4 h-4 text-primary" />
                    </div>
                    <Badge variant="outline" className="text-[10px] font-mono px-1.5 py-0">{meta.short}</Badge>
                  </div>
                  <p className="text-xs font-medium text-muted-foreground leading-tight mb-1">{meta.label}</p>
                  <p className="text-lg font-bold text-foreground">{count.toLocaleString()}</p>
                  <p className="text-[10px] text-muted-foreground">₱{(total / 1_000_000).toFixed(1)}M total</p>
                  <p className={cn("text-[10px] font-semibold mt-0.5", isNeg ? "text-destructive" : "text-success")}>{growth}</p>
                </CardContent>
              </Card>
            );
          })}
        </div>
      </div>

      {/* Main Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="h-9">
          <TabsTrigger value="overview" className="text-xs px-3">Overview</TabsTrigger>
          <TabsTrigger value="queue" className="text-xs px-3">Processing Queue</TabsTrigger>
          <TabsTrigger value="recent" className="text-xs px-3">Recent Invoices</TabsTrigger>
          <TabsTrigger value="formats" className="text-xs px-3">Formats & Export</TabsTrigger>
          <TabsTrigger value="capabilities" className="text-xs px-3">Capabilities</TabsTrigger>
        </TabsList>

        {/* ── Overview ── */}
        <TabsContent value="overview" className="mt-4 space-y-4">
          <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {/* Hourly Volume */}
            <Card className="lg:col-span-2 border border-border/60">
              <CardHeader className="pb-2 px-5 pt-5">
                <div className="flex items-center justify-between">
                  <CardTitle className="text-sm font-semibold">Hourly Invoice Volume — Today</CardTitle>
                  <div className="flex items-center gap-3 text-[10px] text-muted-foreground">
                    <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-primary inline-block" />Sales</span>
                    <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-chart-2 inline-block" />Receipts</span>
                    <span className="flex items-center gap-1"><span className="w-2 h-2 rounded-full bg-chart-3 inline-block" />Service</span>
                  </div>
                </div>
              </CardHeader>
              <CardContent className="px-2 pb-4">
                <ChartContainer config={chartConfig} className="h-[220px] w-full">
                  <AreaChart data={volumeData}>
                    <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" strokeOpacity={0.5} />
                    <XAxis dataKey="hour" tick={{ fontSize: 10, fill: "var(--muted-foreground)" }} axisLine={false} tickLine={false} />
                    <YAxis tick={{ fontSize: 10, fill: "var(--muted-foreground)" }} axisLine={false} tickLine={false} width={40} />
                    <ChartTooltip content={<ChartTooltipContent />} />
                    <Area type="monotone" dataKey="sales" stackId="1" stroke="var(--primary)" fill="var(--primary)" fillOpacity={0.15} strokeWidth={2} />
                    <Area type="monotone" dataKey="receipts" stackId="1" stroke="var(--chart-2)" fill="var(--chart-2)" fillOpacity={0.15} strokeWidth={2} />
                    <Area type="monotone" dataKey="service" stackId="1" stroke="var(--chart-3)" fill="var(--chart-3)" fillOpacity={0.15} strokeWidth={2} />
                  </AreaChart>
                </ChartContainer>
              </CardContent>
            </Card>

            {/* Processing Stats */}
            <Card className="border border-border/60">
              <CardHeader className="pb-2 px-5 pt-5">
                <CardTitle className="text-sm font-semibold">Engine Performance</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-5 space-y-4">
                {[
                  { label: "Generation Rate", value: "99.84%", pct: 99.84, color: "bg-success" },
                  { label: "Validation Pass", value: "99.91%", pct: 99.91, color: "bg-primary" },
                  { label: "Digital Signing", value: "100%",   pct: 100,   color: "bg-success" },
                  { label: "QR Generation",   value: "100%",   pct: 100,   color: "bg-success" },
                  { label: "Bulk Processing", value: "98.7%",  pct: 98.7,  color: "bg-chart-2" },
                  { label: "Format Export",   value: "99.6%",  pct: 99.6,  color: "bg-primary" },
                ].map(({ label, value, pct, color }) => (
                  <div key={label}>
                    <div className="flex justify-between text-xs mb-1">
                      <span className="text-muted-foreground">{label}</span>
                      <span className="font-semibold text-foreground">{value}</span>
                    </div>
                    <Progress value={pct} className={cn("h-1.5", color)} />
                  </div>
                ))}
                <Separator />
                <div className="grid grid-cols-2 gap-3 pt-1">
                  <div className="text-center">
                    <p className="text-lg font-bold text-foreground">84ms</p>
                    <p className="text-[10px] text-muted-foreground">Avg Gen Time</p>
                  </div>
                  <div className="text-center">
                    <p className="text-lg font-bold text-foreground">247ms</p>
                    <p className="text-[10px] text-muted-foreground">Sign + Validate</p>
                  </div>
                  <div className="text-center">
                    <p className="text-lg font-bold text-foreground">50K</p>
                    <p className="text-[10px] text-muted-foreground">Bulk Limit</p>
                  </div>
                  <div className="text-center">
                    <p className="text-lg font-bold text-foreground">4</p>
                    <p className="text-[10px] text-muted-foreground">Formats</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Weekly Summary */}
          <Card className="border border-border/60">
            <CardHeader className="pb-2 px-5 pt-5">
              <CardTitle className="text-sm font-semibold">Weekly Invoice Pipeline — Generated / Validated / Transmitted</CardTitle>
            </CardHeader>
            <CardContent className="px-2 pb-4">
              <ChartContainer config={chartConfig} className="h-[180px] w-full">
                <BarChart data={weeklyData} barGap={2}>
                  <CartesianGrid strokeDasharray="3 3" stroke="var(--border)" strokeOpacity={0.5} vertical={false} />
                  <XAxis dataKey="day" tick={{ fontSize: 10, fill: "var(--muted-foreground)" }} axisLine={false} tickLine={false} />
                  <YAxis tick={{ fontSize: 10, fill: "var(--muted-foreground)" }} axisLine={false} tickLine={false} width={45} tickFormatter={(v) => `${(v/1000).toFixed(0)}K`} />
                  <ChartTooltip content={<ChartTooltipContent />} />
                  <Bar dataKey="generated" fill="var(--primary)" radius={[3,3,0,0]} fillOpacity={0.8} />
                  <Bar dataKey="validated" fill="var(--success)" radius={[3,3,0,0]} fillOpacity={0.8} />
                  <Bar dataKey="transmitted" fill="var(--chart-2)" radius={[3,3,0,0]} fillOpacity={0.8} />
                </BarChart>
              </ChartContainer>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Processing Queue ── */}
        <TabsContent value="queue" className="mt-4">
          <Card className="border border-border/60">
            <CardHeader className="px-5 pt-5 pb-3">
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm font-semibold">Live Processing Queue</CardTitle>
                <div className="flex items-center gap-2">
                  <span className="flex items-center gap-1.5 text-xs text-success">
                    <span className="w-2 h-2 rounded-full bg-success animate-pulse" />
                    Engine Running
                  </span>
                  <Button variant="outline" size="sm" className="h-7 text-xs gap-1">
                    <RefreshCw className="w-3 h-3" /> Refresh
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent className="px-5 pb-5">
              <div className="space-y-3">
                {processingQueue.map((q) => {
                  const meta = DOC_TYPE_META[q.type];
                  return (
                    <div key={q.id} className="flex items-center gap-4 p-3 rounded-lg border border-border/60 bg-muted/20">
                      <div className="w-8 h-8 rounded-md bg-primary/10 flex items-center justify-center flex-shrink-0">
                        <Zap className="w-4 h-4 text-primary" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center gap-2 mb-1">
                          <span className="text-xs font-mono font-semibold text-foreground">{q.id}</span>
                          <Badge variant="outline" className="text-[10px] px-1.5 py-0">{meta.short}</Badge>
                          <Badge className={cn("text-[10px] px-1.5 py-0 border", statusColors[q.status])}>{q.status}</Badge>
                        </div>
                        <div className="flex items-center gap-3 text-[10px] text-muted-foreground">
                          <span>{meta.label}</span>
                          <span>•</span>
                          <span>{q.count.toLocaleString()} documents</span>
                          <span>•</span>
                          <span>ETA: {q.eta}</span>
                        </div>
                      </div>
                      <Progress value={q.status === "processing" ? 67 : q.status === "signing" ? 45 : q.status === "validating" ? 82 : 0} className="w-24 h-1.5" />
                    </div>
                  );
                })}
              </div>

              <Separator className="my-4" />

              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                {[
                  { label: "In Queue", value: "674", icon: Package, color: "text-muted-foreground" },
                  { label: "Processing", value: "247", icon: Zap, color: "text-primary" },
                  { label: "Completed Today", value: "27,550", icon: CheckCircle2, color: "text-success" },
                  { label: "Failed", value: "89", icon: AlertTriangle, color: "text-destructive" },
                ].map(({ label, value, icon: Icon, color }) => (
                  <div key={label} className="flex items-center gap-3 p-3 rounded-lg bg-muted/30 border border-border/40">
                    <Icon className={cn("w-5 h-5 flex-shrink-0", color)} />
                    <div>
                      <p className="text-lg font-bold text-foreground">{value}</p>
                      <p className="text-[10px] text-muted-foreground">{label}</p>
                    </div>
                  </div>
                ))}
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Recent Invoices ── */}
        <TabsContent value="recent" className="mt-4">
          <Card className="border border-border/60">
            <CardHeader className="px-5 pt-5 pb-3">
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm font-semibold">Recently Generated Invoices</CardTitle>
                <Button variant="outline" size="sm" className="h-7 text-xs gap-1" onClick={() => navigate("/merchant/invoices")}>
                  View All <ArrowRight className="w-3 h-3" />
                </Button>
              </div>
            </CardHeader>
            <CardContent className="px-0 pb-0">
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead>
                    <tr className="border-b border-border/60 bg-muted/30">
                      <th className="text-left text-[10px] font-semibold text-muted-foreground uppercase px-5 py-2.5 tracking-wider">Invoice #</th>
                      <th className="text-left text-[10px] font-semibold text-muted-foreground uppercase px-3 py-2.5 tracking-wider">Type</th>
                      <th className="text-left text-[10px] font-semibold text-muted-foreground uppercase px-3 py-2.5 tracking-wider">Buyer</th>
                      <th className="text-right text-[10px] font-semibold text-muted-foreground uppercase px-3 py-2.5 tracking-wider">Amount</th>
                      <th className="text-left text-[10px] font-semibold text-muted-foreground uppercase px-3 py-2.5 tracking-wider">Status</th>
                      <th className="text-left text-[10px] font-semibold text-muted-foreground uppercase px-3 py-2.5 tracking-wider">Formats</th>
                      <th className="text-left text-[10px] font-semibold text-muted-foreground uppercase px-3 py-2.5 tracking-wider">Time</th>
                      <th className="px-5 py-2.5" />
                    </tr>
                  </thead>
                  <tbody>
                    {recentInvoices.map((inv, i) => {
                      const meta = DOC_TYPE_META[inv.type];
                      return (
                        <tr key={inv.id} className={cn("border-b border-border/40 hover:bg-muted/20 transition-colors", i % 2 === 0 ? "" : "bg-muted/10")}>
                          <td className="px-5 py-3 font-mono font-semibold text-primary">{inv.id}</td>
                          <td className="px-3 py-3">
                            <Badge variant="outline" className="text-[10px] px-1.5 py-0">{meta.short}</Badge>
                          </td>
                          <td className="px-3 py-3 text-foreground font-medium">{inv.buyer}</td>
                          <td className="px-3 py-3 text-right font-semibold text-foreground">₱{inv.amount.toLocaleString()}</td>
                          <td className="px-3 py-3">
                            <Badge className={cn("text-[10px] px-1.5 py-0 border", statusColors[inv.status])}>{inv.status}</Badge>
                          </td>
                          <td className="px-3 py-3">
                            <div className="flex gap-1">
                              {inv.formats.map((f) => (
                                <span key={f} className="text-[9px] font-mono bg-muted border border-border/60 px-1.5 py-0.5 rounded uppercase">{f}</span>
                              ))}
                            </div>
                          </td>
                          <td className="px-3 py-3 text-muted-foreground">{inv.time}</td>
                          <td className="px-5 py-3">
                            <Button variant="ghost" size="sm" className="h-6 w-6 p-0">
                              <Eye className="w-3.5 h-3.5" />
                            </Button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── Formats & Export ── */}
        <TabsContent value="formats" className="mt-4">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <Card className="border border-border/60">
              <CardHeader className="px-5 pt-5 pb-3">
                <CardTitle className="text-sm font-semibold">Supported Output Formats</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-5 space-y-4">
                {formatStats.map(({ format, count, pct, color }) => (
                  <div key={format}>
                    <div className="flex justify-between text-xs mb-1.5">
                      <div className="flex items-center gap-2">
                        <span className={cn("w-2 h-2 rounded-full", color)} />
                        <span className="font-semibold text-foreground">{format}</span>
                        <span className="text-muted-foreground">— {count.toLocaleString()} invoices today</span>
                      </div>
                      <span className="font-semibold text-foreground">{pct}%</span>
                    </div>
                    <Progress value={pct} className={cn("h-2", color)} />
                  </div>
                ))}
                <Separator />
                <div className="grid grid-cols-2 gap-3 pt-1">
                  {[
                    { fmt: "JSON", desc: "Machine-readable, API-native, BIR-compliant schema", actions: ["Download","Stream","Webhook"] },
                    { fmt: "XML",  desc: "BIR EIS official submission format, schema-validated", actions: ["Download","Transmit"] },
                    { fmt: "PDF",  desc: "Print-ready with QR code and digital signature overlay", actions: ["Download","Email","Print"] },
                    { fmt: "CSV",  desc: "Flat-file export for accounting and reconciliation", actions: ["Download","Schedule"] },
                  ].map(({ fmt, desc, actions }) => (
                    <div key={fmt} className="p-3 rounded-lg border border-border/60 bg-muted/20">
                      <div className="flex items-center gap-2 mb-1.5">
                        <span className="text-xs font-mono font-bold text-primary">.{fmt.toLowerCase()}</span>
                      </div>
                      <p className="text-[10px] text-muted-foreground leading-relaxed mb-2">{desc}</p>
                      <div className="flex flex-wrap gap-1">
                        {actions.map((a) => (
                          <span key={a} className="text-[9px] bg-primary/10 text-primary border border-primary/20 px-1.5 py-0.5 rounded">{a}</span>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>

            <Card className="border border-border/60">
              <CardHeader className="px-5 pt-5 pb-3">
                <CardTitle className="text-sm font-semibold">Bulk & Scheduled Operations</CardTitle>
              </CardHeader>
              <CardContent className="px-5 pb-5 space-y-3">
                {[
                  { label: "Bulk Upload (CSV/JSON)", sub: "Import up to 50,000 invoices per batch with validation", icon: Upload, action: "Run Bulk", href: "/merchant/invoices/bulk" },
                  { label: "Recurring Billing",      sub: "Auto-generate invoices on daily/weekly/monthly/annual schedule", icon: Repeat, action: "Configure", href: "/merchant/invoices/recurring" },
                  { label: "Scheduled Generation",   sub: "Cron-based future-dated invoice creation and delivery", icon: Calendar, action: "Schedule", href: "/merchant/invoices/create" },
                  { label: "Bulk Export",            sub: "Export any date range in JSON, XML, PDF, or CSV", icon: Download, action: "Export", href: "/merchant/invoices" },
                ].map(({ label, sub, icon: Icon, action, href }) => (
                  <div key={label} className="flex items-center gap-3 p-3 rounded-lg border border-border/60 hover:border-primary/40 transition-colors">
                    <div className="w-9 h-9 rounded-lg bg-primary/10 flex items-center justify-center flex-shrink-0">
                      <Icon className="w-4.5 h-4.5 text-primary" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <p className="text-xs font-semibold text-foreground">{label}</p>
                      <p className="text-[10px] text-muted-foreground">{sub}</p>
                    </div>
                    <Button variant="outline" size="sm" className="h-7 text-xs flex-shrink-0 gap-1" onClick={() => navigate(href)}>
                      {action} <ArrowRight className="w-3 h-3" />
                    </Button>
                  </div>
                ))}
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        {/* ── Capabilities ── */}
        <TabsContent value="capabilities" className="mt-4">
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            {capabilities.map(({ id, icon: Icon, label, desc, active }) => (
              <div
                key={id}
                className={cn(
                  "flex items-start gap-3 p-4 rounded-lg border transition-colors",
                  active ? "border-success/30 bg-success/5" : "border-border/60 bg-muted/20"
                )}
              >
                <div className={cn(
                  "w-9 h-9 rounded-lg flex items-center justify-center flex-shrink-0",
                  active ? "bg-success/15" : "bg-muted"
                )}>
                  <Icon className={cn("w-4.5 h-4.5", active ? "text-success" : "text-muted-foreground")} />
                </div>
                <div className="flex-1">
                  <div className="flex items-center gap-2 mb-0.5">
                    <p className="text-xs font-semibold text-foreground">{label}</p>
                    {active && <CheckCircle2 className="w-3 h-3 text-success flex-shrink-0" />}
                  </div>
                  <p className="text-[10px] text-muted-foreground leading-relaxed">{desc}</p>
                </div>
              </div>
            ))}
          </div>

          <Separator className="my-4" />

          {/* Quick Actions */}
          <div>
            <h3 className="text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-3">Quick Actions</h3>
            <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
              {[
                { label: "Create Invoice",     icon: Plus,       href: "/merchant/invoices/create",    desc: "Start a new document" },
                { label: "Bulk Process",       icon: Package,    href: "/merchant/invoices/bulk",       desc: "Upload CSV/JSON batch" },
                { label: "Recurring Setup",    icon: Repeat,     href: "/merchant/invoices/recurring",  desc: "Configure auto-billing" },
                { label: "View All Invoices",  icon: Eye,        href: "/merchant/invoices",            desc: "Browse invoice center" },
              ].map(({ label, icon: Icon, href, desc }) => (
                <Button
                  key={label}
                  variant="outline"
                  className="h-auto flex-col gap-2 py-4 border-border/60 hover:border-primary/40"
                  onClick={() => navigate(href)}
                >
                  <Icon className="w-5 h-5 text-primary" />
                  <div className="text-center">
                    <p className="text-xs font-semibold">{label}</p>
                    <p className="text-[10px] text-muted-foreground">{desc}</p>
                  </div>
                </Button>
              ))}
            </div>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
