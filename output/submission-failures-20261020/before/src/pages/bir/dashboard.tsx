import {
  Activity, AlertTriangle, Building2, Download,
  FileText, Filter, Globe, Landmark, Receipt, RefreshCw, Search, ShieldCheck, TrendingUp, UserCheck,
} from "lucide-react";
import { useMemo, useState } from "react";
import {
  Area, AreaChart, Bar, BarChart, CartesianGrid, Line, LineChart,
  ResponsiveContainer, Tooltip, XAxis, YAxis,
} from "recharts";
import { Link } from "react-router-dom";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { cn } from "@/lib/utils";
import { useDashboardData, type DashboardListItem } from "@/lib/dashboard-data";

const iconMap = {
  Activity,
  AlertTriangle,
  Building2,
  Landmark,
  Receipt,
  ShieldCheck,
  TrendingUp,
};

const AUTO_REFRESH_MS = 5000;
const birCommandModules = [
  { title: "RDO Setup", subtitle: "Office registration and jurisdiction coverage", href: "/bir/rdo-registration", icon: Landmark },
  { title: "VAT Reconciliation", subtitle: "Output VAT, input VAT, and variance monitoring", href: "/bir/vat-reconciliation", icon: ShieldCheck },
  { title: "Invoice Matching", subtitle: "Seller and buyer invoice matching queue", href: "/bir/invoice-matching", icon: Receipt },
  { title: "B2B Network", subtitle: "Supplier and customer relationship review", href: "/bir/network", icon: Globe },
  { title: "Risk Scoring", subtitle: "Taxpayer risk prioritization and exposure", href: "/bir/risk-scoring", icon: AlertTriangle },
  { title: "AI Audit", subtitle: "Audit leads, evidence summary, and recommendations", href: "/bir/ai-audit", icon: TrendingUp },
  { title: "Forecasts", subtitle: "Revenue pace and projected collection movement", href: "/bir/forecasts", icon: Activity },
];

function initialsFor(value?: string | null) {
  return (value || "BA")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase() || "BA";
}

function metaText(item: DashboardListItem, key: string, fallback = "") {
  const value = item.metadata[key];
  return value === null || value === undefined ? fallback : String(value);
}

function numberValue(value: unknown) {
  const numeric = Number(value ?? 0);
  return Number.isFinite(numeric) ? numeric : 0;
}

function peso(value: number) {
  return new Intl.NumberFormat("en-PH", {
    style: "currency",
    currency: "PHP",
    maximumFractionDigits: 0,
  }).format(value);
}

export function BirDashboard() {
  const [regionFilter, setRegionFilter] = useState("all");
  const [rdoFilter, setRdoFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState("all");
  const [transactionSearch, setTransactionSearch] = useState("");
  const { data, loading, refreshing, error, reload, lastUpdatedAt } = useDashboardData("bir", {
    refreshIntervalMs: AUTO_REFRESH_MS,
  });
  const collectionTrend = data.series.collection_trend ?? [];
  const revenueByRegion = data.series.revenue_by_region ?? [];
  const jurisdictionFilters = data.lists.jurisdiction_filters ?? [];
  const transactionWatchlist = data.lists.transaction_watchlist ?? [];
  const rdoCoverage = data.lists.rdo_coverage ?? [];
  const merchantCompliance = data.lists.merchant_compliance ?? [];
  const auditQueue = data.lists.audit_queue ?? [];
  const reports = data.lists.reports ?? [];
  const receiptVerification = data.lists.receipt_verification?.[0];
  const forecastCards = data.lists.forecast_cards ?? [];
  const liveReportCount = reports.reduce((total, report) => total + Number(report.metadata.record_count ?? 0), 0);
  const lastUpdatedLabel = lastUpdatedAt
    ? new Intl.DateTimeFormat("en-PH", { hour: "2-digit", minute: "2-digit", second: "2-digit" }).format(new Date(lastUpdatedAt))
    : "Waiting";
  const regionOptions = jurisdictionFilters.filter((item) => metaText(item, "type") === "region");
  const rdoOptions = jurisdictionFilters.filter((item) => metaText(item, "type") === "rdo");
  const statusOptions = jurisdictionFilters.filter((item) => metaText(item, "type") === "status");
  const filteredTransactions = useMemo(() => {
    const query = transactionSearch.trim().toLowerCase();

    return transactionWatchlist.filter((item) => {
      const region = metaText(item, "region", "Unclassified");
      const rdoCode = metaText(item, "rdo_code", "Unassigned");
      const status = metaText(item, "status_value", item.status ?? "").toLowerCase();
      const searchable = [
        item.title,
        item.subtitle,
        item.status,
        metaText(item, "merchant"),
        metaText(item, "merchant_tin"),
        metaText(item, "customer"),
        metaText(item, "customer_tin"),
        region,
        rdoCode,
        metaText(item, "rdo_name"),
      ].join(" ").toLowerCase();

      return (regionFilter === "all" || region === regionFilter)
        && (rdoFilter === "all" || rdoCode === rdoFilter)
        && (statusFilter === "all" || status === statusFilter.toLowerCase())
        && (!query || searchable.includes(query));
    });
  }, [rdoFilter, regionFilter, statusFilter, transactionSearch, transactionWatchlist]);
  const filteredRevenue = filteredTransactions.reduce((total, item) => total + numberValue(item.primary_value), 0);
  const filteredVat = filteredTransactions.reduce((total, item) => total + numberValue(item.secondary_value), 0);
  const filteredComplianceCount = merchantCompliance.filter((item) => {
    const region = metaText(item, "region", "");
    const rdoCode = metaText(item, "rdo_code", "");

    return (regionFilter === "all" || region === regionFilter)
      && (rdoFilter === "all" || rdoCode === rdoFilter);
  }).length;

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-foreground">BIR Revenue and Compliance Dashboard</h1>
            <Badge variant="secondary" className="gap-1.5 text-xs">
              <Landmark className="h-3 w-3" /> Authorized Regulator View
            </Badge>
            <Badge variant="outline" className="gap-1.5 text-xs">
              <RefreshCw className={cn("h-3 w-3", refreshing && "animate-spin")} />
              Auto-sync {Math.round(AUTO_REFRESH_MS / 1000)}s
            </Badge>
          </div>
          <p className="mt-1 text-sm text-muted-foreground">
            National and regional monitoring for electronic official receipts, business account compliance, tax collections, and audit investigation.
          </p>
          <p className="mt-1 text-xs text-muted-foreground">
            Last database sync: {lastUpdatedLabel}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" size="sm" className="gap-2 text-xs" onClick={reload} disabled={loading || refreshing}>
            <RefreshCw className={cn("h-3.5 w-3.5", (loading || refreshing) && "animate-spin")} /> Sync Now
          </Button>
          <Button asChild variant="outline" size="sm" className="gap-2 text-xs">
            <Link to="/bir/rdo-registration">
              <Landmark className="h-3.5 w-3.5" /> Register RDO
            </Link>
          </Button>
          <Button asChild variant="outline" size="sm" className="gap-2 text-xs">
            <Link to="/bir/citizen-approval">
              <UserCheck className="h-3.5 w-3.5" /> Citizen Approval
            </Link>
          </Button>
          <Button variant="outline" size="sm" className="gap-2 text-xs">
            <Download className="h-3.5 w-3.5" /> Export Report
          </Button>
          <Button size="sm" className="gap-2 text-xs">
            <FileText className="h-3.5 w-3.5" /> Open Audit Case
          </Button>
        </div>
      </div>

      {loading && (
        <Card>
          <CardContent className="p-4 text-sm text-muted-foreground">Loading dashboard data from MySQL...</CardContent>
        </Card>
      )}

      {error && (
        <Card className="border-destructive/30">
          <CardContent className="p-4 text-sm text-destructive">{error}</CardContent>
        </Card>
      )}

      <Card>
        <CardHeader className="pb-3">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <CardTitle className="flex items-center gap-2 text-sm">
                <Filter className="h-4 w-4 text-muted-foreground" /> BIR Regulator Filters
              </CardTitle>
              <p className="text-xs text-muted-foreground">
                Scope dashboard transactions, compliance rows, and RDO coverage by jurisdiction.
              </p>
            </div>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
              <Select value={regionFilter} onValueChange={setRegionFilter}>
                <SelectTrigger className="h-9 w-full text-xs lg:w-44">
                  <SelectValue placeholder="All regions" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Regions</SelectItem>
                  {regionOptions.map((item) => (
                    <SelectItem key={metaText(item, "value")} value={metaText(item, "value")}>
                      {metaText(item, "display", item.title)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={rdoFilter} onValueChange={setRdoFilter}>
                <SelectTrigger className="h-9 w-full text-xs lg:w-48">
                  <SelectValue placeholder="All RDOs" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All RDOs</SelectItem>
                  {rdoOptions.map((item) => (
                    <SelectItem key={metaText(item, "value")} value={metaText(item, "value")}>
                      {metaText(item, "display", item.title)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={statusFilter} onValueChange={setStatusFilter}>
                <SelectTrigger className="h-9 w-full text-xs lg:w-40">
                  <SelectValue placeholder="All statuses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  {statusOptions.map((item) => (
                    <SelectItem key={metaText(item, "value")} value={metaText(item, "value")}>
                      {metaText(item, "display", item.title)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <div className="relative">
                <Search className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                  value={transactionSearch}
                  onChange={(event) => setTransactionSearch(event.target.value)}
                  className="h-9 pl-8 text-xs"
                  placeholder="Search TIN, receipt, merchant"
                />
              </div>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <div className="rounded-lg border bg-secondary/30 p-3">
              <p className="text-[10px] uppercase text-muted-foreground">Filtered Transactions</p>
              <p className="mt-1 text-lg font-bold text-foreground">{filteredTransactions.length.toLocaleString("en-PH")}</p>
            </div>
            <div className="rounded-lg border bg-secondary/30 p-3">
              <p className="text-[10px] uppercase text-muted-foreground">Filtered Revenue</p>
              <p className="mt-1 text-lg font-bold text-foreground">{peso(filteredRevenue)}</p>
            </div>
            <div className="rounded-lg border bg-secondary/30 p-3">
              <p className="text-[10px] uppercase text-muted-foreground">Filtered VAT</p>
              <p className="mt-1 text-lg font-bold text-foreground">{peso(filteredVat)}</p>
            </div>
            <div className="rounded-lg border bg-secondary/30 p-3">
              <p className="text-[10px] uppercase text-muted-foreground">Compliance Rows In Scope</p>
              <p className="mt-1 text-lg font-bold text-foreground">{filteredComplianceCount.toLocaleString("en-PH")}</p>
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4 2xl:grid-cols-7">
        {birCommandModules.map((module) => {
          const Icon = module.icon;

          return (
            <Link
              key={module.href}
              to={module.href}
              className="group rounded-lg border bg-card p-3 shadow-sm transition-colors hover:border-primary/40 hover:bg-secondary/40"
            >
              <div className="flex items-start gap-3">
                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-secondary text-muted-foreground group-hover:text-primary">
                  <Icon className="h-4 w-4" />
                </div>
                <div className="min-w-0">
                  <p className="truncate text-xs font-semibold text-foreground">{module.title}</p>
                  <p className="mt-1 line-clamp-2 text-[10px] leading-snug text-muted-foreground">{module.subtitle}</p>
                </div>
              </div>
            </Link>
          );
        })}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        {data.kpis.map((item) => {
          const Icon = iconMap[item.icon as keyof typeof iconMap] ?? Activity;
          return (
            <Card key={item.key}>
              <CardContent className="p-4">
                <div className="flex items-center justify-between">
                  <span className="text-xs text-muted-foreground">{item.label}</span>
                  <Icon className="h-4 w-4 text-muted-foreground" />
                </div>
                <p className="mt-2 text-xl font-bold tabular-nums text-foreground">{item.value}</p>
                <p className="mt-1 text-[10px] text-muted-foreground">{item.subtext}</p>
              </CardContent>
            </Card>
          );
        })}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-sm">Collection Trends</CardTitle>
                <p className="text-xs text-muted-foreground">Live revenue, VAT, and receipt volume from the database, shown in PHP thousands.</p>
              </div>
              <Badge variant="outline" className="text-xs">Real-time tracking</Badge>
            </div>
          </CardHeader>
          <CardContent>
            {collectionTrend.length === 0 ? (
              <div className="flex h-[260px] items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
                No live transaction trend data available yet.
              </div>
            ) : (
              <ResponsiveContainer width="100%" height={260}>
                <AreaChart data={collectionTrend}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <Tooltip />
                  <Area type="monotone" dataKey="revenue" name="Revenue" stroke="var(--chart-1)" fill="var(--chart-1)" fillOpacity={0.14} strokeWidth={2} />
                  <Area type="monotone" dataKey="tax" name="VAT" stroke="var(--chart-2)" fill="var(--chart-2)" fillOpacity={0.18} strokeWidth={2} />
                  <Area type="monotone" dataKey="receipts" name="Receipts" stroke="var(--chart-3)" fill="var(--chart-3)" fillOpacity={0.12} strokeWidth={2} />
                </AreaChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Receipt Verification</CardTitle>
            <p className="text-xs text-muted-foreground">Latest verification event from the live verification ledger.</p>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="relative">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                className="pl-9"
                value={String(receiptVerification?.metadata.receipt_number ?? "")}
                placeholder="No verification records yet"
                readOnly
              />
            </div>
            {receiptVerification ? (
              <div className="rounded-lg border bg-secondary/40 p-4">
                <div className="flex items-center gap-3">
                  <div className="flex h-10 w-10 items-center justify-center rounded-md bg-success/10">
                    <ShieldCheck className="h-5 w-5 text-success" />
                  </div>
                  <div>
                    <p className="text-sm font-semibold text-foreground">{receiptVerification.title}</p>
                    <p className="text-xs text-muted-foreground">{receiptVerification.subtitle}</p>
                  </div>
                </div>
                <Separator className="my-3" />
                <div className="grid grid-cols-2 gap-2 text-xs">
                  <span className="text-muted-foreground">Business Account</span><span className="text-right font-medium text-foreground">{receiptVerification.metadata.merchant}</span>
                  <span className="text-muted-foreground">Status</span><span className="text-right font-medium text-success">{receiptVerification.status}</span>
                  <span className="text-muted-foreground">Method</span><span className="text-right font-medium text-foreground">{receiptVerification.metadata.method}</span>
                </div>
              </div>
            ) : (
              <div className="rounded-lg border border-dashed p-4 text-xs text-muted-foreground">
                No live receipt verification records are available yet.
              </div>
            )}
            <Button variant="outline" className="w-full gap-2 text-xs">
              <Globe className="h-3.5 w-3.5" /> Open Public Verification Portal
            </Button>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Regional Revenue Overview</CardTitle>
            <p className="text-xs text-muted-foreground">Live regional revenue and VAT by recorded transaction region.</p>
          </CardHeader>
          <CardContent>
            {revenueByRegion.length === 0 ? (
              <div className="flex h-[240px] items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
                No live regional revenue data available yet.
              </div>
            ) : (
              <>
                <ResponsiveContainer width="100%" height={240}>
                  <BarChart data={revenueByRegion}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                    <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                    <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                    <Tooltip />
                    <Bar dataKey="revenue" name="Revenue" fill="var(--chart-1)" radius={[4, 4, 0, 0]} />
                    <Bar dataKey="tax" name="VAT" fill="var(--chart-2)" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
                <div className="mt-3 grid gap-2 sm:grid-cols-2">
                  {revenueByRegion.slice(0, 4).map((region) => (
                    <div key={region.label} className="rounded-lg border p-3">
                      <div className="flex items-center justify-between text-xs">
                        <span className="font-medium text-foreground">{region.label}</span>
                        <span className="text-success">{region.compliance}%</span>
                      </div>
                      <Progress value={Number(region.compliance ?? 0)} className="mt-2 h-1.5" />
                    </div>
                  ))}
                </div>
              </>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Collection Pace</CardTitle>
            <p className="text-xs text-muted-foreground">Month-over-month revenue and VAT derived from the live transaction ledger.</p>
          </CardHeader>
          <CardContent>
            {collectionTrend.length === 0 ? (
              <div className="flex h-[190px] items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
                No live collection pace data available yet.
              </div>
            ) : (
              <ResponsiveContainer width="100%" height={190}>
                <LineChart data={collectionTrend}>
                  <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
                  <XAxis dataKey="label" tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <YAxis tick={{ fontSize: 11 }} stroke="var(--muted-foreground)" />
                  <Tooltip />
                  <Line type="monotone" dataKey="revenue" name="Revenue" stroke="var(--chart-1)" strokeWidth={2} />
                  <Line type="monotone" dataKey="tax" name="VAT" stroke="var(--chart-2)" strokeWidth={2} strokeDasharray="5 5" />
                </LineChart>
              </ResponsiveContainer>
            )}
            {forecastCards.length > 0 ? (
              <div className="mt-4 grid gap-3 sm:grid-cols-3">
                {forecastCards.map((item) => (
                  <div key={item.title} className="rounded-lg border p-3 text-center">
                    <p className="text-lg font-bold text-foreground">{item.metadata.display ?? item.primary_value}</p>
                    <p className="text-[10px] text-muted-foreground">{item.title}</p>
                    {typeof item.metadata.trend === "string" && item.metadata.trend && (
                      <p className="mt-1 text-[10px] text-muted-foreground">{item.metadata.trend}</p>
                    )}
                  </div>
                ))}
              </div>
            ) : (
              <div className="mt-4 rounded-lg border border-dashed p-3 text-center text-xs text-muted-foreground">
                No live month-end pace metrics are available yet.
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader className="pb-3">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <CardTitle className="text-sm">BIR Transaction Watchlist</CardTitle>
                <p className="text-xs text-muted-foreground">Latest live ledger rows filtered by Region, RDO, transaction status, TIN, receipt, or business account.</p>
              </div>
              <Badge variant="outline" className="w-fit text-xs">{filteredTransactions.length} shown</Badge>
            </div>
          </CardHeader>
          <CardContent>
            {filteredTransactions.length === 0 ? (
              <div className="flex h-[220px] items-center justify-center rounded-lg border border-dashed text-sm text-muted-foreground">
                No transaction rows match the current BIR filters.
              </div>
            ) : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[980px]">
                  <thead>
                    <tr className="border-b">
                      <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Reference</th>
                      <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Business / Buyer</th>
                      <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Jurisdiction</th>
                      <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Channel</th>
                      <th className="pb-3 text-right text-xs font-medium text-muted-foreground">Amount</th>
                      <th className="pb-3 text-right text-xs font-medium text-muted-foreground">VAT</th>
                      <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Status</th>
                    </tr>
                  </thead>
                  <tbody>
                    {filteredTransactions.slice(0, 10).map((item) => (
                      <tr key={`${item.title}-${metaText(item, "created_at")}`} className="border-b last:border-0">
                        <td className="py-3">
                          <p className="text-xs font-semibold text-foreground">{item.title}</p>
                          <p className="text-[10px] text-muted-foreground">{metaText(item, "transaction_type")} · {metaText(item, "document_type", "Sales document")}</p>
                        </td>
                        <td className="py-3">
                          <p className="max-w-52 truncate text-xs font-medium text-foreground">{metaText(item, "merchant", item.subtitle ?? "")}</p>
                          <p className="text-[10px] text-muted-foreground">
                            {metaText(item, "customer_tin") || metaText(item, "merchant_tin") || "No taxpayer TIN"}
                          </p>
                        </td>
                        <td className="py-3">
                          <p className="text-xs text-foreground">{metaText(item, "region", "Unclassified")}</p>
                          <p className="text-[10px] text-muted-foreground">{metaText(item, "rdo_name", "Unassigned RDO")}</p>
                        </td>
                        <td className="py-3">
                          <p className="text-xs text-foreground">{metaText(item, "channel", "Channel")}</p>
                          <p className="text-[10px] text-muted-foreground">{metaText(item, "payment_method", "Payment method")}</p>
                        </td>
                        <td className="py-3 text-right text-xs font-semibold text-foreground">{metaText(item, "amount_display", peso(numberValue(item.primary_value)))}</td>
                        <td className="py-3 text-right text-xs text-foreground">{metaText(item, "vat_display", peso(numberValue(item.secondary_value)))}</td>
                        <td className="py-3">
                          <Badge variant="outline" className="text-[10px]">{item.status}</Badge>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">RDO Coverage</CardTitle>
            <p className="text-xs text-muted-foreground">Registered RDO offices and live transaction volume mapped to each office.</p>
          </CardHeader>
          <CardContent className="space-y-3">
            {rdoCoverage.length === 0 ? (
              <div className="rounded-lg border border-dashed p-4 text-xs text-muted-foreground">
                No RDO office coverage records are available yet.
              </div>
            ) : (
              rdoCoverage.map((rdo) => (
                <div key={rdo.title} className="rounded-lg border p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <p className="text-xs font-semibold text-foreground">{rdo.title}</p>
                      <p className="truncate text-[10px] text-muted-foreground">{rdo.subtitle}</p>
                    </div>
                    <Badge variant="secondary" className="text-[10px]">{rdo.status}</Badge>
                  </div>
                  <div className="mt-3 grid grid-cols-2 gap-2 text-xs">
                    <div className="rounded-md bg-secondary/40 p-2">
                      <p className="text-[10px] uppercase text-muted-foreground">Transactions</p>
                      <p className="font-semibold text-foreground">{numberValue(rdo.primary_value).toLocaleString("en-PH")}</p>
                    </div>
                    <div className="rounded-md bg-secondary/40 p-2">
                      <p className="text-[10px] uppercase text-muted-foreground">Revenue</p>
                      <p className="font-semibold text-foreground">{metaText(rdo, "amount_display", peso(numberValue(rdo.secondary_value)))}</p>
                    </div>
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-3">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Business Account Compliance Scoring</CardTitle>
            <p className="text-xs text-muted-foreground">EOR compliance, filing status, and audit readiness</p>
          </CardHeader>
          <CardContent className="space-y-3">
            {merchantCompliance.length === 0 ? (
              <div className="rounded-lg border border-dashed p-4 text-xs text-muted-foreground">
                No live merchant compliance records are available yet.
              </div>
            ) : (
              merchantCompliance.map((merchant) => (
                <div key={merchant.title} className="rounded-lg border p-3">
                  <div className="flex items-center justify-between gap-3">
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar className="h-9 w-9 shrink-0 rounded-lg border bg-secondary">
                        {typeof merchant.metadata.logo_url === "string" && merchant.metadata.logo_url && (
                          <AvatarImage src={merchant.metadata.logo_url} alt={`${merchant.title} logo`} className="object-cover" />
                        )}
                        <AvatarFallback className="rounded-lg bg-secondary text-[10px] font-bold">
                          {initialsFor(merchant.title)}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0">
                        <p className="truncate text-xs font-medium text-foreground">{merchant.title}</p>
                        <p className="truncate text-[10px] text-muted-foreground">{merchant.subtitle}</p>
                      </div>
                    </div>
                    <span className="text-sm font-bold text-foreground">{merchant.primary_value}</span>
                  </div>
                  <Progress value={merchant.primary_value ?? 0} className="mt-2 h-1.5" />
                </div>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Audit Queue</CardTitle>
            <p className="text-xs text-muted-foreground">Investigation, validation, and exception reporting</p>
          </CardHeader>
          <CardContent className="space-y-3">
            {auditQueue.length === 0 ? (
              <div className="rounded-lg border border-dashed p-4 text-xs text-muted-foreground">
                No live audit or exception queue items are open right now.
              </div>
            ) : (
              auditQueue.map((audit) => (
                <div key={audit.title} className="rounded-lg border p-3">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <p className="font-mono text-[10px] text-muted-foreground">{audit.title}</p>
                      <p className="mt-1 text-xs font-medium text-foreground">{audit.subtitle}</p>
                      <p className="text-[10px] text-muted-foreground">{audit.metadata.scope}</p>
                    </div>
                    <Badge variant={audit.status === "High" || audit.status === "Critical" ? "destructive" : "secondary"} className="text-[10px]">{audit.status}</Badge>
                  </div>
                </div>
              ))
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Report Center</CardTitle>
            <p className="text-xs text-muted-foreground">Live export targets derived from the current database tables.</p>
          </CardHeader>
          <CardContent>
            {reports.length === 0 ? (
              <div className="rounded-lg border border-dashed p-4 text-xs text-muted-foreground">
                No live BIR export sources are available yet.
              </div>
            ) : (
              <div className="grid grid-cols-2 gap-2">
                {reports.map((report) => (
                  <Button key={report.title} variant="outline" size="sm" className="justify-start gap-2 text-xs">
                    <FileText className="h-3.5 w-3.5" /> {report.title}
                  </Button>
                ))}
              </div>
            )}
            <Separator className="my-4" />
            <div className="rounded-lg border bg-secondary/40 p-3">
              <p className="flex items-center gap-2 text-xs font-medium text-foreground">
                <Activity className="h-3.5 w-3.5 text-success" /> {reports.length} live report source{reports.length === 1 ? "" : "s"} available
              </p>
              <p className="mt-1 text-[10px] text-muted-foreground">
                {liveReportCount > 0
                  ? `${liveReportCount.toLocaleString("en-PH")} total records are currently represented across these export sources.`
                  : "These report actions will stay empty until the underlying tables receive live records."}
              </p>
            </div>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}
