import { formatTin } from "@/lib/tin";
import { useEffect, useState, useMemo } from "react";
import {
  Building2, Search, Plus, MoreHorizontal,
  CheckCircle2, AlertTriangle, Ban, Download,
  Eye, EyeOff, Edit, ShieldAlert, Trash2, Phone,
  Mail, MapPin, Globe, Users, Calendar,
  FileText, CreditCard, Store, Tag, RefreshCw,
  ClipboardList, AlertCircle, ChevronLeft, ChevronRight,
  Filter, SortAsc, SortDesc, UserCheck, BarChart3,
  Key, Send, History, TrendingUp, Check, ChevronsUpDown,
} from "lucide-react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Progress } from "@/components/ui/progress";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Checkbox } from "@/components/ui/checkbox";
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuItem,
  DropdownMenuSeparator, DropdownMenuTrigger, DropdownMenuLabel,
} from "@/components/ui/dropdown-menu";
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from "@/components/ui/select";
import {
  Command, CommandGroup, CommandInput, CommandItem, CommandList,
} from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from "@/components/ui/dialog";
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent,
  AlertDialogDescription, AlertDialogFooter, AlertDialogHeader, AlertDialogTitle,
} from "@/components/ui/alert-dialog";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Switch } from "@/components/ui/switch";
import { Separator } from "@/components/ui/separator";
import { createMerchantAccount, supabase } from "@/lib/supabase";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { toast } from "sonner";
import { cn } from "@/lib/utils";

type Merchant = {
  id: string;
  tin: string;
  business_name: string;
  owner_name: string;
  email: string;
  phone: string;
  address: string;
  business_registration_address?: string;
  country?: string;
  country_code?: string;
  city: string;
  city_code?: string;
  barangay?: string;
  barangay_code?: string;
  zip_code: string;
  rdo_code?: string;
  rdo_name?: string;
  sector: string;
  region: string;
  business_type: string;
  bir_registration_date: string;
  vat_registered: boolean;
  status: string;
  compliance_score: number;
  monthly_revenue: number;
  annual_revenue: number;
  employee_count: number;
  pos_system: string;
  merchant_account_email: string;
  notes: string;
  last_audit_date: string;
  next_audit_date: string;
  registration_date: string;
  website: string;
  logo_url?: string | null;
  branch_count: number;
  created_at: string;
};

type RegForm = {
  tin: string;
  business_name: string;
  owner_name: string;
  email: string;
  phone: string;
  address: string;
  business_registration_address: string;
  country: string;
  country_code: string;
  city: string;
  city_code: string;
  barangay: string;
  barangay_code: string;
  zip_code: string;
  rdo_code: string;
  rdo_name: string;
  sector: string;
  region: string;
  business_type: string;
  bir_registration_date: string;
  vat_registered: boolean;
  annual_revenue: number;
  employee_count: number;
  pos_system: string;
  merchant_account_email: string;
  merchant_account_password: string;
  notes: string;
  last_audit_date: string;
  next_audit_date: string;
  website: string;
  branch_count: number;
};

type CityOption = {
  code: string;
  name: string;
  region: string;
  province?: string | null;
  rdo_code: string;
  rdo_name: string;
};

type BarangayOption = {
  code: string;
  name: string;
  city_code?: string;
};

const EMPTY_FORM: RegForm = {
  tin: "",
  business_name: "",
  owner_name: "",
  email: "",
  phone: "",
  address: "",
  business_registration_address: "",
  country: "Philippines",
  country_code: "PH",
  city: "",
  city_code: "",
  barangay: "",
  barangay_code: "",
  zip_code: "",
  rdo_code: "",
  rdo_name: "",
  sector: "",
  region: "",
  business_type: "corporation",
  bir_registration_date: "",
  vat_registered: false,
  annual_revenue: 0,
  employee_count: 0,
  pos_system: "",
  merchant_account_email: "",
  merchant_account_password: "",
  notes: "",
  last_audit_date: "",
  next_audit_date: "",
  website: "",
  branch_count: 1,
};

const statusConfig: Record<string, { label: string; variant: "default" | "secondary" | "destructive" | "outline"; icon: React.ElementType; color: string }> = {
  active: { label: "Active", variant: "default", icon: CheckCircle2, color: "border-l-success" },
  under_review: { label: "Under Review", variant: "secondary", icon: AlertTriangle, color: "border-l-warning" },
  suspended: { label: "Suspended", variant: "destructive", icon: Ban, color: "border-l-destructive" },
  pending: { label: "Pending", variant: "outline", icon: AlertCircle, color: "border-l-muted-foreground" },
};

const sectorLabels: Record<string, string> = {
  accounting: "Accounting & Bookkeeping",
  advertising_marketing: "Advertising & Marketing",
  agriculture: "Agriculture",
  aquaculture: "Aquaculture",
  automotive: "Automotive",
  retail: "Retail",
  food_beverage: "Food & Beverage",
  services: "Services",
  wholesale: "Wholesale",
  banking: "Banking & Finance",
  insurance: "Insurance",
  fintech: "FinTech",
  capital_markets: "Capital Markets",
  manufacturing: "Manufacturing",
  construction: "Construction",
  mining_quarrying: "Mining & Quarrying",
  energy_power: "Energy & Power",
  petroleum_fuel: "Petroleum & Fuel",
  water_utilities: "Water & Utilities",
  healthcare: "Healthcare",
  pharmaceuticals: "Pharmaceuticals",
  public_hospital: "Public Hospital",
  education: "Education",
  state_university: "State University / College",
  training_center: "Training Center",
  real_estate: "Real Estate",
  logistics: "Logistics",
  transportation: "Transportation",
  shipping_maritime: "Shipping & Maritime",
  aviation: "Aviation",
  telecommunications: "Telecommunications",
  information_technology: "Information Technology",
  software_saas: "Software / SaaS",
  bpo_call_center: "BPO / Call Center",
  ecommerce: "E-Commerce",
  hospitality: "Hotel & Accommodation",
  tourism_travel: "Tourism & Travel",
  entertainment_media: "Entertainment & Media",
  professional_services: "Professional Services",
  legal_services: "Legal Services",
  consulting: "Consulting",
  security_services: "Security Services",
  government_agency: "National Government Agency",
  lgu: "Local Government Unit",
  gocc: "GOCC",
  regulatory_agency: "Regulatory Agency",
  nonprofit: "Non-Profit / NGO",
  cooperative: "Cooperative",
  religious: "Religious Organization",
  repair_maintenance: "Repair & Maintenance",
  personal_services: "Personal Services",
  import_export: "Import / Export",
  warehousing: "Warehousing",
  other: "Other / Unclassified",
};

const businessTypes = [
  { value: "sole_prop", label: "Sole Proprietorship" },
  { value: "partnership", label: "Partnership" },
  { value: "corporation", label: "Corporation" },
  { value: "cooperative", label: "Cooperative" },
  { value: "opc", label: "One Person Corporation" },
];

const posSystems = [
  "Oracle MICROS", "Square POS", "Clover", "Shopify", "WooCommerce",
  "Custom/Proprietary", "SAP B1", "QuickBooks POS", "Other",
];

const REGIONS = [
  "NCR", "Region I", "Region II", "Region III", "Region IV-A",
  "Region IV-B", "Region V", "Region VI", "Region VII", "Region VIII",
  "Region IX", "Region X", "Region XI", "Region XII", "CAR", "BARMM",
];

const PAGE_SIZE = 15;

function composeRegistrationAddress(form: RegForm) {
  return [
    form.business_registration_address,
    form.barangay ? `Barangay ${form.barangay}` : "",
    form.city,
    form.country,
    form.zip_code,
  ].filter(Boolean).join(", ");
}

async function fetchLocationList<T>(path: string): Promise<T[]> {
  const response = await apiFetch(path, { headers: { Accept: "application/json" } });
  const payload = await readJsonResponse<{ data?: T[] } | T[]>(response, "Location API request failed.");
  return (Array.isArray(payload) ? payload : payload.data ?? []) as T[];
}

async function fetchLocationRecord<T>(path: string): Promise<T> {
  const response = await apiFetch(path, { headers: { Accept: "application/json" } });
  const payload = await readJsonResponse<{ data?: T } | T>(response, "Location API request failed.");
  return (payload && typeof payload === "object" && "data" in payload ? (payload as { data?: T }).data : payload) as T;
}

type SearchableLocationOption = {
  value: string;
  label: string;
  keywords?: string;
};

function normalizeSelectSearch(value: string) {
  return value
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function SearchableLocationSelect({
  value,
  options,
  placeholder,
  searchPlaceholder,
  emptyLabel,
  loadingLabel,
  disabled,
  loading,
  maxListHeight = "min(24rem, calc(var(--radix-popover-content-available-height, 32rem) - 3.5rem))",
  onValueChange,
}: {
  value: string;
  options: SearchableLocationOption[];
  placeholder: string;
  searchPlaceholder: string;
  emptyLabel: string;
  loadingLabel: string;
  disabled?: boolean;
  loading?: boolean;
  maxListHeight?: string;
  onValueChange: (value: string) => void | Promise<void>;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const selected = options.find((option) => option.value === value);
  const filteredOptions = useMemo(() => {
    const needle = normalizeSelectSearch(query);

    if (!needle) {
      return options;
    }

    const words = needle.split(/\s+/).filter(Boolean);

    return options
      .map((option) => {
        const label = normalizeSelectSearch(option.label);
        const keywords = normalizeSelectSearch(option.keywords ?? "");
        const haystack = `${label} ${keywords}`.trim();
        let rank = Number.POSITIVE_INFINITY;

        if (label.startsWith(needle)) rank = 0;
        else if (label.includes(needle)) rank = 1;
        else if (haystack.includes(needle)) rank = 2;
        else if (words.every((word) => haystack.includes(word))) rank = 3;

        return Number.isFinite(rank) ? { option, rank } : null;
      })
      .filter((item): item is { option: SearchableLocationOption; rank: number } => item !== null)
      .sort((a, b) => a.rank - b.rank || a.option.label.localeCompare(b.option.label))
      .map((item) => item.option);
  }, [options, query]);

  return (
    <Popover
      open={open}
      onOpenChange={(nextOpen) => {
        setOpen(nextOpen);
        if (!nextOpen) setQuery("");
      }}
    >
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant="outline"
          role="combobox"
          aria-expanded={open}
          disabled={disabled || loading}
          className={cn(
            "h-10 w-full justify-between bg-background px-3 font-normal",
            !selected && "text-muted-foreground"
          )}
        >
          <span className="truncate">{loading ? loadingLabel : selected?.label ?? placeholder}</span>
          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] overflow-hidden p-0">
        <Command shouldFilter={false}>
          <CommandInput value={query} onValueChange={setQuery} placeholder={searchPlaceholder} />
          <CommandList
            className="overscroll-contain overflow-y-auto pr-1 [scrollbar-gutter:stable]"
            style={{ maxHeight: maxListHeight }}
            onWheelCapture={(event) => {
              const list = event.currentTarget;

              if (list.scrollHeight <= list.clientHeight) {
                return;
              }

              const canScrollUp = event.deltaY < 0 && list.scrollTop > 0;
              const canScrollDown = event.deltaY > 0 && Math.ceil(list.scrollTop + list.clientHeight) < list.scrollHeight;

              if (canScrollUp || canScrollDown) {
                event.preventDefault();
                event.stopPropagation();
                list.scrollTop += event.deltaY;
              }
            }}
          >
            {filteredOptions.length === 0 ? (
              <div className="py-6 text-center text-sm text-muted-foreground">{emptyLabel}</div>
            ) : (
            <CommandGroup>
              {filteredOptions.map((option) => (
                <CommandItem
                  key={option.value}
                  value={`${option.label} ${option.keywords ?? ""}`}
                  onSelect={() => {
                    onValueChange(option.value);
                    setOpen(false);
                  }}
                >
                  <Check className={cn("h-4 w-4", value === option.value ? "opacity-100" : "opacity-0")} />
                  <span className="truncate">{option.label}</span>
                </CommandItem>
              ))}
            </CommandGroup>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}

function formatRevenue(n: number) {
  if (!n) return "—";
  if (n >= 1_000_000) return `₱${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000) return `₱${(n / 1_000).toFixed(0)}K`;
  return `₱${n}`;
}

function complianceBand(score: number) {
  if (score >= 80) return { label: "Good", color: "text-success" };
  if (score >= 50) return { label: "Fair", color: "text-warning" };
  return { label: "Poor", color: "text-destructive" };
}

export function AdminMerchants() {
  const [merchants, setMerchants] = useState<Merchant[]>([]);
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState("all");
  const [sectorFilter, setSectorFilter] = useState("all");
  const [regionFilter, setRegionFilter] = useState("all");
  const [sortField, setSortField] = useState<"business_name" | "compliance_score" | "monthly_revenue" | "registration_date">("compliance_score");
  const [sortDir, setSortDir] = useState<"asc" | "desc">("asc");
  const [selected, setSelected] = useState<Merchant | null>(null);
  const [loading, setLoading] = useState(true);
  const [registerOpen, setRegisterOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<Merchant | null>(null);
  const [bulkDeleteOpen, setBulkDeleteOpen] = useState(false);
  const [regForm, setRegForm] = useState<RegForm>({ ...EMPTY_FORM });
  const [editForm, setEditForm] = useState<Partial<Merchant>>({});
  const [page, setPage] = useState(1);
  const [saving, setSaving] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [selectedIds, setSelectedIds] = useState<Set<string>>(new Set());
  const [activeTab, setActiveTab] = useState("basic");
  const [showFilters, setShowFilters] = useState(false);
  const [cities, setCities] = useState<CityOption[]>([]);
  const [barangays, setBarangays] = useState<BarangayOption[]>([]);
  const [locationLoading, setLocationLoading] = useState({ cities: false, barangays: false });

  useEffect(() => {
    fetchMerchants();
  }, []);

  useEffect(() => {
    fetchCities(regForm.country_code || "PH");
  }, [regForm.country_code]);

  useEffect(() => {
    if (!regForm.city_code && !regForm.city) {
      setBarangays([]);
      return;
    }

    fetchBarangays(regForm.city_code, regForm.city);
  }, [regForm.city_code, regForm.city]);

  async function fetchMerchants() {
    setLoading(true);
    const { data, error } = await supabase
      .from("merchants")
      .select("*")
      .order("created_at", { ascending: false });
    if (error) toast.error("Failed to load business accounts.");
    setMerchants((data ?? []) as Merchant[]);
    setLoading(false);
  }

  async function fetchCities(countryCode: string) {
    setLocationLoading((current) => ({ ...current, cities: true }));
    try {
      const data = await fetchLocationList<CityOption>(`/api/locations/cities?country=${encodeURIComponent(countryCode || "PH")}`);
      setCities(data);
    } catch {
      setCities([]);
      toast.error("Unable to load city list from the locations API.");
    } finally {
      setLocationLoading((current) => ({ ...current, cities: false }));
    }
  }

  async function fetchBarangays(cityCode: string, city: string) {
    setLocationLoading((current) => ({ ...current, barangays: true }));
    try {
      const params = new URLSearchParams();
      if (cityCode) params.set("cityCode", cityCode);
      if (city) params.set("city", city);
      const data = await fetchLocationList<BarangayOption>(`/api/locations/barangays?${params.toString()}`);
      setBarangays(data);
    } catch {
      setBarangays([]);
      toast.error("Unable to load barangays from the locations API.");
    } finally {
      setLocationLoading((current) => ({ ...current, barangays: false }));
    }
  }

  async function handleCityChange(cityCode: string) {
    const city = cities.find((item) => item.code === cityCode);
    setRegForm((current) => ({
      ...current,
      city_code: cityCode,
      city: city?.name ?? "",
      barangay: "",
      barangay_code: "",
      region: city?.region ?? current.region,
      rdo_code: city?.rdo_code ?? "",
      rdo_name: city?.rdo_name ?? "",
    }));

    if (!city || (city.rdo_code && city.rdo_code !== "000")) {
      return;
    }

    try {
      const params = new URLSearchParams();
      params.set("city", city.name);
      if (city.region) params.set("region", city.region);
      if (city.province) params.set("province", city.province);

      const rdo = await fetchLocationRecord<{ code: string; name: string }>(`/api/locations/rdo?${params.toString()}`);

      setRegForm((current) => (
        current.city_code === cityCode
          ? { ...current, rdo_code: rdo.code, rdo_name: rdo.name }
          : current
      ));
    } catch {
      toast.error("Unable to auto-detect RDO for the selected city.");
    }
  }

  async function handleBarangayChange(barangayCode: string) {
    const barangay = barangays.find((item) => item.code === barangayCode);
    setRegForm({
      ...regForm,
      barangay_code: barangayCode,
      barangay: barangay?.name ?? "",
    });

    if (!barangay || !regForm.city) return;

    try {
      const city = cities.find((item) => item.code === regForm.city_code);
      const params = new URLSearchParams();
      params.set("city", city?.name ?? regForm.city);
      params.set("barangay", barangay.name);
      if (city?.region || regForm.region) params.set("region", city?.region ?? regForm.region);
      if (city?.province) params.set("province", city.province);

      const rdo = await fetchLocationRecord<{ code: string; name: string }>(`/api/locations/rdo?${params.toString()}`);

      setRegForm((current) => (
        current.barangay_code === barangayCode
          ? { ...current, rdo_code: rdo.code, rdo_name: rdo.name }
          : current
      ));
    } catch {
      toast.error("Unable to auto-detect RDO for the selected barangay.");
    }
  }

  function handleExport() {
    const rows = [
      ["Business Name", "TIN", "Owner", "Email", "Phone", "Sector", "Region", "Status", "Compliance Score", "Monthly Revenue", "VAT Registered", "POS System"],
      ...merchants.map((m) => [
        m.business_name, m.tin, m.owner_name, m.email, m.phone,
        m.sector, m.region, m.status, m.compliance_score,
        m.monthly_revenue, m.vat_registered ? "Yes" : "No", m.pos_system,
      ]),
    ];
    const csv = rows.map((r) => r.map((c) => `"${String(c ?? "").replace(/"/g, '""')}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `merchants_export_${new Date().toISOString().split("T")[0]}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast.success("Business account data exported as CSV.");
  }

  async function handleRegisterMerchant() {
    if (!regForm.business_name || !regForm.tin || !regForm.email) {
      toast.error("Business name, TIN, and email are required.");
      setActiveTab("basic");
      return;
    }
    if (!regForm.business_registration_address || !regForm.country || !regForm.city || !regForm.barangay) {
      toast.error("Business registration address, country, city, and barangay are required.");
      setActiveTab("contact");
      return;
    }
    if (!regForm.merchant_account_password || regForm.merchant_account_password.length < 6) {
      toast.error("Portal account password must be at least 6 characters.");
      setActiveTab("account");
      return;
    }
    setSaving(true);
    try {
      const accountEmail = regForm.merchant_account_email || regForm.email;
      const registrationAddress = composeRegistrationAddress(regForm);

      const insertPayload: Record<string, unknown> = {
        tin: regForm.tin,
        business_name: regForm.business_name,
        owner_name: regForm.owner_name,
        email: regForm.email,
        phone: regForm.phone,
        address: registrationAddress,
        business_registration_address: regForm.business_registration_address,
        country: regForm.country,
        country_code: regForm.country_code,
        city: regForm.city,
        city_code: regForm.city_code,
        barangay: regForm.barangay,
        barangay_code: regForm.barangay_code,
        zip_code: regForm.zip_code,
        rdo_code: regForm.rdo_code,
        rdo_name: regForm.rdo_name,
        sector: regForm.sector || "retail",
        region: regForm.region || "NCR",
        business_type: regForm.business_type,
        vat_registered: regForm.vat_registered,
        annual_revenue: regForm.annual_revenue || 0,
        employee_count: regForm.employee_count || 0,
        pos_system: regForm.pos_system,
        merchant_account_email: accountEmail,
        notes: regForm.notes,
        website: regForm.website,
        branch_count: regForm.branch_count || 1,
        status: "under_review",
        compliance_score: 0,
        monthly_revenue: 0,
        registration_date: new Date().toISOString().split("T")[0],
      };

      if (regForm.bir_registration_date) insertPayload.bir_registration_date = regForm.bir_registration_date;
      if (regForm.last_audit_date) insertPayload.last_audit_date = regForm.last_audit_date;
      if (regForm.next_audit_date) insertPayload.next_audit_date = regForm.next_audit_date;

      const { data: newMerchant, error } = await supabase
        .from("merchants")
        .insert(insertPayload)
        .select()
        .single();

      if (error) throw error;

      const { data: accountResult, error: accountError } = await createMerchantAccount({
        email: accountEmail,
        password: regForm.merchant_account_password,
        merchantId: (newMerchant as Merchant).id,
        businessName: regForm.business_name,
      });

      if (accountError || !accountResult?.success) {
        const errMsg = accountError?.message ?? "Unknown error";
        toast.error(`Business account registered but portal account creation failed: ${errMsg}`, { duration: 8000 });
      } else {
        toast.success(`"${regForm.business_name}" registered. Portal account created for ${accountEmail}.`, { duration: 5000 });
      }

      setMerchants((prev) => [newMerchant as Merchant, ...prev]);
      setRegForm({ ...EMPTY_FORM });
      setShowPassword(false);
      setActiveTab("basic");
      setRegisterOpen(false);
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : "Failed to register business account.";
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  }

  async function handleSaveEdit() {
    if (!selected) return;
    setSaving(true);
    try {
      // Sanitize date fields
      const payload: Record<string, unknown> = { ...editForm };
      if (payload.bir_registration_date === "") delete payload.bir_registration_date;
      if (payload.last_audit_date === "") delete payload.last_audit_date;
      if (payload.next_audit_date === "") delete payload.next_audit_date;

      const { error } = await supabase.from("merchants").update(payload).eq("id", selected.id);
      if (error) throw error;
      setMerchants((prev) =>
        prev.map((m) => m.id === selected.id ? { ...m, ...editForm } : m)
      );
      setSelected((prev) => prev ? { ...prev, ...editForm } : prev);
      toast.success("Business account record updated.");
      setEditOpen(false);
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : "Failed to update business account.");
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(merchant: Merchant) {
    try {
      const { error } = await supabase.from("merchants").delete().eq("id", merchant.id);
      if (error) throw error;
      setMerchants((prev) => prev.filter((m) => m.id !== merchant.id));
      if (selected?.id === merchant.id) setSelected(null);
      toast.success(`"${merchant.business_name}" deleted from registry.`);
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : "Failed to delete business account.");
    } finally {
      setDeleteTarget(null);
    }
  }

  async function handleBulkDelete() {
    const ids = [...selectedIds];
    try {
      const { error } = await supabase.from("merchants").delete().in("id", ids);
      if (error) throw error;
      setMerchants((prev) => prev.filter((m) => !ids.includes(m.id)));
      setSelectedIds(new Set());
      toast.success(`${ids.length} business accounts deleted.`);
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : "Bulk delete failed.");
    } finally {
      setBulkDeleteOpen(false);
    }
  }

  async function handleBulkStatusChange(status: string) {
    const ids = [...selectedIds];
    try {
      const { error } = await supabase.from("merchants").update({ status }).in("id", ids);
      if (error) throw error;
      setMerchants((prev) => prev.map((m) => ids.includes(m.id) ? { ...m, status } : m));
      setSelectedIds(new Set());
      toast.success(`${ids.length} business accounts updated to "${statusConfig[status]?.label ?? status}".`);
    } catch (err: unknown) {
      toast.error(err instanceof Error ? err.message : "Bulk update failed.");
    }
  }

  async function handleMerchantAction(action: string, merchant: Merchant) {
    switch (action) {
      case "notice":
        toast.success(`Compliance notice sent to ${merchant.business_name}.`);
        break;
      case "audit": {
        const nextAudit = new Date();
        nextAudit.setDate(nextAudit.getDate() + 30);
        const val = nextAudit.toISOString().split("T")[0];
        const { error } = await supabase.from("merchants").update({ next_audit_date: val }).eq("id", merchant.id);
        if (!error) {
          setMerchants((prev) => prev.map((m) => m.id === merchant.id ? { ...m, next_audit_date: val } : m));
          toast.success(`Audit scheduled for ${merchant.business_name} on ${nextAudit.toLocaleDateString()}.`);
        }
        break;
      }
      case "flag": {
        const { error } = await supabase.from("merchants").update({ status: "under_review" }).eq("id", merchant.id);
        if (!error) {
          setMerchants((prev) => prev.map((m) => m.id === merchant.id ? { ...m, status: "under_review" } : m));
          setSelected((prev) => prev?.id === merchant.id ? { ...prev, status: "under_review" } : prev);
          toast.warning(`${merchant.business_name} flagged for investigation.`);
        }
        break;
      }
      case "suspend": {
        const newStatus = merchant.status === "suspended" ? "active" : "suspended";
        const { error } = await supabase.from("merchants").update({ status: newStatus }).eq("id", merchant.id);
        if (!error) {
          setMerchants((prev) => prev.map((m) => m.id === merchant.id ? { ...m, status: newStatus } : m));
          setSelected((prev) => prev?.id === merchant.id ? { ...prev, status: newStatus } : prev);
          toast.success(merchant.status === "suspended" ? `${merchant.business_name} reactivated.` : `${merchant.business_name} suspended.`);
        }
        break;
      }
      case "activate": {
        const { error } = await supabase.from("merchants").update({ status: "active" }).eq("id", merchant.id);
        if (!error) {
          setMerchants((prev) => prev.map((m) => m.id === merchant.id ? { ...m, status: "active" } : m));
          setSelected((prev) => prev?.id === merchant.id ? { ...prev, status: "active" } : prev);
          toast.success(`${merchant.business_name} approved and activated.`);
        }
        break;
      }
      case "resend": {
        toast.success(`Portal invitation resent to ${merchant.merchant_account_email || merchant.email}.`);
        break;
      }
    }
  }

  const filtered = useMemo(() => {
    const q = search.toLowerCase();
    let list = merchants.filter((m) => {
      const matchSearch =
        !q ||
        m.business_name?.toLowerCase().includes(q) ||
        m.tin?.includes(q) ||
        m.owner_name?.toLowerCase().includes(q) ||
        m.email?.toLowerCase().includes(q) ||
        m.city?.toLowerCase().includes(q);
      const matchStatus = statusFilter === "all" || m.status === statusFilter;
      const matchSector = sectorFilter === "all" || m.sector === sectorFilter;
      const matchRegion = regionFilter === "all" || m.region === regionFilter;
      return matchSearch && matchStatus && matchSector && matchRegion;
    });

    list = [...list].sort((a, b) => {
      const av = a[sortField] ?? 0;
      const bv = b[sortField] ?? 0;
      if (typeof av === "string" && typeof bv === "string") {
        return sortDir === "asc" ? av.localeCompare(bv) : bv.localeCompare(av);
      }
      return sortDir === "asc" ? (av as number) - (bv as number) : (bv as number) - (av as number);
    });

    return list;
  }, [merchants, search, statusFilter, sectorFilter, regionFilter, sortField, sortDir]);

  const totalPages = Math.max(1, Math.ceil(filtered.length / PAGE_SIZE));
  const paginated = filtered.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);

  const stats = {
    total: merchants.length,
    active: merchants.filter((m) => m.status === "active").length,
    underReview: merchants.filter((m) => m.status === "under_review").length,
    suspended: merchants.filter((m) => m.status === "suspended").length,
    avgCompliance: merchants.length
      ? Math.round(merchants.reduce((s, m) => s + (m.compliance_score ?? 0), 0) / merchants.length)
      : 0,
  };

  function toggleSort(field: typeof sortField) {
    if (sortField === field) {
      setSortDir((d) => (d === "asc" ? "desc" : "asc"));
    } else {
      setSortField(field);
      setSortDir("asc");
    }
  }

  function toggleSelectAll() {
    if (selectedIds.size === paginated.length) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(paginated.map((m) => m.id)));
    }
  }

  function toggleSelect(id: string) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  function openEdit(merchant: Merchant) {
    setEditForm({
      business_name: merchant.business_name,
      owner_name: merchant.owner_name,
      email: merchant.email,
      phone: merchant.phone,
      address: merchant.address,
      business_registration_address: merchant.business_registration_address,
      country: merchant.country,
      country_code: merchant.country_code,
      city: merchant.city,
      city_code: merchant.city_code,
      barangay: merchant.barangay,
      barangay_code: merchant.barangay_code,
      zip_code: merchant.zip_code,
      rdo_code: merchant.rdo_code,
      rdo_name: merchant.rdo_name,
      sector: merchant.sector,
      region: merchant.region,
      business_type: merchant.business_type,
      vat_registered: merchant.vat_registered,
      employee_count: merchant.employee_count,
      pos_system: merchant.pos_system,
      website: merchant.website,
      branch_count: merchant.branch_count,
      notes: merchant.notes,
      annual_revenue: merchant.annual_revenue,
      compliance_score: merchant.compliance_score,
      bir_registration_date: merchant.bir_registration_date ?? "",
      last_audit_date: merchant.last_audit_date ?? "",
      next_audit_date: merchant.next_audit_date ?? "",
    });
    setEditOpen(true);
  }

  const SortIcon = ({ field }: { field: typeof sortField }) => {
    if (sortField !== field) return null;
    return sortDir === "asc"
      ? <SortAsc className="inline h-3 w-3 ml-1" />
      : <SortDesc className="inline h-3 w-3 ml-1" />;
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Business Account Registry</h1>
          <p className="text-sm text-muted-foreground">
            {stats.total.toLocaleString()} registered businesses · Enterprise management console
          </p>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" className="gap-2" onClick={handleExport}>
            <Download className="h-4 w-4" /> Export
          </Button>
          <Button variant="outline" size="sm" className="gap-2" onClick={fetchMerchants}>
            <RefreshCw className="h-4 w-4" /> Refresh
          </Button>
          <Button size="sm" className="gap-2" onClick={() => { setRegisterOpen(true); setActiveTab("basic"); }}>
            <Plus className="h-4 w-4" /> Register Business Account
          </Button>
        </div>
      </div>

      {/* Stats */}
      <div className="grid gap-4 sm:grid-cols-5">
        {[
          { label: "Total Registered", value: stats.total.toLocaleString(), icon: Building2, color: "border-l-primary" },
          { label: "Active", value: stats.active.toLocaleString(), icon: CheckCircle2, color: "border-l-success" },
          { label: "Under Review", value: stats.underReview.toLocaleString(), icon: AlertTriangle, color: "border-l-warning" },
          { label: "Suspended", value: stats.suspended.toLocaleString(), icon: Ban, color: "border-l-destructive" },
          { label: "Avg. Compliance", value: `${stats.avgCompliance}%`, icon: BarChart3, color: "border-l-primary" },
        ].map((s) => (
          <Card key={s.label} className={`border-l-4 ${s.color}`}>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <span className="text-xs text-muted-foreground">{s.label}</span>
                <s.icon className="h-4 w-4 text-muted-foreground" />
              </div>
              <p className="mt-1 text-2xl font-bold text-foreground">{s.value}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Table */}
      <Card>
        <CardContent className="p-5">
          {/* Search + Filters */}
          <div className="mb-3 flex flex-wrap items-center gap-2">
            <div className="relative min-w-0 flex-1 basis-full sm:min-w-[240px] sm:basis-auto">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search name, TIN, owner, email, city..."
                value={search}
                onChange={(e) => { setSearch(e.target.value); setPage(1); }}
                className="pl-9"
              />
            </div>
            <Button
              variant={showFilters ? "secondary" : "outline"}
              size="sm"
              className="gap-2"
              onClick={() => setShowFilters((v) => !v)}
            >
              <Filter className="h-4 w-4" /> Filters
              {(statusFilter !== "all" || sectorFilter !== "all" || regionFilter !== "all") && (
                <Badge variant="destructive" className="h-4 min-w-4 px-1 text-[10px]">
                  {[statusFilter, sectorFilter, regionFilter].filter((v) => v !== "all").length}
                </Badge>
              )}
            </Button>
            <Button variant="outline" size="sm" className="gap-2" onClick={() => { setStatusFilter("all"); setSectorFilter("all"); setRegionFilter("all"); setSearch(""); }}>
              Clear
            </Button>
          </div>

          {showFilters && (
            <div className="mb-4 flex flex-wrap gap-2 rounded-lg border bg-muted/30 p-3">
              <Select value={statusFilter} onValueChange={(v) => { setStatusFilter(v); setPage(1); }}>
                <SelectTrigger className="w-full bg-background sm:w-44">
                  <SelectValue placeholder="All Statuses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="active">Active</SelectItem>
                  <SelectItem value="under_review">Under Review</SelectItem>
                  <SelectItem value="suspended">Suspended</SelectItem>
                  <SelectItem value="pending">Pending</SelectItem>
                </SelectContent>
              </Select>
              <Select value={sectorFilter} onValueChange={(v) => { setSectorFilter(v); setPage(1); }}>
                <SelectTrigger className="w-full bg-background sm:w-48">
                  <SelectValue placeholder="All Sectors" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Sectors</SelectItem>
                  {Object.entries(sectorLabels).map(([v, l]) => (
                    <SelectItem key={v} value={v}>{l}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Select value={regionFilter} onValueChange={(v) => { setRegionFilter(v); setPage(1); }}>
                <SelectTrigger className="w-full bg-background sm:w-44">
                  <SelectValue placeholder="All Regions" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All Regions</SelectItem>
                  {REGIONS.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}
                </SelectContent>
              </Select>
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button variant="outline" size="sm" className="gap-2 bg-background">
                    <SortAsc className="h-4 w-4" /> Sort: {sortField.replace(/_/g, " ")} {sortDir === "asc" ? "↑" : "↓"}
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent>
                  <DropdownMenuLabel>Sort By</DropdownMenuLabel>
                  {(["business_name", "compliance_score", "monthly_revenue", "registration_date"] as const).map((f) => (
                    <DropdownMenuItem key={f} onClick={() => toggleSort(f)}>
                      {f.replace(/_/g, " ")} {sortField === f && (sortDir === "asc" ? "↑" : "↓")}
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            </div>
          )}

          {/* Bulk actions bar */}
          {selectedIds.size > 0 && (
            <div className="mb-3 flex items-center gap-2 rounded-lg border border-primary/20 bg-primary/5 px-4 py-2">
              <span className="text-sm font-medium text-foreground">{selectedIds.size} selected</span>
              <Separator orientation="vertical" className="h-4" />
              <Button variant="outline" size="sm" className="gap-1.5 text-xs" onClick={() => handleBulkStatusChange("active")}>
                <CheckCircle2 className="h-3.5 w-3.5 text-success" /> Activate
              </Button>
              <Button variant="outline" size="sm" className="gap-1.5 text-xs" onClick={() => handleBulkStatusChange("under_review")}>
                <AlertTriangle className="h-3.5 w-3.5 text-warning" /> Flag Review
              </Button>
              <Button variant="outline" size="sm" className="gap-1.5 text-xs" onClick={() => handleBulkStatusChange("suspended")}>
                <Ban className="h-3.5 w-3.5 text-destructive" /> Suspend
              </Button>
              <Button variant="destructive" size="sm" className="gap-1.5 text-xs ml-auto" onClick={() => setBulkDeleteOpen(true)}>
                <Trash2 className="h-3.5 w-3.5" /> Delete Selected
              </Button>
              <Button variant="ghost" size="sm" className="text-xs" onClick={() => setSelectedIds(new Set())}>
                Clear
              </Button>
            </div>
          )}

          {/* Table */}
          <div className="overflow-x-auto">
            <table className="w-full">
              <thead>
                <tr className="border-b">
                  <th className="pb-3 pr-3 w-8">
                    <Checkbox
                      checked={paginated.length > 0 && selectedIds.size === paginated.length}
                      onCheckedChange={toggleSelectAll}
                    />
                  </th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground cursor-pointer" onClick={() => toggleSort("business_name")}>
                    Business <SortIcon field="business_name" />
                  </th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">TIN</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Sector</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Region</th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground cursor-pointer" onClick={() => toggleSort("monthly_revenue")}>
                    Revenue <SortIcon field="monthly_revenue" />
                  </th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground cursor-pointer" onClick={() => toggleSort("compliance_score")}>
                    Compliance <SortIcon field="compliance_score" />
                  </th>
                  <th className="pb-3 text-left text-xs font-medium text-muted-foreground">Status</th>
                  <th className="pb-3 w-8"></th>
                </tr>
              </thead>
              <tbody>
                {loading
                  ? Array.from({ length: 5 }).map((_, i) => (
                      <tr key={i} className="border-b">
                        {Array.from({ length: 9 }).map((_, j) => (
                          <td key={j} className="py-3 pr-3">
                            <div className="h-4 w-20 animate-pulse rounded bg-muted" />
                          </td>
                        ))}
                      </tr>
                    ))
                  : paginated.length === 0
                  ? (
                    <tr>
                      <td colSpan={9} className="py-12 text-center">
                        <div className="flex flex-col items-center gap-2 text-muted-foreground">
                          <Building2 className="h-8 w-8" />
                          <p className="text-sm font-medium">No business accounts found</p>
                          <p className="text-xs">Try adjusting your search or filters</p>
                        </div>
                      </td>
                    </tr>
                  )
                  : paginated.map((m) => {
                      const sc = statusConfig[m.status] ?? statusConfig.active;
                      const band = complianceBand(m.compliance_score ?? 0);
                      const isChecked = selectedIds.has(m.id);
                      return (
                        <tr
                          key={m.id}
                          className={cn(
                            "border-b last:border-0 transition-colors cursor-pointer",
                            isChecked ? "bg-primary/5" : "hover:bg-muted/30"
                          )}
                          onClick={() => setSelected(m)}
                        >
                          <td className="py-3 pr-3" onClick={(e) => e.stopPropagation()}>
                            <Checkbox
                              checked={isChecked}
                              onCheckedChange={() => toggleSelect(m.id)}
                            />
                          </td>
                          <td className="py-3 pr-4">
                            <div className="flex items-center gap-3">
                              <Avatar className="h-8 w-8 shrink-0">
                                {m.logo_url && <AvatarImage src={m.logo_url} alt={`${m.business_name} logo`} className="object-cover" />}
                                <AvatarFallback className="text-xs bg-secondary font-semibold">
                                  {m.business_name?.slice(0, 2).toUpperCase()}
                                </AvatarFallback>
                              </Avatar>
                              <div className="min-w-0">
                                <p className="text-xs font-medium text-foreground truncate max-w-[160px]">{m.business_name}</p>
                                <p className="text-[10px] text-muted-foreground">{m.owner_name || "—"}</p>
                              </div>
                            </div>
                          </td>
                          <td className="py-3 font-mono text-xs text-muted-foreground whitespace-nowrap">{formatTin(m.tin)}</td>
                          <td className="py-3">
                            <Badge variant="secondary" className="text-[10px] whitespace-nowrap">
                              {sectorLabels[m.sector] ?? m.sector}
                            </Badge>
                          </td>
                          <td className="py-3 text-xs text-muted-foreground whitespace-nowrap">{m.region}</td>
                          <td className="py-3 text-xs font-medium text-foreground whitespace-nowrap">{formatRevenue(m.monthly_revenue)}</td>
                          <td className="py-3">
                            <div className="flex items-center gap-2">
                              <Progress value={m.compliance_score ?? 0} className="h-1.5 w-16" />
                              <span className={`text-xs font-bold ${band.color}`}>{m.compliance_score ?? 0}</span>
                            </div>
                          </td>
                          <td className="py-3">
                            <Badge variant={sc.variant} className="gap-1 text-[10px] whitespace-nowrap">
                              <sc.icon className="h-2.5 w-2.5" />
                              {sc.label}
                            </Badge>
                          </td>
                          <td className="py-3" onClick={(e) => e.stopPropagation()}>
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button variant="ghost" size="icon" className="h-7 w-7">
                                  <MoreHorizontal className="h-4 w-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end">
                                <DropdownMenuItem onClick={() => setSelected(m)}>
                                  <Eye className="mr-2 h-3.5 w-3.5" /> View Details
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => { setSelected(m); openEdit(m); }}>
                                  <Edit className="mr-2 h-3.5 w-3.5" /> Edit Record
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                {m.status === "under_review" && (
                                  <DropdownMenuItem onClick={() => handleMerchantAction("activate", m)}>
                                    <CheckCircle2 className="mr-2 h-3.5 w-3.5 text-success" /> Approve & Activate
                                  </DropdownMenuItem>
                                )}
                                <DropdownMenuItem onClick={() => handleMerchantAction("notice", m)}>
                                  <FileText className="mr-2 h-3.5 w-3.5" /> Send Compliance Notice
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => handleMerchantAction("audit", m)}>
                                  <ClipboardList className="mr-2 h-3.5 w-3.5" /> Schedule Audit
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => handleMerchantAction("resend", m)}>
                                  <Send className="mr-2 h-3.5 w-3.5" /> Resend Portal Invite
                                </DropdownMenuItem>
                                <DropdownMenuItem className="text-warning" onClick={() => handleMerchantAction("flag", m)}>
                                  <ShieldAlert className="mr-2 h-3.5 w-3.5" /> Flag for Investigation
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  className={m.status === "suspended" ? "text-success" : "text-destructive"}
                                  onClick={() => handleMerchantAction("suspend", m)}
                                >
                                  <Ban className="mr-2 h-3.5 w-3.5" />
                                  {m.status === "suspended" ? "Reactivate" : "Suspend Account"}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem className="text-destructive" onClick={() => setDeleteTarget(m)}>
                                  <Trash2 className="mr-2 h-3.5 w-3.5" /> Delete from Registry
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </td>
                        </tr>
                      );
                    })}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          <div data-slot="table-pagination" className="mt-4 flex items-center justify-between">
            <p className="text-xs text-muted-foreground">
              {filtered.length === 0
                ? "No results"
                : `Showing ${(page - 1) * PAGE_SIZE + 1}–${Math.min(page * PAGE_SIZE, filtered.length)} of ${filtered.length.toLocaleString()} business accounts`}
            </p>
            <div className="flex items-center gap-2">
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage(1)} disabled={page === 1}>
                <ChevronLeft className="h-3.5 w-3.5" />
              </Button>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={page === 1}>
                <ChevronLeft className="h-3.5 w-3.5" />
              </Button>
              <span className="text-xs text-muted-foreground px-1">{page} / {totalPages}</span>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage((p) => Math.min(totalPages, p + 1))} disabled={page === totalPages}>
                <ChevronRight className="h-3.5 w-3.5" />
              </Button>
              <Button variant="outline" size="icon" className="h-7 w-7" onClick={() => setPage(totalPages)} disabled={page === totalPages}>
                <ChevronRight className="h-3.5 w-3.5" />
              </Button>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Register Business Account Dialog */}
      <Dialog open={registerOpen} onOpenChange={(v) => { setRegisterOpen(v); if (!v) { setRegForm({ ...EMPTY_FORM }); setShowPassword(false); setActiveTab("basic"); } }}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Register New Business Account</DialogTitle>
            <DialogDescription>
              Register a business in the NUERS registry. A Business Account portal will be automatically created.
            </DialogDescription>
          </DialogHeader>

          <Tabs value={activeTab} onValueChange={setActiveTab}>
            <TabsList className="grid w-full grid-cols-1 sm:grid-cols-3">
              <TabsTrigger value="basic">Business Info</TabsTrigger>
              <TabsTrigger value="contact">Contact & Address</TabsTrigger>
              <TabsTrigger value="account">Portal Account</TabsTrigger>
            </TabsList>

            {/* Business Info */}
            <TabsContent value="basic" className="mt-4 space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2 space-y-1.5">
                  <Label>Business Name <span className="text-destructive">*</span></Label>
                  <Input
                    placeholder="Company Inc."
                    value={regForm.business_name}
                    onChange={(e) => setRegForm({ ...regForm, business_name: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>TIN Number <span className="text-destructive">*</span></Label>
                  <Input
                    placeholder="000-000-000-000"
                    value={regForm.tin}
                    onChange={(e) => setRegForm({ ...regForm, tin: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Owner / Representative</Label>
                  <Input
                    placeholder="Juan Dela Cruz"
                    value={regForm.owner_name}
                    onChange={(e) => setRegForm({ ...regForm, owner_name: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Business Type</Label>
                  <Select value={regForm.business_type} onValueChange={(v) => setRegForm({ ...regForm, business_type: v })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {businessTypes.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>Sector</Label>
                  <SearchableLocationSelect
                    value={regForm.sector}
                    options={Object.entries(sectorLabels).map(([value, label]) => ({
                      value,
                      label,
                      keywords: `${value.replace(/_/g, " ")} ${label}`,
                    }))}
                    placeholder="Select sector..."
                    searchPlaceholder="Search sector or industry..."
                    emptyLabel="No sector found."
                    loadingLabel="Loading sectors..."
                    maxListHeight="22rem"
                    onValueChange={(v) => setRegForm({ ...regForm, sector: v })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Region</Label>
                  <Select value={regForm.region} onValueChange={(v) => setRegForm({ ...regForm, region: v })}>
                    <SelectTrigger><SelectValue placeholder="Select region..." /></SelectTrigger>
                    <SelectContent>
                      {REGIONS.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>BIR Registration Date</Label>
                  <Input
                    type="date"
                    value={regForm.bir_registration_date}
                    onChange={(e) => setRegForm({ ...regForm, bir_registration_date: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>POS System</Label>
                  <Select value={regForm.pos_system} onValueChange={(v) => setRegForm({ ...regForm, pos_system: v })}>
                    <SelectTrigger><SelectValue placeholder="Select POS..." /></SelectTrigger>
                    <SelectContent>
                      {posSystems.map((p) => <SelectItem key={p} value={p}>{p}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>No. of Employees</Label>
                  <Input
                    type="number"
                    min="0"
                    placeholder="0"
                    value={regForm.employee_count || ""}
                    onChange={(e) => setRegForm({ ...regForm, employee_count: Number(e.target.value) })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>No. of Branches</Label>
                  <Input
                    type="number"
                    min="1"
                    placeholder="1"
                    value={regForm.branch_count || ""}
                    onChange={(e) => setRegForm({ ...regForm, branch_count: Number(e.target.value) })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Annual Revenue (₱)</Label>
                  <Input
                    type="number"
                    min="0"
                    placeholder="0"
                    value={regForm.annual_revenue || ""}
                    onChange={(e) => setRegForm({ ...regForm, annual_revenue: Number(e.target.value) })}
                  />
                </div>
                <div className="sm:col-span-2 flex items-center justify-between rounded-lg border p-3">
                  <div>
                    <p className="text-sm font-medium text-foreground">VAT Registered</p>
                    <p className="text-xs text-muted-foreground">Is this business registered for VAT with BIR?</p>
                  </div>
                  <Switch
                    checked={regForm.vat_registered}
                    onCheckedChange={(v) => setRegForm({ ...regForm, vat_registered: v })}
                  />
                </div>
              </div>
              <div className="flex justify-end">
                <Button type="button" onClick={() => setActiveTab("contact")}>Next: Contact &amp; Address</Button>
              </div>
            </TabsContent>

            {/* Contact & Address */}
            <TabsContent value="contact" className="mt-4 space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label>Business Email <span className="text-destructive">*</span></Label>
                  <Input
                    type="email"
                    placeholder="finance@company.com"
                    value={regForm.email}
                    onChange={(e) => setRegForm({ ...regForm, email: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Phone Number</Label>
                  <Input
                    placeholder="+63 2 8xxx xxxx"
                    value={regForm.phone}
                    onChange={(e) => setRegForm({ ...regForm, phone: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Country <span className="text-destructive">*</span></Label>
                  <Input readOnly value="Philippines" className="bg-muted/40 font-medium" />
                  <p className="text-[11px] text-muted-foreground">Country is locked to Philippines for BIR registration.</p>
                </div>
                <div className="space-y-1.5">
                  <Label>City / Municipality <span className="text-destructive">*</span></Label>
                  <SearchableLocationSelect
                    value={regForm.city_code}
                    options={cities.map((city) => ({
                      value: city.code,
                      label: `${city.name}${city.region ? ` · ${city.region}` : ""}`,
                      keywords: [city.name, city.region, city.province, city.rdo_code, city.rdo_name].filter(Boolean).join(" "),
                    }))}
                    placeholder="Select city..."
                    searchPlaceholder="Search city or municipality..."
                    emptyLabel="No city or municipality found."
                    loadingLabel="Loading cities..."
                    loading={locationLoading.cities}
                    disabled={cities.length === 0}
                    onValueChange={handleCityChange}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Barangay <span className="text-destructive">*</span></Label>
                  <SearchableLocationSelect
                    value={regForm.barangay_code}
                    options={barangays.map((barangay) => ({
                      value: barangay.code,
                      label: barangay.name,
                      keywords: [barangay.name, regForm.city].filter(Boolean).join(" "),
                    }))}
                    placeholder={!regForm.city ? "Select city first" : "Select barangay..."}
                    searchPlaceholder="Search barangay..."
                    emptyLabel={!regForm.city ? "Select a city first." : "No barangay found."}
                    loadingLabel="Loading barangays..."
                    loading={locationLoading.barangays}
                    disabled={!regForm.city || barangays.length === 0}
                    onValueChange={handleBarangayChange}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>ZIP Code</Label>
                  <Input
                    placeholder="1234"
                    value={regForm.zip_code}
                    onChange={(e) => setRegForm({ ...regForm, zip_code: e.target.value })}
                  />
                </div>
                <div className="sm:col-span-2 space-y-1.5">
                  <Label>Business Registration Address <span className="text-destructive">*</span></Label>
                  <Textarea
                    placeholder="Unit / floor / building / street where the business is registered"
                    value={regForm.business_registration_address}
                    onChange={(e) => setRegForm({ ...regForm, business_registration_address: e.target.value })}
                    rows={2}
                  />
                  <p className="text-xs text-muted-foreground">
                    Full saved address: {composeRegistrationAddress(regForm) || "Complete the location fields to generate the registered address."}
                  </p>
                </div>
                <div className="sm:col-span-2 grid gap-4 rounded-lg border bg-muted/30 p-3 sm:grid-cols-2">
                  <div className="space-y-1.5">
                    <Label>Auto-detected RDO</Label>
                    <Input
                      readOnly
                      value={regForm.rdo_code ? `${regForm.rdo_code} · ${regForm.rdo_name}` : ""}
                      placeholder="Select a city to detect RDO"
                    />
                  </div>
                  <div className="space-y-1.5">
                    <Label>Detected Region</Label>
                    <Input readOnly value={regForm.region} placeholder="Region will auto-fill" />
                  </div>
                  <p className="sm:col-span-2 text-xs text-muted-foreground">
                    RDO is automatically detected from the selected city/municipality using the NUERS location API.
                  </p>
                </div>
                <div className="sm:col-span-2 space-y-1.5">
                  <Label>Website</Label>
                  <Input
                    placeholder="https://company.com"
                    value={regForm.website}
                    onChange={(e) => setRegForm({ ...regForm, website: e.target.value })}
                  />
                </div>
              </div>
              <div className="flex justify-between">
                <Button type="button" variant="outline" onClick={() => setActiveTab("basic")}>Back</Button>
                <Button type="button" onClick={() => setActiveTab("account")}>Next: Portal Account</Button>
              </div>
            </TabsContent>

            {/* Portal Account */}
            <TabsContent value="account" className="mt-4 space-y-4">
              <div className="rounded-lg border border-primary/20 bg-primary/5 p-4">
                <div className="flex items-center gap-2 mb-1">
                  <UserCheck className="h-4 w-4 text-primary" />
                  <p className="text-sm font-medium text-foreground">Automatic Business Account Portal</p>
                </div>
                <p className="text-xs text-muted-foreground">
                  A Business Account portal will be created immediately. The business account can log in with the credentials below.
                </p>
              </div>
              <div className="space-y-1.5">
                <Label>Portal Account Email</Label>
                <Input
                  type="email"
                  placeholder="Same as business email if left blank"
                  value={regForm.merchant_account_email}
                  onChange={(e) => setRegForm({ ...regForm, merchant_account_email: e.target.value })}
                />
                <p className="text-xs text-muted-foreground">Leave blank to use the business email.</p>
              </div>
              <div className="space-y-1.5">
                <Label>Portal Account Password <span className="text-destructive">*</span></Label>
                <div className="relative">
                  <Input
                    type={showPassword ? "text" : "password"}
                    placeholder="Set initial password"
                    value={regForm.merchant_account_password}
                    onChange={(e) => setRegForm({ ...regForm, merchant_account_password: e.target.value })}
                    className="pr-10"
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="absolute right-1 top-1/2 -translate-y-1/2 h-7 w-7 text-muted-foreground hover:text-foreground"
                    onClick={() => setShowPassword((v) => !v)}
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOff className="h-3.5 w-3.5" /> : <Eye className="h-3.5 w-3.5" />}
                  </Button>
                </div>
                <p className="text-xs text-muted-foreground">Minimum 8 characters. Business account users can change this after first login.</p>
              </div>
              <div className="space-y-1.5">
                <Label>Internal Notes</Label>
                <Textarea
                  placeholder="Any internal notes about this business account registration..."
                  value={regForm.notes}
                  onChange={(e) => setRegForm({ ...regForm, notes: e.target.value })}
                  rows={3}
                />
              </div>
              <div className="flex justify-between">
                <Button type="button" variant="outline" onClick={() => setActiveTab("contact")}>Back</Button>
                <Button onClick={handleRegisterMerchant} disabled={saving} className="gap-2">
                  {saving ? (
                    <><RefreshCw className="h-4 w-4 animate-spin" /> Registering…</>
                  ) : (
                    <><Key className="h-4 w-4" /> Register &amp; Create Account</>
                  )}
                </Button>
              </div>
            </TabsContent>
          </Tabs>

          <DialogFooter className="mt-2 border-t pt-4">
            <Button variant="outline" onClick={() => setRegisterOpen(false)}>Cancel</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Edit Dialog ── */}
      <Dialog open={editOpen} onOpenChange={setEditOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit Business Account — {selected?.business_name}</DialogTitle>
            <DialogDescription>Update business account record details.</DialogDescription>
          </DialogHeader>
          <Tabs defaultValue="info">
            <TabsList className="grid w-full grid-cols-1 sm:grid-cols-3">
              <TabsTrigger value="info">Business Info</TabsTrigger>
              <TabsTrigger value="contact">Contact</TabsTrigger>
              <TabsTrigger value="compliance">Compliance</TabsTrigger>
            </TabsList>
            <TabsContent value="info" className="mt-4 space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="sm:col-span-2 space-y-1.5">
                  <Label>Business Name</Label>
                  <Input value={editForm.business_name ?? ""} onChange={(e) => setEditForm({ ...editForm, business_name: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Owner Name</Label>
                  <Input value={editForm.owner_name ?? ""} onChange={(e) => setEditForm({ ...editForm, owner_name: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Business Type</Label>
                  <Select value={editForm.business_type ?? ""} onValueChange={(v) => setEditForm({ ...editForm, business_type: v })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {businessTypes.map((t) => <SelectItem key={t.value} value={t.value}>{t.label}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>Sector</Label>
                  <SearchableLocationSelect
                    value={editForm.sector ?? ""}
                    options={Object.entries(sectorLabels).map(([value, label]) => ({
                      value,
                      label,
                      keywords: `${value.replace(/_/g, " ")} ${label}`,
                    }))}
                    placeholder="Select sector..."
                    searchPlaceholder="Search sector or industry..."
                    emptyLabel="No sector found."
                    loadingLabel="Loading sectors..."
                    maxListHeight="22rem"
                    onValueChange={(v) => setEditForm({ ...editForm, sector: v })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Region</Label>
                  <Select value={editForm.region ?? ""} onValueChange={(v) => setEditForm({ ...editForm, region: v })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {REGIONS.map((r) => <SelectItem key={r} value={r}>{r}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>POS System</Label>
                  <Select value={editForm.pos_system ?? ""} onValueChange={(v) => setEditForm({ ...editForm, pos_system: v })}>
                    <SelectTrigger><SelectValue /></SelectTrigger>
                    <SelectContent>
                      {posSystems.map((p) => <SelectItem key={p} value={p}>{p}</SelectItem>)}
                    </SelectContent>
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <Label>Employees</Label>
                  <Input type="number" min="0" value={editForm.employee_count ?? 0} onChange={(e) => setEditForm({ ...editForm, employee_count: Number(e.target.value) })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Branches</Label>
                  <Input type="number" min="1" value={editForm.branch_count ?? 1} onChange={(e) => setEditForm({ ...editForm, branch_count: Number(e.target.value) })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Annual Revenue (₱)</Label>
                  <Input type="number" min="0" value={editForm.annual_revenue ?? 0} onChange={(e) => setEditForm({ ...editForm, annual_revenue: Number(e.target.value) })} />
                </div>
                <div className="sm:col-span-2 flex items-center justify-between rounded-lg border p-3">
                  <p className="text-sm font-medium text-foreground">VAT Registered</p>
                  <Switch
                    checked={editForm.vat_registered ?? false}
                    onCheckedChange={(v) => setEditForm({ ...editForm, vat_registered: v })}
                  />
                </div>
              </div>
            </TabsContent>
            <TabsContent value="contact" className="mt-4 space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label>Email</Label>
                  <Input type="email" value={editForm.email ?? ""} onChange={(e) => setEditForm({ ...editForm, email: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label>Phone</Label>
                  <Input value={editForm.phone ?? ""} onChange={(e) => setEditForm({ ...editForm, phone: e.target.value })} />
                </div>
                <div className="sm:col-span-2 space-y-1.5">
                  <Label>Address</Label>
                  <Input value={editForm.address ?? ""} onChange={(e) => setEditForm({ ...editForm, address: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label>City</Label>
                  <Input value={editForm.city ?? ""} onChange={(e) => setEditForm({ ...editForm, city: e.target.value })} />
                </div>
                <div className="space-y-1.5">
                  <Label>ZIP Code</Label>
                  <Input value={editForm.zip_code ?? ""} onChange={(e) => setEditForm({ ...editForm, zip_code: e.target.value })} />
                </div>
                <div className="sm:col-span-2 space-y-1.5">
                  <Label>Website</Label>
                  <Input value={editForm.website ?? ""} onChange={(e) => setEditForm({ ...editForm, website: e.target.value })} />
                </div>
              </div>
            </TabsContent>
            <TabsContent value="compliance" className="mt-4 space-y-4">
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label>Compliance Score (0–100)</Label>
                  <Input
                    type="number"
                    min="0"
                    max="100"
                    value={editForm.compliance_score ?? 0}
                    onChange={(e) => setEditForm({ ...editForm, compliance_score: Number(e.target.value) })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>BIR Registration Date</Label>
                  <Input
                    type="date"
                    value={editForm.bir_registration_date ?? ""}
                    onChange={(e) => setEditForm({ ...editForm, bir_registration_date: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Last Audit Date</Label>
                  <Input
                    type="date"
                    value={editForm.last_audit_date ?? ""}
                    onChange={(e) => setEditForm({ ...editForm, last_audit_date: e.target.value })}
                  />
                </div>
                <div className="space-y-1.5">
                  <Label>Next Audit Date</Label>
                  <Input
                    type="date"
                    value={editForm.next_audit_date ?? ""}
                    onChange={(e) => setEditForm({ ...editForm, next_audit_date: e.target.value })}
                  />
                </div>
              </div>
              <div className="space-y-1.5">
                <Label>Internal Notes</Label>
                <Textarea
                  value={editForm.notes ?? ""}
                  onChange={(e) => setEditForm({ ...editForm, notes: e.target.value })}
                  rows={3}
                />
              </div>
            </TabsContent>
          </Tabs>
          <DialogFooter className="mt-4 border-t pt-4">
            <Button variant="outline" onClick={() => setEditOpen(false)}>Cancel</Button>
            <Button onClick={handleSaveEdit} disabled={saving}>
              {saving ? "Saving…" : "Save Changes"}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Detail Dialog ── */}
      <Dialog open={!!selected && !editOpen} onOpenChange={() => setSelected(null)}>
        <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <div className="flex items-start justify-between gap-4">
              <div className="flex items-center gap-3">
                <Avatar className="h-12 w-12">
                  {selected?.logo_url && <AvatarImage src={selected.logo_url} alt={`${selected.business_name} logo`} className="object-cover" />}
                  <AvatarFallback className="text-sm font-bold bg-secondary">
                    {selected?.business_name?.slice(0, 2).toUpperCase()}
                  </AvatarFallback>
                </Avatar>
                <div>
                  <DialogTitle className="text-xl">{selected?.business_name}</DialogTitle>
                  <DialogDescription className="font-mono mt-0.5">TIN: {formatTin(selected?.tin)}</DialogDescription>
                </div>
              </div>
              {selected && (
                <Badge variant={statusConfig[selected.status]?.variant ?? "default"} className="gap-1 mt-1 shrink-0">
                  {(() => { const sc = statusConfig[selected.status]; return sc ? <sc.icon className="h-3 w-3" /> : null; })()}
                  {statusConfig[selected.status]?.label ?? selected.status}
                </Badge>
              )}
            </div>
          </DialogHeader>

          {selected && (
            <Tabs defaultValue="overview">
              <TabsList className="grid w-full grid-cols-1 sm:grid-cols-4">
                <TabsTrigger value="overview">Overview</TabsTrigger>
                <TabsTrigger value="compliance">Compliance</TabsTrigger>
                <TabsTrigger value="integration">Integration</TabsTrigger>
                <TabsTrigger value="actions">Actions</TabsTrigger>
              </TabsList>

              <TabsContent value="overview" className="mt-4 space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {[
                    { label: "Owner", value: selected.owner_name || "—", icon: Users },
                    { label: "Email", value: selected.email, icon: Mail },
                    { label: "Phone", value: selected.phone || "—", icon: Phone },
                    { label: "Website", value: selected.website || "—", icon: Globe },
                    { label: "Sector", value: sectorLabels[selected.sector] ?? selected.sector, icon: Tag },
                    { label: "Region", value: selected.region, icon: MapPin },
                    { label: "City", value: selected.city || "—", icon: MapPin },
                    { label: "Barangay", value: selected.barangay || "—", icon: MapPin },
                    { label: "Country", value: selected.country || "Philippines", icon: Globe },
                    { label: "Detected RDO", value: selected.rdo_code ? `${selected.rdo_code} · ${selected.rdo_name}` : "—", icon: Building2 },
                    { label: "Business Type", value: businessTypes.find((t) => t.value === selected.business_type)?.label ?? selected.business_type, icon: Building2 },
                    { label: "Monthly Revenue", value: formatRevenue(selected.monthly_revenue), icon: CreditCard },
                    { label: "Annual Revenue", value: formatRevenue(selected.annual_revenue), icon: TrendingUp },
                    { label: "Employees", value: selected.employee_count ? `${selected.employee_count}` : "—", icon: Users },
                    { label: "Branches", value: `${selected.branch_count || 1}`, icon: Store },
                    { label: "Registered", value: selected.registration_date || "—", icon: Calendar },
                    { label: "VAT Status", value: selected.vat_registered ? "VAT Registered" : "Non-VAT", icon: FileText },
                  ].map((f) => (
                    <div key={f.label} className="flex items-start gap-2.5 rounded-lg border p-3">
                      <f.icon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <div>
                        <p className="text-[10px] text-muted-foreground">{f.label}</p>
                        <p className="mt-0.5 text-sm font-medium text-foreground break-all">{f.value}</p>
                      </div>
                    </div>
                  ))}
                </div>
                {selected.address && (
                  <div className="flex items-start gap-2.5 rounded-lg border p-3">
                    <MapPin className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                    <div>
                      <p className="text-[10px] text-muted-foreground">Address</p>
                      <p className="mt-0.5 text-sm text-foreground">{selected.address}</p>
                    </div>
                  </div>
                )}
                {selected.notes && (
                  <div className="rounded-lg border border-dashed p-3">
                    <p className="text-[10px] font-medium text-muted-foreground mb-1">Internal Notes</p>
                    <p className="text-xs text-foreground">{selected.notes}</p>
                  </div>
                )}
              </TabsContent>

              <TabsContent value="compliance" className="mt-4 space-y-4">
                <Card>
                  <CardHeader className="pb-2">
                    <CardTitle className="text-sm">Compliance Overview</CardTitle>
                  </CardHeader>
                  <CardContent>
                    <div className="flex items-center justify-between mb-3">
                      <span className="text-sm text-muted-foreground">Overall Score</span>
                      <span className={`text-3xl font-bold ${complianceBand(selected.compliance_score ?? 0).color}`}>
                        {selected.compliance_score ?? 0}
                        <span className="text-base font-normal text-muted-foreground">/100</span>
                      </span>
                    </div>
                    <Progress value={selected.compliance_score ?? 0} className="h-3 mb-1" />
                    <p className={`text-xs font-medium ${complianceBand(selected.compliance_score ?? 0).color}`}>
                      {complianceBand(selected.compliance_score ?? 0).label} Standing
                    </p>
                  </CardContent>
                </Card>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {[
                    { label: "VAT Registration", value: selected.vat_registered ? "Registered" : "Not Registered", ok: selected.vat_registered },
                    { label: "BIR Registration", value: selected.bir_registration_date || "On record", ok: true },
                    { label: "Last Audit", value: selected.last_audit_date || "Not audited", ok: !!selected.last_audit_date },
                    { label: "Next Audit", value: selected.next_audit_date || "Not scheduled", ok: !!selected.next_audit_date },
                  ].map((item) => (
                    <div key={item.label} className="flex items-center justify-between rounded-lg border p-3">
                      <span className="text-xs text-muted-foreground">{item.label}</span>
                      <div className="flex items-center gap-1.5">
                        {item.ok ? (
                          <CheckCircle2 className="h-3.5 w-3.5 text-success" />
                        ) : (
                          <AlertTriangle className="h-3.5 w-3.5 text-warning" />
                        )}
                        <span className="text-xs font-medium text-foreground">{item.value}</span>
                      </div>
                    </div>
                  ))}
                </div>
              </TabsContent>

              <TabsContent value="integration" className="mt-4 space-y-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  {[
                    { label: "POS System", value: selected.pos_system || "Not configured", icon: Store },
                    { label: "Portal Account Email", value: selected.merchant_account_email || selected.email, icon: Mail },
                  ].map((f) => (
                    <div key={f.label} className="flex items-start gap-2.5 rounded-lg border p-3">
                      <f.icon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <div>
                        <p className="text-[10px] text-muted-foreground">{f.label}</p>
                        <p className="mt-0.5 text-sm font-medium text-foreground break-all">{f.value}</p>
                      </div>
                    </div>
                  ))}
                </div>
                <div className="rounded-lg border border-dashed p-4 text-center">
                  <Key className="h-8 w-8 text-muted-foreground mx-auto mb-2" />
                  <p className="text-xs text-muted-foreground">API keys and webhooks are managed by the business account through its portal account.</p>
                  <Button variant="outline" size="sm" className="mt-3 gap-2" onClick={() => handleMerchantAction("resend", selected)}>
                    <Send className="h-3.5 w-3.5" /> Re-send Portal Invitation
                  </Button>
                </div>
              </TabsContent>

              <TabsContent value="actions" className="mt-4 space-y-2">
                {selected.status === "under_review" && (
                  <Button variant="default" className="w-full justify-start gap-2" onClick={() => handleMerchantAction("activate", selected)}>
                    <CheckCircle2 className="h-4 w-4" /> Approve &amp; Activate Account
                  </Button>
                )}
                <Button variant="outline" className="w-full justify-start gap-2" onClick={() => handleMerchantAction("notice", selected)}>
                  <FileText className="h-4 w-4" /> Send Compliance Notice
                </Button>
                <Button variant="outline" className="w-full justify-start gap-2" onClick={() => handleMerchantAction("audit", selected)}>
                  <History className="h-4 w-4" /> Schedule Audit (30 days)
                </Button>
                <Button variant="outline" className="w-full justify-start gap-2" onClick={() => handleMerchantAction("resend", selected)}>
                  <Send className="h-4 w-4" /> Resend Portal Invitation
                </Button>
                <Button variant="outline" className="w-full justify-start gap-2 text-warning" onClick={() => handleMerchantAction("flag", selected)}>
                  <ShieldAlert className="h-4 w-4" /> Flag for Investigation
                </Button>
                <Separator />
                <Button
                  variant="outline"
                  className={`w-full justify-start gap-2 ${selected.status === "suspended" ? "text-success" : "text-destructive"}`}
                  onClick={() => handleMerchantAction("suspend", selected)}
                >
                  <Ban className="h-4 w-4" />
                  {selected.status === "suspended" ? "Reactivate Account" : "Suspend Account"}
                </Button>
                <Button
                  variant="destructive"
                  className="w-full justify-start gap-2"
                  onClick={() => { setDeleteTarget(selected); setSelected(null); }}
                >
                  <Trash2 className="h-4 w-4" /> Delete from Registry
                </Button>
              </TabsContent>
            </Tabs>
          )}

          <DialogFooter className="mt-4 border-t pt-4">
            <Button variant="outline" onClick={() => setSelected(null)}>Close</Button>
            <Button onClick={() => { openEdit(selected!); }}>
              <Edit className="mr-2 h-4 w-4" /> Edit Record
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ── Delete Single Confirmation ── */}
      <AlertDialog open={!!deleteTarget} onOpenChange={() => setDeleteTarget(null)}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Business Account from Registry</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to permanently delete <strong>{deleteTarget?.business_name}</strong> (TIN: {formatTin(deleteTarget?.tin)}) from the NUERS Business Account Registry? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={() => deleteTarget && handleDelete(deleteTarget)}
            >
              Delete Permanently
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* ── Bulk Delete Confirmation ── */}
      <AlertDialog open={bulkDeleteOpen} onOpenChange={setBulkDeleteOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete {selectedIds.size} Business Accounts</AlertDialogTitle>
            <AlertDialogDescription>
              This will permanently delete <strong>{selectedIds.size} business accounts</strong> from the NUERS registry. This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction
              className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              onClick={handleBulkDelete}
            >
              Delete {selectedIds.size} Business Accounts
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </div>
  );
}
