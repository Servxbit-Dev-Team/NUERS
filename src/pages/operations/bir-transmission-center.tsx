import { exportReport as openReportExport } from "@/components/reports/export-report";
import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import {
  Send,
  Building2,
  ShieldCheck,
  FileCheck2,
  AlertTriangle,
  Clock3,
  Search,
  SlidersHorizontal,
  RefreshCw,
  Download,
  ChevronLeft,
  ChevronRight,
  Eye,
  X,
  Layers3,
  CheckCircle2,
  ArrowRight,
  LockKeyhole,
} from "lucide-react";
import { apiJson } from "@/lib/api-url";
import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { SearchableSelect } from "@/components/registration/searchable-select";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { formatTin } from "@/lib/tin";
import { SALE_CATEGORIES, saleLabel } from "@/lib/statutory-sales";
import "./bir-transmission-center.css";
type Filters = Record<string, string>;
const defaults: Filters = {
  merchant: "all",
  search: "",
  from: "",
  to: "",
  date_basis: "document",
  document: "all",
  status: "all",
  branch: "",
  rdo: "",
  region: "",
  source: "all",
  sale_category: "all",
  min: "",
  max: "",
  deadline: "all",
  sort: "newest",
};
const states: Record<string, string> = {
  unprepared: "Not prepared",
  needs_review: "Needs source review",
  prepared: "Prepared · connection pending",
  queued: "Queued for delivery",
  sending: "Sending",
  accepted: "BIR accepted",
  rejected: "BIR rejected",
  retry: "Retry scheduled",
  uncertain: "Inquiry required",
  dead_letter: "Reconciliation required",
  pending: "Pending preparation",
  completed: "Preparation completed",
  failed: "Preparation failed",
};
type Doc = {
  identity_count: number;
  key: string;
  source: string;
  id: string;
  merchant_id: string;
  business: string;
  tin: string;
  number: string;
  reference: string;
  document_type: string;
  sale_category: string;
  branch: string;
  rdo: string;
  region: string;
  business_date: string;
  received_at: string;
  amount: number;
  vat_amount: number;
  status: string;
  document_status: string;
  attempts: number;
  provider_reference: string;
  blockers: string[];
  last_error: string;
  document_hash: string;
  outbox_id: string;
  prepared_at: string;
  due_at: string;
};
type Data = {
  data: Doc[];
  total: number;
  last_page: number;
  summary: Record<string, number>;
  generated_at: string;
  can_manage: boolean;
  batch_limit: number;
  companies: { id: string; business_name: string; tin: string; status: string }[];
  gateway: { can_send: boolean; transport_ready: boolean; blockers: string[] };
  options: { documents: string[] };
};
type Review = {
  preview_id: string;
  documents: {
    source: string;
    id: string;
    number: string;
    amount: number;
    valid: boolean;
    blockers: string[];
    status: string;
  }[];
  total: number;
  valid: number;
  amount: number;
  expires_at: string;
};
type Batch = {
  id: string;
  business_name: string;
  status: string;
  document_count: number;
  created_at: string;
  reason: string;
};
const label = (v: unknown) =>
  String(v || "Not recorded")
    .replaceAll("_", " ")
    .replace(/\b\w/g, (c) => c.toUpperCase())
    .replace(/\b(Vat|Bir|Rdo|Tin)\b/g, (c) => c.toUpperCase());
const money = (v: unknown) =>
  "₱" +
  Number(v || 0).toLocaleString("en-PH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const count = (v: unknown) => Number(v || 0).toLocaleString("en-PH");
const time = (v: unknown) =>
  v
    ? new Date(
        String(v).includes("T") ? String(v) : String(v).replace(" ", "T") + "+08:00",
      ).toLocaleString("en-PH", {
        timeZone: "Asia/Manila",
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "—";
function Status({ value }: { value: string }) {
  return <span className={"eisc-status " + value}>{states[value] || label(value)}</span>;
}
async function get(url: string, body?: unknown) {
  return apiJson(url, body ? { method: "POST", body: JSON.stringify(body) } : {});
}
function params(f: Filters) {
  const p = new URLSearchParams();
  Object.entries(f).forEach(([k, v]) => {
    if (v && v !== "all") p.set(k, v);
  });
  return p;
}

export function BirTransmissionWorkspace() {
  const [data, setData] = useState<Data | null>(null),
    [filters, setFilters] = useState(defaults),
    [applied, setApplied] = useState(defaults),
    [page, setPage] = useState(1),
    [size, setSize] = useState("25"),
    [advanced, setAdvanced] = useState(false),
    [view, setView] = useState("documents"),
    [selection, setSelection] = useState<string[]>([]),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [loading, setLoading] = useState(true),
    [busy, setBusy] = useState(false),
    [review, setReview] = useState<Review | null>(null),
    [reason, setReason] = useState("Prepare company documents for BIR reporting readiness."),
    [batches, setBatches] = useState<{ data: Batch[]; last_page: number }>({
      data: [],
      last_page: 1,
    }),
    [batchPage, setBatchPage] = useState(1),
    [batch, setBatch] = useState<any>(null),
    [detail, setDetail] = useState<any>(null),
    [history, setHistory] = useState<any>(null),
    [historyEnv, setHistoryEnv] = useState("production"),
    [historyStatus, setHistoryStatus] = useState("all"),
    [historyPage, setHistoryPage] = useState(1);
  const seq = useRef(0),
    pending = useRef(false);
  const update = (key: string, v: string) => setFilters((f) => ({ ...f, [key]: v }));
  async function load(quiet = false) {
    if (pending.current && quiet) return;
    pending.current = true;
    const ticket = ++seq.current;
    if (!quiet) setLoading(true);
    try {
      const p = params(applied);
      p.set("page", String(page));
      p.set("per_page", size);
      const d = await get("/api/admin/eis-center?" + p);
      if (ticket === seq.current) {
        setData(d);
        setSelection((s) => s.filter((k) => d.data.some((r: Doc) => r.key === k)));
      }
    } catch (e) {
      if (ticket === seq.current) setError((e as Error).message);
    } finally {
      pending.current = false;
      if (ticket === seq.current) setLoading(false);
    }
  }
  async function loadBatches() {
    try {
      const p = new URLSearchParams({ page: String(batchPage) });
      if (applied.merchant !== "all") p.set("merchant", applied.merchant);
      setBatches(await get("/api/admin/eis-center/batches?" + p));
      if (batch?.batch?.status === "pending")
        setBatch(await get("/api/admin/eis-center/batches/" + batch.batch.id));
    } catch (e) {
      setError((e as Error).message);
    }
  }
  async function loadHistory() {
    try {
      const p = new URLSearchParams({
        environment: historyEnv,
        page: String(historyPage),
        per_page: "25",
      });
      ["merchant", "search", "from", "to", "document"].forEach((k) => {
        if (applied[k] && applied[k] !== "all") p.set(k, applied[k]);
      });
      if (historyStatus !== "all") p.set("status", historyStatus);
      setHistory(await get("/api/bir-eis/transmissions?" + p));
    } catch (e) {
      setError((e as Error).message);
    }
  }
  useEffect(() => {
    void load();
    return () => {
      seq.current++;
    };
  }, [applied, page, size]);
  useEffect(() => {
    if (view === "batches") void loadBatches();
    if (view === "history") void loadHistory();
  }, [view, applied, batchPage, historyPage, historyEnv, historyStatus]);
  useRealtimeRefresh(
    async () => {
      await load(true);
      if (view === "batches") await loadBatches();
      if (view === "history") await loadHistory();
    },
    !busy && !review && !detail,
  );
  useEffect(() => {
    if (batch?.batch?.status !== "pending") return;
    const t = setInterval(
      () =>
        void get("/api/admin/eis-center/batches/" + batch.batch.id)
          .then(setBatch)
          .catch((e) => setError(e.message)),
      5000,
    );
    return () => clearInterval(t);
  }, [batch?.batch?.id, batch?.batch?.status]);
  const s = data?.summary || {},
    rows = data?.data || [],
    company = data?.companies.find((c) => c.id === applied.merchant),
    dirty = JSON.stringify(filters) !== JSON.stringify(applied);
  async function preview(scope: string, keys = selection, merchant = applied.merchant) {
    if (merchant === "all") {
      setError("Choose one company and apply filters before preparing a batch.");
      return;
    }
    setError("");
    setBusy(true);
    try {
      setReview(
        await get("/api/admin/eis-center/preview", {
          ...applied,
          merchant,
          scope,
          documents: keys,
        }),
      );
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function commit() {
    if (!review) return;
    setBusy(true);
    setError("");
    try {
      const d = await get("/api/admin/eis-center/batches", {
        preview_id: review.preview_id,
        reason,
      });
      setReview(null);
      setSelection([]);
      setView("batches");
      setBatch(await get("/api/admin/eis-center/batches/" + d.id));
      setNotice(
        "Batch saved. The background worker prepares each document and records its result.",
      );
      await loadBatches();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function show(r: Doc) {
    setBusy(true);
    setError("");
    try {
      setDetail(
        await get(`/api/admin/eis-center/documents/${r.source}/${encodeURIComponent(r.id)}`),
      );
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function exportCsv() {
    setBusy(true);
    try {
      await openReportExport({
        path: "/api/admin/eis-center/export?" + params(applied),
        title: "BIR Transmission Register",
        scope: "All matching Live documents; preparation and BIR acceptance are separate states",
        filters: applied,
      });
    } finally {
      setBusy(false);
    }
  }
  function select(k: string, title: string, items: [string, string][]) {
    return (
      <SearchableSelect
        key={k}
        label={title}
        value={filters[k]}
        onChange={(v) => update(k, v)}
        items={items.map(([value, label]) => ({ value, label }))}
        required={false}
      />
    );
  }
  function apply() {
    setApplied({ ...filters });
    setPage(1);
    setHistoryPage(1);
    setBatchPage(1);
    setSelection([]);
    setError("");
  }
  const chips = Object.entries(applied).filter(([k, v]) => v && v !== defaults[k]);
  return (
    <main className="eisc-page">
      <div className="eisc-crumb">
        <Link to="/super-admin">Dashboard</Link>
        <ChevronRight size={13} />
        BIR Transmission Center<span>NUERS · Version 1.0.0</span>
      </div>
      <header className="eisc-heading">
        <div className="eisc-heading-icon">
          <Send size={29} />
        </div>
        <div>
          <h1>BIR Transmission Center</h1>
          <p>
            Prepare company documents, manage delivery batches and review BIR response evidence.
          </p>
        </div>
        <div className="eisc-heading-actions">
          <span className="eisc-environment">
            <ShieldCheck size={15} />
            Live document workspace
          </span>
          <button onClick={() => void load()} disabled={loading}>
            <RefreshCw size={16} />
            Refresh
          </button>
        </div>
      </header>
      {error && (
        <div className="eisc-message error" role="alert">
          {error}
          <button onClick={() => setError("")} aria-label="Dismiss error">
            <X size={16} />
          </button>
        </div>
      )}
      {notice && (
        <div className="eisc-message" role="status">
          {notice}
          <button onClick={() => setNotice("")} aria-label="Dismiss notification">
            <X size={16} />
          </button>
        </div>
      )}
      <section className="eisc-gateway">
        <span className="eisc-gateway-icon">
          <LockKeyhole size={22} />
        </span>
        <div>
          <strong>
            {data?.gateway.transport_ready
              ? "BIR adapter connected"
              : "Production connection pending"}
          </strong>
          <p>
            Documents can be validated and prepared now. Actual delivery requires verified BIR
            access, company authority and the official interface.
          </p>
        </div>
        <Link to="/super-admin/api-platform?view=bir">
          BIR setup & submission
          <ArrowRight size={15} />
        </Link>
        <details>
          <summary>Readiness checks</summary>
          <ul>
            {(data?.gateway.blockers || []).map((b) => (
              <li key={b}>{b}</li>
            ))}
          </ul>
        </details>
      </section>
      <div className="eisc-metrics">
        {[
          [
            Layers3,
            "Document records",
            count(s.total),
            money(s.amount) + " recorded amount",
            "blue",
          ],
          [
            FileCheck2,
            "Prepared locally",
            count(s.prepared),
            "Waiting for authorized delivery",
            "blue",
          ],
          [Clock3, "In delivery queue", count(s.queued), "Queued, sending or retrying", "gold"],
          [
            CheckCircle2,
            "BIR accepted",
            count(s.accepted),
            "Authenticated production result",
            "green",
          ],
          [
            AlertTriangle,
            "Needs attention",
            count(Number(s.needs_review || 0) + Number(s.attention || 0)),
            `${count(s.unprepared)} not yet prepared`,
            "red",
          ],
        ].map(([Icon, title, value, hint, color]) => {
          const I = Icon as typeof Send;
          return (
            <article key={String(title)}>
              <span className={"eisc-metric-icon " + color}>
                <I size={23} />
              </span>
              <div>
                <h2>{String(title)}</h2>
                <strong>{data ? String(value) : "—"}</strong>
                <small>{String(hint)}</small>
              </div>
            </article>
          );
        })}
      </div>
      <form
        className="eisc-panel eisc-filters"
        onSubmit={(e) => {
          e.preventDefault();
          apply();
        }}
      >
        <header>
          <h2>
            <SlidersHorizontal size={18} />
            Company & document filters
          </h2>
          <span>Totals and CSV follow the applied document filters</span>
        </header>
        <div className="eisc-filter-grid">
          {select("merchant", "Company", [
            ["all", "All companies"],
            ...(data?.companies || []).map(
              (c) => [c.id, `${c.business_name} · ${formatTin(c.tin)}`] as [string, string],
            ),
          ])}
          <label className="eisc-search">
            <span>Search documents</span>
            <div>
              <Search size={16} />
              <input
                value={filters.search}
                onChange={(e) => update("search", e.target.value)}
                placeholder="Document, TIN, transaction or BIR reference"
                maxLength={160}
              />
            </div>
          </label>
          <label>
            <span>From date</span>
            <input
              type="date"
              value={filters.from}
              onChange={(e) => update("from", e.target.value)}
            />
          </label>
          <label>
            <span>To date</span>
            <input
              type="date"
              min={filters.from}
              value={filters.to}
              onChange={(e) => update("to", e.target.value)}
            />
          </label>
          {select("date_basis", "Date basis", [
            ["document", "Original document date"],
            ["received", "Received by NUERS (PHT)"],
          ])}
          {select("status", "Preparation / delivery status", [
            ["all", "All statuses"],
            ...Object.entries(states).filter(
              ([k]) => !["pending", "completed", "failed"].includes(k),
            ),
          ])}
        </div>
        {advanced && (
          <div className="eisc-filter-grid advanced">
            {select("document", "Document type", [
              ["all", "All document types"],
              ...(data?.options.documents || []).map((d) => [d, label(d)] as [string, string]),
            ])}
            {select("sale_category", "Sale category", [
              ["all", "All sale categories"],
              ...Object.entries(SALE_CATEGORIES),
            ])}
            {select("source", "Document source", [
              ["all", "All sources"],
              ["invoice", "NUERS invoice registry"],
              ["receipt", "Receipt / API document registry"],
            ])}
            {select("deadline", "Reporting deadline", [
              ["all", "All deadlines"],
              ["overdue", "Past calculated deadline"],
              ["due_today", "Due today (PHT)"],
              ["upcoming", "Upcoming"],
            ])}
            {(["branch", "rdo", "region"] as const).map((k) => (
              <label key={k}>
                <span>
                  {k === "branch" ? "BIR branch code" : k === "rdo" ? "RDO code" : "Region"}
                </span>
                <input
                  value={filters[k]}
                  onChange={(e) => update(k, e.target.value)}
                  placeholder={
                    k === "branch"
                      ? "e.g. 00000"
                      : k === "rdo"
                        ? "e.g. 027"
                        : "Exact recorded region"
                  }
                  maxLength={k === "region" ? 120 : 20}
                />
              </label>
            ))}
            <label>
              <span>Minimum amount (₱)</span>
              <input
                type="number"
                min="0"
                step="0.01"
                value={filters.min}
                onChange={(e) => update("min", e.target.value)}
              />
            </label>
            <label>
              <span>Maximum amount (₱)</span>
              <input
                type="number"
                min={filters.min || "0"}
                step="0.01"
                value={filters.max}
                onChange={(e) => update("max", e.target.value)}
              />
            </label>
            {select("sort", "Sort records", [
              ["newest", "Newest first"],
              ["oldest", "Oldest first"],
              ["amount_desc", "Highest amount"],
              ["amount_asc", "Lowest amount"],
            ])}
          </div>
        )}
        <footer>
          <button type="button" onClick={() => setAdvanced(!advanced)} aria-expanded={advanced}>
            <SlidersHorizontal size={15} />
            {advanced ? "Fewer filters" : "Advanced filters"}
          </button>
          <span>
            {dirty
              ? "Unapplied changes — apply filters to update results."
              : "Choose one company to prepare or release a batch."}
          </span>
          <button
            type="button"
            onClick={() => {
              setFilters(defaults);
              setApplied(defaults);
              setSelection([]);
              setPage(1);
              setHistoryPage(1);
              setBatchPage(1);
            }}
          >
            Reset
          </button>
          <button className="eisc-primary" disabled={loading}>
            <Search size={16} />
            Apply filters
          </button>
        </footer>
      </form>
      {!!chips.length && (
        <div className="eisc-chips">
          {chips.map(([k, v]) => (
            <button
              key={k}
              onClick={() => {
                setFilters((f) => ({ ...f, [k]: defaults[k] }));
                setApplied((f) => ({ ...f, [k]: defaults[k] }));
                setPage(1);
                setSelection([]);
              }}
            >
              {label(k)}: {k === "merchant" ? company?.business_name : states[v] || v}
              <X size={12} />
            </button>
          ))}
        </div>
      )}
      <nav className="eisc-tabs" aria-label="Transmission workspace sections">
        {[
          ["documents", "Documents & readiness", FileCheck2],
          ["batches", "Company batches", Building2],
          ["history", "Transmission history", Clock3],
        ].map(([id, title, Icon]) => {
          const I = Icon as typeof Send;
          return (
            <button key={String(id)} aria-pressed={view === id} onClick={() => setView(String(id))}>
              <I size={17} />
              {String(title)}
            </button>
          );
        })}
      </nav>
      {view === "documents" && (
        <section className="eisc-panel eisc-register">
          <header>
            <div>
              <h2>{company ? company.business_name : "All company documents"}</h2>
              <p>
                {count(data?.total)} matching records ·{" "}
                {company ? formatTin(company.tin) : "Select a company for bulk preparation"}
              </p>
            </div>
            <div className="eisc-actions">
              <button disabled={!data || busy} onClick={() => void exportCsv()}>
                <Download size={16} />
                Export PDF / CSV
              </button>
              <button
                className="eisc-primary"
                disabled={
                  busy ||
                  loading ||
                  !data?.can_manage ||
                  !company ||
                  dirty ||
                  (!selection.length && (!data?.total || data.total > 100))
                }
                title={
                  !company
                    ? "Apply a company filter first"
                    : dirty
                      ? "Apply edited filters first"
                      : "Review up to 100 documents"
                }
                onClick={() => void preview(selection.length ? "selected" : "filtered")}
              >
                <Layers3 size={17} />
                {busy
                  ? "Working…"
                  : selection.length
                    ? `Review ${selection.length} selected`
                    : "Prepare company batch"}
              </button>
            </div>
          </header>
          {!!selection.length && (
            <div className="eisc-selection">
              <b>{selection.length} documents selected on this page</b>
              <span>Selection is limited to one company per batch.</span>
              <button onClick={() => setSelection([])}>Clear selection</button>
            </div>
          )}
          <div className="eisc-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>
                    <input
                      type="checkbox"
                      aria-label="Select all documents on this page"
                      checked={!!rows.length && selection.length === rows.length}
                      onChange={(e) => setSelection(e.target.checked ? rows.map((r) => r.key) : [])}
                    />
                  </th>
                  <th>Document / company</th>
                  <th>Document date / deadline</th>
                  <th>Type / branch</th>
                  <th className="numeric">Amount (₱)</th>
                  <th>Preparation / delivery</th>
                  <th>Attempts / reference</th>
                  <th className="eisc-actions-cell">Actions</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((r) => (
                  <tr key={r.key} className={selection.includes(r.key) ? "selected" : ""}>
                    <td>
                      <input
                        type="checkbox"
                        aria-label={`Select ${r.number}`}
                        checked={selection.includes(r.key)}
                        onChange={(e) =>
                          setSelection((s) =>
                            e.target.checked ? [...s, r.key] : s.filter((k) => k !== r.key),
                          )
                        }
                      />
                    </td>
                    <td>
                      <button className="eisc-doc-link" onClick={() => void show(r)}>
                        {r.number || r.id}
                      </button>
                      <strong>{r.business}</strong>
                      <small>{formatTin(r.tin)}</small>
                    </td>
                    <td>
                      {r.business_date || "Date missing"}
                      <small
                        className={
                          r.due_at && new Date(r.due_at) < new Date() && r.status !== "accepted"
                            ? "eisc-warning"
                            : ""
                        }
                      >
                        Due {time(r.due_at)}
                      </small>
                    </td>
                    <td>
                      {label(r.document_type)}
                      <small>{saleLabel(r.sale_category)}</small>
                      <small>
                        Branch {r.branch || "Unverified"} · RDO {r.rdo || "—"}
                      </small>
                    </td>
                    <td className="numeric">
                      <strong>{money(r.amount)}</strong>
                      <small>VAT {money(r.vat_amount)}</small>
                    </td>
                    <td>
                      <Status value={r.status} />
                      <small className="eisc-reason" title={r.last_error || r.blockers[0]}>
                        {r.last_error || r.blockers[0] || "Open details to validate the source."}
                      </small>
                      {r.identity_count > 1 && (
                        <small className="eisc-warning">
                          Multiple source identities · review required
                        </small>
                      )}
                    </td>
                    <td>
                      {r.attempts} delivery attempts
                      <small>{r.provider_reference || "No BIR reference"}</small>
                    </td>
                    <td className="eisc-actions-cell">
                      <button
                        onClick={() => void show(r)}
                        disabled={busy}
                        aria-label={`Details for ${r.number}`}
                      >
                        <Eye size={15} />
                        Details
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!rows.length && (
              <div className="eisc-empty">
                <FileCheck2 size={32} />
                <h3>{loading ? "Loading documents…" : "No documents match these filters"}</h3>
                <p>Issued merchant documents appear here for validation and preparation.</p>
              </div>
            )}
          </div>
          <footer className="eisc-pagination" data-slot="table-pagination">
            <span>
              {data?.total
                ? `${count((page - 1) * Number(size) + 1)}–${count(Math.min(page * Number(size), data.total))} of ${count(data.total)} documents`
                : "0 documents"}
            </span>
            <label>
              Rows
              <select
                value={size}
                onChange={(e) => {
                  setSize(e.target.value);
                  setPage(1);
                  setSelection([]);
                }}
              >
                {["10", "25", "50", "100"].map((n) => (
                  <option key={n}>{n}</option>
                ))}
              </select>
            </label>
            <button
              aria-label="Previous documents page"
              disabled={page <= 1 || loading}
              onClick={() => {
                setPage((p) => p - 1);
                setSelection([]);
              }}
            >
              <ChevronLeft size={16} />
            </button>
            <b>
              {page} / {data?.last_page || 1}
            </b>
            <button
              aria-label="Next documents page"
              disabled={page >= (data?.last_page || 1) || loading}
              onClick={() => {
                setPage((p) => p + 1);
                setSelection([]);
              }}
            >
              <ChevronRight size={16} />
            </button>
          </footer>
        </section>
      )}
      {view === "batches" && (
        <section className="eisc-panel eisc-register">
          <header>
            <div>
              <h2>Company preparation batches</h2>
              <p>
                Durable preparation requests · each document has its own outcome · company filter
                applies
              </p>
            </div>
            <button onClick={() => void loadBatches()}>
              <RefreshCw size={16} />
              Refresh batches
            </button>
          </header>
          <div className="eisc-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Batch / company</th>
                  <th>Created (PHT)</th>
                  <th>Documents</th>
                  <th>Status</th>
                  <th>Purpose</th>
                  <th className="eisc-actions-cell">Actions</th>
                </tr>
              </thead>
              <tbody>
                {batches.data.map((b) => (
                  <tr key={b.id}>
                    <td>
                      <strong>{b.business_name}</strong>
                      <small>{b.id}</small>
                    </td>
                    <td>{time(b.created_at)}</td>
                    <td>{b.document_count}</td>
                    <td>
                      <Status value={b.status} />
                    </td>
                    <td>{b.reason}</td>
                    <td className="eisc-actions-cell">
                      <button
                        onClick={() =>
                          void get("/api/admin/eis-center/batches/" + b.id)
                            .then(setBatch)
                            .catch((e) => setError(e.message))
                        }
                      >
                        <Eye size={15} />
                        Results
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!batches.data.length && (
              <div className="eisc-empty">
                <Layers3 size={30} />
                <h3>No preparation batches yet</h3>
                <p>Choose a company in Documents & readiness, then review a selection.</p>
              </div>
            )}
          </div>
          <footer className="eisc-pagination">
            <span>Batch history</span>
            <button disabled={batchPage === 1} onClick={() => setBatchPage((p) => p - 1)}>
              <ChevronLeft size={16} />
            </button>
            <b>
              {batchPage} / {batches.last_page || 1}
            </b>
            <button
              disabled={batchPage >= batches.last_page}
              onClick={() => setBatchPage((p) => p + 1)}
            >
              <ChevronRight size={16} />
            </button>
          </footer>
        </section>
      )}
      {view === "history" && (
        <section className="eisc-panel eisc-register">
          <header>
            <div>
              <h2>Recorded transmission history</h2>
              <p>
                Company, search, date range and document-type filters apply. Dates refer to
                transmission creation.
              </p>
            </div>
            <div className="eisc-actions">
              <label>
                Environment
                <select
                  value={historyEnv}
                  onChange={(e) => {
                    setHistoryEnv(e.target.value);
                    setHistoryPage(1);
                  }}
                >
                  <option value="production">Production</option>
                  <option value="certification">Certification tests</option>
                </select>
              </label>
              <label>
                Status
                <select
                  value={historyStatus}
                  onChange={(e) => {
                    setHistoryStatus(e.target.value);
                    setHistoryPage(1);
                  }}
                >
                  <option value="all">All statuses</option>
                  {[
                    "queued",
                    "submitting",
                    "submitted",
                    "acknowledged",
                    "failed",
                    "rejected",
                    "duplicate",
                  ].map((v) => (
                    <option key={v} value={v}>
                      {label(v)}
                    </option>
                  ))}
                </select>
              </label>
            </div>
          </header>
          {historyEnv === "certification" && (
            <div className="eisc-message">
              Certification test acknowledgments are simulations and do not count as BIR production
              acceptance.
            </div>
          )}
          <div className="eisc-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Document / company</th>
                  <th>Created (PHT)</th>
                  <th>Status / environment</th>
                  <th>Attempt</th>
                  <th>BIR reference</th>
                  <th>Response</th>
                </tr>
              </thead>
              <tbody>
                {(history?.transmissions || []).map((r: any) => (
                  <tr key={r.id}>
                    <td>
                      <strong>{r.invoice_number || r.id}</strong>
                      <small>{r.merchant_name || "Synthetic certification record"}</small>
                    </td>
                    <td>{time(r.created_at)}</td>
                    <td>
                      <Status
                        value={r.status === "acknowledged" ? "acknowledgment_recorded" : r.status}
                      />
                      <small>{r.environment}</small>
                    </td>
                    <td>
                      {r.attempt_number} / {r.max_attempts}
                    </td>
                    <td>{r.bir_reference_number || "—"}</td>
                    <td>
                      {r.response_code}
                      <small>{r.response_message}</small>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            {!history?.transmissions?.length && (
              <div className="eisc-empty">
                <Send size={30} />
                <h3>No {historyEnv} transmissions recorded</h3>
                <p>
                  Prepared documents are shown in the document workspace until actual transmission
                  occurs.
                </p>
              </div>
            )}
          </div>
          <footer className="eisc-pagination">
            <span>{count(history?.total)} transmission records</span>
            <button disabled={historyPage === 1} onClick={() => setHistoryPage((p) => p - 1)}>
              <ChevronLeft size={16} />
            </button>
            <b>
              {historyPage} / {history?.last_page || 1}
            </b>
            <button
              disabled={historyPage >= (history?.last_page || 1)}
              onClick={() => setHistoryPage((p) => p + 1)}
            >
              <ChevronRight size={16} />
            </button>
          </footer>
        </section>
      )}
      <div className="eisc-footnote">
        <span>
          <RefreshCw size={13} />
          Updated {time(data?.generated_at)} · automatic updates
        </span>
        <span>
          Deadlines are calculated indicators; confirm applicable coverage and BIR advisories.
        </span>
      </div>
      <Dialog
        open={!!review}
        onOpenChange={(v) => {
          if (!busy && !v) setReview(null);
        }}
      >
        <DialogContent className="eisc-dialog">
          <DialogHeader>
            <DialogTitle>Review company batch</DialogTitle>
            <DialogDescription>
              The reviewed document identities are frozen for 10 minutes. The worker revalidates
              each snapshot before preparing it.
            </DialogDescription>
          </DialogHeader>
          {error && <p className="eisc-message error">{error}</p>}
          {review && (
            <>
              <div className="eisc-review-summary">
                <b>{review.total} documents</b>
                <b>{money(review.amount)}</b>
                <span>
                  {review.valid} pass local validation · {review.total - review.valid} need review
                </span>
              </div>
              <div className="eisc-message">
                <LockKeyhole size={17} />
                This saves a local preparation batch. It does not claim submission or BIR
                acceptance.
              </div>
              <div className="eisc-review-list">
                {review.documents.map((d) => (
                  <article key={d.source + d.id}>
                    <header>
                      <strong>{d.number}</strong>
                      <b>{money(d.amount)}</b>
                      <span
                        className={
                          "eisc-status " + (d.valid ? "validation_passed" : "needs_review")
                        }
                      >
                        {d.valid ? "Local checks passed" : "Needs source review"}
                      </span>
                    </header>
                    <ul>
                      {d.blockers.map((b) => (
                        <li key={b}>{b}</li>
                      ))}
                    </ul>
                  </article>
                ))}
              </div>
              <label>
                Batch purpose / audit note
                <textarea
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  minLength={10}
                  maxLength={1000}
                />
              </label>
              <footer>
                <button disabled={busy} onClick={() => setReview(null)}>
                  Cancel
                </button>
                <button
                  className="eisc-primary"
                  disabled={busy || reason.trim().length < 10}
                  onClick={() => void commit()}
                >
                  <Layers3 size={17} />
                  {busy ? "Saving…" : "Create preparation batch"}
                </button>
              </footer>
            </>
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!detail}
        onOpenChange={(v) => {
          if (!v) setDetail(null);
        }}
      >
        <DialogContent className="eisc-dialog">
          <DialogHeader>
            <DialogTitle>{detail?.document.number || "Document readiness"}</DialogTitle>
            <DialogDescription>
              {detail?.document.business} · {formatTin(detail?.document.tin || "")} · Live document
            </DialogDescription>
          </DialogHeader>
          {detail && (
            <>
              <div className="eisc-review-summary">
                <Status value={detail.document.status} />
                <b>{money(detail.document.amount)}</b>
                <span>
                  {detail.validation.local_validation_passed
                    ? "Local arithmetic & source checks passed"
                    : "Source validation needs review"}
                </span>
              </div>
              <h3>Validation & delivery blockers</h3>
              <ul className="eisc-blockers">
                {detail.validation.blockers.map((b: string) => (
                  <li key={b}>
                    <AlertTriangle size={15} />
                    {b}
                  </li>
                ))}
              </ul>
              <div className="eisc-detail-grid">
                {[
                  ["Reporting coverage", label(detail.validation.submission_applicability)],
                  ["Document date", detail.document.business_date],
                  ["Calculated deadline", time(detail.document.due_at)],
                  ["Prepared at", time(detail.document.prepared_at)],
                  ["Delivery attempts", detail.document.attempts],
                  ["Provider reference", detail.document.provider_reference || "Not received"],
                  ["Outbox record", detail.document.outbox_id || "Not prepared"],
                  ["Document SHA-256", detail.validation.document_hash],
                ].map(([k, v]) => (
                  <div key={String(k)}>
                    <small>{k}</small>
                    <strong>{v}</strong>
                  </div>
                ))}
              </div>
              <h3>Delivery / inquiry attempts</h3>
              {!detail.attempts.length ? (
                <p>No outbound delivery or inquiry attempts recorded.</p>
              ) : (
                <div className="eisc-review-list">
                  {detail.attempts.map((a: any) => (
                    <p key={a.id}>
                      {label(a.operation)} · {label(a.outcome)} ·{" "}
                      {a.authenticated ? "Authenticated response" : "Unverified / no response"} ·{" "}
                      {time(a.started_at)}
                    </p>
                  ))}
                </div>
              )}
              <footer>
                <Link to="/super-admin/reconciliation">
                  Open reconciliation
                  <ArrowRight size={14} />
                </Link>
                <button
                  className="eisc-primary"
                  disabled={busy || !data?.can_manage}
                  onClick={() => {
                    const d = detail.document;
                    setDetail(null);
                    void preview("selected", [d.key], d.merchant_id);
                  }}
                >
                  Review preparation
                </button>
              </footer>
            </>
          )}
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!batch}
        onOpenChange={(v) => {
          if (!v) setBatch(null);
        }}
      >
        <DialogContent className="eisc-dialog">
          <DialogHeader>
            <DialogTitle>Batch preparation results</DialogTitle>
            <DialogDescription>
              {batch?.batch.business_name} · {batch?.batch.id}
            </DialogDescription>
          </DialogHeader>
          {error && (
            <p className="eisc-message error" role="alert">
              {error}
            </p>
          )}
          {batch && (
            <>
              <div className="eisc-review-summary">
                <Status value={batch.batch.status} />
                <span>{batch.batch.document_count} documents</span>
                <span>Worker runs every minute</span>
              </div>
              <div className="eisc-review-list">
                {batch.items.map((i: any) => (
                  <article key={i.id}>
                    <header>
                      <b>{i.document_number}</b>
                      <Status value={i.status} />
                    </header>
                    <p>{i.message || "Waiting for local preparation."}</p>
                  </article>
                ))}
              </div>
              <div className="eisc-message">
                {data?.gateway.can_send
                  ? "Release only after reviewing the complete batch. Server-side company and document gates are checked again."
                  : "These are local preparation results. Open BIR setup & submission to map the official format, select the company connection and review a wire batch."}
              </div>
              <footer>
                <button
                  onClick={() =>
                    void get("/api/admin/eis-center/batches/" + batch.batch.id)
                      .then(setBatch)
                      .catch((e) => setError(e.message))
                  }
                >
                  <RefreshCw size={16} />
                  Refresh results
                </button>
                <Link className="eisc-primary" to="/super-admin/api-platform?view=bir">
                  <Send size={16} />
                  BIR setup & submission
                </Link>
              </footer>
            </>
          )}
        </DialogContent>
      </Dialog>
    </main>
  );
}
