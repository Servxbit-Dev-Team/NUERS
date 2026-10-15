import { useLocation } from "react-router-dom";
import { useCallback, useEffect, useMemo, useState } from "react";
import type { ElementType } from "react";
import {
  Search, Download, Receipt, ChevronLeft, ChevronRight,
  Filter, X, Eye, TrendingUp, Activity,
  CheckCircle2, XCircle, Clock,
  Building2, CreditCard, ArrowUpDown, Printer,
  Copy, RefreshCw, SlidersHorizontal, ArrowUpRight,
  ArrowDownRight, CalendarDays, Banknote,
  CircleDot, AlertTriangle, FileText, MoreHorizontal,
  Loader2, Database, ShieldCheck,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { Separator } from "@/components/ui/separator";
import { Alert, AlertDescription } from "@/components/ui/alert";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";
import { BirDocumentPreview } from "@/components/invoices/bir-document-preview";
import {
  ChartContainer, ChartTooltip, ChartTooltipContent, ChartLegend, ChartLegendContent,
} from "@/components/ui/chart";
import type { ChartConfig } from "@/components/ui/chart";
import { BarChart, Bar, XAxis, YAxis, CartesianGrid } from "recharts";

const API_TOKEN_KEY = "nuers_api_token";
const PAGE_SIZE = 15;

type TxStatus = "completed" | "voided" | "pending";

type ApiTransaction = {
  db_id: string;
  id: string;
  transaction_ref: string | null;
  receipt_number: string | null;
  series_number: string | null;
  permit_to_issue_number?: string | null;
  esp_accreditation_number?: string | null;
  date: string;
  time: string;
  created_at: string;
  amount: number;
  vat: number;
  vatable_sales: number;
  net: number;
  status: TxStatus;
  raw_status: string;
  type: string;
  channel: string;
  source_system: string;
  api_key_name: string | null;
  document_type: string;
  bir_template_code: string;
  document_label: string;
  customer: string;
  customer_tin: string | null;
  branch: string;
  branch_code: string | null;
  branch_type: string | null;
  rdo_code: string | null;
  rdo_name: string | null;
  rdo_branch: string | null;
  payment: string;
  cashier: string;
  tax_type: string;
  tax_classification: string;
};

type TransactionsPayload = {
  merchant: {
    id: string;
    business_name: string;
    tin: string;
  } | null;
  summary: {
    total_transactions: number;
    completed_transactions: number;
    voided_transactions: number;
    pending_transactions: number;
    total_revenue: number;
    total_vat: number;
    average_transaction: number;
    void_rate: number;
  };
  weekly: Array<{
    day: string;
    date: string;
    completed: number;
    voided: number;
    pending: number;
    revenue: number;
  }>;
  channel_revenue: Array<{
    channel: string;
    amount: number;
    transactions: number;
  }>;
  filters: {
    channels: string[];
    branches: string[];
    payments: string[];
    statuses: TxStatus[];
  };
  transactions: ApiTransaction[];
  pagination: {
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
  };
};

const emptyPayload: TransactionsPayload = {
  merchant: null,
  summary: {
    total_transactions: 0,
    completed_transactions: 0,
    voided_transactions: 0,
    pending_transactions: 0,
    total_revenue: 0,
    total_vat: 0,
    average_transaction: 0,
    void_rate: 0,
  },
  weekly: [],
  channel_revenue: [],
  filters: {
    channels: [],
    branches: [],
    payments: [],
    statuses: [],
  },
  transactions: [],
  pagination: {
    page: 1,
    per_page: PAGE_SIZE,
    total: 0,
    total_pages: 1,
  },
};

const volConfig: ChartConfig = {
  completed: { label: "Completed", color: "var(--chart-1)" },
  voided: { label: "Voided", color: "var(--destructive)" },
  pending: { label: "Pending", color: "var(--chart-3)" },
};

const channelConfig: ChartConfig = {
  amount: { label: "Revenue", color: "var(--chart-1)" },
};

const statusMeta: Record<TxStatus, { icon: ElementType; pill: string; dot: string; label: string }> = {
  completed: {
    icon: CheckCircle2,
    pill: "bg-success/10 text-success border-success/20",
    dot: "bg-success",
    label: "Completed",
  },
  voided: {
    icon: XCircle,
    pill: "bg-destructive/10 text-destructive border-destructive/20",
    dot: "bg-destructive",
    label: "Voided",
  },
  pending: {
    icon: Clock,
    pill: "bg-warning/10 text-warning border-warning/20",
    dot: "bg-warning",
    label: "Pending",
  },
};

const channelColors: Record<string, string> = {
  POS: "bg-primary/10 text-primary border-primary/20",
  "E-Commerce": "bg-chart-2/10 text-chart-2 border-chart-2/20",
  Mobile: "bg-chart-3/10 text-chart-3 border-chart-3/20",
  API: "bg-chart-4/10 text-chart-4 border-chart-4/20",
  Corporate: "bg-chart-5/10 text-chart-5 border-chart-5/20",
};

const paymentIcon: Record<string, ElementType> = {
  Cash: Banknote,
  cash: Banknote,
  Card: CreditCard,
  card: CreditCard,
  "Credit Card": CreditCard,
  gcash: CircleDot,
  GCash: CircleDot,
  maya: CircleDot,
  Maya: CircleDot,
  "Bank Transfer": Building2,
  bank_transfer: Building2,
};

function authHeaders() {
  const headers = new Headers({ Accept: "application/json" });

  try {
    const token = window.localStorage.getItem(API_TOKEN_KEY);
    if (token) headers.set("Authorization", `Bearer ${token}`);
  } catch {
    // Local storage can be unavailable in restricted browser contexts.
  }

  return headers;
}

function fullPeso(value: number) {
  return `₱${value.toLocaleString("en-PH", {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`;
}

function compactPeso(value: number) {
  if (value >= 1_000_000) return `₱${(value / 1_000_000).toFixed(2)}M`;
  if (value >= 1_000) return `₱${(value / 1_000).toFixed(0)}K`;
  return fullPeso(value);
}

function toCentavos(value: number) {
  return Math.round(Number(value || 0) * 100);
}

function normalizePaymentLabel(value: string) {
  const normalized = value.replaceAll("_", " ").trim();
  return normalized ? normalized.replace(/\b\w/g, (c) => c.toUpperCase()) : "Unspecified";
}

function csvCell(value: string | number | null | undefined) {
  return `"${String(value ?? "").replaceAll('"', '""')}"`;
}

export function MerchantTransactions() {
  const searchLocation = useLocation();
  const [search, setSearch] = useState(() => new URLSearchParams(window.location.search).get("search") || "");
  useEffect(() => { setSearch(new URLSearchParams(searchLocation.search).get("search") || ""); }, [searchLocation.search]);
  const [statusFilter, setStatusFilter] = useState("all");
  const [typeFilter, setTypeFilter] = useState("all");
  const [branchFilter, setBranchFilter] = useState("all");
  const [paymentFilter, setPaymentFilter] = useState("all");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<ApiTransaction | null>(null);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [showFilters, setShowFilters] = useState(false);
  const [sortKey, setSortKey] = useState<"amount" | "date">("date");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("desc");
  const [data, setData] = useState<TransactionsPayload>(emptyPayload);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");

  const loadTransactions = useCallback(async (quiet = false) => {
    if (quiet) {
      setRefreshing(true);
    } else {
      setLoading(true);
    }
    setError("");

    try {
      const params = new URLSearchParams({
        page: String(page),
        per_page: String(PAGE_SIZE),
      });
      if (search.trim()) params.set("search", search.trim());
      if (statusFilter !== "all") params.set("status", statusFilter);
      if (typeFilter !== "all") params.set("channel", typeFilter);
      if (branchFilter !== "all") params.set("branch", branchFilter);
      if (paymentFilter !== "all") params.set("payment", paymentFilter);

      const response = await apiFetch(`/api/merchant/transactions?${params}`, {
        headers: authHeaders(),
        cache: "no-store",
      });
      const payload = await readJsonResponse<TransactionsPayload>(response, "Unable to load API transactions.");

      setData(payload);
    } catch (err) {
      setData(emptyPayload);
      setError(err instanceof Error ? err.message : "Unable to load API transactions.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }, [branchFilter, page, paymentFilter, search, statusFilter, typeFilter]);

  useEffect(() => {
    const timer = window.setTimeout(() => {
      loadTransactions();
    }, 250);

    return () => window.clearTimeout(timer);
  }, [loadTransactions]);

  useEffect(() => {
    setPage(1);
    setSelectedIds(new Set());
  }, [search, statusFilter, typeFilter, branchFilter, paymentFilter]);

  useEffect(() => {
    if (page > data.pagination.total_pages) {
      setPage(data.pagination.total_pages || 1);
    }
  }, [data.pagination.total_pages, page]);

  const rows = data.transactions;
  const sortedRows = useMemo(() => {
    const copy = [...rows];
    const multiplier = sortDir === "desc" ? -1 : 1;

    return copy.sort((a, b) => {
      if (sortKey === "amount") return multiplier * (a.amount - b.amount);
      return multiplier * a.created_at.localeCompare(b.created_at);
    });
  }, [rows, sortDir, sortKey]);

  const hasFilters = statusFilter !== "all" || typeFilter !== "all" || branchFilter !== "all" || paymentFilter !== "all";
  const allPageSelected = sortedRows.length > 0 && sortedRows.every((tx) => selectedIds.has(tx.db_id));
  const selectedRows = sortedRows.filter((tx) => selectedIds.has(tx.db_id));
  const channels = useMemo(() => Array.from(new Set(["POS", "E-Commerce", "Mobile", "API", "Corporate", ...data.filters.channels])), [data.filters.channels]);
  const branches = data.filters.branches;
  const payments = data.filters.payments;
  const weekly = data.weekly.length > 0 ? data.weekly : [
    { day: "Mon", date: "", completed: 0, voided: 0, pending: 0, revenue: 0 },
    { day: "Tue", date: "", completed: 0, voided: 0, pending: 0, revenue: 0 },
    { day: "Wed", date: "", completed: 0, voided: 0, pending: 0, revenue: 0 },
    { day: "Thu", date: "", completed: 0, voided: 0, pending: 0, revenue: 0 },
    { day: "Fri", date: "", completed: 0, voided: 0, pending: 0, revenue: 0 },
    { day: "Sat", date: "", completed: 0, voided: 0, pending: 0, revenue: 0 },
    { day: "Sun", date: "", completed: 0, voided: 0, pending: 0, revenue: 0 },
  ];
  const channelRevenue = data.channel_revenue.length > 0 ? data.channel_revenue : [{ channel: "API", amount: 0, transactions: 0 }];

  function toggleSelect(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      next.has(id) ? next.delete(id) : next.add(id);
      return next;
    });
  }

  function togglePageSelect() {
    const ids = sortedRows.map((tx) => tx.db_id);
    const shouldClear = ids.every((id) => selectedIds.has(id));

    setSelectedIds((prev) => {
      const next = new Set(prev);
      ids.forEach((id) => {
        if (shouldClear) next.delete(id);
        else next.add(id);
      });
      return next;
    });
  }

  function clearFilters() {
    setStatusFilter("all");
    setTypeFilter("all");
    setBranchFilter("all");
    setPaymentFilter("all");
    setPage(1);
  }

  function toggleSort(key: "amount" | "date") {
    if (sortKey === key) setSortDir((direction) => direction === "desc" ? "asc" : "desc");
    else {
      setSortKey(key);
      setSortDir("desc");
    }
  }

  function handleExport(subset = sortedRows) {
    if (subset.length === 0) {
      toast.info("No API transactions to export for this Business Account.");
      return;
    }

    const csvRows = [
      ["Business Account", data.merchant?.business_name ?? ""],
      ["TIN", data.merchant?.tin ?? ""],
      [],
      ["Transaction", "Document", "Template", "Receipt", "Date", "Time", "Branch", "Branch Type", "RDO Branch", "Channel", "Source System", "Payment", "Customer", "Customer TIN", "Tax Type", "Tax Classification", "Amount", "VAT", "Net", "Status"],
      ...subset.map((tx) => [
        tx.id,
        tx.document_label,
        tx.bir_template_code,
        tx.receipt_number ?? "",
        tx.date,
        tx.time,
        tx.branch,
        tx.branch_type ?? "",
        tx.rdo_branch ?? "",
        tx.type,
        tx.source_system,
        tx.payment,
        tx.customer,
        tx.customer_tin ?? "",
        tx.tax_type,
        tx.tax_classification,
        tx.amount.toFixed(2),
        tx.vat.toFixed(2),
        tx.net.toFixed(2),
        tx.status,
      ]),
    ];

    const csv = csvRows.map((row) => row.map(csvCell).join(",")).join("\n");
    const link = document.createElement("a");
    link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
    link.download = `nuers-api-transactions-${data.merchant?.tin || "business-account"}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
    toast.success(`${subset.length} API transaction${subset.length !== 1 ? "s" : ""} exported.`);
  }

  function SortIcon({ k }: { k: "amount" | "date" }) {
    if (sortKey !== k) return <ArrowUpDown className="h-3 w-3 text-muted-foreground/50 ml-1 inline" />;
    return sortDir === "desc"
      ? <ArrowDownRight className="h-3 w-3 text-primary ml-1 inline" />
      : <ArrowUpRight className="h-3 w-3 text-primary ml-1 inline" />;
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-tight">Transaction History</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            {data.summary.total_transactions.toLocaleString()} API transactions · POS/accounting/billing integrations · Business Account scoped
          </p>
        </div>
        <div className="flex items-center gap-2">
          {selectedIds.size > 0 && (
            <Button
              variant="outline"
              size="sm"
              className="gap-2 text-xs"
              onClick={() => handleExport(selectedRows)}
            >
              <Download className="h-3.5 w-3.5" />
              Export {selectedIds.size} selected
            </Button>
          )}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button size="sm" className="gap-2" disabled={sortedRows.length === 0}>
                <Download className="h-3.5 w-3.5" /> Export
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-52">
              <DropdownMenuItem onClick={() => handleExport(sortedRows)}>
                <FileText className="h-3.5 w-3.5 mr-2" /> Export current page
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => handleExport(selectedRows)} disabled={selectedRows.length === 0}>
                <Filter className="h-3.5 w-3.5 mr-2" /> Export selected ({selectedRows.length})
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={() => toast.info("Tax reports are generated from API transactions and receipts only.")}>
                <Receipt className="h-3.5 w-3.5 mr-2" /> BIR tax data source
              </DropdownMenuItem>
              <DropdownMenuItem onClick={() => toast.info("Batch printing uses the receipts linked to API transactions.")}>
                <Printer className="h-3.5 w-3.5 mr-2" /> Print linked receipts
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => loadTransactions(true)}
            disabled={refreshing}
          >
            {refreshing ? <Loader2 className="h-3.5 w-3.5 animate-spin" /> : <RefreshCw className="h-3.5 w-3.5" />}
          </Button>
        </div>
      </div>

      {error && (
        <Alert className="border-destructive/30 bg-destructive/5">
          <AlertTriangle className="h-4 w-4 text-destructive" />
          <AlertDescription className="text-sm text-destructive">{error}</AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        {[
          {
            label: "API Revenue",
            value: compactPeso(data.summary.total_revenue),
            sub: `${data.summary.completed_transactions} completed`,
            icon: TrendingUp,
            accent: "border-l-success",
            iconBg: "bg-success/10",
            iconColor: "text-success",
            delta: data.summary.total_transactions > 0 ? "DB live" : null,
          },
          {
            label: "VAT Collected",
            value: compactPeso(data.summary.total_vat),
            sub: "from completed API transactions",
            icon: Receipt,
            accent: "border-l-chart-2",
            iconBg: "bg-chart-2/10",
            iconColor: "text-chart-2",
            delta: null,
          },
          {
            label: "Avg. Transaction",
            value: fullPeso(data.summary.average_transaction),
            sub: "per completed API sale",
            icon: Activity,
            accent: "border-l-primary",
            iconBg: "bg-primary/10",
            iconColor: "text-primary",
            delta: null,
          },
          {
            label: "Void Rate",
            value: `${data.summary.void_rate.toFixed(1)}%`,
            sub: `${data.summary.voided_transactions} voided transactions`,
            icon: XCircle,
            accent: "border-l-destructive",
            iconBg: "bg-destructive/10",
            iconColor: "text-destructive",
            delta: null,
          },
          {
            label: "Pending",
            value: data.summary.pending_transactions.toString(),
            sub: "awaiting completion",
            icon: Clock,
            accent: "border-l-warning",
            iconBg: "bg-warning/10",
            iconColor: "text-warning",
            delta: null,
          },
        ].map((s) => (
          <Card key={s.label} className={cn("border-l-4", s.accent)}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between mb-3">
                <div className={cn("rounded-lg p-2", s.iconBg)}>
                  <s.icon className={cn("h-4 w-4", s.iconColor)} />
                </div>
                {s.delta && (
                  <span className="flex items-center gap-0.5 text-[11px] font-semibold text-success">
                    <Database className="h-3 w-3" />{s.delta}
                  </span>
                )}
              </div>
              <p className="text-xl font-extrabold text-foreground tracking-tight tabular-nums">{s.value}</p>
              <p className="text-xs font-medium text-muted-foreground mt-0.5">{s.label}</p>
              <p className="text-[11px] text-muted-foreground/70 mt-0.5">{s.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        <Card className="lg:col-span-2">
          <CardHeader className="pb-3">
            <div className="flex items-center justify-between">
              <div>
                <CardTitle className="text-sm">API Transaction Volume</CardTitle>
                <p className="text-xs text-muted-foreground mt-0.5">By status, anchored to the latest database transaction</p>
              </div>
              <Badge variant="secondary" className="text-[10px] gap-1">
                <span className="h-1.5 w-1.5 rounded-full bg-success animate-pulse" /> Live DB
              </Badge>
            </div>
          </CardHeader>
          <CardContent>
            <ChartContainer config={volConfig} className="min-h-[220px] w-full">
              <BarChart data={weekly} accessibilityLayer>
                <CartesianGrid vertical={false} stroke="var(--border)" />
                <XAxis dataKey="day" tickLine={false} axisLine={false} tick={{ fontSize: 11 }} />
                <YAxis allowDecimals={false} tickLine={false} axisLine={false} tick={{ fontSize: 10 }} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <ChartLegend content={<ChartLegendContent />} />
                <Bar dataKey="completed" fill="var(--color-completed)" radius={[3, 3, 0, 0]} stackId="a" />
                <Bar dataKey="pending" fill="var(--color-pending)" radius={[0, 0, 0, 0]} stackId="a" />
                <Bar dataKey="voided" fill="var(--color-voided)" radius={[3, 3, 0, 0]} stackId="a" />
              </BarChart>
            </ChartContainer>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm">Revenue by API Channel</CardTitle>
            <p className="text-xs text-muted-foreground mt-0.5">Completed transactions only</p>
          </CardHeader>
          <CardContent>
            <ChartContainer config={channelConfig} className="min-h-[220px] w-full">
              <BarChart data={channelRevenue} layout="vertical" accessibilityLayer>
                <CartesianGrid horizontal={false} stroke="var(--border)" />
                <XAxis type="number" tickLine={false} axisLine={false} tick={{ fontSize: 9 }} tickFormatter={(value) => `₱${(Number(value) / 1000).toFixed(0)}K`} />
                <YAxis type="category" dataKey="channel" tickLine={false} axisLine={false} tick={{ fontSize: 10 }} width={84} />
                <ChartTooltip content={<ChartTooltipContent />} />
                <Bar dataKey="amount" fill="var(--color-amount)" radius={[0, 3, 3, 0]} />
              </BarChart>
            </ChartContainer>
          </CardContent>
        </Card>
      </div>

      <Card>
        <div className="flex flex-wrap items-center gap-2 p-4 border-b">
          <div className="relative flex-1 min-w-0 basis-full sm:min-w-[220px] sm:basis-auto">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search transaction, receipt, customer, source, RDO..."
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1); }}
              className="pl-9 text-sm"
            />
          </div>
          <Button
            variant="outline"
            size="sm"
            className={cn("gap-2 h-9", showFilters && "bg-accent text-accent-foreground")}
            onClick={() => setShowFilters(!showFilters)}
          >
            <SlidersHorizontal className="h-3.5 w-3.5" />
            Filters
            {hasFilters && <span className="h-1.5 w-1.5 rounded-full bg-primary" />}
          </Button>
          {selectedIds.size > 0 && (
            <div className="flex items-center gap-1.5 rounded-md border bg-muted/40 px-3 py-1.5 text-xs font-medium text-foreground">
              <span className="h-3 w-3 rounded-sm bg-primary flex items-center justify-center">
                <CheckCircle2 className="h-2 w-2 text-primary-foreground" />
              </span>
              {selectedIds.size} selected
            </div>
          )}
        </div>

        {showFilters && (
          <div className="flex flex-wrap items-center gap-2 px-4 py-3 border-b bg-muted/20">
            <span className="text-xs font-medium text-muted-foreground mr-1">Filter by:</span>
            <Select value={statusFilter} onValueChange={(value) => { setStatusFilter(value); setPage(1); }}>
              <SelectTrigger className="h-8 w-full text-xs sm:w-36"><SelectValue placeholder="Status" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="completed">Completed</SelectItem>
                <SelectItem value="voided">Voided</SelectItem>
                <SelectItem value="pending">Pending</SelectItem>
              </SelectContent>
            </Select>
            <Select value={typeFilter} onValueChange={(value) => { setTypeFilter(value); setPage(1); }}>
              <SelectTrigger className="h-8 w-full text-xs sm:w-40"><SelectValue placeholder="Channel" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Channels</SelectItem>
                {channels.map((channel) => <SelectItem key={channel} value={channel}>{channel}</SelectItem>)}
              </SelectContent>
            </Select>
            <Select value={branchFilter} onValueChange={(value) => { setBranchFilter(value); setPage(1); }}>
              <SelectTrigger className="h-8 w-full text-xs sm:w-48"><SelectValue placeholder="Branch" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Branches</SelectItem>
                {branches.map((branch) => <SelectItem key={branch} value={branch}>{branch}</SelectItem>)}
              </SelectContent>
            </Select>
            <Select value={paymentFilter} onValueChange={(value) => { setPaymentFilter(value); setPage(1); }}>
              <SelectTrigger className="h-8 w-full text-xs sm:w-44"><SelectValue placeholder="Payment" /></SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Payments</SelectItem>
                {payments.map((payment) => <SelectItem key={payment} value={payment}>{normalizePaymentLabel(payment)}</SelectItem>)}
              </SelectContent>
            </Select>
            {hasFilters && (
              <Button variant="ghost" size="sm" className="h-8 text-xs gap-1 text-muted-foreground" onClick={clearFilters}>
                <X className="h-3 w-3" /> Clear all
              </Button>
            )}
            <span className="ml-auto text-[11px] text-muted-foreground">
              {data.pagination.total.toLocaleString()} database result{data.pagination.total !== 1 ? "s" : ""}
            </span>
          </div>
        )}

        <CardContent className="p-0">
          {loading && (
            <div className="py-16 text-center text-muted-foreground">
              <Loader2 className="h-8 w-8 mx-auto mb-3 animate-spin" />
              <p className="text-sm font-medium">Loading API transactions from database...</p>
            </div>
          )}

          {!loading && sortedRows.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/30">
                    <th className="px-4 py-3 w-10">
                      <Checkbox checked={allPageSelected} onCheckedChange={togglePageSelect} />
                    </th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Transaction</th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Document</th>
                    <th
                      className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider cursor-pointer select-none hover:text-foreground transition-colors"
                      onClick={() => toggleSort("date")}
                    >
                      Date & Time <SortIcon k="date" />
                    </th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Branch</th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">RDO Branch</th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Channel</th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Payment</th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Customer</th>
                    <th
                      className="px-4 py-3 text-right text-[11px] font-semibold text-muted-foreground uppercase tracking-wider cursor-pointer select-none hover:text-foreground transition-colors"
                      onClick={() => toggleSort("amount")}
                    >
                      Amount <SortIcon k="amount" />
                    </th>
                    <th className="px-4 py-3 text-right text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">VAT</th>
                    <th className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">Status</th>
                    <th className="px-4 py-3 w-10" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/50">
                  {sortedRows.map((tx) => {
                    const sm = statusMeta[tx.status];
                    const PayIcon = paymentIcon[tx.payment] ?? CreditCard;
                    const isSelected = selectedIds.has(tx.db_id);
                    return (
                      <tr
                        key={tx.db_id}
                        className={cn(
                          "group hover:bg-muted/20 transition-colors cursor-pointer",
                          isSelected && "bg-primary/5 hover:bg-primary/8",
                          tx.status === "voided" && "opacity-60"
                        )}
                        onClick={() => setSelected(tx)}
                      >
                        <td className="px-4 py-3.5" onClick={(event) => { event.stopPropagation(); toggleSelect(tx.db_id); }}>
                          <Checkbox checked={isSelected} onCheckedChange={() => toggleSelect(tx.db_id)} />
                        </td>
                        <td className="px-4 py-3.5">
                          <p className="font-mono text-xs font-semibold text-foreground tracking-tight">{tx.id}</p>
                          <p className="text-[10px] text-muted-foreground mt-0.5 font-mono">{tx.receipt_number ?? "No receipt linked"}</p>
                        </td>
                        <td className="px-4 py-3.5">
                          <div className="flex items-start gap-2">
                            <Badge variant="outline" className="shrink-0 text-[10px] font-mono">
                              {tx.bir_template_code}
                            </Badge>
                            <div className="min-w-0">
                              <p className="max-w-44 truncate text-xs font-medium text-foreground">{tx.document_label}</p>
                              <p className="text-[10px] text-muted-foreground">{tx.document_type.replaceAll("_", " ")}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3.5">
                          <div className="flex items-center gap-1.5">
                            <CalendarDays className="h-3 w-3 text-muted-foreground/50 shrink-0" />
                            <div>
                              <p className="text-xs text-foreground font-medium">{tx.date}</p>
                              <p className="text-[10px] text-muted-foreground">{tx.time}</p>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3.5">
                          <div className="flex items-center gap-1.5">
                            <Building2 className="h-3 w-3 text-muted-foreground/50 shrink-0" />
                            <div className="min-w-0">
                              <span className="block max-w-44 truncate text-xs text-muted-foreground">{tx.branch}</span>
                              <span className="block text-[10px] text-muted-foreground/60">{tx.branch_type ?? "api"} · {tx.branch_code ?? "N/A"}</span>
                            </div>
                          </div>
                        </td>
                        <td className="px-4 py-3.5">
                          <div className="flex items-center gap-1.5">
                            <ShieldCheck className="h-3 w-3 text-muted-foreground/50 shrink-0" />
                            <span className="max-w-48 truncate text-xs text-muted-foreground">{tx.rdo_branch ?? "Not mapped"}</span>
                          </div>
                        </td>
                        <td className="px-4 py-3.5">
                          <span className={cn(
                            "inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold border",
                            channelColors[tx.type] ?? "bg-muted text-muted-foreground border-border"
                          )}>
                            {tx.type}
                          </span>
                          <p className="text-[10px] text-muted-foreground mt-1">{tx.source_system}</p>
                        </td>
                        <td className="px-4 py-3.5">
                          <div className="flex items-center gap-1.5">
                            <PayIcon className="h-3 w-3 text-muted-foreground/70 shrink-0" />
                            <span className="text-xs text-muted-foreground">{normalizePaymentLabel(tx.payment)}</span>
                          </div>
                        </td>
                        <td className="px-4 py-3.5">
                          <span className="block text-xs text-foreground">{tx.customer}</span>
                          {tx.customer_tin && <span className="block text-[10px] text-muted-foreground font-mono">{tx.customer_tin}</span>}
                        </td>
                        <td className="px-4 py-3.5 text-right">
                          <span className="text-sm font-bold text-foreground tabular-nums">{fullPeso(tx.amount)}</span>
                        </td>
                        <td className="px-4 py-3.5 text-right">
                          <span className="text-xs text-muted-foreground tabular-nums">{fullPeso(tx.vat)}</span>
                          <p className="text-[10px] text-muted-foreground/60">{tx.tax_classification}</p>
                        </td>
                        <td className="px-4 py-3.5">
                          <span className={cn(
                            "inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border",
                            sm.pill
                          )}>
                            <sm.icon className="h-2.5 w-2.5" />
                            {sm.label}
                          </span>
                        </td>
                        <td className="px-4 py-3.5">
                          <DropdownMenu>
                            <DropdownMenuTrigger asChild onClick={(event) => event.stopPropagation()}>
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                              >
                                <MoreHorizontal className="h-3.5 w-3.5" />
                              </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end" className="w-48">
                              <DropdownMenuItem onClick={() => setSelected(tx)}>
                                <Eye className="h-3.5 w-3.5 mr-2" /> View details
                              </DropdownMenuItem>
                              <DropdownMenuItem onClick={() => { navigator.clipboard.writeText(tx.id); toast.success("Transaction reference copied."); }}>
                                <Copy className="h-3.5 w-3.5 mr-2" /> Copy reference
                              </DropdownMenuItem>
                              {tx.receipt_number && (
                                <DropdownMenuItem onClick={() => { navigator.clipboard.writeText(tx.receipt_number ?? ""); toast.success("Receipt number copied."); }}>
                                  <Receipt className="h-3.5 w-3.5 mr-2" /> Copy receipt
                                </DropdownMenuItem>
                              )}
                            </DropdownMenuContent>
                          </DropdownMenu>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}

          {!loading && sortedRows.length === 0 && (
            <div className="py-16 text-center">
              <Receipt className="h-10 w-10 mx-auto mb-3 text-muted-foreground opacity-25" />
              <p className="text-sm font-medium text-muted-foreground">
                {hasFilters || search ? "No API transactions match your filters" : "No API transactions found for this Business Account"}
              </p>
              <p className="text-xs text-muted-foreground/60 mt-1">
                Transactions will appear here after the connected POS, accounting, billing, or e-commerce system posts to the NUERS integration API.
              </p>
              {hasFilters && (
                <Button variant="outline" size="sm" className="mt-4 gap-2" onClick={clearFilters}>
                  <X className="h-3.5 w-3.5" /> Clear filters
                </Button>
              )}
            </div>
          )}

          <div className="flex items-center justify-between px-4 py-3 border-t bg-muted/20">
            <p className="text-xs text-muted-foreground">
              {selectedIds.size > 0
                ? <><strong className="text-foreground">{selectedIds.size}</strong> selected · </>
                : null
              }
              Showing{" "}
              <strong className="text-foreground">
                {data.pagination.total === 0 ? 0 : ((data.pagination.page - 1) * PAGE_SIZE) + 1}–{Math.min(data.pagination.page * PAGE_SIZE, data.pagination.total)}
              </strong>
              {" "}of{" "}
              <strong className="text-foreground">{data.pagination.total.toLocaleString()}</strong>
            </p>
            <div className="flex items-center gap-1">
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage(1)} disabled={page === 1}>
                <ChevronLeft className="h-3 w-3" />
              </Button>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={page === 1}>
                <ChevronLeft className="h-3.5 w-3.5" />
              </Button>
              <div className="flex items-center gap-1 px-2">
                <span className="text-xs font-semibold text-foreground">{data.pagination.page}</span>
                <span className="text-xs text-muted-foreground">/</span>
                <span className="text-xs text-muted-foreground">{data.pagination.total_pages}</span>
              </div>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage((current) => Math.min(data.pagination.total_pages, current + 1))} disabled={page === data.pagination.total_pages}>
                <ChevronRight className="h-3.5 w-3.5" />
              </Button>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage(data.pagination.total_pages)} disabled={page === data.pagination.total_pages}>
                <ChevronRight className="h-3 w-3" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <Dialog open={!!selected} onOpenChange={() => setSelected(null)}>
        <DialogContent className="max-h-[92vh] max-w-4xl overflow-y-auto">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2.5 text-sm">
              <div className="rounded-lg bg-primary/10 p-1.5">
                <Receipt className="h-4 w-4 text-primary" />
              </div>
              API Transaction Detail
            </DialogTitle>
          </DialogHeader>
          {selected && (() => {
            const sm = statusMeta[selected.status];
            return (
              <div className="space-y-5">
                <div className={cn(
                  "flex items-center gap-3 rounded-xl border p-4",
                  sm.pill
                )}>
                  <div className="rounded-full bg-background/70 p-1.5">
                    <sm.icon className="h-4 w-4" />
                  </div>
                  <div className="flex-1">
                    <p className="text-sm font-semibold">{sm.label}</p>
                    <p className="text-[11px] text-muted-foreground">{selected.date} at {selected.time}</p>
                  </div>
                  <span className="font-mono text-xs text-muted-foreground">{selected.id}</span>
                </div>

                <BirDocumentPreview
                  documentType={selected.document_type}
                  taxType={selected.tax_type}
                  documentNumber={selected.receipt_number || selected.id}
                  seriesNumber={selected.series_number || selected.transaction_ref || selected.id}
                  issueDate={`${selected.date} ${selected.time}`}
                  seller={{
                    name: data.merchant?.business_name ?? "Business Account",
                    tin: data.merchant?.tin ?? undefined,
                    permitToIssueNumber: selected.permit_to_issue_number,
                    espAccreditationNumber: selected.esp_accreditation_number,
                  }}
                  buyer={{
                    name: selected.customer,
                    tin: selected.customer_tin ?? undefined,
                  }}
                  items={[{
                    description: selected.transaction_ref || selected.source_system || "API transaction",
                    quantity: 1,
                    unit: "txn",
                    unitPrice: toCentavos(selected.amount),
                    amount: toCentavos(selected.amount),
                    taxType: selected.tax_type,
                  }]}
                  totals={{
                    gross: toCentavos(selected.amount),
                    vatableSales: toCentavos(selected.vatable_sales),
                    vatAmount: toCentavos(selected.vat),
                    totalDue: toCentavos(selected.amount),
                  }}
                  paymentMethod={selected.payment}
                  referenceNumber={selected.transaction_ref ?? undefined}
                  status={selected.status}
                  qrValue={selected.receipt_number || selected.id}
                />

                <div className="grid grid-cols-2 gap-2.5">
                  {[
                    { label: "Transaction Ref", value: selected.id, mono: true },
                    { label: "Receipt No.", value: selected.receipt_number ?? "No receipt linked", mono: true },
                    { label: "BIR/RMC Document", value: selected.document_label },
                    { label: "Template Code", value: selected.bir_template_code, mono: true },
                    { label: "Series No.", value: selected.series_number ?? "N/A", mono: true },
                    { label: "Date & Time", value: `${selected.date} ${selected.time}` },
                    { label: "Branch", value: selected.branch },
                    { label: "Branch Type", value: selected.branch_type ?? "api" },
                    { label: "RDO Branch", value: selected.rdo_branch ?? "Not mapped" },
                    { label: "Channel", value: selected.type },
                    { label: "Source System", value: selected.source_system },
                    { label: "API Key", value: selected.api_key_name ?? "Not captured" },
                    { label: "Payment Method", value: normalizePaymentLabel(selected.payment) },
                    { label: "Customer", value: selected.customer },
                    { label: "Customer TIN", value: selected.customer_tin ?? "N/A", mono: true },
                    { label: "Tax Classification", value: selected.tax_classification },
                    { label: "Raw Status", value: selected.raw_status || selected.status },
                  ].map((field) => (
                    <div key={field.label} className="rounded-lg bg-muted/40 px-3 py-2.5">
                      <p className="text-[10px] text-muted-foreground uppercase tracking-wider font-medium">{field.label}</p>
                      <p className={cn("text-xs font-semibold text-foreground mt-0.5 break-words", field.mono && "font-mono")}>{field.value}</p>
                    </div>
                  ))}
                </div>

                <Separator />

                <div className="space-y-2">
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">Net Amount</span>
                    <span className="font-medium text-foreground tabular-nums">{fullPeso(selected.net)}</span>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">VAT Amount</span>
                    <span className="font-medium text-foreground tabular-nums">{fullPeso(selected.vat)}</span>
                  </div>
                  <div className="flex justify-between text-sm">
                    <span className="text-muted-foreground">VATable Sales</span>
                    <span className="font-medium text-foreground tabular-nums">{fullPeso(selected.vatable_sales)}</span>
                  </div>
                  <Separator />
                  <div className="flex justify-between items-baseline">
                    <span className="text-sm font-bold text-foreground">Gross Amount</span>
                    <span className="text-2xl font-extrabold text-foreground tabular-nums">{fullPeso(selected.amount)}</span>
                  </div>
                </div>

                {selected.status === "voided" && (
                  <div className="flex items-start gap-2.5 rounded-xl border border-destructive/20 bg-destructive/5 p-3.5">
                    <AlertTriangle className="h-4 w-4 text-destructive shrink-0 mt-0.5" />
                    <p className="text-xs text-destructive font-medium">
                      This API transaction is voided or failed. Any linked receipt must not be used for tax claims.
                    </p>
                  </div>
                )}

                <div className="flex flex-wrap gap-2">
                  <Button className="flex-1 gap-2" size="sm" onClick={() => { navigator.clipboard.writeText(selected.id); toast.success("Transaction reference copied."); }}>
                    <Copy className="h-3.5 w-3.5" /> Copy Transaction
                  </Button>
                  {selected.receipt_number && (
                    <Button variant="outline" size="sm" className="gap-2" onClick={() => { navigator.clipboard.writeText(selected.receipt_number ?? ""); toast.success("Receipt number copied."); }}>
                      <Receipt className="h-3.5 w-3.5" /> Copy Receipt
                    </Button>
                  )}
                  <Button variant="outline" size="sm" className="gap-2" onClick={() => toast.info("Print uses the linked electronic receipt from the API receipt ledger.")}>
                    <Printer className="h-3.5 w-3.5" /> Print
                  </Button>
                </div>
              </div>
            );
          })()}
        </DialogContent>
      </Dialog>
    </div>
  );
}
