import { useEffect, useRef, useState, type FormEvent } from "react";
import { Link } from "react-router-dom";
import {
  FileCheck2,
  Plus,
  RefreshCw,
  Download,
  ExternalLink,
  ShieldCheck,
  ArrowRight,
  Upload,
  Search,
  Clock3,
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiFetch, apiJson, readJsonResponse } from "@/lib/api-url";
import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import "./applications.css";
type Form = {
  application_type: string;
  previous_reference: string;
  system_name: string;
  system_version: string;
  system_provider: string;
  system_type: string;
  scope: string;
  branches: string[];
  representative: string;
  contact_email: string;
  contact_phone: string;
  office: string;
  notes: string;
};
type Business = {
  id: string;
  business_name: string;
  tin: string;
  rdo_code: string;
  address: string;
  owner_name: string;
  email: string;
  phone: string;
  status?: string;
};
type AppRecord = {
  id: string;
  reference: string;
  merchant_id: string;
  business_name?: string;
  tin?: string;
  status: string;
  status_label: string;
  revision: number;
  created_at: string;
  updated_at: string;
  authorization_id?: string;
  payload: {
    identity: Business;
    form: Form;
    filing?: Record<string, string>;
    permit?: Record<string, string>;
  };
};
type Document = {
  id: string;
  kind: string;
  label: string;
  filename: string;
  bytes: number;
  sha256: string;
  created_at: string;
};
type Detail = {
  record: AppRecord;
  documents: Document[];
  history: { event: string; created_at: string; revision: number; details: { note: string } }[];
};
type Catalog = {
  statuses: Record<string, string>;
  document_kinds: Record<string, string>;
  review_documents: string[];
  source_url: string;
  filing_notice: string;
  checklist_notice: string;
};
type List = {
  data: AppRecord[];
  total: number;
  page: number;
  last_page: number;
  summary: Record<string, number>;
  is_admin: boolean;
  merchant: Business | null;
  catalog: Catalog;
};
const fresh: Form = {
  application_type: "new",
  previous_reference: "",
  system_name: "NUERS",
  system_version: "1.0.0",
  system_provider: "NUERS",
  system_type: "nuers",
  scope: "",
  branches: ["00000"],
  representative: "",
  contact_email: "",
  contact_phone: "",
  office: "",
  notes: "",
};
const nextSteps: Record<string, string[]> = {
  draft: ["in_review", "withdrawn"],
  needs_changes: ["in_review", "withdrawn"],
  in_review: ["ready_to_file", "needs_changes", "withdrawn"],
  ready_to_file: ["filed", "needs_changes", "withdrawn"],
  filed: ["issued", "declined"],
};
const actionLabels: Record<string, string> = {
  in_review: "Send to NUERS preparation review",
  ready_to_file: "Mark ready for RDO / LT filing",
  needs_changes: "Request corrections",
  filed: "Record BIR filing evidence",
  issued: "Record BIR-issued PTI",
  declined: "Record BIR decision: not approved",
  withdrawn: "Withdraw in NUERS",
};
const stamp = (v: string) =>
  new Date(v.includes("T") ? v : v.replace(" ", "T") + "+08:00").toLocaleString("en-PH", {
    dateStyle: "medium",
    timeStyle: "short",
    timeZone: "Asia/Manila",
  });
const send = (method: string, body: unknown) => ({ method, body: JSON.stringify(body) });
export function PtiApplications({ merchant = false }: { merchant?: boolean }) {
  const [data, setData] = useState<List | null>(null),
    [detail, setDetail] = useState<Detail | null>(null),
    [opened, setOpened] = useState(false),
    [creating, setCreating] = useState(false),
    [editing, setEditing] = useState(false),
    [loading, setLoading] = useState(true),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [page, setPage] = useState(1),
    [search, setSearch] = useState(""),
    [applied, setApplied] = useState(""),
    [status, setStatus] = useState("all");
  const [form, setForm] = useState<Form>(fresh),
    [branchText, setBranchText] = useState("00000"),
    [businessId, setBusinessId] = useState(""),
    [businesses, setBusinesses] = useState<Business[]>([]),
    [businessSearch, setBusinessSearch] = useState(""),
    [docKind, setDocKind] = useState("registration"),
    [file, setFile] = useState<File | null>(null),
    [action, setAction] = useState(""),
    [note, setNote] = useState(""),
    [confirmed, setConfirmed] = useState(false),
    [proof, setProof] = useState("");
  const [official, setOfficial] = useState({
    filing_reference: "",
    filed_on: "",
    receiving_office: "",
    permit_reference: "",
    issued_at: "",
    valid_until: "",
    branch_code: "00000",
  });
  const sequence = useRef(0),
    requestId = useRef(crypto.randomUUID()),
    detailSequence = useRef(0);
  async function load(quiet = false) {
    const n = ++sequence.current;
    if (!quiet) setLoading(true);
    try {
      const d = await apiJson<List>(
        "/api/pti-applications?" +
          new URLSearchParams({ page: String(page), status, search: applied }),
      );
      if (n === sequence.current) {
        setData(d);
        setError("");
      }
    } catch (e) {
      if (n === sequence.current) setError((e as Error).message);
    } finally {
      if (n === sequence.current) setLoading(false);
    }
  }
  useEffect(() => {
    void load();
    return () => {
      sequence.current++;
    };
  }, [page, applied, status]);
  useRealtimeRefresh(() => load(true), !opened && !busy);
  useEffect(() => {
    if (!creating || !data?.is_admin) return;
    const c = new AbortController();
    const timer = setTimeout(() => {
      void apiJson<{ data: Business[] }>(
        "/api/pti-applications/businesses?search=" + encodeURIComponent(businessSearch),
        { signal: c.signal },
      )
        .then((v) => setBusinesses(v.data))
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    }, 250);
    return () => {
      clearTimeout(timer);
      c.abort();
    };
  }, [creating, businessSearch, data?.is_admin]);
  function chooseBusiness(m?: Business) {
    setBusinessId(m?.id || "");
    setForm((f) => ({
      ...f,
      representative: m?.owner_name || "",
      contact_email: m?.email || "",
      contact_phone: m?.phone || "",
      office: m?.rdo_code ? "RDO " + m.rdo_code : "",
    }));
  }
  function start() {
    requestId.current = crypto.randomUUID();
    setError("");
    setMessage("");
    setDetail(null);
    setForm({ ...fresh });
    setBranchText("00000");
    setCreating(true);
    setEditing(true);
    setOpened(true);
    setAction("");
    setBusinessSearch("");
    setBusinesses([]);
    chooseBusiness(data?.merchant || undefined);
  }
  async function inspect(id: string) {
    const n = ++detailSequence.current;
    setError("");
    setMessage("");
    setOpened(true);
    setCreating(false);
    setEditing(false);
    setDetail(null);
    setAction("");
    setFile(null);
    try {
      const v = await apiJson<Detail>("/api/pti-applications/" + id);
      if (n === detailSequence.current) setDetail(v);
    } catch (e) {
      if (n === detailSequence.current) setError((e as Error).message);
    }
  }
  async function refreshDetail() {
    if (detail) setDetail(await apiJson<Detail>("/api/pti-applications/" + detail.record.id));
  }
  function close() {
    if (busy) return;
    if ((editing || action) && !window.confirm("Close without saving these changes?")) return;
    detailSequence.current++;
    setOpened(false);
    setEditing(false);
    setAction("");
    setError("");
    setMessage("");
  }
  async function run(fn: () => Promise<void>) {
    setBusy(true);
    setError("");
    try {
      await fn();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function save(e: FormEvent) {
    e.preventDefault();
    await run(async () => {
      const body = {
        ...form,
        branches: branchText
          .split(",")
          .map((s) => s.trim())
          .filter(Boolean),
      };
      const result = await apiJson<{ record: AppRecord }>(
        "/api/pti-applications" + (creating ? "" : "/" + detail?.record.id),
        send(creating ? "POST" : "PUT", {
          ...body,
          ...(creating
            ? {
                request_id: requestId.current,
                ...(data?.is_admin ? { merchant_id: businessId } : {}),
              }
            : { revision: detail?.record.revision }),
        }),
      );
      setCreating(false);
      setEditing(false);
      setDetail(await apiJson<Detail>("/api/pti-applications/" + result.record.id));
      setMessage("Application saved in NUERS. Upload the preparation documents below.");
      void load(true);
    });
  }
  async function upload() {
    if (!detail || !file) return;
    await run(async () => {
      const body = new FormData();
      body.set("kind", docKind);
      body.set("file", file);
      body.set("revision", String(detail.record.revision));
      await apiJson("/api/pti-applications/" + detail.record.id + "/documents", {
        method: "POST",
        body,
      });
      setFile(null);
      await refreshDetail();
      setMessage("Supporting document stored securely.");
      void load(true);
    });
  }
  async function changeStatus() {
    if (!detail) return;
    await run(async () => {
      await apiJson(
        "/api/pti-applications/" + detail.record.id + "/status",
        send("POST", {
          status: action,
          note,
          confirmed,
          revision: detail.record.revision,
          evidence_id: proof || null,
          ...Object.fromEntries(Object.entries(official).map(([k, v]) => [k, v || null])),
        }),
      );
      setAction("");
      setConfirmed(false);
      await refreshDetail();
      setMessage("Application status updated with an audit record.");
      void load(true);
    });
  }
  async function download(path: string, name: string) {
    await run(async () => {
      const r = await apiFetch(path);
      if (!r.ok) await readJsonResponse(r);
      const url = URL.createObjectURL(await r.blob()),
        a = document.createElement("a");
      a.href = url;
      a.download = name;
      a.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
  }
  function field(key: keyof Form, label: string, type = "text", required = true) {
    return (
      <label>
        <span>
          {label}
          {required ? " *" : ""}
        </span>
        <input
          required={required}
          type={type}
          value={String(form[key])}
          maxLength={key === "contact_email" ? 254 : 160}
          onChange={(e) => setForm((f) => ({ ...f, [key]: e.target.value }))}
        />
      </label>
    );
  }
  const admin = !!data?.is_admin,
    record = detail?.record,
    catalog = data?.catalog,
    editable = record && ["draft", "needs_changes"].includes(record.status),
    allowed = record
      ? (nextSteps[record.status] || []).filter(
          (x) => admin || ["in_review", "withdrawn"].includes(x),
        )
      : [];
  const canUpload =
    editable ||
    (admin && record && ["in_review", "ready_to_file", "filed"].includes(record.status));
  const evidenceKind =
    action === "filed" ? "bir_receipt" : action === "issued" ? "bir_permit" : "bir_decision";
  return (
    <div className="pa-page">
      <header className="pa-heading">
        <div className="pa-heading-icon">
          <FileCheck2 size={29} />
        </div>
        <div>
          <div className="pa-eyebrow">
            {merchant ? "MERCHANT SERVICES" : "TAXPAYER MANAGEMENT"} · NUERS 1.0.0
          </div>
          <h1>PTI Applications</h1>
          <p>Prepare, coordinate and track your Permit to Issue application.</p>
        </div>
        <button className="pa-primary" disabled={!data || busy} onClick={start}>
          <Plus size={17} />
          Start PTI Application
        </button>
      </header>
      <div className="pa-channel">
        <ShieldCheck size={22} />
        <div>
          <strong>Filing channel: registered RDO / LT Office</strong>
          <p>
            {catalog?.filing_notice ||
              "Prepare your application in NUERS, then file through your registered RDO / LT Office."}
          </p>
          <a
            href={catalog?.source_url || "https://www.bir.gov.ph/"}
            target="_blank"
            rel="noopener noreferrer"
          >
            Official PTI guidance <ExternalLink size={13} />
          </a>
          <a
            href="https://eis-cert.bir.gov.ph/#/apiGuide"
            target="_blank"
            rel="noopener noreferrer"
          >
            EIS Certification API Guide <ExternalLink size={13} />
          </a>
        </div>
        <span>
          Direct PTI API
          <br />
          <b>Not connected</b>
        </span>
      </div>
      <div className="pa-steps">
        {[
          "Prepare application",
          "NUERS preparation review",
          "File at RDO / LT Office",
          "Record BIR-issued PTI",
        ].map((x, i) => (
          <div key={x}>
            <b>{i + 1}</b>
            <span>{x}</span>
          </div>
        ))}
      </div>
      <div className="pa-metrics">
        {[
          ["Drafts", "draft"],
          ["Preparation review", "in_review"],
          ["Ready for filing", "ready_to_file"],
          ["BIR filing recorded", "filed"],
          ["PTIs recorded", "issued"],
        ].map(([label, key]) => (
          <div key={key}>
            <small>{label}</small>
            <strong>{data ? Number(data.summary[key] || 0).toLocaleString() : "—"}</strong>
          </div>
        ))}
      </div>
      {error && !opened && (
        <p className="pa-error" role="alert">
          {error}
        </p>
      )}
      {message && !opened && (
        <p className="pa-success" role="status">
          {message}
        </p>
      )}
      <section className="pa-card">
        <form
          className="pa-filters"
          onSubmit={(e) => {
            e.preventDefault();
            setApplied(search);
            setPage(1);
          }}
        >
          <label>
            <span>Search applications</span>
            <input
              placeholder="Business name, TIN or application ID"
              value={search}
              maxLength={120}
              onChange={(e) => setSearch(e.target.value)}
            />
          </label>
          <label>
            <span>Application status</span>
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="all">All statuses</option>
              {Object.entries(catalog?.statuses || {}).map(([v, l]) => (
                <option key={v} value={v}>
                  {l}
                </option>
              ))}
            </select>
          </label>
          <button className="pa-primary">
            <Search size={16} />
            Search
          </button>
          <button
            type="button"
            onClick={() => {
              setSearch("");
              setApplied("");
              setStatus("all");
              setPage(1);
            }}
          >
            Reset
          </button>
          <button
            type="button"
            aria-label="Refresh applications"
            disabled={loading || busy}
            onClick={() => void load()}
          >
            <RefreshCw size={16} />
          </button>
        </form>
        <div className="pa-scroll">
          <table>
            <thead>
              <tr>
                <th>Application</th>
                <th>Taxpayer / TIN</th>
                <th>Status</th>
                <th>Updated (PHT)</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              {data?.data.map((x) => (
                <tr key={x.id}>
                  <td>
                    <strong>{x.reference}</strong>
                    <small>Revision {x.revision}</small>
                  </td>
                  <td>
                    {x.business_name}
                    <small>{x.tin}</small>
                  </td>
                  <td>
                    <span className={"pa-status " + x.status}>{x.status_label}</span>
                  </td>
                  <td>{stamp(x.updated_at)}</td>
                  <td>
                    <button onClick={() => void inspect(x.id)}>
                      View application <ArrowRight size={14} />
                    </button>
                  </td>
                </tr>
              ))}
              {!data?.data.length && (
                <tr>
                  <td colSpan={5} className="pa-empty">
                    {loading
                      ? "Loading applications…"
                      : error
                        ? "Applications could not be loaded. Use Refresh to retry."
                        : "No applications match these filters. Start a PTI application to prepare your documents."}
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
        <footer className="pa-pager">
          <span>{data?.total || 0} applications · 15 per page</span>
          <button
            disabled={!data || data.page <= 1 || loading}
            onClick={() => setPage((data?.page || 1) - 1)}
          >
            Previous
          </button>
          <span>
            {data?.page || 1} / {data?.last_page || 1}
          </span>
          <button
            disabled={!data || data.page >= data.last_page || loading}
            onClick={() => setPage((data?.page || 1) + 1)}
          >
            Next
          </button>
        </footer>
      </section>
      <div className="pa-footer">
        <p>
          <b>PTI and EIS certification are separate.</b> The linked EIS API guide covers
          authentication, invoice transmission, result inquiry and optional callbacks; it does not
          document a PTI application API.
        </p>
        <p>
          The taxpayer retains ownership of the permit. Application activity does not change account
          verification, API keys, wallet balances or BIR transmission controls.
        </p>
        {!merchant && (
          <Link to="/super-admin/pti-ptt?view=register">
            View the issued PTI / PTT register <ArrowRight size={14} />
          </Link>
        )}
      </div>
      <Dialog
        open={opened}
        onOpenChange={(v) => {
          if (!v) close();
        }}
      >
        <DialogContent className="pa-dialog">
          <DialogHeader>
            <DialogTitle>
              {creating ? "Start PTI Application" : record?.reference || "PTI Application"}
            </DialogTitle>
            <DialogDescription>
              {creating
                ? "Prepare the application details. Documents can be added after saving the draft."
                : `${record?.status_label || "Loading…"} · Changes are recorded in the application history.`}
            </DialogDescription>
          </DialogHeader>
          {error && (
            <p className="pa-error" role="alert">
              {error}
            </p>
          )}
          {message && (
            <p className="pa-success" role="status">
              {message}
            </p>
          )}
          {creating || editing ? (
            <form className="pa-form" onSubmit={save}>
              <fieldset disabled={busy}>
                {creating && admin && (
                  <div className="pa-business-picker">
                    <label>
                      <span>Find a registered business</span>
                      <input
                        placeholder="Search business name or TIN (first 30 matches)"
                        value={businessSearch}
                        onChange={(e) => setBusinessSearch(e.target.value)}
                      />
                    </label>
                    <label>
                      <span>Taxpayer *</span>
                      <select
                        required
                        value={businessId}
                        onChange={(e) =>
                          chooseBusiness(businesses.find((b) => b.id === e.target.value))
                        }
                      >
                        <option value="">Select registered business</option>
                        {businesses.map((b) => (
                          <option key={b.id} value={b.id}>
                            {b.business_name} · {b.tin}
                          </option>
                        ))}
                      </select>
                    </label>
                  </div>
                )}
                {!admin && (
                  <div className="pa-identity">
                    <strong>{data?.merchant?.business_name}</strong>
                    <span>
                      TIN {data?.merchant?.tin} · RDO {data?.merchant?.rdo_code || "not recorded"}
                    </span>
                  </div>
                )}
                <div className="pa-form-grid">
                  <label>
                    <span>Application type *</span>
                    <select
                      value={form.application_type}
                      onChange={(e) => setForm({ ...form, application_type: e.target.value })}
                    >
                      <option value="new">New PTI</option>
                      <option value="amendment">New / amended system authority</option>
                    </select>
                  </label>
                  {form.application_type === "amendment" &&
                    field("previous_reference", "Previous PTI reference")}
                  {field("system_name", "Invoicing software / system")}
                  {field("system_version", "Software version")}
                  {field("system_provider", "Software provider")}
                  <label>
                    <span>System category *</span>
                    <select
                      value={form.system_type}
                      onChange={(e) => setForm({ ...form, system_type: e.target.value })}
                    >
                      {["nuers", "erp", "pos", "ecommerce", "other"].map((v) => (
                        <option key={v} value={v}>
                          {v.toUpperCase()}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label>
                    <span>Covered BIR branch codes *</span>
                    <input
                      required
                      value={branchText}
                      maxLength={350}
                      onChange={(e) => setBranchText(e.target.value)}
                      placeholder="00000, 00001"
                    />
                    <small>
                      Five digits each, separated by commas. Confirm against registration.
                    </small>
                  </label>
                  {field("representative", "Applicant / authorized representative")}
                  {field("contact_email", "Contact email", "email")}
                  {field("contact_phone", "Contact number", "tel")}
                  {field("office", "Registered RDO / LT Office")}
                </div>
                <label>
                  <span>System scope & covered locations *</span>
                  <textarea
                    required
                    minLength={10}
                    maxLength={1500}
                    value={form.scope}
                    onChange={(e) => setForm({ ...form, scope: e.target.value })}
                    placeholder="Describe the invoicing system, locations and business activities covered by this application."
                  />
                </label>
                <label>
                  <span>Notes / office instructions</span>
                  <textarea
                    maxLength={3000}
                    value={form.notes}
                    onChange={(e) => setForm({ ...form, notes: e.target.value })}
                  />
                </label>
                <p className="pa-note">
                  Registered identity is copied from the business record. Request identity
                  corrections through Business Profile; this application cannot change the
                  registered name or TIN.
                </p>
                <button
                  className="pa-primary"
                  disabled={busy || (creating && admin && !businessId)}
                >
                  {busy ? "Saving…" : "Save application draft"}
                </button>
              </fieldset>
            </form>
          ) : detail ? (
            <>
              <div className="pa-detail-actions">
                {editable && (
                  <button
                    disabled={busy}
                    onClick={() => {
                      setForm(record!.payload.form);
                      setBranchText(record!.payload.form.branches.join(", "));
                      setEditing(true);
                      setMessage("");
                    }}
                  >
                    Edit application
                  </button>
                )}
                <button
                  disabled={busy}
                  onClick={() =>
                    void download(
                      "/api/pti-applications/" + record!.id + "/packet",
                      "NUERS-" + record!.reference + ".pdf",
                    )
                  }
                >
                  <Download size={15} />
                  Summary PDF
                </button>
                <button
                  disabled={busy}
                  onClick={() =>
                    void download(
                      "/api/pti-applications/" + record!.id + "/packet?format=zip",
                      "NUERS-" + record!.reference + ".zip",
                    )
                  }
                >
                  <Download size={15} />
                  Complete packet ZIP
                </button>
                <button disabled={busy} onClick={() => void run(refreshDetail)}>
                  <RefreshCw size={15} />
                  Refresh
                </button>
              </div>
              <div className="pa-identity">
                <strong>{record!.payload.identity.business_name}</strong>
                <span>
                  TIN {record!.payload.identity.tin} · {record!.payload.form.office}
                </span>
                <span>{record!.payload.identity.address}</span>
              </div>
              <div className="pa-info-grid">
                {Object.entries(record!.payload.form)
                  .filter(([k]) => !["notes", "scope"].includes(k))
                  .map(([k, v]) => (
                    <div key={k}>
                      <small>{k.replaceAll("_", " ")}</small>
                      <strong>{Array.isArray(v) ? v.join(", ") : v || "—"}</strong>
                    </div>
                  ))}
              </div>
              <p>
                <b>Scope:</b> {record!.payload.form.scope}
              </p>
              {record!.payload.form.notes && (
                <p>
                  <b>Notes:</b> {record!.payload.form.notes}
                </p>
              )}
              <h3>Preparation documents</h3>
              <p className="pa-note">{catalog?.checklist_notice}</p>
              <div className="pa-checklist">
                {catalog?.review_documents.map((k) => (
                  <div key={k}>
                    <span className={detail.documents.some((d) => d.kind === k) ? "complete" : ""}>
                      {detail.documents.some((d) => d.kind === k) ? "✓" : "○"}
                    </span>
                    {catalog.document_kinds[k]}
                  </div>
                ))}
              </div>
              {canUpload && (
                <div className="pa-upload">
                  <label>
                    <span>Document category</span>
                    <select
                      value={docKind}
                      onChange={(e) => setDocKind(e.target.value)}
                      disabled={busy}
                    >
                      {Object.entries(catalog?.document_kinds || {})
                        .filter(
                          ([k]) =>
                            admin || !["bir_receipt", "bir_permit", "bir_decision"].includes(k),
                        )
                        .map(([k, v]) => (
                          <option value={k} key={k}>
                            {v}
                          </option>
                        ))}
                    </select>
                  </label>
                  <label>
                    <span>PDF / PNG / JPG · maximum 3 MB</span>
                    <input
                      key={file ? "selected" : "empty"}
                      type="file"
                      accept="application/pdf,image/png,image/jpeg"
                      disabled={busy}
                      onChange={(e) => {
                        const f = e.target.files?.[0];
                        if (f && f.size > 3 * 1024 * 1024) {
                          setError("Choose a file no larger than 3 MB.");
                          return;
                        }
                        setFile(f || null);
                        setError("");
                      }}
                    />
                  </label>
                  <button
                    className="pa-primary"
                    disabled={busy || !file}
                    onClick={() => void upload()}
                  >
                    <Upload size={15} />
                    Upload
                  </button>
                </div>
              )}
              <div className="pa-documents">
                {detail.documents.map((d) => (
                  <article key={d.id}>
                    <FileCheck2 size={18} />
                    <div>
                      <strong>{d.label}</strong>
                      <span>
                        {d.filename} · {Math.ceil(d.bytes / 1024)} KB · {stamp(d.created_at)}
                      </span>
                      <small>SHA-256: {d.sha256}</small>
                    </div>
                    <button
                      disabled={busy}
                      onClick={() =>
                        void download(
                          "/api/pti-applications/" + record!.id + "/documents/" + d.id,
                          d.filename,
                        )
                      }
                    >
                      Download
                    </button>
                  </article>
                ))}
              </div>
              {["filing", "permit"].map((s) => {
                const values = record!.payload[s as "filing" | "permit"];
                return values ? (
                  <section key={s} className="pa-recorded">
                    <h3>{s === "filing" ? "BIR filing evidence" : "BIR-issued permit"} recorded</h3>
                    {Object.entries(values)
                      .filter(([k]) => !["recorded_by", "evidence_id"].includes(k))
                      .map(([k, v]) => (
                        <p key={k}>
                          <b>{k.replaceAll("_", " ")}:</b> {v || "Not stated"}
                        </p>
                      ))}
                  </section>
                ) : null;
              })}
              {allowed.length > 0 && (
                <section className="pa-next">
                  <h3>Next step</h3>
                  <select
                    aria-label="Next application step"
                    value={action}
                    disabled={busy}
                    onChange={(e) => {
                      setAction(e.target.value);
                      setConfirmed(false);
                      setNote("");
                      setProof("");
                      setOfficial({
                        filing_reference: "",
                        filed_on: "",
                        receiving_office: record!.payload.form.office,
                        permit_reference: "",
                        issued_at: "",
                        valid_until: "",
                        branch_code: record!.payload.form.branches[0],
                      });
                    }}
                  >
                    <option value="">Select an action</option>
                    {allowed.map((k) => (
                      <option key={k} value={k}>
                        {actionLabels[k]}
                      </option>
                    ))}
                  </select>
                  {action && (
                    <div className="pa-action-form">
                      <p className="pa-note">
                        {action === "in_review"
                          ? "This sends the application to NUERS for preparation review. It does not file it with BIR."
                          : action === "ready_to_file"
                            ? "Confirm the documents and current RDO / LT instructions. Ready for filing is a NUERS preparation status."
                            : action === "issued"
                              ? "Record only the actual BIR-issued PTI. This creates an evidence-reviewed production permit record for the selected branch; other BIR controls remain separate."
                              : action === "filed"
                                ? "Record a completed filing using the actual receiving acknowledgement. This action does not transmit to BIR."
                                : "Record the reason below. Status changes retain the application history."}
                      </p>
                      {["filed", "issued", "declined"].includes(action) && (
                        <label>
                          <span>Supporting BIR evidence *</span>
                          <select value={proof} onChange={(e) => setProof(e.target.value)}>
                            <option value="">
                              Select uploaded {catalog?.document_kinds[evidenceKind]}
                            </option>
                            {detail.documents
                              .filter((d) => d.kind === evidenceKind)
                              .map((d) => (
                                <option key={d.id} value={d.id}>
                                  {d.filename} · {stamp(d.created_at)}
                                </option>
                              ))}
                          </select>
                          <small>Upload the matching evidence category above first.</small>
                        </label>
                      )}
                      {action === "filed" && (
                        <div className="pa-form-grid">
                          {[
                            ["filing_reference", "BIR receiving reference", "text"],
                            ["filed_on", "Actual filing date", "date"],
                            ["receiving_office", "Receiving RDO / LT Office", "text"],
                          ].map(([k, l, t]) => (
                            <label key={k}>
                              <span>{l} *</span>
                              <input
                                type={t}
                                value={official[k as keyof typeof official]}
                                onChange={(e) => setOfficial({ ...official, [k]: e.target.value })}
                              />
                            </label>
                          ))}
                        </div>
                      )}
                      {action === "issued" && (
                        <div className="pa-form-grid">
                          {[
                            ["permit_reference", "BIR PTI reference", "text"],
                            ["issued_at", "Issue date", "date"],
                            ["valid_until", "Valid until (only if stated)", "date"],
                          ].map(([k, l, t]) => (
                            <label key={k}>
                              <span>{l}</span>
                              <input
                                type={t}
                                value={official[k as keyof typeof official]}
                                onChange={(e) => setOfficial({ ...official, [k]: e.target.value })}
                              />
                            </label>
                          ))}
                          <label>
                            <span>Branch on issued authority</span>
                            <select
                              value={official.branch_code}
                              onChange={(e) =>
                                setOfficial({ ...official, branch_code: e.target.value })
                              }
                            >
                              {record!.payload.form.branches.map((b) => (
                                <option key={b} value={b}>
                                  {b}
                                </option>
                              ))}
                            </select>
                          </label>
                        </div>
                      )}
                      <label>
                        <span>Reason / review note *</span>
                        <textarea
                          minLength={10}
                          maxLength={2000}
                          value={note}
                          onChange={(e) => setNote(e.target.value)}
                        />
                      </label>
                      <label className="pa-confirm">
                        <input
                          type="checkbox"
                          checked={confirmed}
                          onChange={(e) => setConfirmed(e.target.checked)}
                        />
                        <span>
                          I checked the application details and any evidence required for this step.
                        </span>
                      </label>
                      <button
                        className="pa-primary"
                        disabled={
                          busy ||
                          !confirmed ||
                          note.trim().length < 10 ||
                          (["filed", "issued", "declined"].includes(action) && !proof)
                        }
                        onClick={() => void changeStatus()}
                      >
                        {busy ? "Saving…" : actionLabels[action]}
                      </button>
                    </div>
                  )}
                </section>
              )}
              <h3>
                <Clock3 size={17} />
                Application history
              </h3>
              <div className="pa-history">
                {detail.history.map((h, i) => (
                  <div key={i}>
                    <strong>
                      {h.event.replaceAll("_", " ")} · revision {h.revision}
                    </strong>
                    <small>{stamp(h.created_at)}</small>
                    <p>{h.details.note}</p>
                  </div>
                ))}
              </div>
              <small>Most recent 100 events shown. Full audit history is retained.</small>
            </>
          ) : (
            !error && <p>Loading application…</p>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
