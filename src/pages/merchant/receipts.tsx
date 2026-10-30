import { exportReport as openReportExport } from "@/components/reports/export-report";
import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import {
  QrCode,
  Search,
  Download,
  CheckCircle2,
  XCircle,
  Eye,
  Copy,
  Receipt,
  ChevronLeft,
  ChevronRight,
  Printer,
  TrendingUp,
  Hash,
  Building2,
  FileCheck,
  AlertCircle,
  LayoutGrid,
  List,
  ScanLine,
  ArrowUpRight,
  CalendarDays,
  Filter,
  Loader2,
  Database,
  Clock,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";
import { BirDocumentPreview } from "@/components/invoices/bir-document-preview";

const API_TOKEN_KEY = "nuers_api_token";
const PAGE_SIZE = 12;

type ReceiptStatus = "valid" | "voided" | "pending";

type ReceiptItem = {
  desc: string;
  qty: number;
  unit: string;
  price: number;
};

type ApiReceipt = {
  db_id: string;
  id: string;
  transaction_id: string | null;
  transaction_ref: string | null;
  series_no: string;
  date: string;
  time: string;
  issued_at: string;
  type: string;
  source_system: string;
  api_key_name: string | null;
  customer: string;
  amount: number;
  vat: number;
  vatable_sales: number;
  vat_exempt_sales: number;
  zero_rated_sales: number;
  status: ReceiptStatus;
  raw_status: string;
  tin_buyer: string | null;
  branch: string;
  branch_code: string | null;
  branch_type: string | null;
  cashier: string;
  receipt_type: string;
  document_type: string;
  bir_template_code: string;
  document_label: string;
  merchant_name: string;
  merchant_tin: string;
  permit_to_issue_number?: string | null;
  esp_accreditation_number?: string | null;
  merchant_address: string;
  merchant_vat_reg: string;
  rdo_code: string | null;
  rdo_name: string | null;
  rdo_branch: string | null;
  tax_type: string;
  payment_method: string;
  items: ReceiptItem[];
};

type ReceiptsPayload = {
  merchant: {
    id: string;
    business_name: string;
    tin: string;
  } | null;
  summary: {
    total_receipts: number;
    valid_receipts: number;
    voided_receipts: number;
    pending_receipts: number;
    total_revenue: number;
    total_vat: number;
    void_rate: number;
  };
  filters: {
    channels: string[];
    sources: string[];
  };
  receipts: ApiReceipt[];
};

const emptyPayload: ReceiptsPayload = {
  merchant: null,
  summary: {
    total_receipts: 0,
    valid_receipts: 0,
    voided_receipts: 0,
    pending_receipts: 0,
    total_revenue: 0,
    total_vat: 0,
    void_rate: 0,
  },
  filters: {
    channels: [],
    sources: [],
  },
  receipts: [],
};

const typeColors: Record<string, string> = {
  POS: "bg-primary/10 text-primary border-primary/20",
  "E-Commerce": "bg-chart-2/10 text-chart-2 border-chart-2/20",
  Mobile: "bg-chart-3/10 text-chart-3 border-chart-3/20",
  API: "bg-chart-4/10 text-chart-4 border-chart-4/20",
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

function exportCsv(receipts: ApiReceipt[], merchant: ReceiptsPayload["merchant"]) {
  void openReportExport({
    title: "API Receipt Records",
    scope:
      (merchant?.business_name || "Merchant") +
      " · " +
      receipts.length +
      " records loaded in this legacy receipt view. Use Invoices & Receipts for the complete filtered register.",
    table: {
      headers: [
        "Document",
        "Template",
        "Reference",
        "Transaction Reference",
        "Series",
        "Date",
        "Time",
        "Source",
        "Channel",
        "Branch",
        "Customer",
        "Buyer TIN",
        "Amount PHP",
        "VAT PHP",
        "Status",
        "RDO",
      ],
      rows: receipts.map((r) =>
        [
          r.document_label,
          r.bir_template_code,
          r.id,
          r.transaction_ref || "",
          r.series_no,
          r.date,
          r.time,
          r.source_system,
          r.type,
          r.branch,
          r.customer,
          r.tin_buyer || "",
          r.amount.toFixed(2),
          r.vat.toFixed(2),
          r.status,
          r.rdo_branch || "",
        ].map(String),
      ),
    },
  });
}

function OfficialReceiptPrint({ r }: { r: ApiReceipt }) {
  return (
    <BirDocumentPreview
      documentType={r.document_type}
      taxType={r.tax_type}
      documentNumber={r.id}
      seriesNumber={r.series_no}
      issueDate={`${r.date} ${r.time}`}
      seller={{
        name: r.merchant_name,
        tin: r.merchant_tin,
        permitToIssueNumber: r.permit_to_issue_number,
        espAccreditationNumber: r.esp_accreditation_number,
        address: r.merchant_address,
        vatRegistration: r.merchant_vat_reg,
      }}
      buyer={{
        name: r.customer,
        tin: r.tin_buyer ?? undefined,
      }}
      items={r.items.map((item) => ({
        description: item.desc,
        quantity: item.qty,
        unit: item.unit,
        unitPrice: toCentavos(item.price / Math.max(1, item.qty || 1)),
        amount: toCentavos(item.price),
        taxType: r.tax_type,
      }))}
      totals={{
        gross: toCentavos(r.amount),
        vatableSales: toCentavos(r.vatable_sales),
        vatExemptSales: toCentavos(r.vat_exempt_sales),
        zeroRatedSales: toCentavos(r.zero_rated_sales),
        vatAmount: toCentavos(r.vat),
        totalDue: toCentavos(r.amount),
      }}
      paymentMethod={r.payment_method}
      referenceNumber={r.transaction_ref ?? undefined}
      qrValue={r.id}
      status={r.status}
    />
  );
}

export function MerchantReceipts() {
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [typeFilter, setTypeFilter] = useState("all");
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<ApiReceipt | null>(null);
  const [qrSearch, setQrSearch] = useState("");
  const [qrResult, setQrResult] = useState<{
    valid: boolean;
    found?: ApiReceipt;
    msg: string;
  } | null>(null);
  const [activeView, setActiveView] = useState<"table" | "grid">("table");
  const [data, setData] = useState<ReceiptsPayload>(emptyPayload);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState("");

  const sequence = useRef(0);
  useRealtimeRefresh(() => loadReceipts(true), !selected);

  const loadReceipts = useCallback(
    async (quiet = false) => {
      const ticket = ++sequence.current;
      if (!quiet) {
        setLoading(true);
      }
      setError("");

      try {
        const params = new URLSearchParams();
        if (search.trim()) params.set("search", search.trim());
        if (statusFilter !== "all") params.set("status", statusFilter);
        if (typeFilter !== "all") params.set("channel", typeFilter);

        const response = await apiFetch(
          `/api/merchant/receipts${params.toString() ? `?${params}` : ""}`,
          {
            headers: authHeaders(),
            cache: "no-store",
          },
        );
        const payload = await readJsonResponse<ReceiptsPayload>(
          response,
          "Unable to load API-generated receipts.",
        );

        if (ticket === sequence.current) setData(payload);
      } catch (err) {
        if (ticket !== sequence.current) return;
        if (!quiet) setData(emptyPayload);
        setError(err instanceof Error ? err.message : "Unable to load API-generated receipts.");
      } finally {
        if (ticket === sequence.current) {
          setLoading(false);
          setRefreshing(false);
        }
      }
    },
    [search, statusFilter, typeFilter],
  );

  useEffect(() => {
    const timer = window.setTimeout(() => {
      loadReceipts();
    }, 250);

    return () => {
      window.clearTimeout(timer);
      ++sequence.current;
    };
  }, [loadReceipts]);

  useEffect(() => {
    setPage(1);
  }, [search, statusFilter, typeFilter]);

  const receipts = data.receipts;
  const totalPages = Math.max(1, Math.ceil(receipts.length / PAGE_SIZE));
  const paginated = receipts.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
  const channels = useMemo(
    () => Array.from(new Set(["POS", "E-Commerce", "Mobile", "API", ...data.filters.channels])),
    [data.filters.channels],
  );

  async function verifyQr() {
    const needle = qrSearch.trim();
    if (!needle) return;
    setQrResult(null);

    try {
      const response = await apiFetch(
        `/api/merchant/receipts/verify?receipt=${encodeURIComponent(needle)}`,
        {
          headers: authHeaders(),
          cache: "no-store",
        },
      );
      const payload = await readJsonResponse<{ receipt: ApiReceipt }>(
        response,
        `No API receipt found for "${needle}".`,
      );

      const found = payload.receipt as ApiReceipt;
      setQrResult({
        valid: found.status === "valid",
        found,
        msg:
          found.status === "voided"
            ? `Receipt ${found.id} is VOIDED and no longer valid.`
            : found.status === "pending"
              ? `Receipt ${found.id} is in PENDING status.`
              : `Receipt ${found.id} is VALID — ${fullPeso(found.amount)}`,
      });
    } catch (err) {
      setQrResult({
        valid: false,
        msg: err instanceof Error ? err.message : `No API receipt found for "${needle}".`,
      });
    }
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-bold text-foreground tracking-tight">Receipt Management</h1>
          <p className="text-sm text-muted-foreground mt-0.5">
            {data.summary.total_receipts.toLocaleString()} API-generated receipts ·
            POS/accounting/billing integrations · Business Account scoped
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => exportCsv(receipts, data.merchant)}
            disabled={receipts.length === 0}
          >
            <Download className="h-3.5 w-3.5" /> Export
          </Button>
          <Button
            size="sm"
            className="gap-2"
            onClick={() => toast.success("Batch print uses the currently loaded API receipts.")}
            disabled={receipts.length === 0}
          >
            <Printer className="h-3.5 w-3.5" /> Print Batch
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="gap-2"
            onClick={() => loadReceipts(true)}
            disabled={refreshing}
          >
            {refreshing ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
            ) : (
              <Database className="h-3.5 w-3.5" />
            )}
            Refresh
          </Button>
        </div>
      </div>

      {error && (
        <Alert className="border-destructive/30 bg-destructive/5">
          <AlertCircle className="h-4 w-4 text-destructive" />
          <AlertDescription className="text-sm text-destructive">{error}</AlertDescription>
        </Alert>
      )}

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          {
            label: "API Receipts",
            value: data.summary.total_receipts.toString(),
            icon: Receipt,
            sub: `${data.summary.valid_receipts} valid · ${data.summary.voided_receipts} voided · ${data.summary.pending_receipts} pending`,
            border: "border-l-primary",
            trend: null,
          },
          {
            label: "API Revenue",
            value: compactPeso(data.summary.total_revenue),
            icon: TrendingUp,
            sub: "from valid API receipts only",
            border: "border-l-success",
            trend: data.summary.total_receipts > 0 ? "Live" : null,
          },
          {
            label: "VAT Collected",
            value: compactPeso(data.summary.total_vat),
            icon: Hash,
            sub: "from API-issued EORs",
            border: "border-l-chart-2",
            trend: null,
          },
          {
            label: "Voided Receipts",
            value: data.summary.voided_receipts.toString(),
            icon: XCircle,
            sub: `${data.summary.void_rate.toFixed(1)}% void rate`,
            border: "border-l-destructive",
            trend: null,
          },
        ].map((s) => (
          <Card
            key={s.label}
            className={cn("border-l-4 bg-gradient-to-br to-transparent", s.border)}
          >
            <CardContent className="p-5">
              <div className="flex items-start justify-between mb-3">
                <div
                  className={cn(
                    "rounded-lg p-2",
                    s.border === "border-l-primary"
                      ? "bg-primary/10"
                      : s.border === "border-l-success"
                        ? "bg-success/10"
                        : s.border === "border-l-destructive"
                          ? "bg-destructive/10"
                          : "bg-muted",
                  )}
                >
                  <s.icon
                    className={cn(
                      "h-4 w-4",
                      s.border === "border-l-primary"
                        ? "text-primary"
                        : s.border === "border-l-success"
                          ? "text-success"
                          : s.border === "border-l-destructive"
                            ? "text-destructive"
                            : "text-muted-foreground",
                    )}
                  />
                </div>
                {s.trend && (
                  <span className="flex items-center gap-0.5 text-[11px] font-semibold text-success">
                    <ArrowUpRight className="h-3 w-3" />
                    {s.trend}
                  </span>
                )}
              </div>
              <p className="text-2xl font-extrabold text-foreground tracking-tight">{s.value}</p>
              <p className="text-xs text-muted-foreground mt-0.5 font-medium">{s.label}</p>
              <p className="text-[11px] text-muted-foreground/70 mt-0.5">{s.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Card className="border-primary/20 bg-gradient-to-r from-primary/[0.03] to-transparent">
        <CardHeader className="pb-4">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2.5">
              <div className="rounded-lg bg-primary/10 p-2">
                <ScanLine className="h-4 w-4 text-primary" />
              </div>
              <div>
                <CardTitle className="text-sm font-semibold">API Receipt Verification</CardTitle>
                <p className="text-[11px] text-muted-foreground mt-0.5">
                  Verify receipts generated from connected POS, accounting, billing, and e-commerce
                  systems
                </p>
              </div>
            </div>
            <Badge variant="secondary" className="text-[10px] gap-1 font-medium">
              <span className="h-1.5 w-1.5 rounded-full bg-success animate-pulse" />
              Account scoped
            </Badge>
          </div>
        </CardHeader>
        <CardContent>
          <div className="flex gap-2 max-w-2xl">
            <div className="relative flex-1">
              <QrCode className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Enter receipt number, series number, or external transaction reference..."
                value={qrSearch}
                onChange={(e) => {
                  setQrSearch(e.target.value);
                  setQrResult(null);
                }}
                onKeyDown={(e) => e.key === "Enter" && verifyQr()}
                className="pl-10 font-mono text-sm"
              />
            </div>
            <Button onClick={verifyQr} className="gap-2 shrink-0 px-5">
              <ScanLine className="h-4 w-4" /> Verify
            </Button>
          </div>

          {qrResult && (
            <div
              className={cn(
                "mt-4 rounded-xl border p-4 transition-all",
                qrResult.valid
                  ? "border-success/30 bg-success/5"
                  : "border-destructive/30 bg-destructive/5",
              )}
            >
              <div className="flex items-start gap-3">
                <div
                  className={cn(
                    "rounded-full p-1.5 shrink-0",
                    qrResult.valid ? "bg-success/20" : "bg-destructive/20",
                  )}
                >
                  {qrResult.valid ? (
                    <CheckCircle2 className="h-4 w-4 text-success" />
                  ) : (
                    <XCircle className="h-4 w-4 text-destructive" />
                  )}
                </div>
                <div className="flex-1">
                  <p
                    className={cn(
                      "text-sm font-semibold",
                      qrResult.valid ? "text-success" : "text-destructive",
                    )}
                  >
                    {qrResult.msg}
                  </p>
                  {qrResult.found && (
                    <div className="grid grid-cols-2 gap-3 mt-3 sm:grid-cols-3">
                      {[
                        { label: "Channel", value: qrResult.found.type },
                        { label: "Date", value: qrResult.found.date },
                        { label: "Branch", value: qrResult.found.branch },
                        { label: "Source", value: qrResult.found.source_system },
                        { label: "Customer", value: qrResult.found.customer },
                        { label: "Series No.", value: qrResult.found.series_no },
                      ].map((f) => (
                        <div key={f.label}>
                          <p className="text-[10px] text-muted-foreground uppercase tracking-wider">
                            {f.label}
                          </p>
                          <p className="text-xs font-semibold text-foreground">{f.value}</p>
                        </div>
                      ))}
                    </div>
                  )}
                  {!qrResult.found && !qrResult.valid && (
                    <Alert className="mt-3 border-destructive/20 bg-destructive/5 py-2">
                      <AlertCircle className="h-3.5 w-3.5 text-destructive" />
                      <AlertDescription className="text-xs text-destructive">
                        This receipt ID is not in this Business Account’s API receipt registry.
                      </AlertDescription>
                    </Alert>
                  )}
                </div>
                {qrResult.found && (
                  <Button
                    variant="outline"
                    size="sm"
                    className="text-xs gap-1.5 shrink-0"
                    onClick={() => setSelected(qrResult.found!)}
                  >
                    <Eye className="h-3 w-3" /> View
                  </Button>
                )}
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <div className="flex flex-wrap items-center gap-2 p-4 border-b">
          <div className="relative flex-1 min-w-0 basis-full sm:min-w-[220px] sm:basis-auto">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search receipt, series, customer, external ref..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="pl-9"
            />
          </div>
          <div className="flex items-center gap-1.5 text-muted-foreground">
            <Filter className="h-3.5 w-3.5" />
          </div>
          <Select value={statusFilter} onValueChange={setStatusFilter}>
            <SelectTrigger className="h-9 w-full text-xs sm:w-36">
              <SelectValue placeholder="All Status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Status</SelectItem>
              <SelectItem value="valid">Valid</SelectItem>
              <SelectItem value="voided">Voided</SelectItem>
              <SelectItem value="pending">Pending</SelectItem>
            </SelectContent>
          </Select>
          <Select value={typeFilter} onValueChange={setTypeFilter}>
            <SelectTrigger className="h-9 w-full text-xs sm:w-40">
              <SelectValue placeholder="All Channels" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Channels</SelectItem>
              {channels.map((channel) => (
                <SelectItem key={channel} value={channel}>
                  {channel}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <div className="flex items-center rounded-md border bg-muted/40 p-0.5 gap-0.5 ml-auto">
            <button
              onClick={() => setActiveView("table")}
              className={cn(
                "rounded px-2.5 py-1.5 transition-colors",
                activeView === "table"
                  ? "bg-background shadow-sm text-foreground"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              <List className="h-3.5 w-3.5" />
            </button>
            <button
              onClick={() => setActiveView("grid")}
              className={cn(
                "rounded px-2.5 py-1.5 transition-colors",
                activeView === "grid"
                  ? "bg-background shadow-sm text-foreground"
                  : "text-muted-foreground hover:text-foreground",
              )}
            >
              <LayoutGrid className="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <CardContent className="p-0">
          {loading && (
            <div className="py-16 text-center text-muted-foreground">
              <Loader2 className="h-8 w-8 mx-auto mb-3 animate-spin" />
              <p className="text-sm font-medium">Loading API receipt records...</p>
            </div>
          )}

          {!loading && activeView === "table" && receipts.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full">
                <thead>
                  <tr className="border-b bg-muted/30">
                    {[
                      "Document",
                      "External Ref",
                      "Date & Time",
                      "Branch",
                      "Channel",
                      "Source",
                      "Customer",
                      "Amount",
                      "VAT",
                      "Status",
                      "",
                    ].map((h) => (
                      <th
                        key={h}
                        className="px-4 py-3 text-left text-[11px] font-semibold text-muted-foreground uppercase tracking-wider"
                      >
                        {h}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-border/50">
                  {paginated.map((r) => (
                    <tr
                      key={r.db_id}
                      className={cn(
                        "group hover:bg-muted/20 transition-colors cursor-pointer",
                        r.status === "voided" && "opacity-60",
                      )}
                      onClick={() => setSelected(r)}
                    >
                      <td className="px-4 py-3.5">
                        <div className="flex items-start gap-2">
                          <Badge variant="outline" className="shrink-0 text-[10px] font-mono">
                            {r.bir_template_code}
                          </Badge>
                          <div className="min-w-0">
                            <span className="block font-mono text-xs font-semibold text-foreground tracking-tight">
                              {r.id}
                            </span>
                            <p className="max-w-40 truncate text-[10px] text-muted-foreground">
                              {r.document_label}
                            </p>
                            <p className="font-mono text-[10px] text-muted-foreground">
                              {r.series_no}
                            </p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3.5">
                        <span className="font-mono text-xs text-muted-foreground">
                          {r.transaction_ref ?? "—"}
                        </span>
                      </td>
                      <td className="px-4 py-3.5">
                        <div className="flex items-center gap-1.5">
                          <CalendarDays className="h-3 w-3 text-muted-foreground/50 shrink-0" />
                          <div>
                            <p className="text-xs text-foreground font-medium">{r.date}</p>
                            <p className="text-[10px] text-muted-foreground">{r.time}</p>
                          </div>
                        </div>
                      </td>
                      <td className="px-4 py-3.5">
                        <div className="flex items-center gap-1.5">
                          <Building2 className="h-3 w-3 text-muted-foreground/50 shrink-0" />
                          <span className="text-xs text-muted-foreground">{r.branch}</span>
                        </div>
                      </td>
                      <td className="px-4 py-3.5">
                        <span
                          className={cn(
                            "inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold border",
                            typeColors[r.type] ?? "bg-muted text-muted-foreground border-border",
                          )}
                        >
                          {r.type}
                        </span>
                      </td>
                      <td className="px-4 py-3.5 text-xs text-muted-foreground">
                        {r.source_system}
                      </td>
                      <td className="px-4 py-3.5 text-xs text-foreground">{r.customer}</td>
                      <td className="px-4 py-3.5">
                        <span className="text-sm font-bold text-foreground tabular-nums">
                          {fullPeso(r.amount)}
                        </span>
                      </td>
                      <td className="px-4 py-3.5">
                        <span className="text-xs text-muted-foreground tabular-nums">
                          {fullPeso(r.vat)}
                        </span>
                      </td>
                      <td className="px-4 py-3.5">
                        {r.status === "valid" ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-success/10 text-success border border-success/20">
                            <CheckCircle2 className="h-2.5 w-2.5" /> valid
                          </span>
                        ) : r.status === "voided" ? (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-destructive/10 text-destructive border border-destructive/20">
                            <XCircle className="h-2.5 w-2.5" /> voided
                          </span>
                        ) : (
                          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-warning/10 text-warning border border-warning/20">
                            <Clock className="h-2.5 w-2.5" /> pending
                          </span>
                        )}
                      </td>
                      <td className="px-4 py-3.5">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                          onClick={(e) => {
                            e.stopPropagation();
                            setSelected(r);
                          }}
                        >
                          <Eye className="h-3.5 w-3.5" />
                        </Button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {!loading && activeView === "grid" && receipts.length > 0 && (
            <div className="p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {paginated.map((r) => (
                <div
                  key={r.db_id}
                  className={cn(
                    "group rounded-xl border-2 p-4 hover:border-primary/40 hover:shadow-sm transition-all cursor-pointer space-y-3",
                    r.status === "voided" ? "border-border opacity-60" : "border-border",
                  )}
                  onClick={() => setSelected(r)}
                >
                  <div className="flex items-start justify-between gap-2">
                    <div>
                      <p className="font-mono text-xs font-bold text-foreground">{r.id}</p>
                      <p className="text-[10px] text-muted-foreground mt-0.5">
                        {r.date} · {r.time}
                      </p>
                      <div className="mt-2 flex items-center gap-1.5">
                        <Badge variant="outline" className="text-[10px] font-mono">
                          {r.bir_template_code}
                        </Badge>
                        <span className="truncate text-[10px] text-muted-foreground">
                          {r.document_label}
                        </span>
                      </div>
                    </div>
                    <span
                      className={cn(
                        "shrink-0 inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border",
                        r.status === "valid"
                          ? "bg-success/10 text-success border-success/20"
                          : r.status === "voided"
                            ? "bg-destructive/10 text-destructive border-destructive/20"
                            : "bg-warning/10 text-warning border-warning/20",
                      )}
                    >
                      {r.status === "valid" ? (
                        <CheckCircle2 className="h-2.5 w-2.5" />
                      ) : r.status === "voided" ? (
                        <XCircle className="h-2.5 w-2.5" />
                      ) : (
                        <Clock className="h-2.5 w-2.5" />
                      )}
                      {r.status}
                    </span>
                  </div>
                  <div className="h-px bg-border border-dashed" />
                  <div className="flex items-end justify-between gap-2">
                    <div className="min-w-0">
                      <p className="text-xs font-medium text-foreground truncate">{r.customer}</p>
                      <p className="text-[10px] text-muted-foreground">{r.branch}</p>
                      <span
                        className={cn(
                          "mt-1.5 inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold border",
                          typeColors[r.type] ?? "bg-muted text-muted-foreground border-border",
                        )}
                      >
                        {r.type}
                      </span>
                    </div>
                    <div className="text-right shrink-0">
                      <p className="text-base font-extrabold text-foreground tabular-nums">
                        {fullPeso(r.amount)}
                      </p>
                      <p className="text-[10px] text-muted-foreground">+{fullPeso(r.vat)} VAT</p>
                    </div>
                  </div>
                </div>
              ))}
            </div>
          )}

          {!loading && receipts.length === 0 && (
            <div className="py-16 text-center text-muted-foreground">
              <FileCheck className="h-10 w-10 mx-auto mb-3 opacity-25" />
              <p className="text-sm font-medium text-foreground">
                No API receipts found for this Business Account
              </p>
              <p className="text-xs mt-1 opacity-70">
                Receipts will appear here after connected POS, accounting, billing, or e-commerce
                systems post transactions to the NUERS API.
              </p>
            </div>
          )}

          <div
            data-slot="table-pagination"
            className="flex items-center justify-between px-4 py-3 border-t bg-muted/20"
          >
            <p className="text-xs text-muted-foreground">
              Showing{" "}
              <strong className="text-foreground">
                {receipts.length === 0 ? 0 : (page - 1) * PAGE_SIZE + 1}–
                {Math.min(page * PAGE_SIZE, receipts.length)}
              </strong>{" "}
              of <strong className="text-foreground">{receipts.length}</strong> API receipts
            </p>
            <div className="flex items-center gap-1">
              <Button
                variant="outline"
                size="icon"
                className="h-7 w-7"
                onClick={() => setPage(1)}
                disabled={page === 1}
              >
                <ChevronLeft className="h-3 w-3" />
              </Button>
              <Button
                variant="outline"
                size="icon"
                className="h-7 w-7"
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page === 1}
              >
                <ChevronLeft className="h-3.5 w-3.5" />
              </Button>
              <div className="flex items-center gap-1 px-2">
                <span className="text-xs font-semibold text-foreground">{page}</span>
                <span className="text-xs text-muted-foreground">/</span>
                <span className="text-xs text-muted-foreground">{totalPages}</span>
              </div>
              <Button
                variant="outline"
                size="icon"
                className="h-7 w-7"
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page === totalPages || receipts.length === 0}
              >
                <ChevronRight className="h-3.5 w-3.5" />
              </Button>
              <Button
                variant="outline"
                size="icon"
                className="h-7 w-7"
                onClick={() => setPage(totalPages)}
                disabled={page === totalPages || receipts.length === 0}
              >
                <ChevronRight className="h-3 w-3" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      <Dialog open={!!selected} onOpenChange={() => setSelected(null)}>
        <DialogContent className="max-w-lg max-h-[90vh] overflow-y-auto p-0">
          <DialogHeader className="sr-only">
            <DialogTitle>{selected?.id}</DialogTitle>
            <DialogDescription>API-generated electronic receipt detail.</DialogDescription>
          </DialogHeader>
          {selected && (
            <div className="space-y-0">
              <OfficialReceiptPrint r={selected} />
              <div className="sticky bottom-0 bg-background border-t px-6 py-3 flex items-center gap-2">
                <Button
                  className="flex-1 gap-2"
                  size="sm"
                  onClick={() =>
                    toast.success("Receipt PDF download will use this database record.")
                  }
                >
                  <Download className="h-3.5 w-3.5" /> Download PDF
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  className="gap-2"
                  onClick={() => toast.success("Sent to printer.")}
                >
                  <Printer className="h-3.5 w-3.5" /> Print
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-8 w-8"
                  onClick={() => {
                    navigator.clipboard.writeText(selected.id);
                    toast.success("Receipt ID copied!");
                  }}
                >
                  <Copy className="h-3.5 w-3.5" />
                </Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
