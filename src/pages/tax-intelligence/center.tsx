import {
  Activity,
  AlertTriangle,
  BarChart3,
  Brain,
  Building2,
  ClipboardList,
  CreditCard,
  Database,
  FileText,
  GitBranch,
  Landmark,
  Network,
  Receipt,
  Search,
  Server,
  ShieldAlert,
  ShieldCheck,
  TrendingUp,
  Users,
  Wallet,
  Zap,
} from "lucide-react";
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { useLocation } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { Separator } from "@/components/ui/separator";
import { useTaxIntelligenceData, type TaxKpi, type TaxRecord, type TaxScope } from "@/lib/tax-intelligence-data";

const iconMap = {
  Activity,
  AlertTriangle,
  BarChart3,
  Brain,
  Building2,
  ClipboardList,
  CreditCard,
  Database,
  FileText,
  Landmark,
  Network,
  Receipt,
  Server,
  ShieldAlert,
  ShieldCheck,
  TrendingUp,
  Users,
  Wallet,
  Zap,
};

const colors = ["var(--chart-1)", "var(--chart-2)", "var(--chart-3)", "var(--chart-4)", "var(--chart-5)"];

type TaxCenterConfig = {
  title: string;
  badge: string;
  description: string;
  primaryAction: string;
  secondaryAction: string;
  mainSeries: string;
  mainChartTitle: string;
  mainChartDescription: string;
  tableTitle: string;
  tableDescription: string;
  primaryRecordKey: string;
  secondaryTitle: string;
  secondaryRecordKey: string;
  modulesTitle: string;
  graphTitle: string;
  graphDescription: string;
  modules: string[][];
};

const scopeConfig = {
  merchant: {
    title: "B2B Tax Management Center",
    badge: "Business Account Tax Intelligence",
    description:
      "Manage sales invoices, purchase invoices, input VAT, output VAT, EWT, supplier compliance, and reconciliation exceptions.",
    primaryAction: "Run Auto Match",
    secondaryAction: "Export VAT Pack",
    mainSeries: "vat_trend",
    mainChartTitle: "Input VAT vs Output VAT",
    mainChartDescription: "Monthly VAT liability, available credits, and remittance forecast in PHP millions.",
    tableTitle: "Invoice Matching Engine",
    tableDescription: "Seller invoices are reconciled against buyer purchase records and VAT claims.",
    primaryRecordKey: "invoice_matches",
    secondaryTitle: "Supplier Compliance Monitoring",
    secondaryRecordKey: "supplier_compliance",
    modulesTitle: "Business Account B2B Tax Modules",
    graphTitle: "Supplier and Buyer Relationship Map",
    graphDescription: "Business counterparties linked by invoice, VAT, and EWT validation signals.",
    modules: [
      ["Output VAT Management", "Real-time output VAT tracking, VAT liability monitoring, remittance forecast, sales ledger, and tax calendar."],
      ["Input VAT Wallet", "Input VAT ledger, supplier VAT tracking, tax credit validation, utilization reports, and claim history."],
      ["Invoice Matching Engine", "Auto match seller invoices against buyer purchase records with mismatch, duplicate, and missing invoice detection."],
      ["Purchase Ledger", "Purchase tracking, expense categorization, supplier monitoring, purchase analytics, and deduction reports."],
      ["Sales Ledger", "Sales tracking, revenue analytics, customer analytics, tax liability reports, and invoice aging."],
      ["EWT and Form 2307", "Automatic EWT computation, certificates, supplier withholding tracking, and remittance status."],
      ["Supplier Risk Scoring", "AI compliance scoring from filing history, invoice accuracy, VAT reconciliation, EWT compliance, and receipt validity."],
    ],
  },
  bir: {
    title: "National Tax Intelligence Dashboard",
    badge: "BIR Regulator View",
    description:
      "Monitor nationwide B2B transactions, VAT reconciliation, EWT collections, taxpayer risk, invoice matching, and AI audit recommendations.",
    primaryAction: "Open Audit Case",
    secondaryAction: "Export Intelligence Report",
    mainSeries: "national_tax_trend",
    mainChartTitle: "National VAT and EWT Collections",
    mainChartDescription: "B2B transaction volume, VAT, EWT, and fraud alert trend by period.",
    tableTitle: "National Invoice Matching System",
    tableDescription: "Matched, pending, disputed, and high-risk transaction pairs by business account, region, industry, and TIN.",
    primaryRecordKey: "invoice_matches",
    secondaryTitle: "Tax Risk Scoring Engine",
    secondaryRecordKey: "tax_risks",
    modulesTitle: "BIR Tax Intelligence Modules",
    graphTitle: "B2B Network Visualization",
    graphDescription: "Supplier networks, customer networks, revenue chains, circular flows, and invoice factory signals.",
    modules: [
      ["VAT Reconciliation Monitoring", "Cross-validates seller output VAT against buyer input VAT and highlights variance patterns."],
      ["Tax Intelligence Engine", "AI detection for tax evasion, circular transactions, ghost suppliers, fake invoices, and shell companies."],
      ["National Invoice Matching", "Matched, pending, disputed, and high-risk transaction queues with business account, industry, region, TIN, and date filters."],
      ["B2B Network Visualization", "Relationship maps for supplier networks, customer networks, revenue chains, and transaction flows."],
      ["Tax Risk Scoring", "Risk levels from VAT mismatches, EWT irregularities, late filings, high variances, and compliance history."],
      ["AI Audit Assistant", "Automated investigations, audit recommendations, fraud alerts, and audit prioritization."],
    ],
  },
  "super-admin": {
    title: "National Tax Operations Center",
    badge: "Super Admin Control Plane",
    description:
      "Operate the national B2B reconciliation, tax intelligence, fraud model, graph database, and compliance rule infrastructure.",
    primaryAction: "Deploy Rule Update",
    secondaryAction: "Export Operations Pack",
    mainSeries: "tax_trend",
    mainChartTitle: "Nationwide Tax Intelligence Throughput",
    mainChartDescription: "VAT processed, EWT processed, and tax collection forecast in PHP billions.",
    tableTitle: "B2B Reconciliation Monitoring",
    tableDescription: "Matched, pending, failed, and suspicious transaction queues across business accounts, buyers, suppliers, and invoices.",
    primaryRecordKey: "reconciliation_monitor",
    secondaryTitle: "Tax Intelligence Management",
    secondaryRecordKey: "rule_management",
    modulesTitle: "Super Admin Tax Operations Modules",
    graphTitle: "Tax Graph Database",
    graphDescription: "Relationship intelligence across business accounts, buyers, suppliers, invoices, receipts, filings, EWT records, and VAT records.",
    modules: [
      ["Rule Configuration", "Configure fraud rules, compliance rules, risk thresholds, VAT rules, EWT rules, and audit triggers."],
      ["AI Fraud Detection Management", "Manage fraud models, risk models, tax scoring models, and compliance algorithms."],
      ["Reconciliation Monitoring", "Drill into matched, pending, failed, and suspicious transactions by business account, buyer, supplier, invoice, region, and industry."],
      ["National Tax Analytics", "VAT trends, industry performance, regional collections, revenue forecasting, compliance forecasting, and tax collection forecasts."],
      ["Graph Database Engine", "Supply chain mapping, fraud detection, network analysis, relationship intelligence, and audit investigation support."],
      ["Scalable Controls", "Operate nationwide tax compliance, B2B reconciliation, revenue intelligence, and electronic receipt infrastructure."],
    ],
  },
} satisfies Record<TaxScope, TaxCenterConfig>;

const birModuleConfig: Record<string, Partial<TaxCenterConfig>> = {
  "tax-intelligence": {},
  "vat-reconciliation": {
    title: "VAT Reconciliation Center",
    badge: "VAT Reconciliation",
    description:
      "Review seller output VAT, buyer input VAT, EWT, and reconciliation variances across BIR-monitored business accounts.",
    primaryAction: "Run VAT Review",
    secondaryAction: "Export VAT Findings",
    mainChartTitle: "VAT and EWT Reconciliation Trend",
    mainChartDescription: "VAT collections, EWT movement, and flagged reconciliation variance by period.",
    tableTitle: "VAT Reconciliation Ledger",
    tableDescription: "Seller and buyer transaction pairs with VAT, EWT, status, risk, and reconciliation score.",
    secondaryTitle: "VAT Risk Exceptions",
    modulesTitle: "VAT Reconciliation Controls",
    modules: [
      ["Output VAT Validation", "Cross-check seller output VAT against transaction ledgers and posted electronic receipts."],
      ["Input VAT Validation", "Match buyer input VAT claims against seller-issued invoices and receipt references."],
      ["Variance Detection", "Highlight missing, duplicated, disputed, or materially mismatched VAT records."],
    ],
  },
  "invoice-matching": {
    title: "National Invoice Matching Center",
    badge: "Invoice Matching",
    description:
      "Match seller invoices against buyer purchase records, VAT claims, EWT records, and receipt references for BIR review.",
    primaryAction: "Run Match Review",
    secondaryAction: "Export Match Queue",
    mainChartTitle: "Invoice Match Throughput",
    mainChartDescription: "Matched, pending, disputed, and high-risk invoice activity by period.",
    tableTitle: "Invoice Matching Queue",
    tableDescription: "Invoice pairs grouped by business account, counterparty, amount, VAT, EWT, status, and risk score.",
    secondaryTitle: "Linked Tax Risk Signals",
    modulesTitle: "Invoice Matching Controls",
    graphTitle: "Invoice Relationship Map",
    graphDescription: "Seller-to-buyer invoice relationships, mismatches, and transaction-flow risk signals.",
    modules: [
      ["Matched Invoices", "Validated seller and buyer records with aligned VAT, EWT, amount, and receipt references."],
      ["Pending Review", "Invoice pairs requiring BIR or system review because supporting records are incomplete."],
      ["Mismatch Detection", "Detects duplicated invoices, missing buyer records, VAT variances, and suspicious counterparties."],
    ],
  },
  network: {
    title: "B2B Network Intelligence",
    badge: "B2B Network",
    description:
      "Map supplier, customer, and business-account transaction relationships to detect circular flows and unusual revenue chains.",
    primaryAction: "Open Network Case",
    secondaryAction: "Export Network Map",
    mainChartTitle: "B2B Transaction Network Trend",
    mainChartDescription: "Network volume, VAT movement, EWT movement, and flagged relationship activity by period.",
    tableTitle: "Network Transaction Pairs",
    tableDescription: "Business-to-business transaction pairs with amount, VAT, EWT, relationship status, and risk score.",
    secondaryTitle: "Network Risk Signals",
    modulesTitle: "B2B Network Controls",
    graphTitle: "B2B Relationship Map",
    graphDescription: "Supplier and customer relationship edges with volume, relationship type, and risk classification.",
    modules: [
      ["Supplier Network", "Shows supplier relationships, high-volume counterparties, and unusual dependency patterns."],
      ["Customer Network", "Shows buyer relationships, revenue chains, and repeated counterparties."],
      ["Circular Flow Detection", "Flags relationship loops, invoice factories, and suspicious transaction chains."],
    ],
  },
  "risk-scoring": {
    title: "Taxpayer Risk Scoring Center",
    badge: "Risk Scoring",
    description:
      "Prioritize taxpayers using VAT mismatches, EWT irregularities, late filings, suspicious counterparties, and compliance history.",
    primaryAction: "Review High Risk",
    secondaryAction: "Export Risk Scores",
    mainChartTitle: "Tax Risk Trend",
    mainChartDescription: "Risk movement, fraud alerts, VAT exposure, and EWT exposure by period.",
    tableTitle: "Risk-Scored Taxpayer Queue",
    tableDescription: "Taxpayers and counterparties ranked by risk level, exposure, status, and intelligence score.",
    primaryRecordKey: "tax_risks",
    secondaryTitle: "Invoice Evidence Linked to Risk",
    secondaryRecordKey: "invoice_matches",
    modulesTitle: "Risk Scoring Controls",
    modules: [
      ["VAT Risk", "Ranks taxpayers with recurring VAT variance, unsupported claims, or unusual filing movement."],
      ["Counterparty Risk", "Scores business relationships using transaction concentration and mismatch patterns."],
      ["Audit Priority", "Surfaces high-impact taxpayers and cases for BIR review and audit planning."],
    ],
  },
  "ai-audit": {
    title: "AI Audit Assistant",
    badge: "AI Audit",
    description:
      "Use AI-assisted tax intelligence to surface audit leads, summarize risk evidence, and prioritize BIR investigation queues.",
    primaryAction: "Generate Audit Lead",
    secondaryAction: "Export Audit Pack",
    mainChartTitle: "AI Audit Signal Trend",
    mainChartDescription: "Audit signal movement across VAT, EWT, invoice matching, and network risk indicators.",
    tableTitle: "AI Audit Lead Queue",
    tableDescription: "Prioritized taxpayers, counterparties, invoices, and risk findings recommended for BIR review.",
    primaryRecordKey: "tax_risks",
    secondaryTitle: "Supporting Invoice Matches",
    secondaryRecordKey: "invoice_matches",
    modulesTitle: "AI Audit Capabilities",
    modules: [
      ["Audit Lead Generation", "Creates review leads from VAT, EWT, matching, and network anomalies."],
      ["Evidence Summary", "Summarizes transaction evidence, risk reasons, and linked counterparties for reviewers."],
      ["Case Prioritization", "Ranks audit candidates by revenue exposure, confidence score, and urgency."],
    ],
  },
};

function resolveConfig(scope: TaxScope, pathname: string): TaxCenterConfig {
  const base = scopeConfig[scope];
  if (scope !== "bir") return base;

  const routeKey = pathname.split("/").filter(Boolean).at(-1) ?? "tax-intelligence";
  const override = birModuleConfig[routeKey] ?? birModuleConfig["tax-intelligence"];

  return {
    ...base,
    ...override,
    modules: override.modules ?? base.modules,
  };
}

function formatAmount(value: number) {
  if (Math.abs(value) >= 1_000_000_000) return `PHP ${(value / 1_000_000_000).toFixed(1)}B`;
  if (Math.abs(value) >= 1_000_000) return `PHP ${(value / 1_000_000).toFixed(1)}M`;
  if (Math.abs(value) >= 1_000) return `PHP ${(value / 1_000).toFixed(1)}K`;
  return `PHP ${value.toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function statusClass(value: string | null | undefined) {
  const status = String(value ?? "").toLowerCase();
  if (["low", "green", "matched", "compliant", "issued", "active", "healthy", "good", "posted", "deductible"].some((key) => status.includes(key))) {
    return "border-success/20 bg-success/10 text-success";
  }
  if (["critical", "red", "flagged", "high", "suspicious"].some((key) => status.includes(key))) {
    return "border-destructive/20 bg-destructive/10 text-destructive";
  }
  if (["medium", "orange", "yellow", "pending", "review", "mismatch", "monitor"].some((key) => status.includes(key))) {
    return "border-warning/20 bg-warning/10 text-warning";
  }
  return "border-border bg-secondary text-muted-foreground";
}

function KpiCard({ item }: { item: TaxKpi }) {
  const Icon = iconMap[item.icon as keyof typeof iconMap] ?? Activity;

  return (
    <Card className={item.accent ?? ""}>
      <CardContent className="p-4">
        <div className="flex items-start justify-between gap-3">
          <span className="text-xs font-medium text-muted-foreground">{item.label}</span>
          <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
        </div>
        <p className="mt-2 text-xl font-bold tabular-nums text-foreground">{item.value}</p>
        <p className="mt-1 text-[10px] leading-snug text-muted-foreground">{item.subtext}</p>
      </CardContent>
    </Card>
  );
}

function RecordsTable({ title, description, rows }: { title: string; description: string; rows: TaxRecord[] }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
          <div>
            <CardTitle className="text-sm">{title}</CardTitle>
            <p className="text-xs text-muted-foreground">{description}</p>
          </div>
          <div className="relative w-full md:w-72">
            <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <Input className="h-9 pl-8 text-xs" placeholder="Search TIN, invoice, supplier..." />
          </div>
        </div>
      </CardHeader>
      <CardContent>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[860px]">
            <thead>
              <tr className="border-b">
                <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Reference</th>
                <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Party</th>
                <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Counterparty</th>
                <th className="pb-3 text-right text-xs font-medium text-muted-foreground">Amount</th>
                <th className="pb-3 text-right text-xs font-medium text-muted-foreground">VAT</th>
                <th className="pb-3 text-right text-xs font-medium text-muted-foreground">EWT/CWT</th>
                <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Status</th>
                <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Risk</th>
                <th className="pb-3 text-right text-xs font-medium text-muted-foreground">Score</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.reference} className="border-b last:border-0">
                  <td className="py-3">
                    <p className="text-xs font-semibold text-foreground">{row.reference}</p>
                    <p className="text-[10px] text-muted-foreground">{String(row.metadata.category ?? row.metadata.region ?? row.metadata.rule_type ?? "")}</p>
                  </td>
                  <td className="py-3 text-xs text-foreground">{row.party_name}</td>
                  <td className="py-3 text-xs text-muted-foreground">{row.counterparty_name ?? "National control"}</td>
                  <td className="py-3 text-right text-xs font-medium text-foreground">{formatAmount(row.amount)}</td>
                  <td className="py-3 text-right text-xs text-foreground">{formatAmount(row.vat_amount)}</td>
                  <td className="py-3 text-right text-xs text-foreground">{row.withholding_amount===null?"Not verified":formatAmount(row.withholding_amount)}</td>
                  <td className="py-3">
                    <Badge variant="outline" className={`text-[10px] ${statusClass(row.status)}`}>{row.status}</Badge>
                  </td>
                  <td className="py-3">
                    <Badge variant="outline" className={`text-[10px] ${statusClass(row.risk_level)}`}>{row.risk_level ?? "Normal"}</Badge>
                  </td>
                  <td className="py-3 text-right">
                    <div className="ml-auto flex w-24 items-center justify-end gap-2">
                      <Progress value={row.score ?? 0} className="h-1.5 w-14" />
                      <span className="text-xs font-bold text-foreground">{row.score ?? "-"}</span>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </CardContent>
    </Card>
  );
}

function SecondaryRecords({ title, rows }: { title: string; rows: TaxRecord[] }) {
  return (
    <Card>
      <CardHeader className="pb-3">
        <CardTitle className="text-sm">{title}</CardTitle>
        <p className="text-xs text-muted-foreground">Compliance, risk, model, and remittance controls from the tax intelligence database.</p>
      </CardHeader>
      <CardContent className="space-y-3">
        {rows.map((row) => (
          <div key={row.reference} className="rounded-lg border p-3">
            <div className="flex items-start justify-between gap-3">
              <div>
                <p className="text-sm font-semibold text-foreground">{row.party_name}</p>
                <p className="mt-0.5 text-xs text-muted-foreground">{row.counterparty_name ?? row.reference}</p>
              </div>
              <Badge variant="outline" className={`text-[10px] ${statusClass(row.risk_level ?? row.status)}`}>
                {row.risk_level ?? row.status}
              </Badge>
            </div>
            <div className="mt-3 grid gap-2 text-xs sm:grid-cols-3">
              <div className="rounded-md bg-secondary/40 p-2">
                <p className="text-[10px] uppercase text-muted-foreground">Amount</p>
                <p className="font-semibold text-foreground">{formatAmount(row.amount)}</p>
              </div>
              <div className="rounded-md bg-secondary/40 p-2">
                <p className="text-[10px] uppercase text-muted-foreground">VAT</p>
                <p className="font-semibold text-foreground">{formatAmount(row.vat_amount)}</p>
              </div>
              <div className="rounded-md bg-secondary/40 p-2">
                <p className="text-[10px] uppercase text-muted-foreground">EWT/CWT</p>
                <p className="font-semibold text-foreground">{row.withholding_amount===null?"Not verified":formatAmount(row.withholding_amount)}</p>
              </div>
            </div>
            <div className="mt-3 flex items-center gap-2">
              <Progress value={row.score ?? 0} className="h-1.5" />
              <span className="w-8 text-right text-xs font-bold text-foreground">{row.score ?? "-"}</span>
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function GraphPanel({ scope }: { scope: TaxScope }) {
  const { data } = useTaxIntelligenceData(scope);
  const cfg = scopeConfig[scope];

  return (
    <Card>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <div>
            <CardTitle className="text-sm">{cfg.graphTitle}</CardTitle>
            <p className="text-xs text-muted-foreground">{cfg.graphDescription}</p>
          </div>
          <Network className="h-4 w-4 text-muted-foreground" />
        </div>
      </CardHeader>
      <CardContent className="grid gap-3 md:grid-cols-2">
        {data.graph.map((edge, index) => (
          <div key={`${edge.source}-${edge.target}-${edge.relationship}`} className="rounded-lg border p-3">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-md bg-primary/10 text-primary">
                <GitBranch className="h-4 w-4" />
              </div>
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-foreground">{edge.source}</p>
                <p className="truncate text-xs text-muted-foreground">{edge.target}</p>
              </div>
              <Badge variant="outline" className={`text-[10px] ${statusClass(edge.risk_level)}`}>{edge.risk_level ?? "Normal"}</Badge>
            </div>
            <Separator className="my-3" />
            <div className="flex items-center justify-between gap-3 text-xs">
              <span className="text-muted-foreground">{edge.relationship}</span>
              <span className="font-semibold text-foreground">{formatAmount(edge.volume)}</span>
            </div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-secondary">
              <div className="h-full rounded-full" style={{ width: `${Math.max(12, 100 - index * 13)}%`, backgroundColor: colors[index % colors.length] }} />
            </div>
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function TaxIntelligenceCenter({ scope }: { scope: TaxScope }) {
  const location = useLocation();
  const cfg = resolveConfig(scope, location.pathname);
  const { data, loading, error } = useTaxIntelligenceData(scope);
  const mainSeries = data.series[cfg.mainSeries] ?? [];
  const matchStatus = data.series.match_status ?? data.series.risk_distribution ?? data.series.model_performance ?? [];
  const primaryRows = data.records[cfg.primaryRecordKey] ?? [];
  const secondaryRows = data.records[cfg.secondaryRecordKey] ?? [];

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-foreground">{cfg.title}</h1>
            <Badge variant="secondary" className="gap-1.5 text-xs">
              <Brain className="h-3 w-3" /> {cfg.badge}
            </Badge>
          </div>
          <p className="mt-1 max-w-5xl text-sm text-muted-foreground">{cfg.description}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button disabled title="Requires a verified reporting workflow" variant="outline" size="sm" className="gap-2 text-xs">
            <FileText className="h-3.5 w-3.5" /> {cfg.secondaryAction}
          </Button>
          <Button disabled title="Requires verified workflow and model evidence" size="sm" className="gap-2 text-xs">
            <Zap className="h-3.5 w-3.5" /> {cfg.primaryAction}
          </Button>
        </div>
      </div>

      {data.notice&&<p role="status" className="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">{data.notice}</p>}
      {loading && (
        <Card>
          <CardContent className="p-4 text-sm text-muted-foreground">Loading tax intelligence data from MySQL...</CardContent>
        </Card>
      )}

      {error && (
        <Card className="border-destructive/30">
          <CardContent className="p-4 text-sm text-destructive">{error}</CardContent>
        </Card>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-6">
        {data.kpis.map((item) => <KpiCard key={item.key} item={item} />)}
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-sm">{cfg.mainChartTitle}</CardTitle>
                <p className="text-xs text-muted-foreground">{cfg.mainChartDescription}</p>
              </div>
              <Badge variant="outline" className="text-xs">Real-time ledger</Badge>
            </div>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={280}>
              <AreaChart data={mainSeries}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Area type="monotone" dataKey={scope === "super-admin" ? "vat" : scope === "bir" ? "vat" : "output_vat"} name="VAT / Output VAT" stroke="var(--chart-1)" fill="var(--chart-1)" fillOpacity={0.14} strokeWidth={2} />
                <Area type="monotone" dataKey={scope === "super-admin" ? "ewt" : scope === "bir" ? "ewt" : "input_vat"} name="EWT / Input VAT" stroke="var(--chart-2)" fill="var(--chart-2)" fillOpacity={0.18} strokeWidth={2} />
                <Area type="monotone" dataKey={scope === "super-admin" ? "forecast" : scope === "bir" ? "b2b" : "vat_payable"} name="Forecast / B2B / Payable" stroke="var(--chart-3)" fill="var(--chart-3)" fillOpacity={0.12} strokeWidth={2} />
              </AreaChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Reconciliation and Risk Mix</CardTitle>
            <p className="text-xs text-muted-foreground">Current matching, risk, or model performance distribution.</p>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={205}>
              <PieChart>
                <Pie data={matchStatus} dataKey={scope === "bir" ? "value" : scope === "super-admin" ? "accuracy" : "value"} nameKey="label" innerRadius={48} outerRadius={76} paddingAngle={3}>
                  {matchStatus.map((entry, index) => (
                    <Cell key={entry.label} fill={colors[index % colors.length]} />
                  ))}
                </Pie>
                <Tooltip />
              </PieChart>
            </ResponsiveContainer>
            <div className="space-y-2">
              {matchStatus.map((item, index) => (
                <div key={item.label} className="flex items-center justify-between text-xs">
                  <span className="flex items-center gap-2 text-muted-foreground">
                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: colors[index % colors.length] }} />
                    {item.label}
                  </span>
                  <span className="font-semibold text-foreground">{String(item.value ?? item.accuracy ?? item.taxpayers ?? "-")}</span>
                </div>
              ))}
            </div>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-2 xl:grid-cols-3">
        {cfg.modules.map(([title, description], index) => (
          <Card key={title}>
            <CardContent className="p-4">
              <div className="flex items-start gap-3">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                  {index % 3 === 0 ? <Receipt className="h-4 w-4" /> : index % 3 === 1 ? <ShieldCheck className="h-4 w-4" /> : <Brain className="h-4 w-4" />}
                </div>
                <div>
                  <p className="text-sm font-semibold text-foreground">{title}</p>
                  <p className="mt-1 text-xs leading-relaxed text-muted-foreground">{description}</p>
                </div>
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      <RecordsTable title={cfg.tableTitle} description={cfg.tableDescription} rows={primaryRows} />

      <div className="grid gap-4 xl:grid-cols-2">
        <SecondaryRecords title={cfg.secondaryTitle} rows={secondaryRows} />
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Tax Analytics Snapshot</CardTitle>
            <p className="text-xs text-muted-foreground">VAT, EWT, compliance, and reconciliation analytics by period.</p>
          </CardHeader>
          <CardContent>
            <ResponsiveContainer width="100%" height={260}>
              <BarChart data={mainSeries}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                <Tooltip />
                <Bar dataKey={scope === "merchant" ? "pending_remittance" : scope === "bir" ? "fraud_alerts" : "forecast"} name="Risk / Forecast" fill="var(--chart-4)" radius={[4, 4, 0, 0]} />
                <Bar dataKey={scope === "merchant" ? "vat_payable" : scope === "bir" ? "ewt" : "ewt"} name="EWT / Payable" fill="var(--chart-2)" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </CardContent>
        </Card>
      </div>

      <GraphPanel scope={scope} />
    </div>
  );
}

export function MerchantB2BTaxCenter() {
  return <TaxIntelligenceCenter scope="merchant" />;
}

export function BirTaxIntelligenceDashboard() {
  return <TaxIntelligenceCenter scope="bir" />;
}

export function SuperAdminTaxOperationsCenter() {
  return <TaxIntelligenceCenter scope="super-admin" />;
}
