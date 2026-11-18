import { AccountSecuritySettings } from "@/pages/auth/account-security";
import { useEffect, useMemo, useRef, useState, type ElementType, type FormEvent } from "react";
import { Link, Navigate, useLocation } from "react-router-dom";
import { EnterpriseDashboard } from "@/components/enterprise/enterprise-dashboard";
import {
  AlertCircle,
  ArrowDownRight,
  Barcode,
  Bell,
  Building2,
  CalendarClock,
  Camera,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock3,
  CreditCard,
  Download,
  Eye,
  FileCheck2,
  FileSearch,
  FileText,
  Fingerprint,
  Landmark,
  Loader2,
  LockKeyhole,
  Mail,
  Phone,
  QrCode,
  Receipt,
  RefreshCw,
  Search,
  Save,
  ShieldAlert,
  ShieldCheck,
  Smartphone,
  Store,
  TrendingUp,
  User,
  UserCheck,
  Wallet,
} from "lucide-react";
import { toast } from "sonner";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Progress } from "@/components/ui/progress";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { supabase } from "@/lib/supabase";
import { useAuth } from "@/lib/auth";
import { useDashboardData, type DashboardListItem } from "@/lib/dashboard-data";
import { apiJson, apiFetch, publicAssetUrl, readJsonResponse } from "@/lib/api-url";
import { downloadB6ReceiptPdf } from "@/lib/receipt-pdf";

type ClientProfile = {
  id: number;
  email: string;
  role: string;
  full_name: string;
  organization: string;
  tin: string | null;
  tin_bound_at: string | null;
  profile_photo_url?: string | null;
  created_at?: string;
  updated_at?: string;
};

type ClientAccount = {
  id: string;
  user_id: number | null;
  account_number: string;
  full_name: string;
  account_type: string;
  email: string | null;
  mobile: string | null;
  wallet_balance: number;
  mfa_enabled: boolean;
  notification_preferences: Record<string, unknown> | null;
  status: string;
  tin?: string | null;
  created_at: string;
  updated_at: string;
};

type Merchant = {
  id: string;
  tin: string | null;
  business_name: string;
  rdo_code?: string | null;
  rdo_name?: string | null;
  logo_url?: string | null;
  branch_count: number;
  sector: string;
};

type MerchantTransaction = {
  id: string;
  merchant_id: string | null;
  transaction_ref: string | null;
  amount: number;
  vat_amount: number;
  vatable_sales?: number | null;
  net_amount?: number | null;
  payment_method: string;
  branch: string | null;
  rdo_code?: string | null;
  rdo_name?: string | null;
  channel: string;
  transaction_type: string;
  tax_type?: string | null;
  document_type?: string | null;
  bir_template_code?: string | null;
  external_payload?: unknown;
  customer_name?: string | null;
  customer_tin: string | null;
  status: string;
  receipt_id: string | null;
  created_at: string;
};

type TransactionReceipt = {
  id: string;
  transaction_id: string | null;
  merchant_id: string | null;
  receipt_number: string;
  merchant_name: string;
  merchant_tin: string;
  rdo_code?: string | null;
  rdo_name?: string | null;
  receipt_type?: string | null;
  document_type?: string | null;
  bir_template_code?: string | null;
  buyer_name?: string | null;
  buyer_tin: string | null;
  gross_amount?: number | null;
  vatable_sales?: number | null;
  vat_exempt_sales?: number | null;
  zero_rated_sales?: number | null;
  vat_amount?: number | null;
  total_due: number;
  items?: unknown;
  status: string;
  issued_at: string | null;
  created_at: string;
};

type PurchaseItem = {
  description: string;
  quantity: number;
  unitPrice: number;
  total: number;
  taxType: string | null;
};

type TransactionRow = {
  id: string;
  reference: string;
  merchant: string;
  merchantLogo?: string | null;
  merchantTin?: string | null;
  branch?: string | null;
  rdoBranch?: string | null;
  type: string;
  paymentMethod: string;
  channel: string;
  amount: number;
  vat?: number;
  taxLabel: string;
  taxSubtext: string;
  items: PurchaseItem[];
  status: string;
  receiptNumber?: string;
  receiptId?: string | null;
  buyerName?: string | null;
  buyerTin?: string | null;
  documentLabel?: string | null;
  date: string;
};

type ClientProfileForm = {
  full_name: string;
  organization: string;
  email: string;
  mobile: string;
};

type ClientProfilePayload = {
  profile?: ClientProfile | null;
  account?: ClientAccount | null;
};

type ClientPageView =
  | "overview"
  | "transactions"
  | "receipts"
  | "barcode"
  | "wallet"
  | "notifications"
  | "security"
  | "profile";

const clientPageMeta: Record<ClientPageView, { title: string; description: string }> = {
  overview: {
    title: "Client Command Center",
    description:
      "Unified transaction, electronic receipt, wallet, verification, and security workspace for your NUERS account.",
  },
  transactions: {
    title: "My Transactions",
    description:
      "View every business account, agency, school, hospital, and LGU transaction matched to your bound TIN.",
  },
  receipts: {
    title: "Receipt Vault",
    description:
      "Search, verify, and download electronic official receipts attached to your taxpayer identity.",
  },
  barcode: {
    title: "TIN Barcode",
    description:
      "Use your TIN-based client barcode so business accounts can scan and post future transactions to your dashboard.",
  },
  wallet: {
    title: "Wallet & Payments",
    description:
      "Manage wallet balance, saved payment methods, upcoming payments, and payment activity.",
  },
  notifications: {
    title: "Notification Center",
    description: "Review receipt, payment, wallet, and account security notifications from NUERS.",
  },
  security: {
    title: "Security Center",
    description:
      "Monitor MFA, TIN binding, account controls, and receipt fraud protection for your client account.",
  },
  profile: {
    title: "Citizen Profile",
    description:
      "Manage your citizen identity, contact details, and profile picture for your NUERS client account.",
  },
};

const legacyClientHashRoutes: Record<string, string> = {
  "#overview": "/client",
  "#transactions": "/client/transactions",
  "#receipts": "/client/receipts",
  "#barcode": "/client/barcode",
  "#wallet": "/client/wallet",
  "#notifications": "/client/notifications",
  "#security": "/client/security",
  "#profile": "/client/profile",
};

const code39Patterns: Record<string, string> = {
  "0": "nnnwwnwnn",
  "1": "wnnwnnnnw",
  "2": "nnwwnnnnw",
  "3": "wnwwnnnnn",
  "4": "nnnwwnnnw",
  "5": "wnnwwnnnn",
  "6": "nnwwwnnnn",
  "7": "nnnwnnwnw",
  "8": "wnnwnnwnn",
  "9": "nnwwnnwnn",
  A: "wnnnnwnnw",
  B: "nnwnnwnnw",
  C: "wnwnnwnnn",
  D: "nnnnwwnnw",
  E: "wnnnwwnnn",
  F: "nnwnwwnnn",
  G: "nnnnnwwnw",
  H: "wnnnnwwnn",
  I: "nnwnnwwnn",
  J: "nnnnwwwnn",
  K: "wnnnnnnww",
  L: "nnwnnnnww",
  M: "wnwnnnnwn",
  N: "nnnnwnnww",
  O: "wnnnwnnwn",
  P: "nnwnwnnwn",
  Q: "nnnnnnwww",
  R: "wnnnnnwwn",
  S: "nnwnnnwwn",
  T: "nnnnwnwwn",
  U: "wwnnnnnnw",
  V: "nwwnnnnnw",
  W: "wwwnnnnnn",
  X: "nwnnwnnnw",
  Y: "wwnnwnnnn",
  Z: "nwwnwnnnn",
  "-": "nwnnnnwnw",
  ".": "wwnnnnwnn",
  " ": "nwwnnnwnn",
  "*": "nwnnwnwnn",
};

function formatPHP(value: number) {
  return `PHP ${Number(value || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function compactPHP(value: number) {
  if (Math.abs(value) >= 1_000_000) return `PHP ${(value / 1_000_000).toFixed(2)}M`;
  if (Math.abs(value) >= 1_000) return `PHP ${(value / 1_000).toFixed(1)}K`;
  return formatPHP(value);
}

function formatDate(value?: string | null) {
  if (!value) return "No date";
  return new Intl.DateTimeFormat("en-PH", {
    month: "short",
    day: "numeric",
    year: "numeric",
    hour: "numeric",
    minute: "2-digit",
  }).format(new Date(value));
}

function formatRdoBranch(code?: string | null, name?: string | null) {
  const normalizedCode = (code ?? "").trim();
  const normalizedName = (name ?? "").trim();

  if (normalizedCode && normalizedName) return `${normalizedCode} · ${normalizedName}`;
  if (normalizedCode) return `RDO ${normalizedCode}`;
  return normalizedName || "Unassigned RDO";
}

function normalizeTin(value: string) {
  return value.replace(/\D/g, "");
}

function formatTin(value: string) {
  const digits = normalizeTin(value).slice(0, 12);
  const groups = [
    digits.slice(0, 3),
    digits.slice(3, 6),
    digits.slice(6, 9),
    digits.slice(9, 12),
  ].filter(Boolean);
  return groups.join("-");
}

function barcodePayload(tin: string) {
  return `NUERS-${normalizeTin(tin)}`;
}

function tinMatchValues(tin: string) {
  const digits = normalizeTin(tin);
  const formatted = formatTin(digits);

  return Array.from(
    new Set([tin, formatted, digits, digits ? barcodePayload(digits) : ""].filter(Boolean)),
  );
}

function tinMatchFilter(columns: string[], tin: string) {
  return tinMatchValues(tin)
    .flatMap((value) => columns.map((column) => `${column}.eq.${value}`))
    .join(",");
}

function merchantTinKey(tin?: string | null) {
  const digits = normalizeTin(tin ?? "");
  return digits ? `tin:${digits}` : "";
}

function merchantNameKey(name?: string | null) {
  const normalized = (name ?? "").trim().toLowerCase();
  return normalized ? `name:${normalized}` : "";
}

function merchantDirectoryKeys(merchant: Merchant) {
  return [
    merchant.id,
    merchantTinKey(merchant.tin),
    merchantNameKey(merchant.business_name),
  ].filter(Boolean);
}

function merchantFromDirectory(
  directory: Record<string, Merchant>,
  id?: string | null,
  tin?: string | null,
  name?: string | null,
) {
  return (
    (id ? directory[id] : null) ||
    directory[merchantTinKey(tin)] ||
    directory[merchantNameKey(name)] ||
    null
  );
}

function isValidTin(value: string) {
  const digits = normalizeTin(value);
  return digits.length >= 9 && digits.length <= 12;
}

function resolveBoundTin(...values: Array<string | null | undefined>) {
  const candidates = values.map((value) => (value ?? "").trim());
  return candidates.find(isValidTin) ?? candidates.find(Boolean) ?? "";
}

function statusVariant(status?: string | null) {
  const normalized = (status ?? "").toLowerCase();
  if (["paid", "issued", "verified", "completed", "active", "receipt"].includes(normalized))
    return "secondary";
  if (["pending", "reminder"].includes(normalized)) return "outline";
  return "outline";
}

function numberValue(value: unknown, fallback = 0) {
  if (typeof value === "number" && Number.isFinite(value)) return value;
  if (typeof value === "string") {
    const parsed = Number(value.replace(/,/g, ""));
    return Number.isFinite(parsed) ? parsed : fallback;
  }
  return fallback;
}

function stringValue(value: unknown) {
  return typeof value === "string" ? value.trim() : "";
}

function parseJsonValue(value: unknown): unknown {
  if (typeof value !== "string") return value;
  const trimmed = value.trim();
  if (!trimmed) return null;

  try {
    return JSON.parse(trimmed);
  } catch {
    return value;
  }
}

function recordFromUnknown(value: unknown): Record<string, unknown> | null {
  const parsed = parseJsonValue(value);
  return parsed && typeof parsed === "object" && !Array.isArray(parsed)
    ? (parsed as Record<string, unknown>)
    : null;
}

function arrayFromJson(value: unknown): unknown[] {
  const parsed = parseJsonValue(value);
  return Array.isArray(parsed) ? parsed : [];
}

function labelFromSnake(value?: string | null) {
  const normalized = (value ?? "").trim();
  if (!normalized) return "Standard tax";

  return normalized.replace(/_/g, " ").replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function taxTypeLabel(value?: string | null) {
  const normalized = (value ?? "").toLowerCase();

  if (["non_vat", "non-vat", "b2", "b3"].includes(normalized)) return "Non-VAT";
  if (["vat_exempt", "vat_exempt_invoice"].includes(normalized)) return "VAT-exempt";
  if (["zero_rated", "zero_rated_invoice"].includes(normalized)) return "Zero-rated";
  if (["vatable", "vat_invoice", "vat"].includes(normalized)) return "VATable";

  return labelFromSnake(value);
}

function firstNumberValue(...values: unknown[]) {
  const found = values.find((value) => value !== undefined && value !== null && value !== "");
  return numberValue(found, 0);
}

function firstPositiveNumberValue(...values: unknown[]) {
  const positive = values.map((value) => numberValue(value, 0)).find((value) => value > 0);

  return positive ?? firstNumberValue(...values);
}

function purchaseItemsFromSources(
  transaction?: MerchantTransaction | null,
  receipt?: TransactionReceipt | null,
): PurchaseItem[] {
  const payload = recordFromUnknown(transaction?.external_payload);
  const payloadItems = Array.isArray(payload?.items) ? payload.items : [];
  const receiptItems = arrayFromJson(receipt?.items);
  const sourceItems = payloadItems.length ? payloadItems : receiptItems;

  return sourceItems
    .map((source) => {
      const item = recordFromUnknown(source);
      if (!item) return null;

      const description =
        stringValue(item.description) ||
        stringValue(item.name) ||
        stringValue(item.item_name) ||
        stringValue(item.item_code) ||
        "Purchased item";
      const quantity = Math.max(1, numberValue(item.quantity ?? item.qty, 1));
      let unitPrice = numberValue(item.unit_price ?? item.unitPrice ?? item.price, 0);
      let total = numberValue(
        item.line_total ?? item.total ?? item.subtotal ?? item.amount,
        quantity * unitPrice,
      );

      if (!unitPrice && total) unitPrice = total / quantity;
      if (!total && unitPrice) total = quantity * unitPrice;

      return {
        description,
        quantity,
        unitPrice,
        total,
        taxType: stringValue(item.tax_type) || stringValue(item.taxType) || null,
      };
    })
    .filter((item): item is PurchaseItem => Boolean(item));
}

function taxSummary(
  transaction?: MerchantTransaction | null,
  receipt?: TransactionReceipt | null,
  items: PurchaseItem[] = [],
) {
  const taxSource =
    transaction?.tax_type ||
    items.find((item) => item.taxType)?.taxType ||
    receipt?.document_type ||
    transaction?.document_type ||
    receipt?.receipt_type;
  const vatAmount = firstNumberValue(transaction?.vat_amount, receipt?.vat_amount);
  const baseAmount = firstPositiveNumberValue(
    transaction?.vatable_sales,
    receipt?.vatable_sales,
    receipt?.vat_exempt_sales,
    receipt?.zero_rated_sales,
    transaction?.net_amount,
    receipt?.gross_amount,
    receipt?.total_due,
    transaction?.amount,
  );

  return {
    label: taxTypeLabel(taxSource),
    subtext: `VAT ${formatPHP(vatAmount)} • Base ${formatPHP(baseAmount)}`,
    vatAmount,
  };
}

function documentLabel(
  transaction?: MerchantTransaction | null,
  receipt?: TransactionReceipt | null,
) {
  const templateCode = receipt?.bir_template_code || transaction?.bir_template_code;
  const type = receipt?.document_type || transaction?.document_type || receipt?.receipt_type;
  const label = taxTypeLabel(type);

  return [templateCode, label].filter(Boolean).join(" ");
}

function isWithinDateRange(value: string, from: string, to: string) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return false;

  if (from) {
    const fromDate = new Date(`${from}T00:00:00`);
    if (date < fromDate) return false;
  }

  if (to) {
    const toDate = new Date(`${to}T23:59:59.999`);
    if (date > toDate) return false;
  }

  return true;
}

function safeFilePart(value: string) {
  return (
    value
      .replace(/[^a-z0-9._-]+/gi, "-")
      .replace(/^-+|-+$/g, "")
      .toLowerCase() || "receipt"
  );
}

function downloadReceiptFile(row: TransactionRow, clientName: string, clientTin?: string | null) {
  const filenameBase = `${safeFilePart(row.receiptNumber || row.reference)}-b6-payment-receipt`;

  downloadB6ReceiptPdf(
    {
      receiptNumber: row.receiptNumber || row.reference,
      reference: row.reference,
      sellerName: row.merchant,
      sellerTin: row.merchantTin,
      sellerAddress: [row.branch, row.rdoBranch].filter(Boolean).join(" • "),
      buyerName: row.buyerName || clientName || "Client account",
      buyerTin: row.buyerTin || clientTin,
      buyerAddress: "Client address on file",
      paymentMethod: row.paymentMethod,
      paymentDate: row.date,
      accountNumber: row.receiptId,
      documentLabel: row.documentLabel || row.taxLabel,
      rdoBranch: row.rdoBranch,
      amount: row.amount,
      vat: row.vat,
      taxLabel: row.taxLabel,
      status: row.status,
      items: row.items,
    },
    filenameBase,
  );
}

function BusinessLogo({ name, src }: { name: string; src?: string | null }) {
  const initials =
    (name || "BA")
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0])
      .join("")
      .toUpperCase() || "BA";
  const assetSrc = publicAssetUrl(src);
  const uploadPath =
    src && !/^(https?:|data:|blob:)/i.test(src) ? (src.startsWith("/") ? src : `/${src}`) : "";
  const liveLogoFallback = uploadPath.startsWith("/uploads/business-logos/")
    ? `https://nuers.net${uploadPath}`
    : "";
  const [imageSrc, setImageSrc] = useState(assetSrc);

  useEffect(() => {
    setImageSrc(assetSrc);
  }, [assetSrc]);

  return (
    <Avatar className="relative h-10 w-10 shrink-0 overflow-hidden rounded-lg border bg-secondary">
      {imageSrc && (
        <img
          src={imageSrc}
          alt={`${name} logo`}
          className="absolute inset-0 h-full w-full object-cover"
          onError={() => {
            setImageSrc((current) =>
              current !== liveLogoFallback && liveLogoFallback ? liveLogoFallback : "",
            );
          }}
        />
      )}
      <AvatarFallback className="rounded-lg bg-secondary text-[10px] font-bold">
        {initials}
      </AvatarFallback>
    </Avatar>
  );
}

function PurchaseDetails({ items }: { items: PurchaseItem[] }) {
  if (!items.length) {
    return (
      <div className="max-w-72 text-xs text-muted-foreground">No purchase item details saved.</div>
    );
  }

  const visibleItems = items.slice(0, 2);

  return (
    <div className="max-w-80 space-y-2">
      {visibleItems.map((item, index) => (
        <div key={`${item.description}-${index}`} className="space-y-0.5">
          <p className="line-clamp-1 text-xs font-semibold text-foreground">{item.description}</p>
          <p className="text-[11px] text-muted-foreground">
            Qty {item.quantity.toLocaleString("en-PH")} • Unit {formatPHP(item.unitPrice)} • Total{" "}
            {formatPHP(item.total)}
          </p>
        </div>
      ))}
      {items.length > visibleItems.length && (
        <Badge variant="outline" className="text-[10px]">
          +{items.length - visibleItems.length} more item
          {items.length - visibleItems.length === 1 ? "" : "s"}
        </Badge>
      )}
    </div>
  );
}

function initialsForName(value?: string | null) {
  const source = (value || "Citizen Account").trim();
  return (
    source
      .split(/\s+/)
      .filter(Boolean)
      .slice(0, 2)
      .map((part) => part[0])
      .join("")
      .toUpperCase() || "CA"
  );
}

function ClientPortalPage({ view }: { view: ClientPageView }) {
  const searchLocation = useLocation();
  const { user, profile: authProfile } = useAuth();
  const {
    data: dashboardData,
    loading: dashboardLoading,
    error: dashboardError,
  } = useDashboardData("client");
  const [clientProfile, setClientProfile] = useState<ClientProfile | null>(null);
  const [clientAccount, setClientAccount] = useState<ClientAccount | null>(null);
  const [transactions, setTransactions] = useState<MerchantTransaction[]>([]);
  const [receipts, setReceipts] = useState<TransactionReceipt[]>([]);
  const [merchantMap, setMerchantMap] = useState<Record<string, Merchant>>({});
  const [loading, setLoading] = useState(true);
  const [transactionLoading, setTransactionLoading] = useState(false);
  const [error, setError] = useState("");
  const [identityNotice, setIdentityNotice] = useState("");
  const [verifiedTin, setVerifiedTin] = useState("");
  const [tinEvidence, setTinEvidence] = useState("");
  const [tinInput, setTinInput] = useState("");
  const [tinError, setTinError] = useState("");
  const [savingTin, setSavingTin] = useState(false);
  const [tinDialogOpen, setTinDialogOpen] = useState(false);
  const [clientIdentityLoaded, setClientIdentityLoaded] = useState(false);
  const [profileForm, setProfileForm] = useState<ClientProfileForm>({
    full_name: "",
    organization: "Citizen Account",
    email: "",
    mobile: "",
  });
  const [profileSaving, setProfileSaving] = useState(false);
  const [avatarFile, setAvatarFile] = useState<File | null>(null);
  const [avatarPreview, setAvatarPreview] = useState("");
  const [search, setSearch] = useState(
    () => new URLSearchParams(window.location.search).get("search") || "",
  );
  useEffect(() => {
    setSearch(new URLSearchParams(searchLocation.search).get("search") || "");
  }, [searchLocation.search]);
  const [statusFilter, setStatusFilter] = useState("all");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [currentPage, setCurrentPage] = useState(1);
  const [pageSize, setPageSize] = useState(10);
  const [selectedReceiptRow, setSelectedReceiptRow] = useState<TransactionRow | null>(null);
  const avatarInputRef = useRef<HTMLInputElement | null>(null);

  const boundTin = verifiedTin;
  const hasBoundTin = isValidTin(boundTin);
  const isClientAccountActive = (clientAccount?.status ?? "").toLowerCase() === "active";
  const payload = hasBoundTin ? barcodePayload(boundTin) : "";
  const avatarSrc =
    avatarPreview ||
    publicAssetUrl(clientProfile?.profile_photo_url || authProfile?.profile_photo_url);
  const clientDisplayName =
    clientProfile?.full_name ||
    authProfile?.full_name ||
    clientAccount?.full_name ||
    user?.email ||
    "Client account";

  useEffect(() => {
    async function loadClientProfile() {
      if (!user?.id) return;
      setClientIdentityLoaded(false);
      setLoading(true);
      setError("");

      const [{ data, error: profileError }, { data: accountData, error: accountError }] =
        await Promise.all([
          supabase.from<ClientProfile>("profiles").select("*").eq("id", user.id).maybeSingle(),
          supabase
            .from<ClientAccount>("client_accounts")
            .select("*")
            .eq("user_id", user.id)
            .maybeSingle(),
        ]);

      if (profileError) {
        setError(profileError.message);
        setLoading(false);
        setClientIdentityLoaded(true);
        return;
      }

      if (accountError) {
        setError(accountError.message);
      }

      setClientProfile(data);
      setClientAccount(accountData ?? null);
      const nextTin = resolveBoundTin(data?.tin, authProfile?.tin, accountData?.tin);
      setTinInput(nextTin);
      setProfileForm({
        full_name:
          data?.full_name || accountData?.full_name || authProfile?.full_name || user.email || "",
        organization: data?.organization || authProfile?.organization || "Citizen Account",
        email: accountData?.email || data?.email || user.email || "",
        mobile: accountData?.mobile || "",
      });
      try {
        const identity = await apiJson<{ data: { verified_tin: string | null } }>(
          "/api/client/profile",
        );
        setVerifiedTin(identity.data.verified_tin || "");
      } catch {
        setVerifiedTin("");
      }
      setTinDialogOpen(false);
      setLoading(false);
      setClientIdentityLoaded(true);
    }

    loadClientProfile();
  }, [authProfile?.tin, user?.id]);

  useEffect(() => {
    if (hasBoundTin) {
      setTinDialogOpen(false);
      setTinError("");
    }
  }, [boundTin, hasBoundTin]);

  useEffect(() => {
    return () => {
      if (avatarPreview) URL.revokeObjectURL(avatarPreview);
    };
  }, [avatarPreview]);

  useEffect(() => {
    setCurrentPage(1);
  }, [dateFrom, dateTo, pageSize, search, statusFilter]);

  useEffect(() => {
    if (!hasBoundTin) {
      setTransactions([]);
      setReceipts([]);
      setMerchantMap({});
      return;
    }

    loadTransactions(boundTin);
  }, [boundTin, hasBoundTin]);

  async function loadTransactions(tin: string) {
    setTransactionLoading(true);
    setError("");

    const [
      { data: transactionData, error: transactionError },
      { data: receiptData, error: receiptError },
    ] = await Promise.all([
      supabase
        .from<MerchantTransaction[]>("merchant_transactions")
        .select("*")
        .or(tinMatchFilter(["customer_tin", "customer_name"], tin))
        .order("created_at", { ascending: false })
        .limit(100),
      supabase
        .from<TransactionReceipt[]>("transaction_receipts")
        .select("*")
        .or(tinMatchFilter(["buyer_tin", "buyer_name"], tin))
        .order("issued_at", { ascending: false })
        .limit(100),
    ]);

    if (transactionError || receiptError) {
      setError(
        transactionError?.message || receiptError?.message || "Unable to load client transactions.",
      );
      setTransactionLoading(false);
      return;
    }

    const nextTransactions = transactionData ?? [];
    const nextReceipts = receiptData ?? [];
    setTransactions(nextTransactions);
    setReceipts(nextReceipts);

    const merchantIds = Array.from(
      new Set([
        ...nextTransactions.map((transaction) => transaction.merchant_id).filter(Boolean),
        ...nextReceipts.map((receipt) => receipt.merchant_id).filter(Boolean),
      ]),
    ) as string[];
    const merchantTinValues = Array.from(
      new Set(nextReceipts.flatMap((receipt) => tinMatchValues(receipt.merchant_tin ?? ""))),
    );
    const merchantNames = Array.from(
      new Set(nextReceipts.map((receipt) => receipt.merchant_name).filter(Boolean)),
    ) as string[];

    if (merchantIds.length || merchantTinValues.length || merchantNames.length) {
      const merchantResults = await Promise.all([
        merchantIds.length
          ? supabase.from<Merchant[]>("merchants").select("*").in("id", merchantIds)
          : Promise.resolve({ data: [] as Merchant[], error: null }),
        merchantTinValues.length
          ? supabase.from<Merchant[]>("merchants").select("*").in("tin", merchantTinValues)
          : Promise.resolve({ data: [] as Merchant[], error: null }),
        merchantNames.length
          ? supabase.from<Merchant[]>("merchants").select("*").in("business_name", merchantNames)
          : Promise.resolve({ data: [] as Merchant[], error: null }),
      ]);
      const merchants = merchantResults
        .flatMap((result) => result.data ?? [])
        .filter(
          (merchant, index, list) => list.findIndex((item) => item.id === merchant.id) === index,
        );

      setMerchantMap(
        merchants.reduce<Record<string, Merchant>>((map, merchant) => {
          merchantDirectoryKeys(merchant).forEach((key) => {
            map[key] = merchant;
          });
          return map;
        }, {}),
      );
    } else {
      setMerchantMap({});
    }

    setTransactionLoading(false);
  }

  async function handleTinSubmit(event: FormEvent) {
    event.preventDefault();
    setTinError("");

    if (!user?.id) {
      setTinError("Unable to identify your client account. Please sign in again.");
      return;
    }

    if (!isValidTin(tinInput)) {
      setTinError("Enter your nine-digit personal TIN.");
      return;
    }

    if (normalizeTin(tinInput).length !== 9 || tinEvidence.trim().length < 10) {
      setTinError("Enter your nine-digit personal TIN and original evidence reference for review.");
      return;
    }
    setSavingTin(true);
    try {
      await apiJson("/api/identity/claims", {
        method: "POST",
        body: JSON.stringify({ tin: normalizeTin(tinInput), evidence_reference: tinEvidence }),
      });
      setTinDialogOpen(false);
      setTinEvidence("");
      setIdentityNotice(
        "Identity review requested. Refresh after approval and activation to make the barcode available.",
      );
    } catch (e) {
      setTinError(e instanceof Error ? e.message : "Could not save the identity review request.");
    } finally {
      setSavingTin(false);
    }
  }

  function emitClientProfileUpdate(profile?: ClientProfile | null, account?: ClientAccount | null) {
    window.dispatchEvent(
      new CustomEvent("nuers:client-profile-updated", {
        detail: {
          full_name: profile?.full_name || account?.full_name || profileForm.full_name,
          organization: profile?.organization || profileForm.organization,
          email: account?.email || profile?.email || profileForm.email,
          profile_photo_url:
            profile?.profile_photo_url ||
            clientProfile?.profile_photo_url ||
            authProfile?.profile_photo_url ||
            null,
        },
      }),
    );
  }

  function applyClientProfilePayload(payload?: ClientProfilePayload | null) {
    const nextProfile = payload?.profile;
    const nextAccount = payload?.account;

    if (nextProfile) {
      setClientProfile((current) => ({ ...(current ?? nextProfile), ...nextProfile }));
    }

    if (nextAccount !== undefined) {
      setClientAccount(nextAccount);
    }

    const resolvedProfile = nextProfile ?? clientProfile;
    const resolvedAccount = nextAccount ?? clientAccount;

    setProfileForm({
      full_name: resolvedProfile?.full_name || resolvedAccount?.full_name || profileForm.full_name,
      organization: resolvedProfile?.organization || profileForm.organization || "Citizen Account",
      email:
        resolvedAccount?.email || resolvedProfile?.email || profileForm.email || user?.email || "",
      mobile: resolvedAccount?.mobile || profileForm.mobile || "",
    });

    emitClientProfileUpdate(resolvedProfile, resolvedAccount);
  }

  function handleProfileFieldChange(field: keyof ClientProfileForm, value: string) {
    setProfileForm((current) => ({ ...current, [field]: value }));
  }

  function handleAvatarChange(file: File | null) {
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast.error("Please choose a valid image file.");
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      toast.error("Profile picture must be 2 MB or smaller.");
      return;
    }

    if (avatarPreview) URL.revokeObjectURL(avatarPreview);

    setAvatarFile(file);
    setAvatarPreview(URL.createObjectURL(file));
  }

  async function uploadCitizenAvatar(): Promise<ClientProfilePayload | null> {
    if (!avatarFile) return null;

    const formData = new FormData();
    formData.append("avatar", avatarFile);

    const response = await apiFetch("/api/client/profile/avatar", {
      method: "POST",
      body: formData,
    });

    const payload = await readJsonResponse<{ data?: ClientProfilePayload }>(
      response,
      "Unable to upload the citizen profile picture.",
    );

    return payload.data ?? null;
  }

  async function handleProfileSubmit(event: FormEvent) {
    event.preventDefault();

    const fullName = profileForm.full_name.trim();

    if (!fullName) {
      toast.error("Full name is required.");
      return;
    }

    setProfileSaving(true);

    try {
      const response = await apiFetch("/api/client/profile", {
        method: "PUT",
        body: JSON.stringify({
          full_name: fullName,
          organization: profileForm.organization.trim() || "Citizen Account",
          email: profileForm.email.trim() || user?.email || "",
          mobile: profileForm.mobile.trim(),
        }),
      });

      const payload = await readJsonResponse<{ data?: ClientProfilePayload }>(
        response,
        "Unable to save the citizen profile.",
      );
      const avatarPayload = await uploadCitizenAvatar();
      const mergedPayload: ClientProfilePayload = {
        profile: avatarPayload?.profile ?? payload.data?.profile ?? null,
        account: avatarPayload?.account ?? payload.data?.account ?? null,
      };

      applyClientProfilePayload(mergedPayload);
      setAvatarFile(null);
      setAvatarPreview("");
      toast.success(
        avatarPayload ? "Citizen profile and picture saved." : "Citizen profile saved.",
      );
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to save the citizen profile.");
    } finally {
      setProfileSaving(false);
    }
  }

  const rows = useMemo<TransactionRow[]>(() => {
    const receiptsByTransaction = receipts.reduce<Record<string, TransactionReceipt>>(
      (map, receipt) => {
        if (receipt.transaction_id) map[receipt.transaction_id] = receipt;
        return map;
      },
      {},
    );

    const transactionRows = transactions.map((transaction) => {
      const receipt = transaction.receipt_id
        ? receipts.find((item) => item.id === transaction.receipt_id)
        : receiptsByTransaction[transaction.id];
      const merchant = merchantFromDirectory(
        merchantMap,
        transaction.merchant_id,
        receipt?.merchant_tin,
        receipt?.merchant_name,
      );
      const items = purchaseItemsFromSources(transaction, receipt);
      const tax = taxSummary(transaction, receipt, items);

      return {
        id: transaction.id,
        reference: transaction.transaction_ref || transaction.id,
        merchant: merchant?.business_name || receipt?.merchant_name || "Business Account POS",
        merchantLogo: merchant?.logo_url ?? null,
        merchantTin: merchant?.tin || receipt?.merchant_tin,
        branch: transaction.branch,
        rdoBranch: formatRdoBranch(
          transaction.rdo_code || receipt?.rdo_code || merchant?.rdo_code,
          transaction.rdo_name || receipt?.rdo_name || merchant?.rdo_name,
        ),
        type: transaction.transaction_type.replace(/_/g, " "),
        paymentMethod: transaction.payment_method.replace(/_/g, " "),
        channel: transaction.channel.replace(/_/g, " "),
        amount: Number(transaction.amount),
        vat: tax.vatAmount,
        taxLabel: tax.label,
        taxSubtext: tax.subtext,
        items,
        status: transaction.status,
        receiptNumber: receipt?.receipt_number,
        receiptId: receipt?.id ?? transaction.receipt_id,
        buyerName: receipt?.buyer_name || transaction.customer_name,
        buyerTin: receipt?.buyer_tin || transaction.customer_tin,
        documentLabel: documentLabel(transaction, receipt),
        date: transaction.created_at,
      };
    });

    const transactionIds = new Set(transactions.map((transaction) => transaction.id));
    const receiptOnlyRows = receipts
      .filter((receipt) => !receipt.transaction_id || !transactionIds.has(receipt.transaction_id))
      .map((receipt) => {
        const merchant = merchantFromDirectory(
          merchantMap,
          receipt.merchant_id,
          receipt.merchant_tin,
          receipt.merchant_name,
        );
        const items = purchaseItemsFromSources(null, receipt);
        const tax = taxSummary(null, receipt, items);

        return {
          id: receipt.id,
          reference: receipt.receipt_number,
          merchant: receipt.merchant_name,
          merchantLogo: merchant?.logo_url ?? null,
          merchantTin: receipt.merchant_tin,
          branch: null,
          rdoBranch: formatRdoBranch(receipt.rdo_code, receipt.rdo_name),
          type: "receipt issued",
          paymentMethod: "recorded by business account",
          channel: "receipt archive",
          amount: Number(receipt.total_due),
          vat: tax.vatAmount,
          taxLabel: tax.label,
          taxSubtext: tax.subtext,
          items,
          status: receipt.status,
          receiptNumber: receipt.receipt_number,
          receiptId: receipt.id,
          buyerName: receipt.buyer_name,
          buyerTin: receipt.buyer_tin,
          documentLabel: documentLabel(null, receipt),
          date: receipt.issued_at || receipt.created_at,
        };
      });

    return [...transactionRows, ...receiptOnlyRows].sort(
      (a, b) => new Date(b.date).getTime() - new Date(a.date).getTime(),
    );
  }, [merchantMap, receipts, transactions]);

  const filteredRows = rows.filter((row) => {
    const itemText = row.items.map((item) => `${item.description} ${item.taxType ?? ""}`).join(" ");
    const text =
      `${row.reference} ${row.merchant} ${row.merchantTin ?? ""} ${row.receiptNumber ?? ""} ${row.type} ${row.status} ${row.paymentMethod} ${row.rdoBranch ?? ""} ${row.taxLabel} ${row.taxSubtext} ${row.amount} ${formatPHP(row.amount)} ${itemText}`.toLowerCase();
    const matchesText = text.includes(search.toLowerCase());
    const matchesStatus = statusFilter === "all" || row.status.toLowerCase() === statusFilter;
    const matchesDate = isWithinDateRange(row.date, dateFrom, dateTo);
    return matchesText && matchesStatus && matchesDate;
  });
  const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
  const safeCurrentPage = Math.min(currentPage, pageCount);
  const pageStart = (safeCurrentPage - 1) * pageSize;
  const paginatedRows = filteredRows.slice(pageStart, pageStart + pageSize);
  const showingFrom = filteredRows.length ? pageStart + 1 : 0;
  const showingTo = Math.min(filteredRows.length, pageStart + pageSize);

  const receiptRows = receipts
    .map((receipt) => {
      const merchant = merchantFromDirectory(
        merchantMap,
        receipt.merchant_id,
        receipt.merchant_tin,
        receipt.merchant_name,
      );

      return {
        id: receipt.id,
        receiptNumber: receipt.receipt_number,
        merchant: receipt.merchant_name,
        merchantLogo: merchant?.logo_url ?? null,
        merchantTin: receipt.merchant_tin,
        amount: Number(receipt.total_due),
        status: receipt.status,
        date: receipt.issued_at || receipt.created_at,
      };
    })
    .sort((a, b) => new Date(b.date).getTime() - new Date(a.date).getTime());

  const walletBalance = Number(clientAccount?.wallet_balance ?? 0);
  const totalSpend = rows.reduce((total, row) => total + row.amount, 0);
  const totalVat = rows.reduce((total, row) => total + Number(row.vat ?? 0), 0);
  const receiptCount = receipts.length;
  const verifiedReceipts = receipts.filter((receipt) =>
    ["verified", "issued", "completed"].includes(receipt.status.toLowerCase()),
  ).length;
  const merchantCount = new Set(rows.map((row) => row.merchant)).size;
  const pendingCount = rows.filter((row) =>
    ["pending", "processing", "awaiting"].includes(row.status.toLowerCase()),
  ).length;
  const averageTransaction = rows.length ? totalSpend / rows.length : 0;
  const latestActivity = rows[0]?.date ?? clientAccount?.updated_at ?? clientProfile?.tin_bound_at;

  const walletActivity = dashboardData.series.wallet_activity ?? [];
  const savedPaymentMethods = dashboardData.lists.payment_methods ?? [];
  const clientNotifications = dashboardData.lists.notifications ?? [];
  const recommendedPayments = dashboardData.lists.transactions ?? [];
  const loyaltyItem = dashboardData.lists.loyalty?.[0];

  const monthlySpend = useMemo(() => {
    const formatter = new Intl.DateTimeFormat("en-PH", { month: "short" });
    const totals = rows.reduce<Record<string, number>>((map, row) => {
      const date = new Date(row.date);
      const key = `${date.getFullYear()}-${date.getMonth()}`;
      map[key] = (map[key] ?? 0) + row.amount;
      return map;
    }, {});

    return Array.from({ length: 6 }, (_, index) => {
      const date = new Date();
      date.setMonth(date.getMonth() - (5 - index));
      const key = `${date.getFullYear()}-${date.getMonth()}`;
      return { label: formatter.format(date), amount: totals[key] ?? 0 };
    });
  }, [rows]);

  const paymentMix = useMemo(() => {
    const totals = rows.reduce<Record<string, number>>((map, row) => {
      map[row.paymentMethod] = (map[row.paymentMethod] ?? 0) + row.amount;
      return map;
    }, {});
    const total = Object.values(totals).reduce((sum, value) => sum + value, 0);
    return Object.entries(totals)
      .map(([label, value]) => ({
        label,
        value,
        percent: total ? Math.round((value / total) * 100) : 0,
      }))
      .sort((a, b) => b.value - a.value)
      .slice(0, 5);
  }, [rows]);

  const issuerLeaderboard = useMemo(() => {
    const totals = rows.reduce<Record<string, { amount: number; count: number }>>((map, row) => {
      const current = map[row.merchant] ?? { amount: 0, count: 0 };
      current.amount += row.amount;
      current.count += 1;
      map[row.merchant] = current;
      return map;
    }, {});

    return Object.entries(totals)
      .map(([merchant, values]) => ({ merchant, ...values }))
      .sort((a, b) => b.amount - a.amount)
      .slice(0, 5);
  }, [rows]);

  function handleDownloadReceipt(row: TransactionRow) {
    if (!row.receiptNumber) {
      toast.info("This transaction does not have an electronic receipt yet.");
      return;
    }

    downloadReceiptFile(row, clientDisplayName, boundTin);
    toast.success(`${row.receiptNumber} PDF downloaded.`);
  }

  const pageMeta = clientPageMeta[view];

  return (
    <div className="space-y-6">
      {identityNotice && (
        <p
          role="status"
          className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-900"
        >
          {identityNotice}
        </p>
      )}
      <TinBindingDialog
        open={tinDialogOpen}
        tinEvidence={tinEvidence}
        onEvidenceChange={setTinEvidence}
        tinInput={tinInput}
        tinError={tinError}
        saving={savingTin}
        canClose={clientIdentityLoaded || hasBoundTin}
        onOpenChange={setTinDialogOpen}
        onTinChange={(value) => setTinInput(formatTin(value))}
        onSubmit={handleTinSubmit}
      />
      <ReceiptPreviewDialog
        row={selectedReceiptRow}
        clientName={clientDisplayName}
        onOpenChange={(open) => !open && setSelectedReceiptRow(null)}
        onDownload={handleDownloadReceipt}
      />

      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="text-2xl font-bold text-foreground">{pageMeta.title}</h1>
            <Badge variant={hasBoundTin ? "secondary" : "destructive"} className="gap-1.5 text-xs">
              {hasBoundTin ? (
                <ShieldCheck className="h-3 w-3" />
              ) : (
                <AlertCircle className="h-3 w-3" />
              )}
              {hasBoundTin ? "TIN Bound" : "TIN Required"}
            </Badge>
            <Badge variant="outline" className="gap-1.5 text-xs">
              <Fingerprint className="h-3 w-3" />
              {clientAccount?.mfa_enabled ? "MFA Enabled" : "MFA Pending"}
            </Badge>
          </div>
          <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{pageMeta.description}</p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button
            variant="outline"
            size="sm"
            className="gap-2 text-xs"
            onClick={() => hasBoundTin && loadTransactions(boundTin)}
            disabled={!hasBoundTin || transactionLoading}
          >
            {transactionLoading ? (
              <Loader2 className="h-3.5 w-3.5 animate-spin" />
            ) : (
              <RefreshCw className="h-3.5 w-3.5" />
            )}
            Refresh
          </Button>
          <Button variant="outline" size="sm" className="gap-2 text-xs" asChild>
            <Link to="/verify">
              <QrCode className="h-3.5 w-3.5" />
              Verify Receipt
            </Link>
          </Button>
          <Button size="sm" className="gap-2 text-xs" onClick={() => setTinDialogOpen(true)}>
            <UserCheck className="h-3.5 w-3.5" />
            {hasBoundTin ? "Update TIN" : "Bind TIN"}
          </Button>
        </div>
      </div>

      {(loading || transactionLoading || dashboardLoading) && (
        <Card>
          <CardContent className="flex items-center gap-2 p-4 text-sm text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" />
            Loading client records from MySQL...
          </CardContent>
        </Card>
      )}

      {(error || dashboardError) && (
        <Card className="border-destructive/30">
          <CardContent className="p-4 text-sm text-destructive">
            {error || dashboardError}
          </CardContent>
        </Card>
      )}

      {view === "overview" && (
        <section id="client-overview" className="scroll-mt-24 space-y-4">
          <div className="grid gap-4 xl:grid-cols-[minmax(0,1.25fr)_minmax(340px,0.75fr)]">
            <Card className="overflow-hidden">
              <CardHeader className="border-b bg-slate-50/80 pb-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                  <div>
                    <CardTitle className="flex items-center gap-2 text-sm">
                      <Landmark className="h-4 w-4 text-primary" />
                      Digital Taxpayer Identity
                    </CardTitle>
                    <p className="mt-1 text-xs text-muted-foreground">
                      {clientAccount?.account_number ?? "Client account pending"} •{" "}
                      {clientAccount?.account_type ?? "citizen"} •{" "}
                      {clientAccount?.status ?? "profile active"}
                    </p>
                  </div>
                  <Badge
                    variant={hasBoundTin ? "secondary" : "destructive"}
                    className="w-fit text-[10px]"
                  >
                    {hasBoundTin ? "Ready for business account scan" : "TIN binding required"}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="grid gap-4 p-5 lg:grid-cols-[minmax(0,1fr)_280px]">
                <div className="grid gap-3 sm:grid-cols-2">
                  <IdentityField
                    label="Account holder"
                    value={
                      clientProfile?.full_name ||
                      authProfile?.full_name ||
                      user?.email ||
                      "Client User"
                    }
                  />
                  <IdentityField
                    label="Account number"
                    value={clientAccount?.account_number ?? "Pending provisioning"}
                  />
                  <IdentityField
                    label="Bound TIN"
                    value={hasBoundTin ? boundTin : "Not bound"}
                    mono
                  />
                  <IdentityField
                    label="Last account update"
                    value={formatDate(clientAccount?.updated_at ?? clientProfile?.tin_bound_at)}
                  />
                  <IdentityField
                    label="Email"
                    value={clientAccount?.email ?? user?.email ?? "No email"}
                  />
                  <IdentityField label="Mobile" value={clientAccount?.mobile ?? "Not registered"} />
                </div>
                <div className="rounded-lg border bg-background p-4">
                  <p className="text-xs text-muted-foreground">Wallet Balance</p>
                  <p className="mt-2 text-3xl font-bold text-foreground">
                    {formatPHP(walletBalance)}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">
                    Available client account balance
                  </p>
                  <div className="mt-4 grid grid-cols-2 gap-2 text-xs">
                    <div className="rounded-md bg-slate-50 p-2">
                      <span className="text-muted-foreground">Receipts</span>
                      <p className="mt-1 font-semibold text-foreground">{receiptCount}</p>
                    </div>
                    <div className="rounded-md bg-slate-50 p-2">
                      <span className="text-muted-foreground">Pending</span>
                      <p className="mt-1 font-semibold text-foreground">{pendingCount}</p>
                    </div>
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-sm">
                  <ShieldCheck className="h-4 w-4 text-primary" />
                  Account Readiness
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <ReadinessRow
                  label="TIN binding"
                  value={hasBoundTin ? 100 : 25}
                  status={hasBoundTin ? "Complete" : "Required"}
                />
                <ReadinessRow
                  label="Receipt matching"
                  value={hasBoundTin ? 90 : 0}
                  status={hasBoundTin ? "Active" : "Locked"}
                />
                <ReadinessRow
                  label="MFA coverage"
                  value={clientAccount?.mfa_enabled ? 100 : 45}
                  status={clientAccount?.mfa_enabled ? "Enabled" : "Pending"}
                />
                <ReadinessRow
                  label="Notification routing"
                  value={clientAccount?.notification_preferences ? 85 : 60}
                  status={clientAccount?.notification_preferences ? "Configured" : "Default"}
                />
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
            <MetricCard
              label="Recorded Transactions"
              value={rows.length.toLocaleString("en-PH")}
              icon={Receipt}
              subtext="Matched to your TIN"
            />
            <MetricCard
              label="Total Amount"
              value={compactPHP(totalSpend)}
              icon={ArrowDownRight}
              subtext="All matched records"
            />
            <MetricCard
              label="VAT Captured"
              value={compactPHP(totalVat)}
              icon={FileCheck2}
              subtext="From transaction ledger"
            />
            <MetricCard
              label="Verified Receipts"
              value={`${verifiedReceipts}/${receiptCount}`}
              icon={ShieldCheck}
              subtext="eOR authenticity"
            />
            <MetricCard
              label="Issuers"
              value={merchantCount.toLocaleString("en-PH")}
              icon={Store}
              subtext="Business accounts and agencies"
            />
            <MetricCard
              label="Average Spend"
              value={compactPHP(averageTransaction)}
              icon={TrendingUp}
              subtext="Per recorded item"
            />
          </div>
        </section>
      )}

      {view === "barcode" && !isClientAccountActive && (
        <section id="client-barcode-locked" className="scroll-mt-24">
          <Card>
            <CardContent className="flex flex-col items-center justify-center px-6 py-14 text-center">
              <div className="mb-4 flex h-12 w-12 items-center justify-center rounded-lg bg-amber-100 text-amber-700">
                <AlertCircle className="h-6 w-6" />
              </div>
              <h2 className="text-lg font-semibold text-foreground">TIN Barcode is hidden</h2>
              <p className="mt-2 max-w-md text-sm text-muted-foreground">
                Your client account must be active before NUERS can show or generate your TIN
                barcode.
              </p>
              <Badge variant="outline" className="mt-4 capitalize">
                Account status: {clientAccount?.status?.replace(/_/g, " ") || "pending"}
              </Badge>
            </CardContent>
          </Card>
        </section>
      )}

      {view === "barcode" && isClientAccountActive && (
        <section id="client-barcode" className="scroll-mt-24">
          <Card className="overflow-hidden">
            <CardHeader className="border-b bg-slate-50/80 pb-4">
              <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                  <CardTitle className="flex items-center gap-2 text-sm">
                    <Barcode className="h-4 w-4 text-primary" />
                    Client Scan Barcode
                  </CardTitle>
                  <p className="mt-1 text-xs text-muted-foreground">
                    TIN-based scan credential for business account, hospital, school, LGU, and
                    agency transaction posting.
                  </p>
                </div>
                <Badge
                  variant={hasBoundTin ? "secondary" : "outline"}
                  className="w-fit text-[10px]"
                >
                  {hasBoundTin ? "Scan ready" : "Locked"}
                </Badge>
              </div>
            </CardHeader>
            <CardContent className="p-5">
              {hasBoundTin ? (
                <div className="space-y-4">
                  <Code39Barcode value={payload} />
                  <div className="grid gap-3 md:grid-cols-3">
                    <IdentityField label="Bound TIN" value={boundTin} mono />
                    <IdentityField label="Scan payload" value={payload} mono />
                    <IdentityField
                      label="Last transaction sync"
                      value={formatDate(latestActivity)}
                    />
                  </div>
                </div>
              ) : (
                <TinLockedState onOpen={() => setTinDialogOpen(true)} />
              )}
            </CardContent>
          </Card>
        </section>
      )}

      {view === "transactions" && (
        <section id="client-transactions" className="scroll-mt-24 space-y-4">
          <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Monthly Transaction Analytics</CardTitle>
              </CardHeader>
              <CardContent>
                <MonthlySpendChart points={monthlySpend} />
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Payment Channel Mix</CardTitle>
              </CardHeader>
              <CardContent>
                <ChannelMix rows={paymentMix} />
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader className="pb-3">
              <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                <div>
                  <CardTitle className="text-sm">My Transaction Ledger</CardTitle>
                  <p className="text-xs text-muted-foreground">
                    Database transactions and receipts matched to your bound TIN.
                  </p>
                </div>
                <div className="grid w-full gap-2 sm:grid-cols-2 lg:w-auto xl:grid-cols-[320px_150px_150px_150px_auto]">
                  <div className="relative min-w-0">
                    <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      className="pl-9"
                      placeholder="Search business account, receipt, amount..."
                      value={search}
                      onChange={(event) => setSearch(event.target.value)}
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="client-transaction-date-from" className="sr-only">
                      Date from
                    </Label>
                    <Input
                      id="client-transaction-date-from"
                      type="date"
                      value={dateFrom}
                      onChange={(event) => setDateFrom(event.target.value)}
                      aria-label="Date from"
                    />
                  </div>
                  <div className="space-y-1">
                    <Label htmlFor="client-transaction-date-to" className="sr-only">
                      Date to
                    </Label>
                    <Input
                      id="client-transaction-date-to"
                      type="date"
                      value={dateTo}
                      onChange={(event) => setDateTo(event.target.value)}
                      aria-label="Date to"
                    />
                  </div>
                  <select
                    className="h-9 rounded-md border border-input bg-background px-3 text-sm text-foreground shadow-xs"
                    value={statusFilter}
                    onChange={(event) => setStatusFilter(event.target.value)}
                    aria-label="Transaction status"
                  >
                    <option value="all">All status</option>
                    <option value="completed">Completed</option>
                    <option value="issued">Issued</option>
                    <option value="verified">Verified</option>
                    <option value="pending">Pending</option>
                  </select>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="h-9 text-xs"
                    onClick={() => {
                      setSearch("");
                      setDateFrom("");
                      setDateTo("");
                      setStatusFilter("all");
                    }}
                  >
                    Clear
                  </Button>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              {hasBoundTin && filteredRows.length > 0 ? (
                <div className="space-y-4">
                  <div className="overflow-x-auto">
                    <Table>
                      <TableHeader>
                        <TableRow>
                          <TableHead>Business Account / Agency</TableHead>
                          <TableHead>Reference</TableHead>
                          <TableHead>Purchase Details</TableHead>
                          <TableHead>Tax</TableHead>
                          <TableHead>Date</TableHead>
                          <TableHead className="text-right">Amount</TableHead>
                          <TableHead>Status</TableHead>
                          <TableHead className="text-right">Receipt</TableHead>
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {paginatedRows.map((row) => (
                          <TableRow key={row.id}>
                            <TableCell>
                              <div className="flex items-center gap-3">
                                <BusinessLogo name={row.merchant} src={row.merchantLogo} />
                                <div className="min-w-0">
                                  <p className="truncate font-medium text-foreground">
                                    {row.merchant}
                                  </p>
                                  <p className="mt-0.5 truncate font-mono text-[10px] text-muted-foreground">
                                    {row.merchantTin ?? "Business account TIN pending"}
                                    {row.branch ? ` • ${row.branch}` : ""}
                                  </p>
                                  <p className="mt-0.5 inline-flex max-w-64 items-center gap-1.5 truncate text-[11px] text-muted-foreground">
                                    <Building2 className="h-3 w-3 shrink-0" />
                                    {row.rdoBranch ?? "Unassigned RDO"}
                                  </p>
                                </div>
                              </div>
                            </TableCell>
                            <TableCell>
                              <div className="space-y-1">
                                <p className="font-mono text-xs font-semibold text-foreground">
                                  {row.reference}
                                </p>
                                <p className="text-[11px] capitalize text-muted-foreground">
                                  {row.type} • {row.channel}
                                </p>
                              </div>
                            </TableCell>
                            <TableCell>
                              <PurchaseDetails items={row.items} />
                            </TableCell>
                            <TableCell>
                              <div className="min-w-36 space-y-1">
                                <Badge variant="outline" className="text-[10px]">
                                  {row.taxLabel}
                                </Badge>
                                <p className="text-[11px] text-muted-foreground">
                                  {row.taxSubtext}
                                </p>
                              </div>
                            </TableCell>
                            <TableCell>
                              <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                                <CalendarClock className="h-3.5 w-3.5" />
                                {formatDate(row.date)}
                              </span>
                            </TableCell>
                            <TableCell className="text-right font-semibold">
                              {formatPHP(row.amount)}
                            </TableCell>
                            <TableCell>
                              <Badge
                                variant={statusVariant(row.status)}
                                className="capitalize text-[10px]"
                              >
                                {row.status}
                              </Badge>
                            </TableCell>
                            <TableCell className="text-right">
                              {row.receiptNumber ? (
                                <div className="flex justify-end gap-1.5">
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8 gap-1.5 text-xs"
                                    onClick={() => setSelectedReceiptRow(row)}
                                  >
                                    <Eye className="h-3.5 w-3.5" />
                                    View
                                  </Button>
                                  <Button
                                    variant="outline"
                                    size="sm"
                                    className="h-8 gap-1.5 text-xs"
                                    onClick={() => handleDownloadReceipt(row)}
                                  >
                                    <Download className="h-3.5 w-3.5" />
                                    Download
                                  </Button>
                                </div>
                              ) : (
                                <span className="text-xs text-muted-foreground">Awaiting eOR</span>
                              )}
                            </TableCell>
                          </TableRow>
                        ))}
                      </TableBody>
                    </Table>
                  </div>
                  <div
                    data-slot="table-pagination"
                    className="flex flex-col gap-3 border-t pt-4 text-xs text-muted-foreground lg:flex-row lg:items-center lg:justify-between"
                  >
                    <div>
                      Showing <span className="font-semibold text-foreground">{showingFrom}</span>{" "}
                      to <span className="font-semibold text-foreground">{showingTo}</span> of{" "}
                      <span className="font-semibold text-foreground">{filteredRows.length}</span>{" "}
                      transactions
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <Label
                        htmlFor="client-transaction-page-size"
                        className="text-xs text-muted-foreground"
                      >
                        Rows per page
                      </Label>
                      <select
                        id="client-transaction-page-size"
                        className="h-8 rounded-md border border-input bg-background px-2 text-xs text-foreground shadow-xs"
                        value={pageSize}
                        onChange={(event) => setPageSize(Number(event.target.value))}
                      >
                        <option value={5}>5</option>
                        <option value={10}>10</option>
                        <option value={25}>25</option>
                      </select>
                      <span className="px-2 text-xs text-muted-foreground">
                        Page {safeCurrentPage} of {pageCount}
                      </span>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 gap-1 text-xs"
                        onClick={() => setCurrentPage((page) => Math.max(1, page - 1))}
                        disabled={safeCurrentPage <= 1}
                      >
                        <ChevronLeft className="h-3.5 w-3.5" />
                        Prev
                      </Button>
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 gap-1 text-xs"
                        onClick={() => setCurrentPage((page) => Math.min(pageCount, page + 1))}
                        disabled={safeCurrentPage >= pageCount}
                      >
                        Next
                        <ChevronRight className="h-3.5 w-3.5" />
                      </Button>
                    </div>
                  </div>
                </div>
              ) : (
                <EmptyLedger hasBoundTin={hasBoundTin} onOpenTin={() => setTinDialogOpen(true)} />
              )}
            </CardContent>
          </Card>
        </section>
      )}

      {view === "receipts" && (
        <section id="client-receipts" className="scroll-mt-24 space-y-4">
          <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-sm">
                  <FileSearch className="h-4 w-4 text-primary" />
                  Receipt Vault
                </CardTitle>
                <p className="text-xs text-muted-foreground">
                  Electronic official receipts matched to your taxpayer identity.
                </p>
              </CardHeader>
              <CardContent>
                {receiptRows.length > 0 ? (
                  <div className="grid gap-3 md:grid-cols-2">
                    {receiptRows.slice(0, 8).map((receipt) => (
                      <div key={receipt.id} className="rounded-lg border p-4">
                        <div className="flex items-start justify-between gap-3">
                          <div className="flex min-w-0 items-start gap-3">
                            <BusinessLogo name={receipt.merchant} src={receipt.merchantLogo} />
                            <div className="min-w-0">
                              <p className="font-mono text-xs font-semibold text-foreground">
                                {receipt.receiptNumber}
                              </p>
                              <p className="mt-1 truncate text-sm font-medium text-foreground">
                                {receipt.merchant}
                              </p>
                              <p className="mt-0.5 font-mono text-[10px] text-muted-foreground">
                                {receipt.merchantTin}
                              </p>
                            </div>
                          </div>
                          <Badge variant={statusVariant(receipt.status)} className="text-[10px]">
                            {receipt.status}
                          </Badge>
                        </div>
                        <div className="mt-4 flex items-end justify-between gap-3">
                          <div>
                            <p className="text-xs text-muted-foreground">
                              {formatDate(receipt.date)}
                            </p>
                            <p className="mt-1 text-lg font-bold text-foreground">
                              {formatPHP(receipt.amount)}
                            </p>
                          </div>
                          <Button variant="outline" size="sm" className="gap-1.5 text-xs">
                            <Download className="h-3.5 w-3.5" />
                            PDF
                          </Button>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <EnterpriseEmpty
                    icon={FileText}
                    title="Receipt vault empty"
                    text={
                      hasBoundTin
                        ? "No electronic official receipts are currently matched to your TIN."
                        : "Bind your TIN to unlock receipt matching."
                    }
                  />
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Issuer Leaderboard</CardTitle>
              </CardHeader>
              <CardContent>
                {issuerLeaderboard.length > 0 ? (
                  <div className="space-y-3">
                    {issuerLeaderboard.map((issuer) => (
                      <div key={issuer.merchant} className="rounded-lg border p-3">
                        <div className="flex items-start justify-between gap-2">
                          <div>
                            <p className="text-sm font-medium text-foreground">{issuer.merchant}</p>
                            <p className="text-xs text-muted-foreground">
                              {issuer.count} recorded item{issuer.count === 1 ? "" : "s"}
                            </p>
                          </div>
                          <p className="text-sm font-semibold text-foreground">
                            {compactPHP(issuer.amount)}
                          </p>
                        </div>
                      </div>
                    ))}
                  </div>
                ) : (
                  <EnterpriseEmpty
                    icon={Building2}
                    title="No issuers yet"
                    text="Issuer activity appears once business accounts or agencies post transactions to your account."
                    compact
                  />
                )}
              </CardContent>
            </Card>
          </div>
        </section>
      )}

      {view === "wallet" && (
        <section id="client-wallet" className="scroll-mt-24 space-y-4">
          <div className="grid gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-sm">
                  <Wallet className="h-4 w-4 text-primary" />
                  Wallet Center
                </CardTitle>
              </CardHeader>
              <CardContent>
                <p className="text-3xl font-bold text-foreground">{formatPHP(walletBalance)}</p>
                <p className="mt-1 text-xs text-muted-foreground">
                  Current MySQL client account balance
                </p>
                {loyaltyItem && (
                  <div className="mt-5 rounded-lg border bg-slate-50 p-3">
                    <div className="flex items-center justify-between gap-3">
                      <div>
                        <p className="text-xs font-medium text-foreground">{loyaltyItem.title}</p>
                        <p className="text-[11px] text-muted-foreground">{loyaltyItem.subtitle}</p>
                      </div>
                      <Badge variant="secondary" className="text-[10px]">
                        {Math.round(loyaltyItem.primary_value ?? 0)}%
                      </Badge>
                    </div>
                    <Progress
                      className="mt-3 h-1.5"
                      value={Math.min(100, loyaltyItem.primary_value ?? 0)}
                    />
                  </div>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Saved Payment Methods</CardTitle>
              </CardHeader>
              <CardContent>
                {savedPaymentMethods.length > 0 ? (
                  <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {savedPaymentMethods.map((method) => (
                      <DashboardListCard
                        key={method.title}
                        item={method}
                        icon={
                          method.subtitle?.toLowerCase().includes("wallet")
                            ? Smartphone
                            : CreditCard
                        }
                      />
                    ))}
                  </div>
                ) : (
                  <EnterpriseEmpty
                    icon={CreditCard}
                    title="No saved methods"
                    text="Payment methods will appear after account payment setup is completed."
                    compact
                  />
                )}
              </CardContent>
            </Card>
          </div>

          <div className="grid gap-4 xl:grid-cols-2">
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Wallet Activity</CardTitle>
              </CardHeader>
              <CardContent>
                <WalletActivityChart points={walletActivity} />
              </CardContent>
            </Card>
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm">Upcoming & Pending Payments</CardTitle>
              </CardHeader>
              <CardContent>
                {recommendedPayments.length > 0 ? (
                  <div className="space-y-3">
                    {recommendedPayments.slice(0, 4).map((item) => (
                      <DashboardListCard
                        key={item.title}
                        item={item}
                        icon={item.status?.toLowerCase() === "pending" ? Clock3 : Landmark}
                        horizontal
                      />
                    ))}
                  </div>
                ) : (
                  <EnterpriseEmpty
                    icon={Clock3}
                    title="No upcoming payments"
                    text="Pending fees and scheduled payments will appear here."
                    compact
                  />
                )}
              </CardContent>
            </Card>
          </div>
        </section>
      )}

      {view === "notifications" && (
        <section id="client-notifications" className="scroll-mt-24">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="flex items-center gap-2 text-sm">
                <Bell className="h-4 w-4 text-primary" />
                Notification Center
              </CardTitle>
              <p className="text-xs text-muted-foreground">
                Receipt, payment, wallet, and compliance notifications from the NUERS database.
              </p>
            </CardHeader>
            <CardContent>
              {clientNotifications.length > 0 ? (
                <div className="grid gap-3 md:grid-cols-3">
                  {clientNotifications.map((item) => (
                    <DashboardListCard
                      key={item.title}
                      item={item}
                      icon={
                        item.badge === "Payment"
                          ? CreditCard
                          : item.badge === "Reminder"
                            ? Clock3
                            : Bell
                      }
                    />
                  ))}
                </div>
              ) : (
                <EnterpriseEmpty
                  icon={Bell}
                  title="No notifications"
                  text="Account notifications will appear here."
                  compact
                />
              )}
            </CardContent>
          </Card>
        </section>
      )}

      {view === "profile" && (
        <section id="client-profile" className="scroll-mt-24 space-y-4">
          <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
            <Card>
              <CardHeader className="border-b bg-slate-50/80 pb-4">
                <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                  <div>
                    <CardTitle className="flex items-center gap-2 text-sm">
                      <User className="h-4 w-4 text-primary" />
                      Citizen Identity
                    </CardTitle>
                    <p className="mt-1 text-xs text-muted-foreground">
                      Update the identity shown across your client portal and receipt records.
                    </p>
                  </div>
                  <Badge
                    variant={hasBoundTin ? "secondary" : "destructive"}
                    className="w-fit text-[10px]"
                  >
                    {hasBoundTin ? "TIN bound" : "TIN required"}
                  </Badge>
                </div>
              </CardHeader>
              <CardContent className="p-5">
                <form onSubmit={handleProfileSubmit} className="space-y-5">
                  <div className="flex flex-col gap-4 md:flex-row md:items-center">
                    <Avatar className="h-20 w-20 rounded-2xl border bg-primary text-primary-foreground">
                      {avatarSrc && (
                        <AvatarImage
                          src={avatarSrc}
                          alt={`${profileForm.full_name || "Citizen"} profile picture`}
                          className="object-cover"
                        />
                      )}
                      <AvatarFallback className="rounded-2xl bg-primary text-xl font-bold text-primary-foreground">
                        {initialsForName(profileForm.full_name || clientProfile?.full_name)}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-foreground">
                        {profileForm.full_name || "Citizen Account"}
                      </p>
                      <p className="mt-1 truncate text-xs text-muted-foreground">
                        {profileForm.email || user?.email || "No email on file"}
                      </p>
                      <div className="mt-3 flex flex-wrap items-center gap-2">
                        <input
                          ref={avatarInputRef}
                          type="file"
                          accept="image/png,image/jpeg,image/jpg,image/webp,image/gif"
                          className="hidden"
                          onChange={(event) =>
                            handleAvatarChange(event.currentTarget.files?.[0] ?? null)
                          }
                        />
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="gap-2 text-xs"
                          onClick={() => avatarInputRef.current?.click()}
                        >
                          <Camera className="h-3.5 w-3.5" />
                          {avatarSrc ? "Change picture" : "Upload picture"}
                        </Button>
                        {avatarFile && (
                          <Badge variant="secondary" className="text-[10px]">
                            Ready to save
                          </Badge>
                        )}
                      </div>
                    </div>
                  </div>

                  <div className="grid gap-4 md:grid-cols-2">
                    <div className="space-y-2">
                      <Label htmlFor="client-profile-full-name">Full name</Label>
                      <div className="relative">
                        <User className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                          id="client-profile-full-name"
                          value={profileForm.full_name}
                          onChange={(event) =>
                            handleProfileFieldChange("full_name", event.target.value)
                          }
                          className="pl-9"
                          disabled={profileSaving}
                        />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="client-profile-label">Account label</Label>
                      <Input
                        id="client-profile-label"
                        value={profileForm.organization}
                        onChange={(event) =>
                          handleProfileFieldChange("organization", event.target.value)
                        }
                        disabled={profileSaving}
                      />
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="client-profile-email">Contact email</Label>
                      <div className="relative">
                        <Mail className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                          id="client-profile-email"
                          type="email"
                          value={profileForm.email}
                          onChange={(event) =>
                            handleProfileFieldChange("email", event.target.value)
                          }
                          className="pl-9"
                          disabled={profileSaving}
                        />
                      </div>
                    </div>
                    <div className="space-y-2">
                      <Label htmlFor="client-profile-mobile">Mobile number</Label>
                      <div className="relative">
                        <Phone className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                          id="client-profile-mobile"
                          value={profileForm.mobile}
                          onChange={(event) =>
                            handleProfileFieldChange("mobile", event.target.value)
                          }
                          className="pl-9"
                          disabled={profileSaving}
                        />
                      </div>
                    </div>
                  </div>

                  <div className="flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                    <p className="text-xs text-muted-foreground">
                      Bound TIN:{" "}
                      <span className="font-mono font-semibold text-foreground">
                        {hasBoundTin ? boundTin : "Not bound"}
                      </span>
                    </p>
                    <Button type="submit" className="gap-2" disabled={profileSaving}>
                      {profileSaving ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : (
                        <Save className="h-4 w-4" />
                      )}
                      {profileSaving ? "Saving..." : "Save profile"}
                    </Button>
                  </div>
                </form>
              </CardContent>
            </Card>

            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-sm">
                  <Fingerprint className="h-4 w-4 text-primary" />
                  Account Record
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                <IdentityField
                  label="Account number"
                  value={clientAccount?.account_number ?? "Pending provisioning"}
                  mono
                />
                <IdentityField
                  label="Account type"
                  value={clientAccount?.account_type ?? "citizen"}
                />
                <IdentityField label="Status" value={clientAccount?.status ?? "profile active"} />
                <IdentityField
                  label="Last updated"
                  value={formatDate(
                    clientAccount?.updated_at ??
                      clientProfile?.updated_at ??
                      clientProfile?.tin_bound_at,
                  )}
                />
                <div className="rounded-lg border bg-slate-50 p-3">
                  <div className="flex items-center justify-between gap-3">
                    <span className="text-xs font-medium text-foreground">Profile readiness</span>
                    <Badge
                      variant={avatarSrc && profileForm.mobile ? "secondary" : "outline"}
                      className="text-[10px]"
                    >
                      {avatarSrc && profileForm.mobile ? "Complete" : "In progress"}
                    </Badge>
                  </div>
                  <Progress
                    className="mt-3 h-1.5"
                    value={
                      (hasBoundTin ? 35 : 0) +
                      (avatarSrc ? 30 : 0) +
                      (profileForm.mobile ? 20 : 0) +
                      (profileForm.email ? 15 : 0)
                    }
                  />
                </div>
              </CardContent>
            </Card>
          </div>
        </section>
      )}

      {view === "security" && (
        <section id="client-security" className="scroll-mt-24 space-y-4">
          <div className="grid gap-4 lg:grid-cols-3">
            <SecurityControl
              icon={Fingerprint}
              title="Multi-Factor Authentication"
              status={clientAccount?.mfa_enabled ? "Enabled" : "Pending"}
              detail={
                clientAccount?.mfa_enabled
                  ? "Strong login protection is active."
                  : "Enable MFA to strengthen account access."
              }
              good={Boolean(clientAccount?.mfa_enabled)}
            />
            <SecurityControl
              icon={ShieldCheck}
              title="TIN Binding"
              status={hasBoundTin ? "Verified" : "Required"}
              detail={
                hasBoundTin
                  ? `Bound to ${boundTin}`
                  : "TIN binding is required before barcode generation."
              }
              good={hasBoundTin}
            />
            <SecurityControl
              icon={ShieldAlert}
              title="Receipt Fraud Protection"
              status="Active"
              detail="Receipts are checked through QR, receipt number, and signature validation."
              good
            />
          </div>
        </section>
      )}
    </div>
  );
}

export function ClientDashboard() {
  const location = useLocation();
  const legacyRoute = legacyClientHashRoutes[location.hash];

  if (legacyRoute) return <Navigate to={legacyRoute} replace />;

  return <EnterpriseDashboard portal="citizen" />;
}

export function ClientTransactionsPage() {
  return <ClientPortalPage view="transactions" />;
}

export function ClientReceiptsPage() {
  return <ClientPortalPage view="receipts" />;
}

export function ClientBarcodePage() {
  return <ClientPortalPage view="barcode" />;
}

export function ClientWalletPage() {
  return <ClientPortalPage view="wallet" />;
}

export function ClientNotificationsPage() {
  return <ClientPortalPage view="notifications" />;
}

export function ClientSecurityPage() {
  return <AccountSecuritySettings />;
}

export function ClientProfilePage() {
  return <ClientPortalPage view="profile" />;
}

function TinBindingDialog({
  open,
  tinInput,
  tinEvidence,
  onEvidenceChange,
  tinError,
  saving,
  canClose,
  onOpenChange,
  onTinChange,
  onSubmit,
}: {
  open: boolean;
  tinInput: string;
  tinEvidence: string;
  onEvidenceChange: (value: string) => void;
  tinError: string;
  saving: boolean;
  canClose: boolean;
  onOpenChange: (open: boolean) => void;
  onTinChange: (value: string) => void;
  onSubmit: (event: FormEvent) => void;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent showCloseButton={canClose} className="sm:max-w-md">
        <form onSubmit={onSubmit} className="space-y-5">
          <DialogHeader>
            <div className="mb-1 flex h-11 w-11 items-center justify-center rounded-lg bg-primary/10">
              <UserCheck className="h-5 w-5 text-primary" />
            </div>
            <DialogTitle>Request taxpayer identity review</DialogTitle>
            <DialogDescription>
              Provide your personal TIN and original evidence reference. An independent reviewer
              must verify the claim before activation or barcode access.
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-2">
            <Label htmlFor="client-tin">Client TIN number</Label>
            <Input
              id="client-tin"
              value={tinInput}
              onChange={(event) => onTinChange(event.target.value)}
              placeholder="000-000-000"
              className="font-mono"
              inputMode="numeric"
              autoFocus
              required
            />
            {tinError ? (
              <p className="text-xs text-destructive">{tinError}</p>
            ) : (
              <p className="text-xs text-muted-foreground">
                Use your nine-digit personal TIN. A submitted claim does not establish verified
                identity.
              </p>
            )}
          </div>

          <label className="block space-y-2">
            Original evidence reference
            <Input
              required
              minLength={10}
              maxLength={2000}
              value={tinEvidence}
              onChange={(e) => onEvidenceChange(e.target.value)}
              placeholder="Reference to the identity / taxpayer evidence submitted for review"
            />
          </label>
          <div className="rounded-lg border bg-slate-50 p-3 text-xs leading-5 text-muted-foreground">
            No barcode will be generated until this TIN is bound. Business account scans will post
            future transactions to this client account.
          </div>

          <DialogFooter className="grid grid-cols-1 gap-2 sm:grid-cols-[auto_minmax(0,1fr)]">
            {canClose && (
              <Button
                type="button"
                variant="outline"
                className="w-full sm:w-auto"
                onClick={() => onOpenChange(false)}
              >
                Cancel
              </Button>
            )}
            <Button type="submit" className="w-full min-w-0 gap-2" disabled={saving}>
              {saving ? (
                <Loader2 className="h-4 w-4 animate-spin" />
              ) : (
                <CheckCircle2 className="h-4 w-4" />
              )}
              Submit identity for review
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}

function ReceiptPreviewDialog({
  row,
  clientName,
  onOpenChange,
  onDownload,
}: {
  row: TransactionRow | null;
  clientName: string;
  onOpenChange: (open: boolean) => void;
  onDownload: (row: TransactionRow) => void;
}) {
  return (
    <Dialog open={Boolean(row)} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
        {row && (
          <div className="space-y-5">
            <DialogHeader>
              <div className="mb-1 flex h-11 w-11 items-center justify-center rounded-lg bg-primary/10">
                <Receipt className="h-5 w-5 text-primary" />
              </div>
              <DialogTitle>Electronic receipt</DialogTitle>
              <DialogDescription>
                {row.receiptNumber || row.reference} from {row.merchant}
              </DialogDescription>
            </DialogHeader>

            <div className="overflow-hidden rounded-lg border">
              <div className="bg-primary p-4 text-primary-foreground">
                <p className="text-xs uppercase tracking-wide opacity-80">
                  NUERS Electronic Receipt
                </p>
                <p className="mt-1 font-mono text-lg font-bold">
                  {row.receiptNumber || row.reference}
                </p>
              </div>
              <div className="grid gap-4 p-4 sm:grid-cols-2">
                <ReceiptPreviewField label="Business account" value={row.merchant} />
                <ReceiptPreviewField
                  label="Business TIN"
                  value={row.merchantTin || "TIN pending"}
                  mono
                />
                <ReceiptPreviewField label="Customer" value={row.buyerName || clientName} />
                <ReceiptPreviewField label="Date" value={formatDate(row.date)} />
                <ReceiptPreviewField label="Document" value={row.documentLabel || row.taxLabel} />
                <ReceiptPreviewField label="Status" value={row.status} />
              </div>
            </div>

            <div className="rounded-lg border">
              <div className="border-b bg-slate-50 px-4 py-3">
                <p className="text-sm font-semibold text-foreground">Purchased items</p>
              </div>
              <div className="overflow-x-auto">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Item</TableHead>
                      <TableHead className="text-right">Qty</TableHead>
                      <TableHead className="text-right">Unit</TableHead>
                      <TableHead className="text-right">Total</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {row.items.length ? (
                      row.items.map((item, index) => (
                        <TableRow key={`${item.description}-${index}`}>
                          <TableCell className="font-medium">{item.description}</TableCell>
                          <TableCell className="text-right">
                            {item.quantity.toLocaleString("en-PH")}
                          </TableCell>
                          <TableCell className="text-right">{formatPHP(item.unitPrice)}</TableCell>
                          <TableCell className="text-right font-semibold">
                            {formatPHP(item.total)}
                          </TableCell>
                        </TableRow>
                      ))
                    ) : (
                      <TableRow>
                        <TableCell
                          colSpan={4}
                          className="py-8 text-center text-xs text-muted-foreground"
                        >
                          No item details saved for this receipt.
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>
              </div>
            </div>

            <div className="grid gap-3 rounded-lg border bg-slate-50 p-4 sm:grid-cols-3">
              <ReceiptPreviewField label="Tax type" value={row.taxLabel} />
              <ReceiptPreviewField label="VAT" value={formatPHP(row.vat ?? 0)} />
              <ReceiptPreviewField label="Total due" value={formatPHP(row.amount)} strong />
            </div>

            <DialogFooter>
              <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                Close
              </Button>
              <Button type="button" className="gap-2" onClick={() => onDownload(row)}>
                <Download className="h-4 w-4" />
                Download PDF receipt
              </Button>
            </DialogFooter>
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

function ReceiptPreviewField({
  label,
  value,
  mono = false,
  strong = false,
}: {
  label: string;
  value: string;
  mono?: boolean;
  strong?: boolean;
}) {
  return (
    <div>
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p
        className={`mt-1 text-sm ${mono ? "font-mono" : ""} ${strong ? "text-lg font-bold text-foreground" : "font-semibold text-foreground"}`}
      >
        {value}
      </p>
    </div>
  );
}

function MetricCard({
  label,
  value,
  subtext,
  icon: Icon,
}: {
  label: string;
  value: string;
  subtext: string;
  icon: ElementType;
}) {
  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-center justify-between gap-3">
          <span className="text-xs text-muted-foreground">{label}</span>
          <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
        </div>
        <p className="mt-2 truncate text-2xl font-bold text-foreground">{value}</p>
        <p className="mt-1 text-xs text-muted-foreground">{subtext}</p>
      </CardContent>
    </Card>
  );
}

function IdentityField({
  label,
  value,
  mono = false,
}: {
  label: string;
  value: string;
  mono?: boolean;
}) {
  return (
    <div className="rounded-lg border bg-background p-3">
      <p className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className={`mt-1 text-sm font-semibold text-foreground ${mono ? "font-mono" : ""}`}>
        {value}
      </p>
    </div>
  );
}

function ReadinessRow({ label, value, status }: { label: string; value: number; status: string }) {
  return (
    <div>
      <div className="mb-2 flex items-center justify-between gap-3">
        <span className="text-xs font-medium text-foreground">{label}</span>
        <span className="text-xs text-muted-foreground">{status}</span>
      </div>
      <Progress value={value} />
    </div>
  );
}

function TinLockedState({ onOpen }: { onOpen: () => void }) {
  return (
    <div className="flex min-h-44 flex-col items-center justify-center rounded-lg border border-dashed bg-slate-50 p-6 text-center">
      <LockKeyhole className="h-8 w-8 text-muted-foreground" />
      <p className="mt-3 text-sm font-semibold text-foreground">Barcode unavailable</p>
      <p className="mt-1 max-w-md text-xs leading-5 text-muted-foreground">
        Bind your TIN first. NUERS will not generate a client barcode until your TIN is saved to
        your profile.
      </p>
      <Button className="mt-4 gap-2" onClick={onOpen}>
        <UserCheck className="h-4 w-4" />
        Bind TIN now
      </Button>
    </div>
  );
}

function EmptyLedger({ hasBoundTin, onOpenTin }: { hasBoundTin: boolean; onOpenTin: () => void }) {
  return (
    <div className="flex min-h-64 flex-col items-center justify-center rounded-lg border border-dashed bg-slate-50 p-8 text-center">
      <FileText className="h-10 w-10 text-muted-foreground" />
      <p className="mt-3 text-sm font-semibold text-foreground">
        {hasBoundTin ? "No transactions recorded yet" : "Bind your TIN to load transactions"}
      </p>
      <p className="mt-1 max-w-lg text-xs leading-5 text-muted-foreground">
        {hasBoundTin
          ? "New purchases and official electronic receipts will appear here once posted by business accounts or agencies."
          : "Your transaction ledger and barcode stay locked until a valid TIN number is bound to your client account."}
      </p>
      {!hasBoundTin && (
        <Button className="mt-4 gap-2" onClick={onOpenTin}>
          <UserCheck className="h-4 w-4" />
          Bind TIN now
        </Button>
      )}
    </div>
  );
}

function EnterpriseEmpty({
  icon: Icon,
  title,
  text,
  compact = false,
}: {
  icon: ElementType;
  title: string;
  text: string;
  compact?: boolean;
}) {
  return (
    <div
      className={`flex flex-col items-center justify-center rounded-lg border border-dashed bg-slate-50 p-6 text-center ${compact ? "min-h-36" : "min-h-52"}`}
    >
      <Icon className="h-8 w-8 text-muted-foreground" />
      <p className="mt-3 text-sm font-semibold text-foreground">{title}</p>
      <p className="mt-1 max-w-md text-xs leading-5 text-muted-foreground">{text}</p>
    </div>
  );
}

function MonthlySpendChart({ points }: { points: Array<{ label: string; amount: number }> }) {
  const max = Math.max(...points.map((point) => point.amount), 1);

  return (
    <div className="flex h-56 items-end gap-3">
      {points.map((point) => (
        <div key={point.label} className="flex min-w-0 flex-1 flex-col items-center gap-2">
          <div className="flex h-40 w-full items-end rounded-md bg-slate-50 p-1">
            <div
              className="w-full rounded bg-primary transition-all"
              style={{ height: `${Math.max(6, (point.amount / max) * 100)}%` }}
            />
          </div>
          <div className="text-center">
            <p className="text-[10px] font-medium text-muted-foreground">{point.label}</p>
            <p className="text-[10px] text-foreground">
              {point.amount ? compactPHP(point.amount) : "PHP 0.00"}
            </p>
          </div>
        </div>
      ))}
    </div>
  );
}

function WalletActivityChart({ points }: { points: Array<Record<string, string | number>> }) {
  const max = Math.max(
    ...points.flatMap((point) => [Number(point.payments ?? 0), Number(point.topup ?? 0)]),
    1,
  );

  if (!points.length) {
    return (
      <EnterpriseEmpty
        icon={Wallet}
        title="No wallet activity"
        text="Wallet payment and top-up activity will appear after account transactions are posted."
        compact
      />
    );
  }

  return (
    <div className="flex h-56 items-end gap-3">
      {points.map((point) => (
        <div key={String(point.label)} className="flex min-w-0 flex-1 flex-col items-center gap-2">
          <div className="flex h-40 w-full items-end gap-1 rounded-md bg-slate-50 p-1">
            <div
              className="flex-1 rounded bg-primary"
              style={{ height: `${Math.max(6, (Number(point.payments ?? 0) / max) * 100)}%` }}
            />
            <div
              className="flex-1 rounded bg-gold"
              style={{ height: `${Math.max(6, (Number(point.topup ?? 0) / max) * 100)}%` }}
            />
          </div>
          <p className="text-[10px] font-medium text-muted-foreground">{point.label}</p>
        </div>
      ))}
    </div>
  );
}

function ChannelMix({ rows }: { rows: Array<{ label: string; value: number; percent: number }> }) {
  if (!rows.length) {
    return (
      <EnterpriseEmpty
        icon={CreditCard}
        title="No payment mix yet"
        text="Payment channel analytics will appear after matched transactions are recorded."
        compact
      />
    );
  }

  return (
    <div className="space-y-3">
      {rows.map((row) => (
        <div key={row.label}>
          <div className="mb-1.5 flex items-center justify-between gap-3">
            <span className="capitalize text-xs font-medium text-foreground">{row.label}</span>
            <span className="text-xs text-muted-foreground">{row.percent}%</span>
          </div>
          <Progress value={row.percent} />
          <p className="mt-1 text-[10px] text-muted-foreground">{formatPHP(row.value)}</p>
        </div>
      ))}
    </div>
  );
}

function DashboardListCard({
  item,
  icon: Icon,
  horizontal = false,
}: {
  item: DashboardListItem;
  icon: ElementType;
  horizontal?: boolean;
}) {
  return (
    <div
      className={`rounded-lg border bg-background p-4 ${horizontal ? "flex items-start justify-between gap-3" : ""}`}
    >
      <div className="flex items-start gap-3">
        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
          <Icon className="h-4 w-4" />
        </div>
        <div className="min-w-0">
          <p className="truncate text-sm font-semibold text-foreground">{item.title}</p>
          <p className="mt-0.5 text-xs text-muted-foreground">{item.subtitle}</p>
          {item.metadata?.type && (
            <p className="mt-1 text-[10px] text-muted-foreground">{String(item.metadata.type)}</p>
          )}
        </div>
      </div>
      <div
        className={`${horizontal ? "shrink-0 text-right" : "mt-4 flex items-end justify-between gap-3"}`}
      >
        <Badge variant={statusVariant(item.status)} className="text-[10px]">
          {item.status ?? item.badge ?? "Active"}
        </Badge>
        {item.primary_value !== null && (
          <p className={`${horizontal ? "mt-2" : ""} text-sm font-bold text-foreground`}>
            {formatPHP(item.primary_value)}
          </p>
        )}
      </div>
    </div>
  );
}

function SecurityControl({
  icon: Icon,
  title,
  status,
  detail,
  good,
}: {
  icon: ElementType;
  title: string;
  status: string;
  detail: string;
  good: boolean;
}) {
  return (
    <Card>
      <CardContent className="p-4">
        <div className="flex items-start gap-3">
          <div
            className={`flex h-10 w-10 shrink-0 items-center justify-center rounded-lg ${good ? "bg-success/10 text-success" : "bg-warning/15 text-warning-foreground"}`}
          >
            <Icon className="h-5 w-5" />
          </div>
          <div className="min-w-0">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-semibold text-foreground">{title}</p>
              <Badge variant={good ? "secondary" : "outline"} className="text-[10px]">
                {status}
              </Badge>
            </div>
            <p className="mt-1 text-xs leading-5 text-muted-foreground">{detail}</p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

function Code39Barcode({ value }: { value: string }) {
  const encoded = `*${value.toUpperCase().replace(/[^0-9A-Z .-]/g, "")}*`;
  const narrow = 2;
  const wide = 6;
  const gap = 2;
  let x = 10;
  const bars: Array<{ x: number; width: number }> = [];

  for (const char of encoded) {
    const pattern = code39Patterns[char] ?? code39Patterns["0"];
    pattern.split("").forEach((unit, index) => {
      const width = unit === "w" ? wide : narrow;
      if (index % 2 === 0) bars.push({ x, width });
      x += width;
    });
    x += gap;
  }

  const width = x + 10;

  return (
    <div className="rounded-lg border bg-white p-4">
      <svg
        viewBox={`0 0 ${width} 96`}
        preserveAspectRatio="none"
        className="h-28 w-full"
        role="img"
        aria-label={`NUERS barcode ${value}`}
      >
        <rect x="0" y="0" width={width} height="96" fill="white" />
        {bars.map((bar, index) => (
          <rect
            key={`${bar.x}-${index}`}
            x={bar.x}
            y="8"
            width={bar.width}
            height="62"
            fill="#0f172a"
          />
        ))}
        <text
          x={width / 2}
          y="88"
          textAnchor="middle"
          fontFamily="monospace"
          fontSize="10"
          fill="#334155"
        >
          {value}
        </text>
      </svg>
    </div>
  );
}
