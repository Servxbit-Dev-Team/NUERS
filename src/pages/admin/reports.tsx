import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import { Link, useLocation } from "react-router-dom";
import {
  Activity,
  ArrowRight,
  BarChart3,
  Building2,
  ChevronLeft,
  ChevronRight,
  Download,
  FileText,
  Filter,
  RefreshCw,
  Search,
  ShieldCheck,
} from "lucide-react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from "recharts";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { SALE_CATEGORIES, saleLabel } from "@/lib/statutory-sales";
import { reportLabel } from "@/lib/report-data";
import { exportReport } from "@/components/reports/export-report";
import { SearchableSelect } from "@/components/registration/searchable-select";
import "./reports.css";

type Filters = Record<string, string> & { from: string; to: string };
type Amounts = {
  sales: number;
  vatable: number;
  vat: number;
  exempt: number;
  zero: number;
  non_vat: number;
  sspt: number;
  manual_discount: number;
  statutory_discount: number;
  vat_relief: number;
};
type Row = Amounts & {
  label: string;
  detail: string;
  merchant_id: string;
  seller_name: string;
  seller_tin: string;
  records: number;
  included: number;
  excluded: number;
};
type Report = {
  totals: Amounts & {
    records: number;
    included: number;
    excluded: number;
    merchants: number;
    success: number;
    failed: number;
    pending: number;
    cancelled: number;
    conflicts: number;
    missing_buyer_tin: number;
  };
  trend: Row[];
  trend_interval: string;
  trend_truncated: boolean;
  categories: Row[];
  top_merchants: Row[];
  document_types: Row[];
  data: Row[];
  group: string;
  filters: Filters;
  pagination: { page: number; per_page: number; total: number; last_page: number };
  options: {
    branches: string[];
    branch_codes: string[];
    documents: string[];
    payments: string[];
    regions: string[];
    rdos: string[];
    channels: string[];
    industries: string[];
    merchants: { id: string; business_name: string; tin: string }[];
  };
  generated_at: string;
  refreshed_at: string;
  revision: number;
  notice: string;
};
const groups: Record<string, string> = {
  daily: "Daily sales",
  monthly: "Monthly sales",
  business: "By merchant",
  branch: "By branch",
  buyer: "Monthly buyer schedule",
  category: "Sale categories",
  document: "Document types",
  payment: "Payment methods",
  region: "Regions",
  rdo: "RDOs",
};
const labels: Record<string, string> = {
  sales: "Net document amount",
  vatable: "VATable sales",
  vat: "Output VAT",
  exempt: "VAT-exempt sales",
  zero: "Zero-rated sales",
  non_vat: "Non-VAT sales",
  sspt: "SSPT sales",
  manual_discount: "Manual discounts",
  statutory_discount: "Statutory discounts",
  vat_relief: "VAT relief",
};
const amountKeys = Object.keys(labels) as (keyof Amounts)[];
const money = (n: number) =>
  new Intl.NumberFormat("en-PH", { style: "currency", currency: "PHP" }).format(n || 0);
const count = (n: number) => new Intl.NumberFormat("en-PH").format(n || 0);
const compact = (n: number) =>
  new Intl.NumberFormat("en", { notation: "compact", maximumFractionDigits: 1 }).format(n);
const pht = (value: string) =>
  new Intl.DateTimeFormat("en-PH", {
    timeZone: "Asia/Manila",
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(value));
function today() {
  return new Intl.DateTimeFormat("en-CA", {
    timeZone: "Asia/Manila",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).format(new Date());
}
function initial(): Filters {
  const date = today();
  return { from: date.slice(0, 7) + "-01", to: date };
}
function query(filters: Filters) {
  return new URLSearchParams(Object.entries(filters).filter(([, v]) => v !== "" && v !== "all"));
}
const mapOptions = (values: string[] = []) =>
  values.map((v) => ({ value: v, label: reportLabel(v) }));
const palette = ["#0056ff", "#00a876", "#8d3bff", "#f5ad00"];

export function AdminReports() {
  const [draft, setDraft] = useState<Filters>(initial),
    [applied, setApplied] = useState<Filters>(initial);
  const [group, setGroup] = useState("daily"),
    [page, setPage] = useState(1),
    [size, setSize] = useState(20);
  const [data, setData] = useState<Report | null>(null),
    [busy, setBusy] = useState(true),
    [error, setError] = useState("");
  const [advanced, setAdvanced] = useState(false),
    [auto, setAuto] = useState(false),
    [exporting, setExporting] = useState(false);
  const pending = useRef<AbortController | null>(null),
    generation = useRef(0);
  const location = useLocation();
  const base = location.pathname.startsWith("/super-admin")
    ? "/super-admin"
    : location.pathname.startsWith("/bir")
      ? "/bir"
      : "/admin";
  const load = useCallback(async () => {
    pending.current?.abort();
    const controller = new AbortController();
    pending.current = controller;
    const version = ++generation.current;
    setBusy(true);
    setError("");
    try {
      const params = query(applied);
      params.set("group", group);
      params.set("page", String(page));
      params.set("per_page", String(size));
      const result = await readJsonResponse<Report>(
        await apiFetch("/api/admin/analytics?" + params, { signal: controller.signal }),
        "Analytics could not load. Please retry.",
      );
      if (version === generation.current) setData(result);
    } catch (e) {
      if (!controller.signal.aborted && version === generation.current)
        setError(e instanceof Error ? e.message : "Unable to load analytics.");
    } finally {
      if (version === generation.current) setBusy(false);
    }
  }, [applied, group, page, size]);
  useEffect(() => {
    void load();
    return () => pending.current?.abort();
  }, [load]);
  useEffect(() => {
    if (!auto) return;
    const id = window.setInterval(() => {
      if (!document.hidden && !pending.current?.signal.aborted && !busy) void load();
    }, 30000);
    return () => clearInterval(id);
  }, [auto, busy, load]);
  function apply(e?: FormEvent) {
    e?.preventDefault();
    if (!draft.from || !draft.to || draft.to < draft.from) {
      setError("Select a valid reporting date range.");
      return;
    }
    setPage(1);
    setApplied({ ...draft });
  }
  function preset(value: string) {
    const date = today(),
      year = Number(date.slice(0, 4)),
      month = Number(date.slice(5, 7));
    let from = date;
    if (value === "month") from = date.slice(0, 7) + "-01";
    if (value === "quarter")
      from = `${year}-${String(Math.floor((month - 1) / 3) * 3 + 1).padStart(2, "0")}-01`;
    if (value === "year") from = `${year}-01-01`;
    setDraft((f) => ({ ...f, from, to: date }));
  }
  const change = (key: string, value: string) =>
    setDraft((f) => ({
      ...f,
      [key]: value,
      ...(key === "merchant" ? { branch: "all", branch_code: "all" } : {}),
    }));
  const select = (
    key: string,
    title: string,
    options: { value: string; label: string }[],
    all = "All",
  ) => (
    <SearchableSelect
      required={false}
      label={title}
      value={draft[key] || "all"}
      onChange={(v) => change(key, v)}
      items={[{ value: "all", label: all }, ...options]}
      placeholder={all}
    />
  );
  const input = (key: string, title: string, type = "text", placeholder = "") => (
    <label className="ar-field" htmlFor={`ar-${key}`}>
      <span>{title}</span>
      <input
        id={`ar-${key}`}
        type={type}
        value={draft[key] || ""}
        onChange={(e) => change(key, e.target.value)}
        placeholder={placeholder}
        min={type === "number" ? "0" : undefined}
        step={type === "number" ? "0.01" : undefined}
      />
    </label>
  );
  const options = data?.options;
  function sourceQuery(row: Row) {
    const filters = { ...data!.filters };
    const key = data!.group;
    if (row.merchant_id) filters.merchant = row.merchant_id;
    if (row.label !== "Not recorded") {
      const field = {
        branch: "branch",
        category: "sale_category",
        document: "document",
        payment: "payment",
        region: "region",
        rdo: "rdo",
      }[key];
      if (field) filters[field] = row.label;
      if (key === "branch" && row.detail !== "Not recorded") filters.branch_code = row.detail;
      if (key === "buyer") filters.customer = row.detail.split(" · ")[0] || row.label;
      if (filters.date_basis !== "received") {
        if (key === "daily") filters.from = filters.to = row.label;
        const month =
          key === "buyer" ? row.detail.split(" · ")[1] : key === "monthly" ? row.label : null;
        if (month) {
          const [year, m] = month.split("-").map(Number);
          const last = new Date(Date.UTC(year, m, 0)).toISOString().slice(0, 10);
          filters.from = [filters.from, month + "-01"].sort().at(-1)!;
          filters.to = [filters.to, last].sort()[0];
        }
      }
    }
    return query(filters);
  }
  const current = !!data && !busy && !error && data.group === group;
  async function download(
    kind:
      | "summary"
      | "breakdown"
      | "register"
      | "sales"
      | "reconciliation"
      | "businesses"
      | "audit",
  ) {
    if (!data || exporting || !current) return;
    setExporting(true);
    try {
      const params = query(applied);
      params.set("group", group);
      const filters = Object.fromEntries(params);
      if (kind === "summary") {
        const rows = [
          ["Overview", "Document records", count(data.totals.records)],
          ["Overview", "Included sales documents", count(data.totals.included)],
          ["Overview", "Excluded documents", count(data.totals.excluded)],
          ...amountKeys.map((k) => ["Overview", labels[k], money(data.totals[k])]),
          ...data.trend.map((r) => ["Sales trend", r.label, money(r.sales)]),
          ...data.categories.map((r) => [
            "Sale category",
            saleLabel(r.label),
            `${count(r.included)} sales documents · ${money(r.sales)} · discount ${money(r.statutory_discount)}`,
          ]),
          ...data.top_merchants.map((r) => [
            "Top 6 merchants",
            `${r.seller_name} · ${r.seller_tin}`,
            money(r.sales),
          ]),
          ...["success", "pending", "failed", "cancelled"].map((s) => [
            "Document status",
            reportLabel(s),
            count(data.totals[s as "success"]),
          ]),
        ];
        await exportReport({
          title: "Analytics & Reports — Executive Summary",
          scope: "Live records · PHP · " + data.notice,
          filters,
          generatedAt: pht(data.generated_at),
          note: `Reporting revision ${data.revision}. Trend interval: ${data.trend_interval}. Top merchants chart contains six merchants; full merchant schedule is available separately.`,
          table: { headers: ["Section", "Metric / Period", "Value"], rows },
        });
      } else {
        const paths = {
          breakdown: "/api/admin/analytics/export",
          register: "/api/transaction-workspace/export",
          sales: "/api/operations-report/export",
          reconciliation: "/api/operations-report/export",
          businesses: "/api/business-registry/export",
          audit: "/api/live-monitoring/audit",
        };
        const titles = {
          breakdown: groups[group],
          register: "Transaction register",
          sales: "Summary list of sales",
          reconciliation: "Reconciliation & exceptions",
          businesses: "Registered merchants",
          audit: "Complete audit journal",
        };
        if (kind !== "breakdown") params.delete("group");
        if (kind === "sales" || kind === "reconciliation") params.set("mode", kind);
        if (kind === "businesses" || kind === "audit") {
          for (const key of [...params.keys()]) params.delete(key);
          if (kind === "audit") params.set("download", "1");
        }
        await exportReport({
          path: paths[kind] + "?" + params,
          title: "NUERS — " + titles[kind],
          scope:
            kind === "businesses" || kind === "audit"
              ? "Authorized complete registry / journal. Analytics date and transaction filters do not apply."
              : "All matching records across all pages. Internal report; not an official BIR submission.",
          filters: Object.fromEntries(params),
          generatedAt: pht(new Date().toISOString()),
          note: kind === "breakdown" ? data.notice : undefined,
        });
      }
    } finally {
      setExporting(false);
    }
  }
  const tax = data
    ? ["vatable", "exempt", "zero", "non_vat", "sspt"].map((k) => ({
        name: labels[k],
        amount: data.totals[k as keyof Amounts],
      }))
    : [];
  const states = data
    ? ["success", "pending", "failed", "cancelled"].map((k) => ({
        name: k === "success" ? "Issued / recorded" : reportLabel(k),
        records: data.totals[k as "success"],
      }))
    : [];
  const chartEmpty = !data || data.totals.records === 0;
  const empty = (
    <div className="ar-empty">No matching records in this period. Adjust the dates or filters.</div>
  );
  const tooltip = (v: unknown) => money(Number(v));
  return (
    <main className="ar-page">
      <header className="ar-header">
        <div>
          <div className="ar-eyebrow">REPORTING WORKSPACE · LIVE RECORDS</div>
          <h1>
            <BarChart3 />
            Analytics & Reports
          </h1>
          <p>
            Review sales, tax classifications and document activity across your authorized
            merchants.
          </p>
        </div>
        <div className="ar-actions">
          <button onClick={() => void load()} disabled={busy}>
            <RefreshCw className={busy ? "ar-spin" : ""} />
            Refresh
          </button>
          <button
            className="ar-primary"
            disabled={!current || exporting}
            onClick={() => void download("summary")}
          >
            <Download />
            Export summary
          </button>
        </div>
      </header>
      <form className="ar-panel" onSubmit={apply}>
        <div className="ar-panel-head">
          <h2>
            <Filter />
            Reporting filters
          </h2>
          <div className="ar-presets">
            {[
              ["today", "Today"],
              ["month", "This month"],
              ["quarter", "This quarter"],
              ["year", "This year"],
            ].map(([v, l]) => (
              <button type="button" key={v} onClick={() => preset(v)}>
                {l}
              </button>
            ))}
          </div>
        </div>
        <div className="ar-filter-grid">
          {input("from", "Period from", "date")}
          {input("to", "Period to", "date")}
          {select(
            "merchant",
            "Merchant / seller TIN",
            (options?.merchants || []).map((m) => ({
              value: m.id,
              label: `${m.business_name} · ${m.tin}`,
            })),
            "All merchants",
          )}
          {input(
            "search",
            "Invoice / reference / seller",
            "search",
            "Search document, business or TIN",
          )}
          {select(
            "sale_category",
            "Sale category",
            Object.keys(SALE_CATEGORIES).map((v) => ({ value: v, label: saleLabel(v) })),
            "All sale categories",
          )}
          {select("vat", "Transaction tax classification", [
            { value: "vatable", label: "VATable" },
            { value: "exempt", label: "VAT-exempt component" },
            { value: "zero", label: "Zero-rated" },
            { value: "mixed", label: "Mixed tax components" },
            { value: "non_vat_document", label: "Non-VAT invoice" },
          ])}
        </div>
        {advanced && (
          <div className="ar-filter-grid ar-advanced">
            {select(
              "date_basis",
              "Date basis",
              [{ value: "received", label: "NUERS received date (PHT)" }],
              "Business / invoice date",
            )}
            {select("status", "Document status", [
              { value: "success", label: "Issued / recorded" },
              ...mapOptions(["pending", "failed", "cancelled"]),
            ])}
            {select("document", "Document type", mapOptions(options?.documents))}
            {select("branch", "Branch / location", mapOptions(options?.branches))}
            {select("branch_code", "Seller branch code", mapOptions(options?.branch_codes))}
            {select("rdo", "Seller RDO", mapOptions(options?.rdos))}
            {select("region", "Seller region", mapOptions(options?.regions))}
            {input("customer", "Buyer name / TIN", "search", "Search recorded buyer details")}
            {select("payment", "Payment method", mapOptions(options?.payments))}
            {select(
              "discount",
              "Discount treatment",
              mapOptions(["any", "manual", "statutory", "none"]),
            )}
            {select("seller_vat", "Seller VAT registration", [
              { value: "vat", label: "VAT registered" },
              { value: "non_vat", label: "Non-VAT registered" },
            ])}
            {select("industry", "Registered industry", mapOptions(options?.industries))}
            {select("source", "Document origin", [
              { value: "invoice", label: "Merchant invoice" },
              { value: "receipt", label: "Imported / API receipt" },
              { value: "transaction", label: "Transaction without document" },
            ])}
            {select("channel", "Source channel", mapOptions(options?.channels))}
            {select("identity", "Document identity", [
              { value: "unique", label: "Unique" },
              { value: "conflict", label: "Conflicting identity" },
            ])}
            {select("bir", "BIR transmission evidence", [
              { value: "not_submitted", label: "Not submitted" },
              { value: "processing", label: "Queued / processing" },
              { value: "acknowledged", label: "Verified acknowledgment" },
              { value: "rejected", label: "Verified rejection" },
              { value: "test", label: "Certification / test evidence" },
              { value: "unverified", label: "Unverified response evidence" },
            ])}
            {input("min", "Minimum document amount (PHP)", "number")}
            {input("max", "Maximum document amount (PHP)", "number")}
          </div>
        )}
        <div className="ar-filter-footer">
          <span>
            Maximum period: 366 days. Dropdowns show up to 200 values; use keyword search for other
            businesses or TINs.
          </span>
          <div className="ar-actions">
            <button type="button" aria-expanded={advanced} onClick={() => setAdvanced(!advanced)}>
              <Filter />
              {advanced ? "Fewer filters" : "Advanced filters"}
            </button>
            <button
              type="button"
              onClick={() => {
                const f = initial();
                setDraft(f);
                setApplied(f);
                setPage(1);
              }}
            >
              Reset
            </button>
            <button className="ar-primary" type="submit" disabled={busy}>
              <Search />
              Apply filters
            </button>
          </div>
        </div>
      </form>
      {error && (
        <div role="alert" className="ar-error">
          {error} <button onClick={() => void load()}>Retry</button>
        </div>
      )}
      <div className="ar-status" role="status">
        <span>
          <span className={error ? "ar-dot ar-dot-error" : "ar-dot"} />
          {busy
            ? "Updating report…"
            : error
              ? "Update failed — previous snapshot retained"
              : data
                ? `Updated ${pht(data.refreshed_at)} PHT · Revision ${data.revision}`
                : "Waiting for records"}
        </span>
        <label>
          <input type="checkbox" checked={auto} onChange={(e) => setAuto(e.target.checked)} />
          Auto-refresh every 30 seconds
        </label>
      </div>
      {data && (
        <div className={busy ? "ar-results ar-updating" : "ar-results"} aria-busy={busy}>
          <div className="ar-period">
            <ShieldCheck size={16} />
            Applied period:{" "}
            <strong>
              {data.filters.from} – {data.filters.to}
            </strong>{" "}
            · PHP · Live source records
            {JSON.stringify(draft) !== JSON.stringify(applied) && (
              <span className="ar-pending">Filter changes not applied</span>
            )}
          </div>
          <section className="ar-kpis">
            {[
              [
                "Net document amount",
                money(data.totals.sales),
                "Validated invoices + debits − credits",
                "blue",
              ],
              [
                "Sales documents",
                count(data.totals.included),
                `${count(data.totals.records)} document records in scope`,
                "green",
              ],
              [
                "VATable sales",
                money(data.totals.vatable),
                "Sales exclusive of output VAT",
                "blue",
              ],
              ["Output VAT", money(data.totals.vat), "Recorded VAT after adjustments", "violet"],
              [
                "VAT-exempt sales",
                money(data.totals.exempt),
                "Non-VAT and SSPT shown separately",
                "amber",
              ],
              [
                "Zero-rated sales",
                money(data.totals.zero),
                "Recorded zero-rated component",
                "green",
              ],
              [
                "Discounts",
                money(data.totals.manual_discount + data.totals.statutory_discount),
                `Manual ${money(data.totals.manual_discount)} · Statutory ${money(data.totals.statutory_discount)}`,
                "violet",
              ],
              [
                "Excluded from sales",
                count(data.totals.excluded),
                "Payment copies, failures or evidence to review",
                "red",
              ],
            ].map(([title, value, hint, color]) => (
              <article className={`ar-kpi ar-${color}`} key={title}>
                <span>{title}</span>
                <strong>{value}</strong>
                <small>{hint}</small>
              </article>
            ))}
          </section>
          <div className="ar-chart-grid">
            <section className="ar-panel">
              <div className="ar-panel-head">
                <h2>Sales & document trend</h2>
                <span>
                  {data.trend_interval === "monthly" ? "Monthly" : "Daily"} · business date
                </span>
              </div>
              <div className="ar-chart">
                {chartEmpty ? (
                  empty
                ) : (
                  <ResponsiveContainer width="100%" height="100%">
                    <ComposedChart
                      data={data.trend}
                      margin={{ top: 12, right: 8, bottom: 8, left: 0 }}
                    >
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="label" tick={{ fontSize: 12 }} minTickGap={25} />
                      <YAxis yAxisId="money" tickFormatter={compact} width={65} />
                      <YAxis yAxisId="count" orientation="right" allowDecimals={false} width={45} />
                      <Tooltip
                        formatter={(v, n) =>
                          n === "Sales documents" ? count(Number(v)) : money(Number(v))
                        }
                      />
                      <Legend />
                      <Bar
                        yAxisId="money"
                        dataKey="sales"
                        name="Net document amount"
                        fill="#0056ff"
                        radius={[3, 3, 0, 0]}
                      />
                      <Line
                        yAxisId="count"
                        dataKey="included"
                        name="Sales documents"
                        stroke="#ef9e00"
                        strokeWidth={2}
                        dot={false}
                      />
                    </ComposedChart>
                  </ResponsiveContainer>
                )}
              </div>
            </section>
            <section className="ar-panel">
              <div className="ar-panel-head">
                <h2>Tax classification</h2>
                <span>Sales bases · PHP</span>
              </div>
              <div className="ar-chart">
                {chartEmpty ? (
                  empty
                ) : (
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={tax} layout="vertical" margin={{ right: 16, left: 0 }}>
                      <CartesianGrid strokeDasharray="3 3" horizontal={false} />
                      <XAxis type="number" tickFormatter={compact} />
                      <YAxis type="category" dataKey="name" width={125} tick={{ fontSize: 12 }} />
                      <Tooltip formatter={tooltip} />
                      <Bar
                        dataKey="amount"
                        name="Sales base"
                        fill="#00a876"
                        radius={[0, 4, 4, 0]}
                      />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </section>
            <section className="ar-panel">
              <div className="ar-panel-head">
                <h2>Sales by category</h2>
                <span>Recorded eligibility</span>
              </div>
              <div className="ar-chart">
                {chartEmpty ? (
                  empty
                ) : (
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart
                      data={data.categories.map((r) => ({ ...r, name: saleLabel(r.label) }))}
                      margin={{ bottom: 22, left: 0, right: 12 }}
                    >
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 11 }} interval={0} />
                      <YAxis tickFormatter={compact} width={65} />
                      <Tooltip formatter={tooltip} />
                      <Legend />
                      <Bar dataKey="sales" name="Net document amount" fill="#0056ff" />
                      <Bar dataKey="statutory_discount" name="Statutory discount" fill="#8d3bff" />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </section>
            <section className="ar-panel">
              <div className="ar-panel-head">
                <h2>Document status</h2>
                <span>Source status · separate from BIR</span>
              </div>
              <div className="ar-chart">
                {chartEmpty ? (
                  empty
                ) : (
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={states} margin={{ right: 15 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} />
                      <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                      <YAxis allowDecimals={false} width={50} />
                      <Tooltip />
                      <Bar
                        dataKey="records"
                        name="Documents"
                        fill="#488cff"
                        radius={[4, 4, 0, 0]}
                      />
                    </BarChart>
                  </ResponsiveContainer>
                )}
              </div>
            </section>
          </div>
          <section className="ar-panel">
            <div className="ar-panel-head">
              <h2>
                <Building2 />
                Top merchants
              </h2>
              <button
                onClick={() => {
                  setGroup("business");
                  setPage(1);
                }}
              >
                View all merchants <ArrowRight />
              </button>
            </div>
            <div className="ar-merchants">
              {data.top_merchants.length
                ? data.top_merchants.map((m, i) => (
                    <button
                      className="ar-merchant"
                      key={`${m.merchant_id}-${m.seller_name}-${m.seller_tin}`}
                      onClick={() => {
                        const f = { ...applied, merchant: m.merchant_id };
                        setDraft(f);
                        setApplied(f);
                        setPage(1);
                      }}
                    >
                      <span className="ar-rank" style={{ color: palette[i % palette.length] }}>
                        {i + 1}
                      </span>
                      <span>
                        <strong>{m.seller_name}</strong>
                        <small>
                          {m.seller_tin} · {count(m.included)} sales documents
                        </small>
                      </span>
                      <b>{money(m.sales)}</b>
                    </button>
                  ))
                : empty}
            </div>
          </section>
          <section className="ar-panel">
            <div className="ar-panel-head">
              <div>
                <h2>Detailed reporting schedules</h2>
                <p>
                  All matching groups · source amounts retained · horizontal scroll shows every tax
                  column.
                </p>
              </div>
              <button
                className="ar-primary"
                disabled={!current || exporting}
                onClick={() => void download("breakdown")}
              >
                <Download />
                Export breakdown PDF / CSV
              </button>
            </div>
            <div className="ar-tabs" role="tablist" aria-label="Report breakdown">
              {Object.entries(groups).map(([key, label]) => (
                <button
                  role="tab"
                  aria-selected={key === group}
                  className={key === group ? "active" : ""}
                  key={key}
                  onClick={() => {
                    setGroup(key);
                    setPage(1);
                  }}
                >
                  {label}
                </button>
              ))}
            </div>
            <div className="ar-table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>{groups[data.group]}</th>
                    <th>Merchant / TIN</th>
                    <th>Details</th>
                    <th>Records</th>
                    <th>Sales docs</th>
                    <th>Excluded</th>
                    {amountKeys.map((k) => (
                      <th key={k}>{labels[k]} (PHP)</th>
                    ))}
                    <th>Source records</th>
                  </tr>
                </thead>
                <tbody>
                  {data.data.length ? (
                    data.data.map((r, i) => (
                      <tr key={`${r.label}-${r.merchant_id}-${r.detail}-${i}`}>
                        <td>{data.group === "category" ? saleLabel(r.label) : r.label}</td>
                        <td>
                          {r.seller_name || "All selected merchants"}
                          <small>{r.seller_tin}</small>
                        </td>
                        <td>{r.detail || "—"}</td>
                        <td>{count(r.records)}</td>
                        <td>{count(r.included)}</td>
                        <td>{count(r.excluded)}</td>
                        {amountKeys.map((k) => (
                          <td className="ar-money" key={k}>
                            {money(r[k])}
                          </td>
                        ))}
                        <td>
                          <Link to={`${base}/transactions?${sourceQuery(r)}`}>
                            Search records <ArrowRight size={14} />
                          </Link>
                        </td>
                      </tr>
                    ))
                  ) : (
                    <tr>
                      <td colSpan={17}>{empty}</td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
            <div className="ar-pagination">
              <span>
                {count(data.pagination.total)} groups · page {data.pagination.page} of{" "}
                {data.pagination.last_page}
              </span>
              <div className="ar-actions">
                <label>
                  Rows{" "}
                  <select
                    value={size}
                    onChange={(e) => {
                      setSize(Number(e.target.value));
                      setPage(1);
                    }}
                  >
                    {[20, 50, 100].map((n) => (
                      <option key={n}>{n}</option>
                    ))}
                  </select>
                </label>
                <button
                  aria-label="Previous report page"
                  disabled={busy || data.pagination.page <= 1}
                  onClick={() => setPage(data.pagination.page - 1)}
                >
                  <ChevronLeft />
                </button>
                <button
                  aria-label="Next report page"
                  disabled={busy || data.pagination.page >= data.pagination.last_page}
                  onClick={() => setPage(data.pagination.page + 1)}
                >
                  <ChevronRight />
                </button>
              </div>
            </div>
          </section>
          {data.trend_truncated && (
            <p className="ar-pending">
              The trend shows the latest 366 periods. Totals and exports include the complete
              selected scope.
            </p>
          )}
          <section className="ar-panel ar-quality">
            <div>
              <h2>
                <Activity />
                Source review
              </h2>
              <p>
                {count(data.totals.conflicts)} conflicting document identities ·{" "}
                {count(data.totals.missing_buyer_tin)} records without a buyer TIN. A missing buyer
                TIN is not automatically an error; requirements depend on the transaction.
              </p>
              <p>{data.notice}</p>
            </div>
            <Link to={`${base}/reconciliation`}>
              Review exceptions <ArrowRight size={16} />
            </Link>
          </section>
        </div>
      )}
      {!data && (
        <section className="ar-panel ar-empty">
          {busy ? (
            <>
              <RefreshCw className="ar-spin" />
              Loading indexed analytics…
            </>
          ) : (
            "Report unavailable. Use Retry to load the report."
          )}
        </section>
      )}
      <section className="ar-panel">
        <div className="ar-panel-head">
          <h2>
            <FileText />
            Report library
          </h2>
          <span>PDF / CSV · NUERS branding</span>
        </div>
        <div className="ar-library">
          {[
            [
              "register",
              "Transaction register",
              "Document-level references, seller/buyer details, VAT, discounts and statuses.",
            ],
            [
              "sales",
              "Summary list of sales",
              "Validated invoice sales and linked adjustments for the applied transaction filters.",
            ],
            [
              "reconciliation",
              "Reconciliation & exceptions",
              "Recorded source differences and review decisions.",
            ],
            [
              "businesses",
              "Registered merchants",
              "Complete authorized registry; transaction filters do not apply.",
            ],
            [
              "audit",
              "Audit journal",
              "Complete authorized audit history; transaction filters do not apply.",
            ],
          ].map(([key, title, detail]) => (
            <article key={key}>
              <h3>{title}</h3>
              <p>{detail}</p>
              <button
                disabled={!current || exporting}
                onClick={() => void download(key as "register")}
              >
                <Download />
                Export PDF / CSV
              </button>
            </article>
          ))}
        </div>
      </section>
      <footer className="ar-footnote">
        BIR reference basis:{" "}
        <a
          href="https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2077-2024.pdf"
          target="_blank"
          rel="noreferrer"
        >
          RMC 77-2024
        </a>{" "}
        ·{" "}
        <a
          href="https://bir-cdn.bir.gov.ph/BIR/pdf/26116rr16-2005.pdf"
          target="_blank"
          rel="noreferrer"
        >
          RR 16-2005, sales schedule fields
        </a>{" "}
        ·{" "}
        <a
          href="https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2011-2025.pdf"
          target="_blank"
          rel="noreferrer"
        >
          RR 11-2025
        </a>
        . These reports support review; PDF/CSV downloads are not validated RELIEF files or proof of
        BIR submission. Purchases, input VAT and SAWT require their own source ledgers.
      </footer>
    </main>
  );
}
