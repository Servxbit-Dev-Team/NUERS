import { useSearchParams } from "react-router-dom";
import { VerificationDocumentsPanel } from "@/components/registration/verification-documents";
import { useEffect, useMemo, useRef, useState } from "react";
import {
  Building2, Mail, Phone, Globe, MapPin, FileText,
  Edit, Save, CheckCircle2, Shield, Upload, Download,
  Clock, AlertCircle, Star, Award, TrendingUp,
  XCircle, ExternalLink, Loader2, Database,
  Check, ChevronsUpDown,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Separator } from "@/components/ui/separator";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { Avatar, AvatarFallback, AvatarImage } from "@/components/ui/avatar";
import { Progress } from "@/components/ui/progress";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { Textarea } from "@/components/ui/textarea";
import { Command, CommandGroup, CommandInput, CommandItem, CommandList } from "@/components/ui/command";
import { Popover, PopoverContent, PopoverTrigger } from "@/components/ui/popover";
import { toast } from "sonner";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { cn } from "@/lib/utils";

type DocStatus = "verified" | "pending" | "expired" | "missing";

type Document = {
  id: string;
  name: string;
  type: string;
  status: DocStatus;
  uploaded?: string;
  expires?: string;
  size?: string;
};

type AuditEntry = {
  id: string;
  action: string;
  user: string;
  timestamp: string;
  field?: string;
  before?: string;
  after?: string;
};

type BusinessProfileForm = {
  business_name: string;
  owner_name: string;
  tin: string;
  sector: string;
  business_type: string;
  bir_registration_date: string;
  vat_registered: boolean;
  email: string;
  phone: string;
  address: string;
  city: string;
  city_code: string;
  zip_code: string;
  region: string;
  country: string;
  country_code: string;
  barangay: string;
  barangay_code: string;
  rdo_code: string;
  rdo_name: string;
  website: string;
  logo_url: string;
  employee_count: number;
  annual_revenue: number;
  pos_system: string;
  branch_count: number;
  compliance_score: number;
  status: string;
  created_at: string;
  updated_at: string;
  notes: string;
};

type BusinessProfilePayload = Partial<BusinessProfileForm> & {
  street_address?: string | null;
  documents?: Document[];
  audit_events?: AuditEntry[];
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

type SearchableLocationOption = {
  value: string;
  label: string;
  keywords?: string;
};

const EMPTY_FORM: BusinessProfileForm = {
  business_name: "",
  owner_name: "",
  tin: "",
  sector: "",
  business_type: "",
  bir_registration_date: "",
  vat_registered: false,
  email: "",
  phone: "",
  address: "",
  city: "",
  city_code: "",
  zip_code: "",
  region: "",
  country: "Philippines",
  country_code: "PH",
  barangay: "",
  barangay_code: "",
  rdo_code: "",
  rdo_name: "",
  website: "",
  logo_url: "",
  employee_count: 0,
  annual_revenue: 0,
  pos_system: "",
  branch_count: 0,
  compliance_score: 0,
  status: "",
  created_at: "",
  updated_at: "",
  notes: "",
};

const API_TOKEN_KEY = "nuers_api_token";

function authHeaders() {
  const headers = new Headers({ Accept: "application/json" });

  try {
    const token = window.localStorage.getItem(API_TOKEN_KEY);
    if (token) headers.set("Authorization", `Bearer ${token}`);
  } catch {
    // Browser storage can be unavailable in restricted contexts.
  }

  return headers;
}

function valueOrDash(value?: string | number | null) {
  if (value === null || value === undefined || value === "") return "Not provided";
  return String(value);
}

function normalizeTin(value?: string | null) {
  return String(value ?? "").replace(/\D/g, "").slice(0, 12);
}

function formatTin(value?: string | null) {
  const digits = normalizeTin(value);
  const groups = [
    digits.slice(0, 3),
    digits.slice(3, 6),
    digits.slice(6, 9),
    digits.slice(9, 12),
  ].filter(Boolean);

  return groups.join("-");
}

function tinOrDash(value?: string | null) {
  return valueOrDash(formatTin(value));
}

function dateOrDash(value?: string | null) {
  if (!value) return "Not provided";
  return new Date(value).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" });
}

function monthYearOrDash(value?: string | null) {
  if (!value) return "Not yet recorded";
  return new Date(value).toLocaleDateString("en-PH", { month: "short", year: "numeric" });
}

function statusLabel(value?: string | null) {
  if (!value) return "Pending";
  return value.replace(/_/g, " ").replace(/\b\w/g, (char) => char.toUpperCase());
}

const docStatusConfig: Record<DocStatus, { label: string; color: string; icon: React.ElementType }> = {
  verified: { label: "Verified", color: "text-success", icon: CheckCircle2 },
  pending: { label: "Under Review", color: "text-warning", icon: Clock },
  expired: { label: "Expired", color: "text-destructive", icon: XCircle },
  missing: { label: "Missing", color: "text-destructive", icon: AlertCircle },
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

const sectorOptions = Object.entries(sectorLabels).map(([value, label]) => ({
  value,
  label,
  keywords: `${value.replace(/_/g, " ")} ${label}`,
}));

function normalizeSectorSearch(value: string) {
  return value
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function normalizeSectorValue(value?: string | null) {
  const normalized = normalizeSectorSearch(value ?? "").replace(/\s+/g, "_");

  if (normalized in sectorLabels) {
    return normalized;
  }

  const matched = Object.entries(sectorLabels).find(([, label]) => normalizeSectorSearch(label).replace(/\s+/g, "_") === normalized);

  return matched?.[0] ?? (value ?? "");
}

function sectorDisplay(value?: string | null) {
  const normalized = normalizeSectorValue(value);

  if (!normalized) return "Not provided";

  return sectorLabels[normalized] ?? String(value).replace(/_/g, " ");
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

function normalizeSelectSearch(value: string) {
  return value
    .toLowerCase()
    .normalize("NFD")
    .replace(/[\u0300-\u036f]/g, "")
    .replace(/[^a-z0-9]+/g, " ")
    .trim();
}

function composeBusinessAddress(form: BusinessProfileForm) {
  return [
    form.address,
    form.barangay ? `Barangay ${form.barangay}` : "",
    form.city,
    form.country || "Philippines",
  ].filter(Boolean).join(", ");
}

function SearchableSectorSelect({
  value,
  onValueChange,
}: {
  value: string;
  onValueChange: (value: string) => void;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const selectedValue = normalizeSectorValue(value);
  const selected = sectorOptions.find((option) => option.value === selectedValue);
  const filteredOptions = useMemo(() => {
    const needle = normalizeSectorSearch(query);

    if (!needle) return sectorOptions;

    const words = needle.split(/\s+/).filter(Boolean);

    return sectorOptions
      .map((option) => {
        const label = normalizeSectorSearch(option.label);
        const keywords = normalizeSectorSearch(option.keywords);
        const haystack = `${label} ${keywords}`;
        let rank = Number.POSITIVE_INFINITY;

        if (label.startsWith(needle)) rank = 0;
        else if (label.includes(needle)) rank = 1;
        else if (haystack.includes(needle)) rank = 2;
        else if (words.every((word) => haystack.includes(word))) rank = 3;

        return Number.isFinite(rank) ? { option, rank } : null;
      })
      .filter((item): item is { option: typeof sectorOptions[number]; rank: number } => item !== null)
      .sort((a, b) => a.rank - b.rank || a.option.label.localeCompare(b.option.label))
      .map((item) => item.option);
  }, [query]);

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
          className={cn("h-10 w-full justify-between bg-background px-3 font-normal", !selected && "text-muted-foreground")}
        >
          <span className="truncate">{selected?.label ?? "Select sector..."}</span>
          <ChevronsUpDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-[var(--radix-popover-trigger-width)] overflow-hidden p-0">
        <Command shouldFilter={false}>
          <CommandInput value={query} onValueChange={setQuery} placeholder="Search sector or industry..." />
          <CommandList
            className="overscroll-contain overflow-y-auto pr-1 [scrollbar-gutter:stable]"
            style={{ maxHeight: "min(24rem, calc(var(--radix-popover-content-available-height, 32rem) - 3.5rem))" }}
            onWheelCapture={(event) => {
              const list = event.currentTarget;

              if (list.scrollHeight <= list.clientHeight) return;

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
              <div className="py-6 text-center text-sm text-muted-foreground">No sector found.</div>
            ) : (
              <CommandGroup>
                {filteredOptions.map((option) => (
                  <CommandItem
                    key={option.value}
                    value={`${option.label} ${option.keywords}`}
                    onSelect={() => {
                      onValueChange(option.value);
                      setOpen(false);
                    }}
                  >
                    <Check className={cn("h-4 w-4", selectedValue === option.value ? "opacity-100" : "opacity-0")} />
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

    if (!needle) return options;

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
          className={cn("h-10 w-full justify-between bg-background px-3 font-normal", !selected && "text-muted-foreground")}
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

              if (list.scrollHeight <= list.clientHeight) return;

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

export function MerchantProfile() {
  const [profileParams] = useSearchParams();
  const [editing, setEditing] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [viewDoc, setViewDoc] = useState<Document | null>(null);
  const [documents, setDocuments] = useState<Document[]>([]);
  const [auditTrail, setAuditTrail] = useState<AuditEntry[]>([]);
  const [form, setForm] = useState<BusinessProfileForm>(EMPTY_FORM);
  const [logoFile, setLogoFile] = useState<File | null>(null);
  const [logoPreview, setLogoPreview] = useState("");
  const [cities, setCities] = useState<CityOption[]>([]);
  const [barangays, setBarangays] = useState<BarangayOption[]>([]);
  const [locationLoading, setLocationLoading] = useState({ cities: false, barangays: false, rdo: false });
  const logoInputRef = useRef<HTMLInputElement | null>(null);

  const logoSrc = logoPreview || form.logo_url;
  const profileInitials = (form.business_name || form.email || "BA")
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0])
    .join("")
    .toUpperCase() || "BA";

  function applyProfile(data: BusinessProfilePayload) {
    if (logoPreview) URL.revokeObjectURL(logoPreview);
    setLogoFile(null);
    setLogoPreview("");

    setForm({
      ...EMPTY_FORM,
      ...data,
      business_name: data.business_name ?? "",
      owner_name: data.owner_name ?? "",
      tin: formatTin(data.tin ?? ""),
      sector: data.sector ?? "",
      business_type: data.business_type ?? "",
      bir_registration_date: data.bir_registration_date ?? "",
      vat_registered: Boolean(data.vat_registered),
      email: data.email ?? "",
      phone: data.phone ?? "",
      address: data.street_address ?? data.address ?? "",
      city: data.city ?? "",
      city_code: data.city_code ?? "",
      zip_code: data.zip_code ?? "",
      region: data.region ?? "",
      country: data.country ?? "Philippines",
      country_code: data.country_code ?? "PH",
      barangay: data.barangay ?? "",
      barangay_code: data.barangay_code ?? "",
      rdo_code: data.rdo_code ?? "",
      rdo_name: data.rdo_name ?? "",
      website: data.website ?? "",
      logo_url: data.logo_url ?? "",
      employee_count: Number(data.employee_count ?? 0),
      annual_revenue: Number(data.annual_revenue ?? 0),
      pos_system: data.pos_system ?? "",
      branch_count: Number(data.branch_count ?? 0),
      compliance_score: Number(data.compliance_score ?? 0),
      status: data.status ?? "",
      created_at: data.created_at ?? "",
      updated_at: data.updated_at ?? "",
      notes: data.notes ?? "",
    });
    setDocuments(data.documents ?? []);
    setAuditTrail(data.audit_events ?? []);

    window.dispatchEvent(new CustomEvent("nuers:business-profile-updated", {
      detail: data,
    }));
  }

  async function loadProfile() {
    setLoading(true);
    setError("");

    try {
      const response = await apiFetch("/api/business-accounts/current", {
        headers: authHeaders(),
        cache: "no-store",
      });
      const payload = await readJsonResponse<{ data?: BusinessProfilePayload }>(response, "Unable to load the logged-in Business Account profile.");

      applyProfile(payload.data ?? {});
    } catch (err) {
      setError(err instanceof Error ? err.message : "Unable to load the logged-in Business Account profile.");
      setForm(EMPTY_FORM);
      setDocuments([]);
      setAuditTrail([]);
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    loadProfile();
  }, []);

  useEffect(() => {
    return () => {
      if (logoPreview) URL.revokeObjectURL(logoPreview);
    };
  }, [logoPreview]);

  useEffect(() => {
    fetchCities(form.country_code || "PH");
  }, [form.country_code]);

  useEffect(() => {
    if (!form.city_code && !form.city) {
      setBarangays([]);
      return;
    }

    fetchBarangays(form.city_code, form.city);
  }, [form.city_code, form.city]);

  useEffect(() => {
    if (form.city_code || !form.city || cities.length === 0) return;

    const normalizedCity = normalizeSelectSearch(form.city);
    const match = cities.find((city) => normalizeSelectSearch(city.name) === normalizedCity);

    if (!match) return;

    setForm((current) => (
      current.city_code || normalizeSelectSearch(current.city) !== normalizedCity
        ? current
        : {
            ...current,
            city_code: match.code,
            region: current.region || match.region,
            rdo_code: current.rdo_code || match.rdo_code,
            rdo_name: current.rdo_name || match.rdo_name,
          }
    ));
  }, [cities, form.city, form.city_code]);

  async function fetchCities(countryCode: string) {
    setLocationLoading((current) => ({ ...current, cities: true }));

    try {
      const data = await fetchLocationList<CityOption>(`/api/locations/cities?country=${encodeURIComponent(countryCode || "PH")}`);
      setCities(data);
    } catch {
      setCities([]);
      toast.error("Unable to load city list from the NUERS location API.");
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
      toast.error("Unable to load barangays from the NUERS location API.");
    } finally {
      setLocationLoading((current) => ({ ...current, barangays: false }));
    }
  }

  async function handleCityChange(cityCode: string) {
    const city = cities.find((item) => item.code === cityCode);

    setForm((current) => ({
      ...current,
      country: "Philippines",
      country_code: "PH",
      city_code: cityCode,
      city: city?.name ?? "",
      barangay: "",
      barangay_code: "",
      region: city?.region ?? current.region,
      rdo_code: city?.rdo_code ?? "",
      rdo_name: city?.rdo_name ?? "",
    }));

    if (!city) return;

    setLocationLoading((current) => ({ ...current, rdo: true }));

    try {
      const params = new URLSearchParams();
      params.set("city", city.name);
      if (city.region) params.set("region", city.region);
      if (city.province) params.set("province", city.province);

      const rdo = await fetchLocationRecord<{ code: string; name: string }>(`/api/locations/rdo?${params.toString()}`);

      setForm((current) => (
        current.city_code === cityCode
          ? { ...current, rdo_code: rdo.code, rdo_name: rdo.name }
          : current
      ));
    } catch {
      toast.error("Unable to auto-detect RDO for the selected city.");
    } finally {
      setLocationLoading((current) => ({ ...current, rdo: false }));
    }
  }

  async function handleBarangayChange(barangayCode: string) {
    const barangay = barangays.find((item) => item.code === barangayCode);

    setForm((current) => ({
      ...current,
      barangay_code: barangayCode,
      barangay: barangay?.name ?? "",
    }));

    if (!barangay || !form.city) return;

    const city = cities.find((item) => item.code === form.city_code);
    setLocationLoading((current) => ({ ...current, rdo: true }));

    try {
      const params = new URLSearchParams();
      params.set("city", city?.name ?? form.city);
      params.set("barangay", barangay.name);
      if (city?.region || form.region) params.set("region", city?.region ?? form.region);
      if (city?.province) params.set("province", city.province);

      const rdo = await fetchLocationRecord<{ code: string; name: string }>(`/api/locations/rdo?${params.toString()}`);

      setForm((current) => (
        current.barangay_code === barangayCode
          ? { ...current, rdo_code: rdo.code, rdo_name: rdo.name }
          : current
      ));
    } catch {
      toast.error("Unable to auto-detect RDO for the selected barangay.");
    } finally {
      setLocationLoading((current) => ({ ...current, rdo: false }));
    }
  }

  function handleLogoSelection(file?: File | null) {
    if (!file) return;

    if (!file.type.startsWith("image/")) {
      toast.error("Please select a valid image file for the company logo.");
      return;
    }

    if (file.size > 2 * 1024 * 1024) {
      toast.error("Company logo must be 2 MB or smaller.");
      return;
    }

    if (logoPreview) URL.revokeObjectURL(logoPreview);
    setLogoFile(file);
    setLogoPreview(URL.createObjectURL(file));
  }

  async function uploadCompanyLogo(): Promise<BusinessProfilePayload | null> {
    if (!logoFile) return null;

    const headers = authHeaders();
    const formData = new FormData();
    formData.append("logo", logoFile);

    const response = await apiFetch("/api/business-accounts/current/logo", {
      method: "POST",
      headers,
      body: formData,
    });
    const payload = await readJsonResponse<{ data?: BusinessProfilePayload | null }>(response, "Unable to upload the Business Account logo.");

    return payload.data ?? null;
  }

  function handleCancelEdit() {
    if (logoPreview) URL.revokeObjectURL(logoPreview);
    setLogoFile(null);
    setLogoPreview("");
    setEditing(false);
  }

  async function handleSave() {
    setSaving(true);

    try {
      const headers = authHeaders();
      headers.set("Content-Type", "application/json");

      const response = await apiFetch("/api/business-accounts/current", {
        method: "PUT",
        headers,
        body: JSON.stringify({
          business_name: form.business_name,
          owner_name: form.owner_name,
          tin: formatTin(form.tin),
          sector: form.sector,
          business_type: form.business_type,
          bir_registration_date: form.bir_registration_date || null,
          vat_registered: form.vat_registered,
          email: form.email,
          phone: form.phone,
          address: form.address,
          country: "Philippines",
          country_code: "PH",
          city: form.city,
          city_code: form.city_code,
          barangay: form.barangay,
          barangay_code: form.barangay_code,
          zip_code: form.zip_code,
          region: form.region,
          rdo_code: form.rdo_code,
          rdo_name: form.rdo_name,
          website: form.website,
          employee_count: form.employee_count,
          annual_revenue: form.annual_revenue,
          pos_system: form.pos_system,
          branch_count: form.branch_count,
          notes: form.notes,
        }),
      });
      const payload = await readJsonResponse<{ data?: BusinessProfilePayload }>(response, "Unable to save the Business Account profile.");

      const logoPayload = await uploadCompanyLogo();
      applyProfile(logoPayload ?? payload.data ?? {});
      toast.success(logoPayload ? "Business profile and company logo saved." : "Business profile saved to the database.");
      setEditing(false);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Unable to save the Business Account profile.");
    } finally {
      setSaving(false);
    }
  }


  const complianceItems = [
    { label: "Business Name", ok: Boolean(form.business_name) },
    { label: "BIR Registration", ok: Boolean(form.tin && form.bir_registration_date) },
    { label: "VAT Registration", ok: form.vat_registered },
    { label: "Business Address", ok: Boolean(form.address || form.city || form.region) },
    { label: "RDO Assignment", ok: Boolean(form.rdo_code) },
    { label: "Contact Email", ok: Boolean(form.email) },
    { label: "POS Registration", ok: Boolean(form.pos_system) },
    { label: "NUERS Integration", ok: form.status === "active" },
  ];

  const derivedComplianceScore = Math.round((complianceItems.filter((c) => c.ok).length / complianceItems.length) * 100);
  const complianceScore = form.compliance_score > 0 ? form.compliance_score : derivedComplianceScore;
  const verifiedDocs = documents.filter((d) => d.status === "verified").length;
  const missingDocs = documents.filter((d) => d.status === "missing" || d.status === "expired").length;

  if (loading) {
    return (
      <div className="flex min-h-[420px] items-center justify-center">
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Loader2 className="h-4 w-4 animate-spin" />
          Loading logged-in Business Account profile...
        </div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="mx-auto flex max-w-lg flex-col items-center justify-center gap-3 py-16 text-center">
        <Database className="h-10 w-10 text-muted-foreground" />
        <h1 className="text-lg font-semibold text-foreground">Unable to load Business Account profile</h1>
        <p className="text-sm text-muted-foreground">{error}</p>
        <Button size="sm" onClick={loadProfile}>Retry</Button>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold text-foreground">Business Profile</h1>
          <p className="text-sm text-muted-foreground">Business account registration, documents, and compliance audit</p>
        </div>
        {!editing ? (
          <Button size="sm" className="gap-2" onClick={() => setEditing(true)}>
            <Edit className="h-4 w-4" /> Edit Profile
          </Button>
        ) : (
          <div className="flex gap-2">
            <Button variant="outline" size="sm" onClick={handleCancelEdit}>Cancel</Button>
            <Button size="sm" className="gap-2" onClick={handleSave} disabled={saving}>
              {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
              {saving ? "Saving..." : "Save Changes"}
            </Button>
          </div>
        )}
      </div>

      {/* Summary KPIs */}
      <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
        {[
          { label: "Compliance Score", value: `${complianceScore}%`, icon: Shield, color: complianceScore >= 80 ? "text-success" : "text-warning", sub: "out of 100" },
          { label: "Verified Records", value: `${verifiedDocs}/${documents.length}`, icon: CheckCircle2, color: "text-success", sub: "profile checks" },
          { label: "Business Standing", value: statusLabel(form.status), icon: Star, color: form.status === "active" ? "text-primary" : "text-warning", sub: form.rdo_code ? `${form.rdo_code} ${form.rdo_name}` : "RDO pending" },
          { label: "On NUERS Since", value: monthYearOrDash(form.created_at), icon: Award, color: "text-foreground", sub: form.created_at ? "registration history" : "not yet recorded" },
        ].map((kpi) => (
          <Card key={kpi.label}>
            <CardContent className="p-4">
              <div className="flex items-center justify-between mb-2">
                <p className="text-xs text-muted-foreground">{kpi.label}</p>
                <kpi.icon className={`h-4 w-4 ${kpi.color}`} />
              </div>
              <p className={`text-2xl font-bold ${kpi.color}`}>{kpi.value}</p>
              <p className="text-xs text-muted-foreground">{kpi.sub}</p>
            </CardContent>
          </Card>
        ))}
      </div>

      <Tabs defaultValue={profileParams.get("tab")==="documents"?"documents":"details"}>
        <TabsList className="grid h-auto w-full grid-cols-2 gap-1 p-1 md:grid-cols-4 xl:w-fit">
          <TabsTrigger value="details" className="min-h-9 px-3 py-2 text-xs sm:text-sm">
            <span className="truncate">Business Details</span>
          </TabsTrigger>
          <TabsTrigger value="documents" className="min-h-9 gap-2 px-3 py-2 text-xs sm:text-sm">
            <span className="truncate">Documents</span>
            {missingDocs > 0 && (
              <Badge variant="destructive" className="h-4 min-w-4 shrink-0 px-1 text-[10px]">{missingDocs}</Badge>
            )}
          </TabsTrigger>
          <TabsTrigger value="compliance" className="min-h-9 px-3 py-2 text-xs sm:text-sm">
            <span className="truncate">Compliance</span>
          </TabsTrigger>
          <TabsTrigger value="audit" className="min-h-9 px-3 py-2 text-xs sm:text-sm">
            <span className="truncate">Audit Trail</span>
          </TabsTrigger>
        </TabsList>

        {/* Details Tab */}
        <TabsContent value="details" className="mt-4">
          <div className="grid gap-6 lg:grid-cols-3">
            <div className="lg:col-span-2 space-y-4">
              <Card>
                <CardHeader className="pb-3">
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-center gap-3">
                    <Avatar className="h-16 w-16 rounded-xl border bg-secondary">
                      {logoSrc && (
                        <AvatarImage src={logoSrc} alt={`${valueOrDash(form.business_name)} logo`} className="object-cover" />
                      )}
                      <AvatarFallback className="rounded-xl bg-secondary text-lg font-bold">
                        {profileInitials}
                      </AvatarFallback>
                    </Avatar>
                    <div>
                      <CardTitle className="text-base">{valueOrDash(form.business_name)}</CardTitle>
                      <p className="text-xs text-muted-foreground font-mono">TIN: {tinOrDash(form.tin)}</p>
                      <div className="flex items-center gap-1.5 mt-1">
                        <Badge variant={form.status === "active" ? "default" : "outline"} className="text-[10px]">{statusLabel(form.status)}</Badge>
                        {form.vat_registered && (
                          <Badge variant="outline" className="text-[10px] border-success/40 text-success gap-1">
                            <CheckCircle2 className="h-2.5 w-2.5" /> VAT Registered
                          </Badge>
                        )}
                        {form.rdo_code && (
                          <Badge variant="outline" className="text-[10px] gap-1">
                            <TrendingUp className="h-2.5 w-2.5" /> {form.rdo_code}
                          </Badge>
                        )}
                      </div>
                    </div>
                    </div>
                    {editing && (
                      <div className="flex flex-col items-start gap-2 sm:items-end">
                        <input
                          ref={logoInputRef}
                          type="file"
                          accept="image/png,image/jpeg,image/webp,image/gif"
                          className="hidden"
                          onChange={(event) => handleLogoSelection(event.target.files?.[0])}
                        />
                        <Button
                          type="button"
                          variant="outline"
                          size="sm"
                          className="gap-2"
                          onClick={() => logoInputRef.current?.click()}
                        >
                          <Upload className="h-3.5 w-3.5" />
                          {logoSrc ? "Change Logo" : "Upload Logo"}
                        </Button>
                        <p className="max-w-56 text-xs text-muted-foreground sm:text-right">
                          PNG, JPG, WebP, or GIF up to 2 MB. This logo appears across BIR, Admin, and client transaction views.
                        </p>
                        {logoFile && <Badge variant="secondary" className="text-[10px]">Ready to save</Badge>}
                      </div>
                    )}
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-2 gap-4">
                    {[
                      { label: "Business Name", key: "business_name" as const, icon: Building2 },
                      { label: "Owner / Representative", key: "owner_name" as const, icon: Building2 },
                      { label: "Email", key: "email" as const, icon: Mail, type: "email" },
                      { label: "Phone", key: "phone" as const, icon: Phone },
                      { label: "Website", key: "website" as const, icon: Globe },
                      { label: "TIN Number", key: "tin" as const, icon: FileText },
                    ].map((f) => {
                      const isTinField = f.key === "tin";
                      const value = form[f.key] as string;

                      return (
                        <div key={f.key} className="space-y-1.5">
                          <Label className="text-xs flex items-center gap-1.5">
                            <f.icon className="h-3.5 w-3.5 text-muted-foreground" /> {f.label}
                          </Label>
                          {editing ? (
                            <Input
                              type={f.type ?? "text"}
                              value={isTinField ? formatTin(value) : value}
                              inputMode={isTinField ? "numeric" : undefined}
                              maxLength={isTinField ? 15 : undefined}
                              placeholder={isTinField ? "000-000-000 or 000-000-000-000" : undefined}
                              onChange={(e) => setForm({ ...form, [f.key]: isTinField ? formatTin(e.target.value) : e.target.value })}
                              className={cn("text-sm", isTinField && "font-mono")}
                            />
                          ) : (
                            <p className={cn("text-sm text-foreground", isTinField && "font-mono")}>
                              {isTinField ? tinOrDash(value) : valueOrDash(value)}
                            </p>
                          )}
                        </div>
                      );
                    })}

                    <div className="space-y-1.5">
                      <Label className="text-xs">Business Type</Label>
                      {editing ? (
                        <Select value={form.business_type} onValueChange={(v) => setForm({ ...form, business_type: v })}>
                          <SelectTrigger className="text-sm"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="corporation">Corporation</SelectItem>
                            <SelectItem value="sole_prop">Sole Proprietorship</SelectItem>
                            <SelectItem value="partnership">Partnership</SelectItem>
                            <SelectItem value="cooperative">Cooperative</SelectItem>
                          </SelectContent>
                        </Select>
                      ) : (
                        <p className="text-sm text-foreground capitalize">{valueOrDash(form.business_type.replace("_", " "))}</p>
                      )}
                    </div>

                    <div className="space-y-1.5">
                      <Label className="text-xs">Sector</Label>
                      {editing ? (
                        <SearchableSectorSelect
                          value={form.sector}
                          onValueChange={(v) => setForm({ ...form, sector: v })}
                        />
                      ) : (
                        <p className="text-sm text-foreground">{sectorDisplay(form.sector)}</p>
                      )}
                    </div>
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm flex items-center gap-2">
                    <MapPin className="h-4 w-4 text-primary" /> Business Address
                  </CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div className="sm:col-span-2 space-y-1.5">
                      <Label className="text-xs">Street Address</Label>
                      {editing ? (
                        <Input
                          value={form.address}
                          onChange={(e) => setForm({ ...form, address: e.target.value })}
                          placeholder="Unit / floor / building / street"
                        />
                      ) : (
                        <p className="text-sm text-foreground">{valueOrDash(form.address)}</p>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">Country</Label>
                      {editing ? (
                        <Input readOnly value="Philippines" className="bg-muted/40 font-medium" />
                      ) : (
                        <p className="text-sm text-foreground">{valueOrDash(form.country || "Philippines")}</p>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">City / Municipality</Label>
                      {editing ? (
                        <SearchableLocationSelect
                          value={form.city_code}
                          options={cities.map((city) => ({
                            value: city.code,
                            label: `${city.name}${city.region ? ` · ${city.region}` : ""}`,
                            keywords: [city.name, city.region, city.province, city.rdo_code, city.rdo_name].filter(Boolean).join(" "),
                          }))}
                          placeholder={form.city || "Select city..."}
                          searchPlaceholder="Search city or municipality..."
                          emptyLabel="No city or municipality found."
                          loadingLabel="Loading cities..."
                          loading={locationLoading.cities}
                          disabled={cities.length === 0}
                          onValueChange={handleCityChange}
                        />
                      ) : (
                        <p className="text-sm text-foreground">{valueOrDash(form.city)}</p>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">Barangay</Label>
                      {editing ? (
                        <SearchableLocationSelect
                          value={form.barangay_code}
                          options={barangays.map((barangay) => ({
                            value: barangay.code,
                            label: barangay.name,
                            keywords: [barangay.name, form.city].filter(Boolean).join(" "),
                          }))}
                          placeholder={!form.city ? "Select city first" : form.barangay || "Select barangay..."}
                          searchPlaceholder="Search barangay..."
                          emptyLabel={!form.city ? "Select a city first." : "No barangay found."}
                          loadingLabel="Loading barangays..."
                          loading={locationLoading.barangays}
                          disabled={!form.city || barangays.length === 0}
                          onValueChange={handleBarangayChange}
                        />
                      ) : (
                        <p className="text-sm text-foreground">{valueOrDash(form.barangay)}</p>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">ZIP Code</Label>
                      {editing ? (
                        <Input value={form.zip_code} onChange={(e) => setForm({ ...form, zip_code: e.target.value })} />
                      ) : (
                        <p className="text-sm text-foreground">{valueOrDash(form.zip_code)}</p>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">Region</Label>
                      {editing ? (
                        <Input readOnly value={form.region} placeholder="Region will auto-fill" className="bg-muted/40" />
                      ) : (
                        <p className="text-sm text-foreground">{valueOrDash(form.region)}</p>
                      )}
                    </div>
                    <div className="space-y-1.5">
                      <Label className="text-xs">RDO Branch</Label>
                      {editing ? (
                        <Input
                          readOnly
                          value={form.rdo_code ? `${form.rdo_code} · ${form.rdo_name}` : ""}
                          placeholder={locationLoading.rdo ? "Detecting RDO..." : "Select a city to detect RDO"}
                          className="bg-muted/40"
                        />
                      ) : (
                        <p className="text-sm text-foreground">{form.rdo_code ? `${form.rdo_code} ${form.rdo_name}` : "Not assigned"}</p>
                      )}
                    </div>
                    {editing && (
                      <div className="sm:col-span-2 rounded-lg border bg-muted/30 p-3 text-xs text-muted-foreground">
                        Full saved address: {composeBusinessAddress({ ...form, country: "Philippines" }) || "Complete the address, city, and barangay fields."}
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Business Details &amp; Financials</CardTitle>
                </CardHeader>
                <CardContent>
                  <div className="grid grid-cols-2 gap-4">
                    {[
                      { label: "No. of Employees", key: "employee_count" as const, type: "number" },
                      { label: "No. of Branches", key: "branch_count" as const, type: "number" },
                      { label: "Annual Revenue (₱)", key: "annual_revenue" as const, type: "number" },
                      { label: "BIR Registration Date", key: "bir_registration_date" as const, type: "date" },
                    ].map((f) => (
                      <div key={f.key} className="space-y-1.5">
                        <Label className="text-xs">{f.label}</Label>
                        {editing ? (
                          <Input
                            type={f.type}
                            value={form[f.key] as number | string}
                            onChange={(e) => setForm({ ...form, [f.key]: f.type === "number" ? Number(e.target.value) : e.target.value })}
                          />
                        ) : (
                          <p className="text-sm text-foreground">
                            {f.key === "annual_revenue"
                              ? `₱${Number(form.annual_revenue).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
                              : f.key === "bir_registration_date"
                                ? dateOrDash(form.bir_registration_date)
                                : f.type === "number"
                                  ? Number(form[f.key] as number).toLocaleString()
                                  : valueOrDash(String(form[f.key] ?? ""))
                            }
                          </p>
                        )}
                      </div>
                    ))}
                    <div className="col-span-2 flex items-center justify-between rounded-lg border p-3">
                      <div>
                        <p className="text-sm font-medium text-foreground">VAT Registered</p>
                        <p className="text-xs text-muted-foreground">BIR Value Added Tax Registration</p>
                      </div>
                      <Switch
                        checked={form.vat_registered}
                        onCheckedChange={(v) => editing && setForm({ ...form, vat_registered: v })}
                        disabled={!editing}
                      />
                    </div>
                    {editing && (
                      <div className="col-span-2 space-y-1.5">
                        <Label className="text-xs">Notes / Change Reason</Label>
                        <Textarea
                          value={form.notes}
                          onChange={(e) => setForm({ ...form, notes: e.target.value })}
                          placeholder="Describe what changed and why (for audit trail)..."
                          className="text-sm resize-none"
                          rows={3}
                        />
                      </div>
                    )}
                  </div>
                </CardContent>
              </Card>
            </div>

            <div className="space-y-4">
              <Card>
                <CardHeader className="pb-3">
                  <div className="flex items-center gap-2">
                    <Shield className="h-4 w-4 text-primary" />
                    <CardTitle className="text-sm">Compliance Status</CardTitle>
                  </div>
                </CardHeader>
                <CardContent className="space-y-4">
                  <div className="text-center">
                    <p className={`text-4xl font-bold ${complianceScore >= 80 ? "text-success" : "text-warning"}`}>{complianceScore}</p>
                    <p className="text-xs text-muted-foreground">Compliance Score</p>
                    <Progress value={complianceScore} className="mt-2 h-2" />
                  </div>
                  <Separator />
                  <div className="space-y-2">
                    {complianceItems.map((c) => (
                      <div key={c.label} className="flex items-center justify-between text-xs">
                        <span className="text-foreground">{c.label}</span>
                        <CheckCircle2 className={`h-3.5 w-3.5 ${c.ok ? "text-success" : "text-muted-foreground"}`} />
                      </div>
                    ))}
                  </div>
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Verification Status</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {[
                    { label: "Identity Verified", ok: true, sub: "Via BIR TIN matching" },
                    { label: "Profile Records Reviewed", ok: verifiedDocs > 0, sub: `${verifiedDocs} of ${documents.length} checks verified` },
                    { label: "RDO Mapping", ok: Boolean(form.rdo_code), sub: form.rdo_code ? `${form.rdo_code} ${form.rdo_name}` : "Not assigned" },
                    { label: "Account Status", ok: form.status === "active", sub: statusLabel(form.status) },
                  ].map((item) => (
                    <div key={item.label} className="flex items-start gap-2">
                      <CheckCircle2 className={`h-3.5 w-3.5 mt-0.5 shrink-0 ${item.ok ? "text-success" : "text-muted-foreground"}`} />
                      <div>
                        <p className="text-xs font-medium text-foreground">{item.label}</p>
                        <p className="text-[10px] text-muted-foreground">{item.sub}</p>
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Quick Stats</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  {[
                    { label: "POS System", value: valueOrDash(form.pos_system) },
                    { label: "Employees", value: form.employee_count.toLocaleString() },
                    { label: "Branches", value: `${form.branch_count} locations` },
                    { label: "Annual Revenue", value: `₱${Number(form.annual_revenue).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` },
                  ].map((s) => (
                    <div key={s.label} className="flex items-center justify-between">
                      <span className="text-xs text-muted-foreground">{s.label}</span>
                      <span className="text-xs font-medium text-foreground">{s.value}</span>
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>
          </div>
        </TabsContent>

        {/* Documents Tab */}
        <TabsContent value="documents" className="mt-4">
          <VerificationDocumentsPanel />
        </TabsContent>

        {/* Compliance Tab */}
        <TabsContent value="compliance" className="mt-4">
          <div className="grid gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader className="pb-3">
                <CardTitle className="text-sm flex items-center gap-2">
                  <Shield className="h-4 w-4 text-primary" /> Compliance Checklist
                </CardTitle>
              </CardHeader>
              <CardContent className="space-y-3">
                {[
                  { label: "Business Name", ok: Boolean(form.business_name), detail: valueOrDash(form.business_name), required: true },
                  { label: "BIR Registration", ok: Boolean(form.tin && form.bir_registration_date), detail: `TIN ${tinOrDash(form.tin)} · Registered ${dateOrDash(form.bir_registration_date)}`, required: true },
                  { label: "VAT Registration", ok: form.vat_registered, detail: form.vat_registered ? "VAT-registered in merchant registry" : "VAT status not enabled", required: true },
                  { label: "Business Address", ok: Boolean(form.address || form.city || form.region), detail: [form.address, form.city, form.region, form.country].filter(Boolean).join(", ") || "Not provided", required: true },
                  { label: "RDO Assignment", ok: Boolean(form.rdo_code), detail: form.rdo_code ? `${form.rdo_code} ${form.rdo_name}` : "No RDO branch assigned", required: true },
                  { label: "Contact Email", ok: Boolean(form.email), detail: valueOrDash(form.email), required: true },
                  { label: "POS Registration", ok: Boolean(form.pos_system), detail: valueOrDash(form.pos_system), required: false },
                  { label: "NUERS Account Status", ok: form.status === "active", detail: statusLabel(form.status), required: true },
                ].map((item) => (
                  <div key={item.label} className={`flex items-start gap-3 rounded-lg border p-3 ${item.ok ? "bg-success/5 border-success/20" : "bg-muted/30"}`}>
                    <div className={`mt-0.5 rounded-full p-1 ${item.ok ? "bg-success/20" : "bg-muted"}`}>
                      {item.ok
                        ? <CheckCircle2 className="h-3.5 w-3.5 text-success" />
                        : <AlertCircle className="h-3.5 w-3.5 text-muted-foreground" />
                      }
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-2">
                        <p className="text-sm font-medium text-foreground">{item.label}</p>
                        {item.required && <Badge variant="outline" className="text-[10px]">Required</Badge>}
                      </div>
                      <p className="text-xs text-muted-foreground mt-0.5">{item.detail}</p>
                    </div>
                  </div>
                ))}
              </CardContent>
            </Card>

            <div className="space-y-4">
              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Database Registry Metadata</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2">
                  {[
                    { label: "Account Created", value: dateOrDash(form.created_at) },
                    { label: "Last Updated", value: dateOrDash(form.updated_at) },
                    { label: "BIR Registration Date", value: dateOrDash(form.bir_registration_date) },
                    { label: "RDO Branch", value: form.rdo_code ? `${form.rdo_code} ${form.rdo_name}` : "Not assigned" },
                    { label: "Region", value: valueOrDash(form.region) },
                    { label: "Status", value: statusLabel(form.status) },
                  ].map((row) => (
                    <div key={row.label} className="flex items-center justify-between py-2 border-b last:border-0">
                      <div>
                        <p className="text-sm font-medium text-foreground">{row.label}</p>
                      </div>
                      <p className="text-xs font-medium text-foreground text-right">{row.value}</p>
                    </div>
                  ))}
                </CardContent>
              </Card>

              <Card>
                <CardHeader className="pb-3">
                  <CardTitle className="text-sm">Profile Readiness</CardTitle>
                </CardHeader>
                <CardContent className="space-y-3">
                  {[
                    { label: "Identity Completeness", value: Math.round(([form.business_name, form.tin, form.owner_name].filter(Boolean).length / 3) * 100), color: form.tin ? "bg-success" : "bg-warning" },
                    { label: "Address Completeness", value: Math.round(([form.address, form.city, form.region].filter(Boolean).length / 3) * 100), color: form.address ? "bg-success" : "bg-warning" },
                    { label: "Tax Registry Completeness", value: Math.round(([form.rdo_code, form.bir_registration_date, form.vat_registered ? "vat" : ""].filter(Boolean).length / 3) * 100), color: form.rdo_code ? "bg-success" : "bg-warning" },
                    { label: "Overall Compliance Score", value: complianceScore, color: complianceScore >= 80 ? "bg-success" : "bg-warning" },
                  ].map((r) => (
                    <div key={r.label} className="space-y-1">
                      <div className="flex items-center justify-between text-xs">
                        <span className="text-muted-foreground">{r.label}</span>
                        <span className="font-medium text-foreground">{r.value}/100</span>
                      </div>
                      <div className="h-1.5 w-full rounded-full bg-muted overflow-hidden">
                        <div className={`h-full rounded-full ${r.color}`} style={{ width: `${r.value}%` }} />
                      </div>
                    </div>
                  ))}
                </CardContent>
              </Card>
            </div>
          </div>
        </TabsContent>

        {/* Audit Trail Tab */}
        <TabsContent value="audit" className="mt-4">
          <Card>
            <CardHeader className="pb-3">
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-2">
                  <Clock className="h-4 w-4 text-primary" />
                  <CardTitle className="text-sm">Profile Change History</CardTitle>
                </div>
                <Button variant="outline" size="sm" className="gap-1.5 text-xs">
                  <Download className="h-3.5 w-3.5" /> Export Log
                </Button>
              </div>
              <p className="text-xs text-muted-foreground">All profile changes are logged and immutable for compliance purposes.</p>
            </CardHeader>
            <CardContent>
              <div className="relative">
                <div className="absolute left-4 top-0 bottom-0 w-px bg-border" />
                <div className="space-y-4 ml-8">
                  {auditTrail.map((entry) => (
                    <div key={entry.id} className="relative">
                      <div className="absolute -left-[2.15rem] mt-1 h-2.5 w-2.5 rounded-full border-2 border-background bg-primary" />
                      <div className="rounded-lg border p-3 hover:bg-muted/20 transition-colors">
                        <div className="flex items-start justify-between gap-2">
                          <div>
                            <p className="text-sm font-medium text-foreground">{entry.action}</p>
                            {entry.field && (
                              <p className="text-xs text-muted-foreground mt-0.5">
                                {entry.before && entry.after
                                  ? <><span className="font-mono text-destructive">{entry.before}</span> → <span className="font-mono text-success">{entry.after}</span></>
                                  : `Field: ${entry.field}`
                                }
                              </p>
                            )}
                          </div>
                          <div className="text-right shrink-0">
                            <p className="text-[10px] text-muted-foreground">{entry.timestamp ? new Date(entry.timestamp).toLocaleString("en-PH") : "—"}</p>
                            <p className="text-[10px] text-muted-foreground font-mono">{entry.user}</p>
                          </div>
                        </div>
                      </div>
                    </div>
                  ))}
                  {auditTrail.length === 0 && (
                    <div className="rounded-lg border border-dashed p-6 text-center">
                      <Database className="mx-auto h-8 w-8 text-muted-foreground/70" />
                      <p className="mt-2 text-sm font-medium text-foreground">No audit events recorded yet</p>
                      <p className="text-xs text-muted-foreground">Profile updates saved from this page will create database audit events.</p>
                    </div>
                  )}
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* View Document Dialog */}
      <Dialog open={!!viewDoc} onOpenChange={() => setViewDoc(null)}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Document Details</DialogTitle>
          </DialogHeader>
          {viewDoc && (
            <div className="space-y-4">
              <div className="rounded-lg bg-muted/30 p-4 space-y-2">
                {[
                  { label: "Document Name", value: viewDoc.name },
                  { label: "Document Type", value: viewDoc.type },
                  { label: "Status", value: docStatusConfig[viewDoc.status].label },
                  ...(viewDoc.uploaded ? [{ label: "Upload Date", value: viewDoc.uploaded }] : []),
                  ...(viewDoc.expires ? [{ label: "Expiry Date", value: viewDoc.expires }] : []),
                  ...(viewDoc.size ? [{ label: "File Size", value: viewDoc.size }] : []),
                ].map((row) => (
                  <div key={row.label} className="flex justify-between text-sm">
                    <span className="text-muted-foreground">{row.label}</span>
                    <span className="font-medium text-foreground">{row.value}</span>
                  </div>
                ))}
              </div>
              <div className="flex justify-end gap-2">
                <Button variant="outline" size="sm" className="gap-1.5">
                  <ExternalLink className="h-3.5 w-3.5" /> Open File
                </Button>
                <Button variant="outline" size="sm" className="gap-1.5">
                  <Download className="h-3.5 w-3.5" /> Download
                </Button>
              </div>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
