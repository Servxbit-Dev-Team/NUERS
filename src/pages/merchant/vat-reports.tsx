import { useCallback, useEffect, useMemo, useState } from "react";
import {
  CheckCircle2, TrendingUp, Zap, ArrowUpRight,
  Download, RefreshCw, Bot, ShieldCheck,
  Activity, Hash, ChevronLeft, ChevronRight,
  Layers, BadgeCheck, Info, Sparkles, CircleDot,
  AlertTriangle,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Separator } from "@/components/ui/separator";
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import {
  ChartContainer, ChartTooltip, ChartTooltipContent, ChartLegend, ChartLegendContent,
} from "@/components/ui/chart";
import type { ChartConfig } from "@/components/ui/chart";
import {
  BarChart, Bar, XAxis, YAxis, CartesianGrid,
  AreaChart, Area, LineChart, Line,
} from "recharts";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";

const API_TOKEN_KEY = "nuers_api_token";
const PAGE_SIZE = 10;

type TaxMonth = {
  month: string;
  month_number: number;
  gross: number;
  vatable_sales: number;
  vat_output: number;
  vat_input: number;
  vat_payable: number;
  vat_receivable: number;
  tx_count: number;
  sales_document_count: number;
  expense_document_count: number;
  effective_rate: number;
};

type TaxLedgerRecord = {
  id: string;
  source: string;
  tx_ref: string;
  date: string;
  time: string;
  channel: string;
  branch: string;
  customer: string;
  gross: number;
  vat_output: number;
  input_credit: number;
  net_vat: number;
  quarter: string;
  tax_type: string;
  status: string;
};

type TaxCenterPayload = {
  merchant: {
    id: string;
    business_name: string;
    tin: string;
    vat_registered: boolean;
  } | null;
  year: number;
  available_years: number[];
  last_sync: string | null;
  summary: {
    total_gross_sales: number;
    vat_output: number;
    input_vat_credit: number;
    net_vat_payable: number;
    vat_receivable: number;
    tax_records_generated: number;
    sales_document_count: number;
    expense_document_count: number;
    effective_tax_rate: number;
  };
  monthly: TaxMonth[];
  ledger: TaxLedgerRecord[];
};

const emptyPayload: TaxCenterPayload = {
  merchant: null,
  year: new Date().getFullYear(),
  available_years: [new Date().getFullYear()],
  last_sync: null,
  summary: {
    total_gross_sales: 0,
    vat_output: 0,
    input_vat_credit: 0,
    net_vat_payable: 0,
    vat_receivable: 0,
    tax_records_generated: 0,
    sales_document_count: 0,
    expense_document_count: 0,
    effective_tax_rate: 0,
  },
  monthly: Array.from({ length: 12 }, (_, index) => {
    const date = new Date(new Date().getFullYear(), index, 1);
    return {
      month: date.toLocaleString("en-PH", { month: "short" }),
      month_number: index + 1,
      gross: 0,
      vatable_sales: 0,
      vat_output: 0,
      vat_input: 0,
      vat_payable: 0,
      vat_receivable: 0,
      tx_count: 0,
      sales_document_count: 0,
      expense_document_count: 0,
      effective_rate: 0,
    };
  }),
  ledger: [],
};

function authHeaders() {
  const headers = new Headers({
    Accept: "application/json",
    "Content-Type": "application/json",
  });

  const token = window.localStorage.getItem(API_TOKEN_KEY);
  if (token) headers.set("Authorization", `Bearer ${token}`);

  return headers;
}

function fmt(n: number) {
  const sign = n < 0 ? "-" : "";
  const abs = Math.abs(n);

  if (abs >= 1_000_000) return `${sign}₱${(abs / 1_000_000).toFixed(2)}M`;
  if (abs >= 1_000) return `${sign}₱${(abs / 1_000).toFixed(0)}K`;
  return `${sign}₱${abs.toLocaleString("en-PH", { maximumFractionDigits: 2 })}`;
}

function fullPeso(n: number) {
  const sign = n < 0 ? "-" : "";
  const abs = Math.abs(n);

  return `${sign}₱${abs.toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function formatDateTime(value: string | null) {
  if (!value) {
    return { time: "No sync yet", date: "Waiting for database records" };
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return { time: "No sync yet", date: "Waiting for database records" };
  }

  return {
    time: date.toLocaleTimeString("en-PH", { hour: "2-digit", minute: "2-digit" }),
    date: date.toLocaleDateString("en-PH"),
  };
}

function exportCsv(data: TaxCenterPayload) {
  const rows = [
    ["Month", "Sales Documents", "Input VAT Documents", "Gross Sales", "Vatable Sales", "VAT Output", "Input VAT Credit", "Net VAT Payable", "VAT Receivable", "Effective Rate"],
    ...data.monthly.map((row) => [
      `${row.month} ${data.year}`,
      row.sales_document_count,
      row.expense_document_count,
      row.gross,
      row.vatable_sales,
      row.vat_output,
      row.vat_input,
      row.vat_payable,
      row.vat_receivable,
      `${row.effective_rate}%`,
    ]),
    [],
    ["Tax Ledger"],
    ["Record ID", "Source", "Document Ref", "Date", "Time", "Channel", "Counterparty", "Gross", "VAT Output", "Input Credit", "Net VAT", "Tax Type", "Status"],
    ...data.ledger.map((row) => [
      row.id,
      row.source,
      row.tx_ref,
      row.date,
      row.time,
      row.channel,
      row.customer,
      row.gross,
      row.vat_output,
      row.input_credit,
      row.net_vat,
      row.tax_type,
      row.status,
    ]),
  ];

  const csv = rows.map((row) => row.map((cell) => `"${String(cell ?? "").replaceAll('"', '""')}"`).join(",")).join("\n");
  const blob = new Blob([csv], { type: "text/csv;charset=utf-8" });
  const url = URL.createObjectURL(blob);
  const link = document.createElement("a");
  link.href = url;
  link.download = `nuers-tax-center-${data.merchant?.tin || "business"}-${data.year}.csv`;
  link.click();
  URL.revokeObjectURL(url);
  toast.success("Database-backed tax report exported.");
}

const vatChartConfig: ChartConfig = {
  vat_output: { label: "VAT Output", color: "var(--chart-1)" },
  vat_input: { label: "Input Credit", color: "var(--chart-2)" },
  vat_payable: { label: "Net Payable", color: "var(--chart-3)" },
};

const grossChartConfig: ChartConfig = {
  gross: { label: "Gross Sales", color: "var(--chart-1)" },
};

const txChartConfig: ChartConfig = {
  tx_count: { label: "Tax Records", color: "var(--chart-4)" },
};

const effConfig: ChartConfig = {
  effective_rate: { label: "Effective Rate (%)", color: "var(--chart-4)" },
};

const channelColors: Record<string, string> = {
  POS: "bg-primary/10 text-primary border-primary/20",
  "E Commerce": "bg-chart-2/10 text-chart-2 border-chart-2/20",
  "E-Commerce": "bg-chart-2/10 text-chart-2 border-chart-2/20",
  Mobile: "bg-chart-3/10 text-chart-3 border-chart-3/20",
  API: "bg-chart-4/10 text-chart-4 border-chart-4/20",
  Invoice: "bg-warning/10 text-warning border-warning/20",
  Expense: "bg-success/10 text-success border-success/20",
};

export function MerchantVatReports() {
  const [year, setYear] = useState(String(new Date().getFullYear()));
  const [page, setPage] = useState(1);
  const [detailRecord, setDetailRecord] = useState<TaxLedgerRecord | null>(null);
  const [data, setData] = useState<TaxCenterPayload>(emptyPayload);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setError(null);

    try {
      const response = await apiFetch(`/api/merchant/tax-center?year=${encodeURIComponent(year)}`, {
        headers: authHeaders(),
        cache: "no-store",
      });
      const payload = await readJsonResponse<TaxCenterPayload>(response, "Unable to load database tax center records.");

      setData(payload);
    } catch (err) {
      setData({ ...emptyPayload, year: Number(year) || new Date().getFullYear() });
      setError(err instanceof Error ? err.message : "Unable to load database tax center records.");
    } finally {
      setLoading(false);
    }
  }, [year]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    const timer = window.setInterval(load, 30000);
    return () => window.clearInterval(timer);
  }, [load]);

  useEffect(() => {
    setPage(1);
  }, [data.ledger.length, year]);

  const totalPages = Math.max(1, Math.ceil(data.ledger.length / PAGE_SIZE));
  const paginated = data.ledger.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
  const lastSync = formatDateTime(data.last_sync);
  const availableYears = useMemo(() => {
    const years = new Set([Number(year), ...data.available_years]);
    return Array.from(years).filter(Boolean).sort((a, b) => b - a);
  }, [data.available_years, year]);

  const hasRecords = data.summary.tax_records_generated > 0;
  const totalInput = data.summary.input_vat_credit;

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <div className="flex items-center gap-2.5 mb-1">
            <h1 className="text-2xl font-bold text-foreground tracking-tight">Tax Center</h1>
            <span className="inline-flex items-center gap-1.5 rounded-full bg-success/10 border border-success/20 px-2.5 py-0.5 text-[11px] font-semibold text-success">
              <span className="h-1.5 w-1.5 rounded-full bg-success animate-pulse" />
              Database-Computed
            </span>
          </div>
          <p className="text-sm text-muted-foreground">
            VAT output, input credits, and tax position are computed from this logged-in Business Account only.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Select value={year} onValueChange={setYear}>
            <SelectTrigger className="w-24 h-9 text-xs"><SelectValue /></SelectTrigger>
            <SelectContent>
              {availableYears.map((item) => (
                <SelectItem key={item} value={String(item)}>{item}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button variant="outline" size="sm" className="gap-2 h-9" onClick={() => exportCsv(data)} disabled={loading || !hasRecords}>
            <Download className="h-3.5 w-3.5" /> Export
          </Button>
          <Button variant="outline" size="sm" className="gap-2 h-9" onClick={load} disabled={loading}>
            <RefreshCw className={cn("h-3.5 w-3.5", loading && "animate-spin")} /> Refresh
          </Button>
        </div>
      </div>

      {error && (
        <Card className="border-destructive/30 bg-destructive/5">
          <CardContent className="flex items-center gap-3 p-4">
            <AlertTriangle className="h-4 w-4 text-destructive" />
            <p className="text-sm font-medium text-destructive">{error}</p>
          </CardContent>
        </Card>
      )}

      <Card className="border-primary/20 bg-gradient-to-r from-primary/5 to-transparent overflow-hidden relative">
        <div className="absolute right-0 top-0 bottom-0 w-32 bg-gradient-to-l from-primary/5 to-transparent pointer-events-none" />
        <CardContent className="p-5">
          <div className="flex items-start gap-4">
            <div className="rounded-xl bg-primary/10 p-3 shrink-0">
              <Bot className="h-6 w-6 text-primary" />
            </div>
            <div className="flex-1 min-w-0">
              <p className="text-sm font-semibold text-foreground mb-1">Digital Tax Ledger</p>
              <p className="text-xs text-muted-foreground leading-relaxed">
                Sales transactions and posted invoices create Output VAT. Validated B2B expense documents create Input VAT credits. No seeded or cross-account data is included.
              </p>
              <div className="flex flex-wrap gap-4 mt-3">
                {[
                  { icon: Zap, label: "Sales and invoices are scoped by account" },
                  { icon: ShieldCheck, label: "BIR-style VAT computation" },
                  { icon: BadgeCheck, label: "Input credits require validation" },
                  { icon: Sparkles, label: "Auto-refreshes every 30 seconds" },
                ].map((f) => (
                  <div key={f.label} className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <f.icon className="h-3.5 w-3.5 text-primary shrink-0" />
                    {f.label}
                  </div>
                ))}
              </div>
            </div>
            <div className="text-right shrink-0 hidden md:block">
              <p className="text-[10px] text-muted-foreground uppercase tracking-wider font-medium">Last DB sync</p>
              <p className="text-xs font-semibold text-foreground mt-0.5">{lastSync.time}</p>
              <p className="text-[10px] text-muted-foreground">{lastSync.date}</p>
            </div>
          </div>
        </CardContent>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        {[
          {
            label: "Total Gross Sales", value: fmt(data.summary.total_gross_sales), sub: `${year} YTD · posted sales docs`,
            icon: TrendingUp, accent: "border-l-chart-1", iconBg: "bg-chart-1/10", iconColor: "text-chart-1",
          },
          {
            label: "VAT Output", value: fmt(data.summary.vat_output), sub: "From posted sales and invoices",
            icon: Hash, accent: "border-l-chart-2", iconBg: "bg-chart-2/10", iconColor: "text-chart-2",
          },
          {
            label: "Input VAT Credit", value: fmt(totalInput), sub: "Validated B2B expenses only",
            icon: ArrowUpRight, accent: "border-l-chart-3", iconBg: "bg-chart-3/10", iconColor: "text-chart-3",
          },
          {
            label: "Net VAT Payable", value: fmt(data.summary.net_vat_payable), sub: `${data.summary.effective_tax_rate.toFixed(2)}% effective rate`,
            icon: Activity, accent: "border-l-primary", iconBg: "bg-primary/10", iconColor: "text-primary",
          },
          {
            label: "Tax Records", value: data.summary.tax_records_generated.toLocaleString(), sub: `${data.summary.sales_document_count} sales · ${data.summary.expense_document_count} expenses`,
            icon: Layers, accent: "border-l-success", iconBg: "bg-success/10", iconColor: "text-success",
          },
        ].map((s) => (
          <Card key={s.label} className={cn("border-l-4", s.accent)}>
            <CardContent className="p-4">
              <div className="flex items-start justify-between mb-3">
                <div className={cn("rounded-lg p-2", s.iconBg)}>
                  <s.icon className={cn("h-4 w-4", s.iconColor)} />
                </div>
                {loading && <RefreshCw className="h-3.5 w-3.5 text-muted-foreground animate-spin" />}
              </div>
              <p className="text-xl font-extrabold text-foreground tracking-tight tabular-nums">{s.value}</p>
              <p className="text-xs font-medium text-muted-foreground mt-0.5">{s.label}</p>
              <p className="text-[10px] text-muted-foreground/70 mt-0.5">{s.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Monthly VAT Breakdown</CardTitle>
            <p className="text-xs text-muted-foreground">Output VAT vs validated input VAT credits</p>
          </CardHeader>
          <CardContent>
            <ChartContainer config={vatChartConfig} className="min-h-[220px] w-full">
              <BarChart data={data.monthly} accessibilityLayer>
                <CartesianGrid vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fontSize: 11 }} />
                <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 10 }} tickFormatter={(v) => `₱${(Number(v) / 1000).toFixed(0)}K`} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <ChartLegend content={<ChartLegendContent />} />
                <Bar dataKey="vat_output" fill="var(--color-vat_output)" radius={[3, 3, 0, 0]} />
                <Bar dataKey="vat_input" fill="var(--color-vat_input)" radius={[3, 3, 0, 0]} />
                <Bar dataKey="vat_payable" fill="var(--color-vat_payable)" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ChartContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Gross Sales Trend</CardTitle>
            <p className="text-xs text-muted-foreground">Posted taxable sales documents from the database</p>
          </CardHeader>
          <CardContent>
            <ChartContainer config={grossChartConfig} className="min-h-[220px] w-full">
              <AreaChart data={data.monthly} accessibilityLayer>
                <defs>
                  <linearGradient id="grossGrad" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor="var(--chart-1)" stopOpacity={0.3} />
                    <stop offset="100%" stopColor="var(--chart-1)" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fontSize: 11 }} />
                <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 10 }} tickFormatter={(v) => `₱${(Number(v) / 1_000_000).toFixed(1)}M`} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Area type="monotone" dataKey="gross" stroke="var(--chart-1)" strokeWidth={2} fill="url(#grossGrad)" />
              </AreaChart>
            </ChartContainer>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Effective Tax Rate</CardTitle>
            <p className="text-xs text-muted-foreground">Net VAT payable as % of gross sales</p>
          </CardHeader>
          <CardContent>
            <ChartContainer config={effConfig} className="min-h-[140px] w-full">
              <LineChart data={data.monthly} accessibilityLayer>
                <CartesianGrid vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fontSize: 10 }} />
                <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 10 }} tickFormatter={(v) => `${v}%`} domain={[0, "auto"]} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Line type="monotone" dataKey="effective_rate" stroke="var(--color-effective_rate)" strokeWidth={2.5} dot={{ r: 4, fill: "var(--chart-4)" }} />
              </LineChart>
            </ChartContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Tax Records Generated</CardTitle>
            <p className="text-xs text-muted-foreground">Sales documents plus validated B2B expenses</p>
          </CardHeader>
          <CardContent>
            <ChartContainer config={txChartConfig} className="min-h-[140px] w-full">
              <BarChart data={data.monthly} accessibilityLayer>
                <CartesianGrid vertical={false} stroke="var(--border)" />
                <XAxis dataKey="month" tickLine={false} axisLine={false} tick={{ fontSize: 10 }} />
                <YAxis tickLine={false} axisLine={false} tick={{ fontSize: 10 }} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Bar dataKey="tx_count" fill="var(--color-tx_count)" radius={[3, 3, 0, 0]} />
              </BarChart>
            </ChartContainer>
          </CardContent>
        </Card>

        <Card className="bg-gradient-to-br from-primary/5 to-transparent border-primary/20">
          <CardHeader className="pb-3">
            <div className="flex items-center gap-2">
              <ShieldCheck className="h-4 w-4 text-primary" />
              <CardTitle className="text-sm">YTD Tax Position</CardTitle>
            </div>
          </CardHeader>
          <CardContent className="space-y-3">
            {[
              { label: "Gross Sales", value: fmt(data.summary.total_gross_sales), color: "text-foreground" },
              { label: "VAT Output", value: fmt(data.summary.vat_output), color: "text-foreground" },
              { label: "Input Credits", value: `-${fmt(totalInput)}`, color: "text-success" },
            ].map((row) => (
              <div key={row.label} className="flex justify-between text-sm">
                <span className="text-muted-foreground">{row.label}</span>
                <span className={cn("font-semibold tabular-nums", row.color)}>{row.value}</span>
              </div>
            ))}
            <Separator />
            <div className="flex justify-between items-baseline">
              <span className="text-sm font-bold text-foreground">Net VAT Payable</span>
              <span className="text-2xl font-extrabold text-foreground tabular-nums">{fmt(data.summary.net_vat_payable)}</span>
            </div>
            {data.summary.vat_receivable > 0 && (
              <div className="flex justify-between items-baseline">
                <span className="text-sm font-bold text-success">VAT Receivable</span>
                <span className="text-lg font-extrabold text-success tabular-nums">{fmt(data.summary.vat_receivable)}</span>
              </div>
            )}
            <div className={cn(
              "rounded-lg border p-3 flex items-center gap-2",
              hasRecords ? "bg-success/10 border-success/20" : "bg-muted/30 border-border",
            )}>
              <CheckCircle2 className={cn("h-4 w-4 shrink-0", hasRecords ? "text-success" : "text-muted-foreground")} />
              <p className={cn("text-xs font-medium", hasRecords ? "text-success" : "text-muted-foreground")}>
                {hasRecords ? "Tax position is generated from live database records." : "No posted tax records for this Business Account and year."}
              </p>
            </div>
          </CardContent>
        </Card>
      </div>

      <Card>
        <CardHeader className="pb-0">
          <div className="flex items-center justify-between flex-wrap gap-2 p-0">
            <div>
              <CardTitle className="text-sm">Monthly Tax Computation</CardTitle>
              <p className="text-xs text-muted-foreground mt-0.5">Generated from account-scoped sales transactions, posted invoices, and validated B2B expenses</p>
            </div>
            <Badge variant="secondary" className="gap-1 text-xs">
              <CircleDot className="h-3 w-3 text-success" />
              Database-backed
            </Badge>
          </div>
        </CardHeader>
        <CardContent className="pt-4">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b bg-muted/30">
                  {["Month", "Sales Docs", "Expense Docs", "Gross Sales", "VAT Output", "Input Credits", "Net VAT Payable", "Eff. Rate", "Status"].map((h) => (
                    <th key={h} className="px-3 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border/50">
                {data.monthly.map((d) => (
                  <tr key={d.month_number} className="hover:bg-muted/20 transition-colors">
                    <td className="px-3 py-3.5 text-xs font-semibold text-foreground">{d.month} {year}</td>
                    <td className="px-3 py-3.5 text-xs text-muted-foreground tabular-nums">{d.sales_document_count.toLocaleString()}</td>
                    <td className="px-3 py-3.5 text-xs text-muted-foreground tabular-nums">{d.expense_document_count.toLocaleString()}</td>
                    <td className="px-3 py-3.5 text-xs font-medium text-foreground tabular-nums">{fmt(d.gross)}</td>
                    <td className="px-3 py-3.5 text-xs text-foreground tabular-nums">{fmt(d.vat_output)}</td>
                    <td className="px-3 py-3.5 text-xs text-success tabular-nums">-{fmt(d.vat_input)}</td>
                    <td className="px-3 py-3.5 text-xs font-bold text-foreground tabular-nums">{fmt(d.vat_payable)}</td>
                    <td className="px-3 py-3.5 text-xs text-muted-foreground tabular-nums">{d.effective_rate.toFixed(2)}%</td>
                    <td className="px-3 py-3.5">
                      <span className={cn(
                        "inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border",
                        d.tx_count > 0
                          ? "bg-success/10 text-success border-success/20"
                          : "bg-muted/40 text-muted-foreground border-border",
                      )}>
                        <CheckCircle2 className="h-2.5 w-2.5" /> {d.tx_count > 0 ? "Computed" : "No Records"}
                      </span>
                    </td>
                  </tr>
                ))}
                <tr className="border-t-2 border-border bg-muted/40">
                  <td className="px-3 py-3.5 text-xs font-bold text-foreground">TOTAL {year}</td>
                  <td className="px-3 py-3.5 text-xs font-bold text-foreground tabular-nums">{data.summary.sales_document_count.toLocaleString()}</td>
                  <td className="px-3 py-3.5 text-xs font-bold text-foreground tabular-nums">{data.summary.expense_document_count.toLocaleString()}</td>
                  <td className="px-3 py-3.5 text-xs font-bold text-foreground tabular-nums">{fmt(data.summary.total_gross_sales)}</td>
                  <td className="px-3 py-3.5 text-xs font-bold text-foreground tabular-nums">{fmt(data.summary.vat_output)}</td>
                  <td className="px-3 py-3.5 text-xs font-bold text-success tabular-nums">-{fmt(totalInput)}</td>
                  <td className="px-3 py-3.5 text-xs font-bold text-foreground tabular-nums">{fmt(data.summary.net_vat_payable)}</td>
                  <td className="px-3 py-3.5 text-xs font-bold text-foreground tabular-nums">{data.summary.effective_tax_rate.toFixed(2)}%</td>
                  <td />
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="pb-0 border-b">
          <div className="flex items-center justify-between flex-wrap gap-2 pb-4">
            <div>
              <div className="flex items-center gap-2">
                <CardTitle className="text-sm">Live Tax Ledger</CardTitle>
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-primary/10 text-primary border border-primary/20">
                  <span className="h-1.5 w-1.5 rounded-full bg-primary animate-pulse" />
                  Scoped
                </span>
              </div>
              <p className="text-xs text-muted-foreground mt-0.5">Latest taxable sales documents and claimable input VAT records for this Business Account</p>
            </div>
            <Button variant="outline" size="sm" className="gap-2 text-xs h-8" onClick={() => exportCsv(data)} disabled={!hasRecords}>
              <Download className="h-3.5 w-3.5" /> Export Ledger
            </Button>
          </div>
        </CardHeader>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b bg-muted/30">
                  {["Tax Record ID", "Source", "Document", "Date & Time", "Channel", "Gross", "VAT Output", "Input Credit", "Net VAT", "Status"].map((h) => (
                    <th key={h} className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-border/50">
                {paginated.map((r) => (
                  <tr
                    key={r.id}
                    className="group hover:bg-muted/20 transition-colors cursor-pointer"
                    onClick={() => setDetailRecord(r)}
                  >
                    <td className="px-4 py-3.5">
                      <span className="font-mono text-xs font-semibold text-foreground">{r.id}</span>
                    </td>
                    <td className="px-4 py-3.5">
                      <p className="text-xs font-semibold text-foreground">{r.source}</p>
                      <p className="text-[10px] text-muted-foreground">{r.tax_type}</p>
                    </td>
                    <td className="px-4 py-3.5">
                      <span className="font-mono text-[11px] text-muted-foreground">{r.tx_ref}</span>
                    </td>
                    <td className="px-4 py-3.5">
                      <p className="text-xs text-foreground font-medium">{r.date}</p>
                      <p className="text-[10px] text-muted-foreground">{r.time}</p>
                    </td>
                    <td className="px-4 py-3.5">
                      <span className={cn(
                        "inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold border",
                        channelColors[r.channel] ?? "bg-muted text-muted-foreground border-border",
                      )}>
                        {r.channel}
                      </span>
                    </td>
                    <td className="px-4 py-3.5 text-xs font-semibold text-foreground tabular-nums">{fullPeso(r.gross)}</td>
                    <td className="px-4 py-3.5 text-xs text-foreground tabular-nums">{fullPeso(r.vat_output)}</td>
                    <td className="px-4 py-3.5 text-xs text-success tabular-nums">-{fullPeso(r.input_credit)}</td>
                    <td className={cn("px-4 py-3.5 text-xs font-bold tabular-nums", r.net_vat < 0 ? "text-success" : "text-foreground")}>{fullPeso(r.net_vat)}</td>
                    <td className="px-4 py-3.5">
                      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-success/10 text-success border border-success/20">
                        <Zap className="h-2.5 w-2.5" /> {r.status}
                      </span>
                    </td>
                  </tr>
                ))}
                {paginated.length === 0 && (
                  <tr>
                    <td colSpan={10} className="px-4 py-14 text-center">
                      <div className="mx-auto max-w-sm">
                        <Layers className="mx-auto h-8 w-8 text-muted-foreground/60" />
                        <p className="mt-3 text-sm font-semibold text-foreground">No tax records for this account yet</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                          Posted sales transactions, sent invoices, and validated B2B expenses will appear here automatically.
                        </p>
                      </div>
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>

          <div data-slot="table-pagination" className="flex items-center justify-between px-4 py-3 border-t bg-muted/20">
            <p className="text-xs text-muted-foreground">
              Showing <strong className="text-foreground">{data.ledger.length === 0 ? 0 : (page - 1) * PAGE_SIZE + 1}–{Math.min(page * PAGE_SIZE, data.ledger.length)}</strong> of <strong className="text-foreground">{data.ledger.length}</strong> records
            </p>
            <div className="flex items-center gap-1">
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1}>
                <ChevronLeft className="h-3.5 w-3.5" />
              </Button>
              <div className="flex items-center gap-1 px-2">
                <span className="text-xs font-semibold text-foreground">{page}</span>
                <span className="text-xs text-muted-foreground">/</span>
                <span className="text-xs text-muted-foreground">{totalPages}</span>
              </div>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page === totalPages || data.ledger.length === 0}>
                <ChevronRight className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <Dialog open={!!detailRecord} onOpenChange={() => setDetailRecord(null)}>
        <DialogContent className="max-w-md">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2 text-sm">
              <div className="rounded-lg bg-success/10 p-1.5">
                <Zap className="h-4 w-4 text-success" />
              </div>
              Database Tax Record
            </DialogTitle>
            <DialogDescription>
              Detailed VAT computation for the selected database-backed tax record.
            </DialogDescription>
          </DialogHeader>
          {detailRecord && (
            <div className="space-y-4">
              <div className="rounded-lg bg-muted/40 p-4 space-y-2">
                <div className="flex justify-between gap-4 text-xs">
                  <span className="text-muted-foreground">Tax Record ID</span>
                  <span className="font-mono font-semibold text-foreground text-right">{detailRecord.id}</span>
                </div>
                <div className="flex justify-between gap-4 text-xs">
                  <span className="text-muted-foreground">Source</span>
                  <span className="font-medium text-foreground text-right">{detailRecord.source}</span>
                </div>
                <div className="flex justify-between gap-4 text-xs">
                  <span className="text-muted-foreground">Document Ref</span>
                  <span className="font-mono text-foreground text-right">{detailRecord.tx_ref}</span>
                </div>
                <div className="flex justify-between gap-4 text-xs">
                  <span className="text-muted-foreground">Period</span>
                  <span className="font-medium text-foreground text-right">{detailRecord.quarter}</span>
                </div>
                <div className="flex justify-between gap-4 text-xs">
                  <span className="text-muted-foreground">Counterparty</span>
                  <span className="font-medium text-foreground text-right">{detailRecord.customer}</span>
                </div>
                <div className="flex justify-between gap-4 text-xs">
                  <span className="text-muted-foreground">Branch</span>
                  <span className="font-medium text-foreground text-right">{detailRecord.branch}</span>
                </div>
              </div>

              <div className="space-y-2">
                <p className="text-[10px] text-muted-foreground uppercase tracking-wider font-semibold">Tax Computation</p>
                <div className="space-y-1.5">
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Gross Amount</span>
                    <span className="font-medium text-foreground tabular-nums">{fullPeso(detailRecord.gross)}</span>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">VAT Output</span>
                    <span className="font-medium text-foreground tabular-nums">{fullPeso(detailRecord.vat_output)}</span>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Input VAT Credit</span>
                    <span className="font-medium text-success tabular-nums">-{fullPeso(detailRecord.input_credit)}</span>
                  </div>
                  <Separator />
                  <div className="flex justify-between items-baseline">
                    <span className="text-sm font-bold text-foreground">Net VAT Position</span>
                    <span className={cn("text-xl font-extrabold tabular-nums", detailRecord.net_vat < 0 ? "text-success" : "text-foreground")}>{fullPeso(detailRecord.net_vat)}</span>
                  </div>
                </div>
              </div>

              <div className="rounded-xl border border-success/20 bg-success/5 p-4 flex items-start gap-3">
                <CheckCircle2 className="h-4 w-4 text-success shrink-0 mt-0.5" />
                <div>
                  <p className="text-sm font-semibold text-success">Loaded from NUERS database</p>
                  <p className="text-xs text-muted-foreground mt-0.5">
                    This row is generated from a real transaction, invoice, or validated B2B expense linked to the current Business Account.
                  </p>
                </div>
              </div>

              <div className="rounded-lg border border-border bg-muted/30 p-3 flex gap-2">
                <Info className="h-3.5 w-3.5 text-muted-foreground shrink-0 mt-0.5" />
                <p className="text-xs text-muted-foreground">
                  Input VAT credits are only counted when the related B2B expense has a validated or claimable status in the database.
                </p>
              </div>

              <Button className="w-full gap-2" size="sm" onClick={() => exportCsv(data)}>
                <Download className="h-3.5 w-3.5" /> Download Tax Ledger CSV
              </Button>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
