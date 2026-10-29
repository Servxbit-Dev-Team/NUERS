import { formatTin } from "@/lib/tin";
import { useLocation } from "react-router-dom";
import { useEffect, useState, useCallback, useRef } from "react";
import {
  Activity, Zap, Clock, Server, Search, Download, Eye,
  Receipt, RefreshCw,
  Building2, MapPin, CreditCard, Banknote, Smartphone,
  TrendingUp, CheckCircle2, XCircle, X,
  ChevronLeft, ChevronRight, FileText, Globe, BarChart3,
  CalendarDays, Filter,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Separator } from "@/components/ui/separator";
import { Progress } from "@/components/ui/progress";
import {
  AreaChart, Area, BarChart, Bar, XAxis, YAxis,
  CartesianGrid, ResponsiveContainer, Tooltip,
} from "recharts";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";

// ─── Types ───────────────────────────────────────────────────────────────────

type Transaction = {
  id: string;
  merchant_id: string;
  merchant_ref_id: string | null;
  transaction_ref: string | null;
  amount: number;
  vat_amount: number;
  vatable_sales: number;
  net_amount: number;
  payment_method: string;
  region: string;
  branch: string;
  rdo_code?: string | null;
  rdo_name?: string | null;
  rdo_branch?: string | null;
  channel: string;
  transaction_type: string;
  customer_name: string;
  customer_tin: string | null;
  status: string;
  created_at: string;
  receipt_id: string | null;
  // joined
  merchant_name?: string;
  merchant_tin?: string;
  receipt_number?: string;
};

type Receipt = {
  id: string;
  transaction_id: string | null;
  merchant_id: string | null;
  receipt_number: string;
  series_number: string;
  bir_accreditation: string;
  receipt_type: string;
  merchant_name: string;
  merchant_tin: string;
  rdo_code?: string | null;
  rdo_name?: string | null;
  rdo_branch?: string | null;
  merchant_address: string;
  merchant_vat_reg: string;
  buyer_name: string | null;
  buyer_tin: string | null;
  gross_amount: number;
  discount_amount: number;
  vatable_sales: number;
  vat_exempt_sales: number;
  zero_rated_sales: number;
  vat_amount: number;
  total_due: number;
  items: Array<{
    description?: string;
    name?: string;
    qty?: number | string;
    quantity?: number | string;
    unit_price?: number | string;
    price?: number | string;
    vat?: number | string;
    vat_amount?: number | string;
    tax?: number | string;
    tax_amount?: number | string;
    amount?: number | string;
    total?: number | string;
    line_total?: number | string;
    total_amount?: number | string;
    gross_amount?: number | string;
  }>;
  status: string;
  issued_at: string;
};

type LiveFeedItem = {
  id: string;
  merchant: string;
  amount: number;
  tax: number;
  region: string;
  rdo_code?: string | null;
  rdo_name?: string | null;
  rdo_branch?: string | null;
  time: string;
  method: string;
  status: string;
  receipt_number?: string | null;
};

type LiveMetrics = {
  tps: number;
  latency: number | null;
  queue: number;
  success: number;
  totalTransactions: number;
  latencySource: string;
};

type ThroughputPoint = {
  time: string;
  transactions: number;
  successful: number;
  pending: number;
  tps: number;
};

type TaxSummary = {
  completed_transactions: number;
  total_gross: number;
  total_vat: number;
  total_vatable: number;
  total_net: number;
  vat_rate: number;
  by_region: Array<{ region: string; count: number; gross: number; vat: number }>;
  by_payment: Array<{ method: string; count: number; amount: number }>;
};

type TransactionOverviewPayload = {
  meta?: {
    generated_at?: string;
    note?: string;
  };
  metrics?: {
    throughput_tps?: number;
    avg_latency_ms?: number | null;
    latency_source?: string;
    queue_depth?: number;
    success_rate?: number;
    total_transactions?: number;
    throughput_window?: string;
  };
  throughput_series?: ThroughputPoint[];
  live_feed?: LiveFeedItem[];
  transactions?: Transaction[];
  receipts?: Receipt[];
  tax_summary?: TaxSummary;
  filters?: {
    payment_methods?: string[];
    regions?: string[];
    statuses?: string[];
    rdos?: Array<{ code: string; name: string; label: string }>;
  };
  pagination?: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
};

const DEFAULT_PAYMENT_METHODS = ["all", "cash", "card", "gcash", "maya", "bank_transfer", "check"];
const DEFAULT_REGIONS = ["all", "NCR"];
const DEFAULT_STATUSES = ["all", "completed", "pending", "voided", "refunded"];
const ALL_RDO_FILTER = { code: "all", name: "All RDO Branches", label: "All RDO Branches" };

const paymentIcon: Record<string, React.ElementType> = {
  cash: Banknote,
  card: CreditCard,
  gcash: Smartphone,
  maya: Smartphone,
  bank_transfer: Building2,
  check: FileText,
};

const paymentColor: Record<string, string> = {
  cash: "text-success",
  card: "text-primary",
  gcash: "text-blue-500",
  maya: "text-green-500",
  bank_transfer: "text-orange-500",
  check: "text-muted-foreground",
};

const statusBadge: Record<string, { variant: "default" | "secondary" | "destructive" | "outline"; icon: React.ElementType }> = {
  completed: { variant: "default", icon: CheckCircle2 },
  paid: { variant: "default", icon: CheckCircle2 },
  issued: { variant: "default", icon: CheckCircle2 },
  settled: { variant: "default", icon: CheckCircle2 },
  verified: { variant: "default", icon: CheckCircle2 },
  pending: { variant: "secondary", icon: Clock },
  queued: { variant: "secondary", icon: Clock },
  processing: { variant: "secondary", icon: Clock },
  under_review: { variant: "secondary", icon: Clock },
  failed: { variant: "destructive", icon: XCircle },
  voided: { variant: "destructive", icon: XCircle },
  cancelled: { variant: "destructive", icon: XCircle },
  canceled: { variant: "destructive", icon: XCircle },
  rejected: { variant: "destructive", icon: XCircle },
  refunded: { variant: "outline", icon: CheckCircle2 },
};

// ─── Receipt Modal ────────────────────────────────────────────────────────────

function parseMoneyValue(value: unknown): number | null {
  if (typeof value === "number") {
    return Number.isFinite(value) ? value : null;
  }

  if (typeof value !== "string") {
    return null;
  }

  const trimmed = value.trim();

  if (!trimmed || trimmed.toLowerCase() === "nan") {
    return null;
  }

  const normalized = trimmed.replace(/[^\d.-]/g, "");
  const amount = Number(normalized);

  return Number.isFinite(amount) ? amount : null;
}

function moneyValue(value: unknown, fallback = 0): number {
  return parseMoneyValue(value) ?? fallback;
}

function peso(value: unknown, fallback = 0): string {
  return `₱${moneyValue(value, fallback).toFixed(2)}`;
}

function receiptTypeLabel(type: string | null | undefined): string {
  return (type || "Transaction item")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (char) => char.toUpperCase());
}

type ReceiptLine = {
  description: string;
  vat: number;
  amount: number;
};

function buildReceiptLines(receipt: Receipt): ReceiptLine[] {
  const rawItems = receipt.items.length > 0
    ? receipt.items
    : [{ description: receiptTypeLabel(receipt.receipt_type) }];
  const singleItem = rawItems.length === 1;
  const receiptTotal = moneyValue(receipt.total_due);
  const receiptVat = moneyValue(receipt.vat_amount);

  return rawItems.map((item) => {
    const quantity = moneyValue(item.quantity ?? item.qty, 1);
    const unitPrice = parseMoneyValue(item.unit_price ?? item.price);
    const explicitAmount = parseMoneyValue(
      item.amount ?? item.total ?? item.line_total ?? item.total_amount ?? item.gross_amount,
    );
    const computedAmount = unitPrice === null ? null : quantity * unitPrice;
    let amount = explicitAmount ?? computedAmount ?? (singleItem ? receiptTotal : 0);

    if (singleItem && amount <= 0 && receiptTotal > 0) {
      amount = receiptTotal;
    }

    const explicitVat = parseMoneyValue(item.vat ?? item.vat_amount ?? item.tax ?? item.tax_amount);
    let vat = explicitVat ?? (singleItem ? receiptVat : 0);

    if (singleItem && vat <= 0 && receiptVat > 0) {
      vat = receiptVat;
    }

    return {
      description: item.description?.trim() || item.name?.trim() || receiptTypeLabel(receipt.receipt_type),
      vat,
      amount,
    };
  });
}

function ReceiptModal({ receipt, onClose }: { receipt: Receipt | null; onClose: () => void }) {
  if (!receipt) return null;

  const currentReceipt = receipt;
  const receiptLines = buildReceiptLines(currentReceipt);

  return (
    <Dialog open onOpenChange={onClose}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <Receipt className="h-4 w-4" />
            Official Receipt
          </DialogTitle>
        </DialogHeader>

        {/* BIR Receipt Header */}
        <div className="rounded-lg border bg-muted/30 p-4 text-center space-y-0.5">
          <p className="text-sm font-bold text-foreground">{currentReceipt.merchant_name}</p>
          <p className="text-xs text-muted-foreground">{currentReceipt.merchant_address}</p>
          <p className="text-xs text-muted-foreground">TIN: {formatTin(currentReceipt.merchant_tin)}</p>
          <p className="text-xs text-muted-foreground">RDO: {currentReceipt.rdo_branch ?? "Unassigned RDO"}</p>
          <p className="text-xs text-muted-foreground">VAT Reg: {currentReceipt.merchant_vat_reg}</p>
          <p className="text-xs text-muted-foreground">BIR Accreditation: {currentReceipt.bir_accreditation}</p>
        </div>

        <div className="space-y-3">
          <div className="flex justify-between text-xs">
            <span className="font-semibold text-foreground">OFFICIAL RECEIPT</span>
            <span className="font-mono text-foreground">{currentReceipt.receipt_number}</span>
          </div>
          <div className="flex justify-between text-xs text-muted-foreground">
            <span>Series: {currentReceipt.series_number}</span>
            <span>Date: {new Date(currentReceipt.issued_at).toLocaleDateString("en-PH", { year: "numeric", month: "long", day: "numeric" })}</span>
          </div>

          {(currentReceipt.buyer_name || currentReceipt.buyer_tin) && (
            <div className="rounded border p-2.5 text-xs space-y-0.5">
              {currentReceipt.buyer_name && <div className="flex gap-2"><span className="text-muted-foreground w-16">Sold to:</span><span className="font-medium">{currentReceipt.buyer_name}</span></div>}
              {currentReceipt.buyer_tin && <div className="flex gap-2"><span className="text-muted-foreground w-16">TIN:</span><span className="font-mono">{formatTin(currentReceipt.buyer_tin)}</span></div>}
            </div>
          )}

          <Separator />

          {/* Line items */}
          <div className="space-y-1.5">
            <div className="grid grid-cols-4 text-[10px] font-medium text-muted-foreground uppercase tracking-wide">
              <span className="col-span-2">Description</span>
              <span className="text-right">VAT</span>
              <span className="text-right">Amount</span>
            </div>
            {receiptLines.map((item, i) => (
              <div key={i} className="grid grid-cols-4 text-xs">
                <span className="col-span-2 text-foreground">{item.description}</span>
                <span className="text-right text-muted-foreground">{peso(item.vat)}</span>
                <span className="text-right font-medium text-foreground">{peso(item.amount)}</span>
              </div>
            ))}
          </div>

          <Separator />

          {/* Tax breakdown */}
          <div className="space-y-1 text-xs">
            <div className="flex justify-between text-muted-foreground">
              <span>Vatable Sales</span>
              <span className="font-mono">{peso(currentReceipt.vatable_sales)}</span>
            </div>
            {moneyValue(currentReceipt.vat_exempt_sales) > 0 && (
              <div className="flex justify-between text-muted-foreground">
                <span>VAT-Exempt Sales</span>
                <span className="font-mono">{peso(currentReceipt.vat_exempt_sales)}</span>
              </div>
            )}
            {moneyValue(currentReceipt.zero_rated_sales) > 0 && (
              <div className="flex justify-between text-muted-foreground">
                <span>Zero-Rated Sales</span>
                <span className="font-mono">{peso(currentReceipt.zero_rated_sales)}</span>
              </div>
            )}
            {moneyValue(currentReceipt.discount_amount) > 0 && (
              <div className="flex justify-between text-muted-foreground">
                <span>Discount</span>
                <span className="font-mono text-destructive">-{peso(currentReceipt.discount_amount)}</span>
              </div>
            )}
            <div className="flex justify-between text-muted-foreground">
              <span>12% VAT</span>
              <span className="font-mono text-success">{peso(currentReceipt.vat_amount)}</span>
            </div>
            <Separator />
            <div className="flex justify-between text-sm font-bold text-foreground">
              <span>TOTAL AMOUNT DUE</span>
              <span className="font-mono">{peso(currentReceipt.total_due)}</span>
            </div>
          </div>

          <div className="text-center">
            <Badge variant={currentReceipt.status === "issued" ? "default" : "destructive"} className="text-[10px]">
              {currentReceipt.status.toUpperCase()}
            </Badge>
          </div>

          <p className="text-center text-[9px] text-muted-foreground leading-relaxed">
            This is a system-generated receipt. Valid for income tax purposes.<br />
            Bureau of Internal Revenue — Electronic Receipt System v2.0
          </p>
        </div>

        <div className="flex gap-2">
          <Button variant="outline" className="flex-1 gap-2 text-xs" onClick={() => toast.info("Print functionality available in production.")}>
            <FileText className="h-3.5 w-3.5" /> Print Receipt
          </Button>
          <Button className="flex-1 gap-2 text-xs" onClick={() => toast.info("Download functionality available in production.")}>
            <Download className="h-3.5 w-3.5" /> Download PDF
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}

// ─── Tax Breakdown Panel ──────────────────────────────────────────────────────

function TaxBreakdownPanel({ transactions, taxSummary }: { transactions: Transaction[]; taxSummary: TaxSummary | null }) {
  const completed = transactions.filter((t) => t.status === "completed");
  const totalGross = taxSummary?.total_gross ?? completed.reduce((s, t) => s + Number(t.amount), 0);
  const totalVat = taxSummary?.total_vat ?? completed.reduce((s, t) => s + Number(t.vat_amount), 0);
  const totalVatable = taxSummary?.total_vatable ?? completed.reduce((s, t) => s + Number(t.vatable_sales), 0);
  const totalNet = taxSummary?.total_net ?? completed.reduce((s, t) => s + Number(t.net_amount), 0);
  const completedCount = taxSummary?.completed_transactions ?? completed.length;

  const fallbackRegions = Array.from(new Set(completed.map((t) => t.region || "Unclassified"))).sort().map((region) => {
    const txns = completed.filter((t) => (t.region || "Unclassified") === region);
    return {
      region,
      gross: txns.reduce((s, t) => s + Number(t.amount), 0),
      vat: txns.reduce((s, t) => s + Number(t.vat_amount), 0),
      count: txns.length,
    };
  }).filter((r) => r.count > 0);

  const fallbackPayments = Array.from(new Set(completed.map((t) => t.payment_method || "cash"))).sort().map((method) => {
    const txns = completed.filter((t) => (t.payment_method || "cash") === method);
    return { method, count: txns.length, amount: txns.reduce((s, t) => s + Number(t.amount), 0) };
  }).filter((m) => m.count > 0);

  const byRegion = taxSummary?.by_region?.length ? taxSummary.by_region : fallbackRegions;
  const byPayment = taxSummary?.by_payment?.length ? taxSummary.by_payment : fallbackPayments;
  const vatRate = taxSummary?.vat_rate ?? (totalGross > 0 ? (totalVat / totalGross) * 100 : 0);

  return (
    <div className="space-y-5">
      {/* Summary KPIs */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { label: "Total Gross Sales", value: `₱${(totalGross / 1_000_000).toFixed(2)}M`, sub: `${completedCount} transactions`, color: "border-l-primary" },
          { label: "Vatable Sales", value: `₱${(totalVatable / 1_000_000).toFixed(2)}M`, sub: `${((totalVatable / totalGross) * 100 || 0).toFixed(1)}% of gross`, color: "border-l-chart-2" },
          { label: "12% VAT Collected", value: `₱${(totalVat / 1_000_000).toFixed(2)}M`, sub: `Effective rate ${vatRate.toFixed(1)}%`, color: "border-l-success" },
          { label: "Net (Ex-VAT)", value: `₱${(totalNet / 1_000_000).toFixed(2)}M`, sub: "Base amount", color: "border-l-muted" },
        ].map((kpi) => (
          <Card key={kpi.label} className={cn("border-l-4", kpi.color)}>
            <CardContent className="p-4">
              <p className="text-xs text-muted-foreground">{kpi.label}</p>
              <p className="mt-1 text-xl font-bold tabular-nums text-foreground">{kpi.value}</p>
              <p className="text-[10px] text-muted-foreground mt-0.5">{kpi.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        {/* VAT by Region */}
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h3 className="text-sm font-semibold text-foreground">VAT Collection by Region</h3>
                <p className="text-xs text-muted-foreground">Breakdown of 12% VAT per region</p>
              </div>
              <Globe className="h-4 w-4 text-muted-foreground" />
            </div>
            {byRegion.length === 0 ? (
              <p className="text-center text-xs text-muted-foreground py-8">No regional data</p>
            ) : (
              <ResponsiveContainer width="100%" height={200}>
                <BarChart data={byRegion} layout="vertical">
                  <CartesianGrid strokeDasharray="3 3" horizontal={false} stroke="var(--border)" />
                  <XAxis type="number" tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" tickFormatter={(v) => `₱${(v / 1000).toFixed(0)}K`} />
                  <YAxis type="category" dataKey="region" tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" width={70} />
                  <Tooltip formatter={(v) => [`₱${Number(v).toFixed(2)}`, "VAT"]} />
                  <Bar dataKey="vat" fill="var(--chart-1)" radius={[0, 4, 4, 0]} name="VAT" />
                </BarChart>
              </ResponsiveContainer>
            )}
          </CardContent>
        </Card>

        {/* Payment method breakdown */}
        <Card>
          <CardContent className="p-5">
            <div className="flex items-center justify-between mb-4">
              <div>
                <h3 className="text-sm font-semibold text-foreground">Payment Method Split</h3>
                <p className="text-xs text-muted-foreground">Transaction count and volume</p>
              </div>
              <CreditCard className="h-4 w-4 text-muted-foreground" />
            </div>
            <div className="space-y-3">
              {byPayment.map((p) => {
                const pct = totalGross > 0 ? (p.amount / totalGross) * 100 : 0;
                const Icon = paymentIcon[p.method] ?? Banknote;
                return (
                  <div key={p.method}>
                    <div className="flex items-center justify-between mb-1">
                      <div className="flex items-center gap-2">
                        <Icon className={cn("h-3.5 w-3.5", paymentColor[p.method])} />
                        <span className="text-xs font-medium capitalize text-foreground">{p.method.replace("_", " ")}</span>
                        <span className="text-[10px] text-muted-foreground">{p.count} txns</span>
                      </div>
                      <span className="text-xs font-semibold text-foreground">₱{(p.amount / 1000).toFixed(1)}K</span>
                    </div>
                    <Progress value={pct} className="h-1.5" />
                  </div>
                );
              })}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* VAT Computation Table */}
      <Card>
        <CardContent className="p-5">
          <div className="flex items-center justify-between mb-4">
            <div>
              <h3 className="text-sm font-semibold text-foreground">BIR VAT Computation Summary</h3>
              <p className="text-xs text-muted-foreground">Per RMC 16-2013 output VAT breakdown</p>
            </div>
            <Button variant="outline" size="sm" className="gap-2 text-xs h-7" onClick={() => toast.info("Export to Excel in production.")}>
              <Download className="h-3 w-3" /> Export
            </Button>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-xs">
              <thead>
                <tr className="border-b">
                  {["Tax Category", "Sales Amount", "Tax Base", "Output VAT (12%)", "% of Total"].map((h) => (
                    <th key={h} className="pb-2 text-left font-medium text-muted-foreground">{h}</th>
                  ))}
                </tr>
              </thead>
              <tbody>
                <tr className="border-b hover:bg-muted/30">
                  <td className="py-2.5 font-medium text-foreground">Vatable Sales</td>
                  <td className="py-2.5 font-mono">₱{totalGross.toFixed(2)}</td>
                  <td className="py-2.5 font-mono">₱{totalVatable.toFixed(2)}</td>
                  <td className="py-2.5 font-mono text-success font-semibold">₱{totalVat.toFixed(2)}</td>
                  <td className="py-2.5">100%</td>
                </tr>
                <tr className="border-b hover:bg-muted/30">
                  <td className="py-2.5 text-muted-foreground">VAT-Exempt Sales</td>
                  <td className="py-2.5 font-mono">₱0.00</td>
                  <td className="py-2.5 font-mono">₱0.00</td>
                  <td className="py-2.5 font-mono">₱0.00</td>
                  <td className="py-2.5">0%</td>
                </tr>
                <tr className="border-b hover:bg-muted/30">
                  <td className="py-2.5 text-muted-foreground">Zero-Rated Sales</td>
                  <td className="py-2.5 font-mono">₱0.00</td>
                  <td className="py-2.5 font-mono">₱0.00</td>
                  <td className="py-2.5 font-mono">₱0.00</td>
                  <td className="py-2.5">0%</td>
                </tr>
                <tr className="bg-muted/20 font-bold">
                  <td className="py-2.5 text-foreground">TOTAL</td>
                  <td className="py-2.5 font-mono">₱{totalGross.toFixed(2)}</td>
                  <td className="py-2.5 font-mono">₱{totalVatable.toFixed(2)}</td>
                  <td className="py-2.5 font-mono text-success">₱{totalVat.toFixed(2)}</td>
                  <td className="py-2.5">100%</td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}

// ─── Main Component ───────────────────────────────────────────────────────────

export function AdminTransactions() {
  const searchLocation = useLocation();
  const AUTO_REFRESH_MS = 5000;
  const [activeTab, setActiveTab] = useState("live");
  const [transactions, setTransactions] = useState<Transaction[]>([]);
  const [receipts, setReceipts] = useState<Receipt[]>([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [selectedReceipt, setSelectedReceipt] = useState<Receipt | null>(null);
  const [taxSummary, setTaxSummary] = useState<TaxSummary | null>(null);
  const [throughputData, setThroughputData] = useState<ThroughputPoint[]>([]);
  const [throughputWindow, setThroughputWindow] = useState("Last 30 minutes");
  const [lastUpdated, setLastUpdated] = useState<string | null>(null);
  const [availableFilters, setAvailableFilters] = useState({
    payment_methods: DEFAULT_PAYMENT_METHODS,
    regions: DEFAULT_REGIONS,
    statuses: DEFAULT_STATUSES,
    rdos: [ALL_RDO_FILTER],
  });
  const [pagination, setPagination] = useState({
    total: 0,
    total_pages: 1,
  });

  // Filters
  const [search, setSearch] = useState(() => new URLSearchParams(window.location.search).get("search") || "");
  useEffect(() => { setSearch(new URLSearchParams(searchLocation.search).get("search") || ""); }, [searchLocation.search]);
  const [statusFilter, setStatusFilter] = useState("all");
  const [regionFilter, setRegionFilter] = useState("all");
  const [paymentFilter, setPaymentFilter] = useState("all");
  const [rdoFilter, setRdoFilter] = useState("all");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);
  const PAGE_SIZE = 15;

  // Live database feed
  const [feed, setFeed] = useState<LiveFeedItem[]>([]);
  const [liveMetrics, setLiveMetrics] = useState<LiveMetrics>({
    tps: 0,
    latency: null,
    queue: 0,
    success: 0,
    totalTransactions: 0,
    latencySource: "Loading database telemetry...",
  });
  const refreshInFlight = useRef(false);

  const fetchOverview = useCallback(async (silent = false) => {
    if (refreshInFlight.current) return;

    refreshInFlight.current = true;

    if (silent) setRefreshing(true);
    else setLoading(true);

    try {
      const params = new URLSearchParams({
        page: String(page),
        per_page: String(PAGE_SIZE),
        status: statusFilter,
        region: regionFilter,
        payment: paymentFilter,
        rdo: rdoFilter,
      });

      if (search.trim()) {
        params.set("search", search.trim());
      }
      if (dateFrom) {
        params.set("date_from", dateFrom);
      }
      if (dateTo) {
        params.set("date_to", dateTo);
      }

      const response = await apiFetch(`/api/transactions/overview?${params.toString()}`, {
        headers: { Accept: "application/json" },
        cache: "no-store",
      });

      const payload = await readJsonResponse<TransactionOverviewPayload>(response, "Unable to load database transactions.");
      const metrics = payload.metrics ?? {};

      setTransactions(payload.transactions ?? []);
      setReceipts(payload.receipts ?? []);
      setFeed(payload.live_feed ?? []);
      setThroughputData(payload.throughput_series ?? []);
      setTaxSummary(payload.tax_summary ?? null);
      setThroughputWindow(metrics.throughput_window ?? "Last 30 minutes");
      setLastUpdated(payload.meta?.generated_at ?? new Date().toISOString());
      setAvailableFilters({
        payment_methods: payload.filters?.payment_methods?.length ? payload.filters.payment_methods : DEFAULT_PAYMENT_METHODS,
        regions: payload.filters?.regions?.length ? payload.filters.regions : DEFAULT_REGIONS,
        statuses: payload.filters?.statuses?.length ? payload.filters.statuses : DEFAULT_STATUSES,
        rdos: payload.filters?.rdos?.length ? payload.filters.rdos : [ALL_RDO_FILTER],
      });
      setPagination({
        total: payload.pagination?.total ?? 0,
        total_pages: payload.pagination?.total_pages ?? 1,
      });
      setLiveMetrics({
        tps: Number(metrics.throughput_tps ?? 0),
        latency: metrics.avg_latency_ms ?? null,
        queue: Number(metrics.queue_depth ?? 0),
        success: Number(metrics.success_rate ?? 0),
        totalTransactions: Number(metrics.total_transactions ?? 0),
        latencySource: metrics.latency_source ?? "No telemetry source available.",
      });
    } catch (error) {
      toast.error("Failed to load database transactions.");
    } finally {
      refreshInFlight.current = false;
      if (silent) setRefreshing(false);
      else setLoading(false);
    }
  }, [dateFrom, dateTo, page, paymentFilter, rdoFilter, regionFilter, search, statusFilter]);

  useEffect(() => {
    fetchOverview();
  }, [fetchOverview]);

  useEffect(() => {
    const interval = window.setInterval(() => {
      fetchOverview(true);
    }, AUTO_REFRESH_MS);

    return () => window.clearInterval(interval);
  }, [AUTO_REFRESH_MS, fetchOverview]);

  const filtered = transactions;
  const totalPages = pagination.total_pages;
  const paginated = transactions;

  function openReceipt(receiptId: string | null) {
    if (!receiptId) return toast.warning("No receipt linked to this transaction.");
    const r = receipts.find((r) => r.id === receiptId);
    if (r) setSelectedReceipt(r);
    else toast.warning("Receipt not found.");
  }

  const totalVat = taxSummary?.total_vat ?? filtered.reduce((s, t) => s + Number(t.vat_amount), 0);
  const totalGross = taxSummary?.total_gross ?? filtered.reduce((s, t) => s + Number(t.amount), 0);
  const activeGlobalFilterCount = [dateFrom, dateTo, regionFilter !== "all" ? regionFilter : "", rdoFilter !== "all" ? rdoFilter : ""].filter(Boolean).length;
  const activeFilterCount = activeGlobalFilterCount + [search.trim(), statusFilter !== "all" ? statusFilter : "", paymentFilter !== "all" ? paymentFilter : ""].filter(Boolean).length;

  function clearGlobalFilters() {
    setDateFrom("");
    setDateTo("");
    setRegionFilter("all");
    setRdoFilter("all");
    setPage(1);
  }

  function handleRegionFilterChange(value: string) {
    setRegionFilter(value);
    setRdoFilter("all");
    setAvailableFilters((current) => ({ ...current, rdos: [ALL_RDO_FILTER] }));
    setPage(1);
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Transaction Management</h1>
          <p className="text-sm text-muted-foreground">
            Nationwide business account transaction monitoring, receipts & VAT tracking from the NUERS database
          </p>
          {lastUpdated && (
            <p className="mt-1 text-[10px] text-muted-foreground">
              Last synced {new Date(lastUpdated).toLocaleTimeString("en-PH")} · {liveMetrics.totalTransactions.toLocaleString()} {activeFilterCount > 0 ? "matching" : "total"} database transactions
            </p>
          )}
        </div>
        <div className="flex items-center gap-2">
          <Badge variant="secondary" className="gap-1.5 animate-pulse">
            <Activity className="h-3 w-3 text-success" /> Database Live
          </Badge>
          <Badge variant="outline" className="gap-1.5">
            <RefreshCw className={cn("h-3 w-3", refreshing && "animate-spin")} /> Auto-sync {Math.round(AUTO_REFRESH_MS / 1000)}s
          </Badge>
          <Button variant="outline" size="sm" className="gap-2 h-8" onClick={() => fetchOverview()} disabled={loading || refreshing}>
            <RefreshCw className={cn("h-3.5 w-3.5", (loading || refreshing) && "animate-spin")} /> Refresh
          </Button>
        </div>
      </div>

      {/* Global Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap items-end gap-3">
            <div className="flex min-w-32 items-center gap-2 pb-2 text-sm font-medium text-foreground">
              <Filter className="h-4 w-4 text-muted-foreground" />
              Filters
              {activeGlobalFilterCount > 0 && (
                <Badge variant="secondary" className="h-5 px-1.5 text-[10px]">
                  {activeGlobalFilterCount}
                </Badge>
              )}
            </div>
            <div className="grid min-w-40 flex-1 gap-1 sm:max-w-48">
              <span className="text-[10px] font-medium text-muted-foreground">From</span>
              <div className="relative">
                <CalendarDays className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                  aria-label="From date"
                  type="date"
                  value={dateFrom}
                  max={dateTo || undefined}
                  onChange={(event) => {
                    setDateFrom(event.target.value);
                    setPage(1);
                  }}
                  className="h-9 pl-9 text-sm"
                />
              </div>
            </div>
            <div className="grid min-w-40 flex-1 gap-1 sm:max-w-48">
              <span className="text-[10px] font-medium text-muted-foreground">To</span>
              <div className="relative">
                <CalendarDays className="absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                <Input
                  aria-label="To date"
                  type="date"
                  value={dateTo}
                  min={dateFrom || undefined}
                  onChange={(event) => {
                    setDateTo(event.target.value);
                    setPage(1);
                  }}
                  className="h-9 pl-9 text-sm"
                />
              </div>
            </div>
            <div className="grid min-w-44 flex-1 gap-1 sm:max-w-48">
              <span className="text-[10px] font-medium text-muted-foreground">Region</span>
              <Select value={regionFilter} onValueChange={handleRegionFilterChange}>
                <SelectTrigger className="h-9">
                  <SelectValue placeholder="Region" />
                </SelectTrigger>
                <SelectContent>
                  {availableFilters.regions.map((region) => (
                    <SelectItem key={region} value={region}>
                      {region === "all" ? "All Regions" : region}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="grid min-w-56 flex-1 gap-1">
              <span className="text-[10px] font-medium text-muted-foreground">RDO</span>
              <Select value={rdoFilter} onValueChange={(value) => { setRdoFilter(value); setPage(1); }}>
                <SelectTrigger className="h-9">
                  <SelectValue placeholder="RDO Branch" />
                </SelectTrigger>
                <SelectContent>
                  {availableFilters.rdos.map((rdo) => (
                    <SelectItem key={rdo.code} value={rdo.code}>
                      {rdo.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <Button
              variant="outline"
              size="sm"
              className="h-9 gap-2"
              onClick={clearGlobalFilters}
              disabled={activeGlobalFilterCount === 0}
            >
              <X className="h-3.5 w-3.5" /> Clear
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Top KPIs */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Card className="border-l-4 border-l-success">
          <CardContent className="p-4 flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-success/10">
              <Zap className="h-4 w-4 text-success animate-pulse" />
            </div>
            <div>
              <p className="text-[10px] text-muted-foreground">Throughput</p>
              <p className="text-sm font-bold tabular-nums text-foreground">
                {liveMetrics.tps.toLocaleString(undefined, { maximumFractionDigits: 2 })} TPS
              </p>
              <p className="text-[10px] text-muted-foreground">Last 60 seconds</p>
            </div>
          </CardContent>
        </Card>
        <Card className="border-l-4 border-l-primary">
          <CardContent className="p-4 flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-primary/10">
              <Clock className="h-4 w-4 text-primary" />
            </div>
            <div>
              <p className="text-[10px] text-muted-foreground">Avg Latency</p>
              <p className="text-sm font-bold tabular-nums text-foreground">
                {liveMetrics.latency === null ? "N/A" : `${liveMetrics.latency.toFixed(1)}ms`}
              </p>
              <p className="text-[10px] text-muted-foreground truncate max-w-40">{liveMetrics.latencySource}</p>
            </div>
          </CardContent>
        </Card>
        <Card className="border-l-4 border-l-warning">
          <CardContent className="p-4 flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-warning/10">
              <Server className="h-4 w-4 text-warning" />
            </div>
            <div>
              <p className="text-[10px] text-muted-foreground">Pending Queue</p>
              <p className="text-sm font-bold tabular-nums text-foreground">{liveMetrics.queue.toLocaleString()}</p>
              <p className="text-[10px] text-muted-foreground">Pending database rows</p>
            </div>
          </CardContent>
        </Card>
        <Card className="border-l-4 border-l-chart-2">
          <CardContent className="p-4 flex items-center gap-3">
            <div className="flex h-9 w-9 items-center justify-center rounded-lg bg-secondary">
              <CheckCircle2 className="h-4 w-4 text-muted-foreground" />
            </div>
            <div>
              <p className="text-[10px] text-muted-foreground">Success Rate</p>
              <p className="text-sm font-bold tabular-nums text-foreground">{liveMetrics.success.toFixed(2)}%</p>
              <p className="text-[10px] text-muted-foreground">Completed vs total</p>
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Throughput Chart */}
      <Card>
        <CardContent className="p-5">
          <div className="flex items-center justify-between mb-3">
            <h3 className="text-sm font-semibold text-foreground">System Throughput (TPS)</h3>
            <span className="text-[10px] text-muted-foreground">{throughputWindow}</span>
          </div>
          <ResponsiveContainer width="100%" height={160}>
            <AreaChart data={throughputData}>
              <CartesianGrid strokeDasharray="3 3" vertical={false} stroke="var(--border)" />
              <XAxis dataKey="time" tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
              <YAxis tick={{ fontSize: 10 }} stroke="var(--muted-foreground)" />
              <Tooltip />
              <Area type="monotone" dataKey="tps" stroke="var(--chart-1)" fill="var(--chart-1)" fillOpacity={0.1} strokeWidth={2} name="TPS" />
            </AreaChart>
          </ResponsiveContainer>
        </CardContent>
      </Card>

      {/* Main Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList className="grid w-full grid-cols-1 sm:grid-cols-3 sm:max-w-md">
          <TabsTrigger value="live">Live Feed</TabsTrigger>
          <TabsTrigger value="records">Transaction Records</TabsTrigger>
          <TabsTrigger value="tax">Tax Breakdown</TabsTrigger>
        </TabsList>

        {/* ── LIVE FEED ─────────────────────────────────────────────────── */}
        <TabsContent value="live" className="mt-4">
          <Card>
            <CardContent className="p-5">
              <div className="flex items-center justify-between mb-4">
                <h3 className="text-sm font-semibold text-foreground">Latest Database Transaction Feed</h3>
                <div className="flex items-center gap-2">
                  <span className="h-1.5 w-1.5 rounded-full bg-success animate-pulse" />
                  <span className="text-[10px] text-muted-foreground">{feed.length} visible</span>
                </div>
              </div>
              <div className="overflow-x-auto">
                <table className="w-full font-mono text-xs">
                  <thead>
                    <tr className="border-b">
                      {["Time", "ID", "Business Account", "Region", "RDO", "Method", "Amount", "VAT (12%)"].map((h) => (
                        <th key={h} className={cn("pb-2 text-[10px] font-medium text-muted-foreground", h === "Amount" || h === "VAT (12%)" ? "text-right" : "text-left")}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {feed.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="py-10 text-center font-sans text-sm text-muted-foreground">
                          No transactions recorded in the database yet
                        </td>
                      </tr>
                    ) : feed.map((tx, i) => {
                      const Icon = paymentIcon[tx.method] ?? Banknote;
                      return (
                        <tr key={tx.id + i} className={cn("border-b last:border-0 transition-colors", i === 0 ? "bg-success/5" : "hover:bg-muted/30")}>
                          <td className="py-2 text-muted-foreground">{tx.time}</td>
                          <td className="py-2 font-medium text-foreground">{tx.id}</td>
                          <td className="py-2 text-muted-foreground">{tx.merchant}</td>
                          <td className="py-2 text-muted-foreground">{tx.region}</td>
                          <td className="py-2 text-muted-foreground">{tx.rdo_branch ?? "Unassigned RDO"}</td>
                          <td className="py-2">
                            <div className="flex items-center gap-1">
                              <Icon className={cn("h-3 w-3", paymentColor[tx.method])} />
                              <span className="capitalize text-muted-foreground">{tx.method.replace("_"," ")}</span>
                            </div>
                          </td>
                          <td className="py-2 text-right font-semibold text-foreground">₱{tx.amount.toFixed(2)}</td>
                          <td className="py-2 text-right font-semibold text-success">₱{tx.tax.toFixed(2)}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── TRANSACTION RECORDS ────────────────────────────────────────── */}
        <TabsContent value="records" className="mt-4 space-y-4">
          {/* Summary banner */}
          <div className="grid gap-3 sm:grid-cols-3">
            <Card>
              <CardContent className="p-4 flex items-center justify-between">
                <div>
                  <p className="text-xs text-muted-foreground">Filtered Transactions</p>
                  <p className="text-xl font-bold text-foreground">{pagination.total.toLocaleString()}</p>
                </div>
                <BarChart3 className="h-5 w-5 text-muted-foreground" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4 flex items-center justify-between">
                <div>
                  <p className="text-xs text-muted-foreground">Total Gross Amount</p>
                  <p className="text-xl font-bold text-foreground">₱{(totalGross / 1_000_000).toFixed(2)}M</p>
                </div>
                <TrendingUp className="h-5 w-5 text-success" />
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-4 flex items-center justify-between">
                <div>
                  <p className="text-xs text-muted-foreground">Total VAT (12%)</p>
                  <p className="text-xl font-bold text-success">₱{(totalVat / 1_000_000).toFixed(2)}M</p>
                </div>
                <Receipt className="h-5 w-5 text-success" />
              </CardContent>
            </Card>
          </div>

          {/* Filters */}
          <Card>
            <CardContent className="p-4">
              <div className="flex flex-wrap gap-3">
                <div className="relative flex-1 min-w-0 basis-full sm:min-w-48 sm:basis-auto">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground" />
                  <Input placeholder="Search by ref, customer, branch, RDO..." value={search} onChange={(e) => { setSearch(e.target.value); setPage(1); }} className="pl-9 h-9 text-sm" />
                  {search && <button onClick={() => setSearch("")} className="absolute right-3 top-1/2 -translate-y-1/2"><X className="h-3.5 w-3.5 text-muted-foreground" /></button>}
                </div>
                <Select value={statusFilter} onValueChange={(v) => { setStatusFilter(v); setPage(1); }}>
                  <SelectTrigger className="h-9 w-full sm:w-36"><SelectValue placeholder="Status" /></SelectTrigger>
                  <SelectContent>
                    {availableFilters.statuses.map((s) => <SelectItem key={s} value={s} className="capitalize">{s === "all" ? "All Status" : s}</SelectItem>)}
                  </SelectContent>
                </Select>
                <Select value={paymentFilter} onValueChange={(v) => { setPaymentFilter(v); setPage(1); }}>
                  <SelectTrigger className="h-9 w-full sm:w-40"><SelectValue placeholder="Payment" /></SelectTrigger>
                  <SelectContent>
                    {availableFilters.payment_methods.map((m) => <SelectItem key={m} value={m} className="capitalize">{m === "all" ? "All Methods" : m.replace("_"," ")}</SelectItem>)}
                  </SelectContent>
                </Select>
                <Button variant="outline" size="sm" className="h-9 gap-2" onClick={() => toast.info("Export to CSV in production.")}>
                  <Download className="h-3.5 w-3.5" /> Export
                </Button>
              </div>
            </CardContent>
          </Card>

          {/* Table */}
          <Card>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead className="border-b bg-muted/30">
                    <tr>
                      {["Date/Time", "Reference", "Branch", "RDO Branch", "Region", "Payment", "Gross Amount", "Vatable", "VAT 12%", "Net Amount", "Status", "Receipt"].map((h) => (
                        <th key={h} className={cn("px-4 py-3 text-left text-[10px] font-semibold text-muted-foreground uppercase tracking-wide whitespace-nowrap", ["Gross Amount","Vatable","VAT 12%","Net Amount"].includes(h) && "text-right")}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {loading ? (
                      <tr><td colSpan={12} className="py-12 text-center text-sm text-muted-foreground">Loading transactions...</td></tr>
                    ) : paginated.length === 0 ? (
                      <tr><td colSpan={12} className="py-12 text-center text-sm text-muted-foreground">No transactions found</td></tr>
                    ) : paginated.map((tx) => {
                      const SBadge = statusBadge[tx.status] ?? statusBadge.pending;
                      const PMIcon = paymentIcon[tx.payment_method] ?? Banknote;
                      return (
                        <tr key={tx.id} className="border-b last:border-0 hover:bg-muted/20 transition-colors">
                          <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">
                            {new Date(tx.created_at).toLocaleDateString("en-PH")}<br />
                            <span className="text-[10px]">{new Date(tx.created_at).toLocaleTimeString()}</span>
                          </td>
                          <td className="px-4 py-3 font-mono font-medium text-foreground">{tx.transaction_ref ?? tx.id.slice(0, 8).toUpperCase()}</td>
                          <td className="px-4 py-3 text-muted-foreground">{tx.branch ?? "Main Branch"}</td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-1.5">
                              <Building2 className="h-3 w-3 text-muted-foreground flex-shrink-0" />
                              <span className="max-w-52 truncate text-muted-foreground">{tx.rdo_branch ?? "Unassigned RDO"}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-1">
                              <MapPin className="h-3 w-3 text-muted-foreground flex-shrink-0" />
                              <span className="text-muted-foreground">{tx.region}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3">
                            <div className="flex items-center gap-1.5">
                              <PMIcon className={cn("h-3.5 w-3.5", paymentColor[tx.payment_method])} />
                              <span className="capitalize">{(tx.payment_method ?? "cash").replace("_"," ")}</span>
                            </div>
                          </td>
                          <td className="px-4 py-3 text-right font-mono font-semibold text-foreground">₱{Number(tx.amount).toFixed(2)}</td>
                          <td className="px-4 py-3 text-right font-mono text-muted-foreground">₱{Number(tx.vatable_sales).toFixed(2)}</td>
                          <td className="px-4 py-3 text-right font-mono font-semibold text-success">₱{Number(tx.vat_amount).toFixed(2)}</td>
                          <td className="px-4 py-3 text-right font-mono text-muted-foreground">₱{Number(tx.net_amount).toFixed(2)}</td>
                          <td className="px-4 py-3">
                            <Badge variant={SBadge.variant} className="text-[10px] capitalize gap-1">
                              <SBadge.icon className="h-2.5 w-2.5" />
                              {tx.status}
                            </Badge>
                          </td>
                          <td className="px-4 py-3">
                            <Button variant="ghost" size="sm" className="h-7 gap-1 text-xs" onClick={() => openReceipt(tx.receipt_id)}>
                              <Eye className="h-3 w-3" /> View
                            </Button>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              </div>

              {/* Pagination */}
              {totalPages > 1 && (
                <div data-slot="table-pagination" className="flex items-center justify-between border-t px-4 py-3">
                  <p className="text-xs text-muted-foreground">
                    Showing {pagination.total === 0 ? 0 : ((page - 1) * PAGE_SIZE) + 1}–{Math.min(page * PAGE_SIZE, pagination.total)} of {pagination.total}
                  </p>
                  <div className="flex items-center gap-2">
                    <Button variant="outline" size="sm" className="h-7 w-7 p-0" onClick={() => setPage(p => p - 1)} disabled={page === 1}>
                      <ChevronLeft className="h-3.5 w-3.5" />
                    </Button>
                    <span className="text-xs text-muted-foreground">{page} / {totalPages}</span>
                    <Button variant="outline" size="sm" className="h-7 w-7 p-0" onClick={() => setPage(p => p + 1)} disabled={page === totalPages}>
                      <ChevronRight className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Receipts Section */}
          <Card>
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between">
                <CardTitle className="text-sm font-semibold flex items-center gap-2">
                  <Receipt className="h-4 w-4" />
                  Official Receipts Registry
                </CardTitle>
                <Badge variant="secondary" className="text-xs">{receipts.length} linked to current page</Badge>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-x-auto">
                <table className="w-full text-xs">
                  <thead className="border-b bg-muted/30">
                    <tr>
                      {["Receipt No.", "Business Account", "TIN", "Issued Date", "Vatable Sales", "VAT Amount", "Total Due", "Status", "Action"].map((h) => (
                        <th key={h} className={cn("px-4 py-3 text-left text-[10px] font-semibold text-muted-foreground uppercase tracking-wide whitespace-nowrap", ["Vatable Sales","VAT Amount","Total Due"].includes(h) && "text-right")}>{h}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {receipts.slice(0, 20).map((r) => (
                      <tr key={r.id} className="border-b last:border-0 hover:bg-muted/20 transition-colors">
                        <td className="px-4 py-3 font-mono font-medium text-foreground">{r.receipt_number}</td>
                        <td className="px-4 py-3 text-muted-foreground max-w-36 truncate">{r.merchant_name}</td>
                        <td className="px-4 py-3 font-mono text-muted-foreground">{formatTin(r.merchant_tin)}</td>
                        <td className="px-4 py-3 text-muted-foreground whitespace-nowrap">{new Date(r.issued_at).toLocaleDateString("en-PH")}</td>
                        <td className="px-4 py-3 text-right font-mono">₱{Number(r.vatable_sales).toFixed(2)}</td>
                        <td className="px-4 py-3 text-right font-mono text-success font-semibold">₱{Number(r.vat_amount).toFixed(2)}</td>
                        <td className="px-4 py-3 text-right font-mono font-semibold text-foreground">₱{Number(r.total_due).toFixed(2)}</td>
                        <td className="px-4 py-3">
                          <Badge variant={r.status === "issued" ? "default" : "destructive"} className="text-[10px] capitalize">{r.status}</Badge>
                        </td>
                        <td className="px-4 py-3">
                          <Button variant="ghost" size="sm" className="h-7 gap-1 text-xs" onClick={() => setSelectedReceipt(r)}>
                            <Eye className="h-3 w-3" /> View
                          </Button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        {/* ── TAX BREAKDOWN ──────────────────────────────────────────────── */}
        <TabsContent value="tax" className="mt-4">
          <TaxBreakdownPanel transactions={transactions} taxSummary={taxSummary} />
        </TabsContent>
      </Tabs>

      {/* Receipt Modal */}
      {selectedReceipt && (
        <ReceiptModal receipt={selectedReceipt} onClose={() => setSelectedReceipt(null)} />
      )}
    </div>
  );
}
