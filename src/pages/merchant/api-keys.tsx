import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { IntegrationReceiptGuide } from "@/components/merchant/integration-receipt-guide";
import { SALE_CATEGORIES, type SaleCategory } from "@/lib/statutory-sales";
import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type ElementType,
  type ReactNode,
  type FormEvent,
} from "react";
import { Link, useSearchParams } from "react-router-dom";
import {
  Link2,
  Plus,
  Copy,
  Eye,
  Pencil,
  Trash2,
  Activity,
  Clock,
  Webhook,
  FileText,
  Code2,
  Network,
  MapPin,
  FlaskConical,
  ListChecks,
  Settings,
  ShieldCheck,
  RefreshCw,
  UploadCloud,
  Download,
  Info,
  ArrowRight,
  Loader2,
  LockKeyhole,
  CheckCircle2,
  Wallet,
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiJson, apiUrl, readJsonResponse } from "@/lib/api-url";
import {
  MerchantSourceProfiles,
  type MerchantSourceProfile,
} from "@/components/merchant/source-profile-panel";
import { MerchantWebhookPanel } from "@/components/merchant/webhook-panel";
import { ClientQueuePanel } from "@/components/merchant/client-queue";
import {
  codeExample,
  DOCUMENTS,
  downloadText,
  integrationGuide,
  sampleEvent,
} from "@/lib/integrations/merchant-guide";
import "./api-integration.css";
import "./wallet.css";

type Key = {
  id: string;
  name: string;
  key_preview: string;
  env: "live" | "sandbox";
  created: string;
  last_used_at: string | null;
  expires_at: string | null;
  active: boolean;
  status: string;
  permissions: string[];
  ip_whitelist: string[];
  rate_limit: number;
  branch_type: string;
  branch_code: string;
  branch_name: string;
};
type Connection = {
  schema_version: string;
  record_mode: "source_capture";
  source_profile_id: string;
  source_system: string;
  branch_code: string;
};
type Branch = { bir_branch_code: string; name: string; branch_type: string };
type SourceProfile = MerchantSourceProfile;
type Data = {
  registered_branches: Branch[];
  source_profiles: SourceProfile[];
  summary: {
    active_keys: number;
    total_keys: number;
    requests_today: number;
    requests_month: number;
    avg_latency_ms: number | null;
    active_webhooks: number;
  };
  keys: Key[];
  webhooks: { id: string; url: string; events: string[]; status: string; deliveries: number }[];
  merchant: { id: string; name: string; status: string; vat_registered: boolean };
  access: { owner: boolean; credential_mfa_required: boolean };
  integration: {
    transaction_endpoint: string;
    schema_version: string;
    key_validity_days: number;
    webhook_delivery_enabled: boolean;
    document_types: { code: string; label: string; event: string; tax: string }[];
  };
};
type Logs = {
  data: {
    id: string;
    merchant_api_key_id: string;
    endpoint: string;
    method: string;
    status_code: number;
    latency_ms: number;
    environment: "live" | "sandbox" | "unknown";
    request_reference: string;
    source_system: string;
    created_at: string;
  }[];
  total: number;
  current_page: number;
  last_page: number;
};
const stamp = (v?: string | null) =>
  v
    ? new Date(v).toLocaleString("en-PH", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        timeZone: "Asia/Manila",
      })
    : "Never";
const tabs = [
  ["keys", "API Keys", Link2],
  ["profiles", "Source Profiles", FileText],
  ["endpoints", "Endpoints", Network],
  ["documents", "Document Types", FileText],
  ["webhooks", "Webhooks", Webhook],
  ["ip", "IP Whitelist", MapPin],
  ["testing", "Testing Tools", FlaskConical],
  ["logs", "Usage & Logs", ListChecks],
  ["retry", "Manual Retry", UploadCloud],
  ["settings", "Settings", Settings],
] as const;
const endpoints = [
  ["POST", "", "Submit one finalized invoice or payment event", "transactions:write"],
  ["POST", "/batch", "Atomic batch: 1–50 events for one branch", "transactions:write"],
  ["GET", "/{id}", "Read a durable intake result / status", "transactions:read"],
  ["GET", "/wallet", "Check balance, fee and connector intake mode", "transactions:read"],
];
function Panel({
  icon: Icon,
  title,
  description,
  action,
  children,
}: {
  icon: ElementType;
  title: string;
  description?: string;
  action?: ReactNode;
  children: ReactNode;
}) {
  return (
    <section className="mi-panel">
      <header>
        <span className="mi-icon">
          <Icon size={23} />
        </span>
        <div>
          <h2>{title}</h2>
          {description && <p>{description}</p>}
        </div>
        {action}
      </header>
      {children}
    </section>
  );
}
function Status({ value }: { value: string }) {
  return (
    <span
      className={
        "mi-badge " +
        (["active", "live", "valid", "durably_recorded"].includes(value)
          ? "good"
          : ["revoked", "expired", "error"].includes(value)
            ? "bad"
            : "neutral")
      }
    >
      {value === "live" ? "Live" : value === "sandbox" ? "Sandbox" : value.replaceAll("_", " ")}
    </span>
  );
}
export function MerchantApiKeys() {
  const [params, setParams] = useSearchParams(),
    [data, setData] = useState<Data | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(""),
    [message, setMessage] = useState(""),
    [selected, setSelected] = useState<Key | null>(null),
    [modal, setModal] = useState<"create" | "edit" | "view" | "revoke" | "guide" | "limits" | null>(
      null,
    ),
    [plain, setPlain] = useState(""),
    [newConnection, setNewConnection] = useState<Connection | null>(null),
    [newEnvironment, setNewEnvironment] = useState<"live" | "sandbox">("live"),
    [busy, setBusy] = useState(false),
    [lang, setLang] = useState("PHP"),
    [operation, setOperation] = useState("submit"),
    [doc, setDoc] = useState("vat_invoice"),
    [testPayload, setTestPayload] = useState(() => JSON.stringify(sampleEvent(), null, 2)),
    [testResult, setTestResult] = useState(""),
    [logs, setLogs] = useState<Logs | null>(null),
    [logPage, setLogPage] = useState(1),
    [logFilter, setLogFilter] = useState({ from: "", to: "", key: "", result: "" }),
    [applied, setApplied] = useState(logFilter),
    [mode, setMode] = useState<"batch" | "realtime">("batch"),
    [walletMode, setWalletMode] = useState("Not loaded"),
    [syntheticVat, setSyntheticVat] = useState(true),
    [saleCategory, setSaleCategory] = useState<SaleCategory>("regular");
  const generated = useRef(sampleEvent());
  const environment = params.get("environment") === "sandbox" ? "sandbox" : "live";
  const tab = tabs.some((t) => t[0] === params.get("tab")) ? params.get("tab")! : "keys";
  const initializedMerchant = useRef(""),
    requestSerial = useRef(0),
    interaction = useRef({ busy: false, modal: false });
  interaction.current = { busy, modal: modal !== null };
  const load = useCallback(
    async (signal?: AbortSignal, quiet = false) => {
      const serial = ++requestSerial.current;
      if (!quiet) {
        setLoading(true);
        setError("");
      }
      try {
        const d = await apiJson<Data>("/api/merchant/api-keys?environment=" + environment, {
          signal,
        });
        if (signal?.aborted || serial !== requestSerial.current) return;
        setData(d);
        if (initializedMerchant.current !== d.merchant.id) {
          initializedMerchant.current = d.merchant.id;
          setSyntheticVat(d.merchant.vat_registered);
          const initialDoc = d.merchant.vat_registered ? "vat_invoice" : "non_vat_invoice";
          setDoc(initialDoc);
          generated.current = sampleEvent(initialDoc, d.merchant.vat_registered);
          setTestPayload(JSON.stringify(generated.current, null, 2));
        }
        window.dispatchEvent(
          new CustomEvent("nuers:merchant-connection", {
            detail: { connected: true, updatedAt: new Date().toISOString() },
          }),
        );
      } catch (e) {
        if (!signal?.aborted && serial === requestSerial.current && !quiet)
          setError((e as Error).message);
      } finally {
        if (!signal?.aborted && serial === requestSerial.current) setLoading(false);
      }
    },
    [environment],
  );
  useEffect(() => {
    const c = new AbortController();
    let pending = false;
    const refresh = async () => {
      if (
        document.visibilityState === "hidden" ||
        interaction.current.busy ||
        interaction.current.modal ||
        pending
      )
        return;
      pending = true;
      try {
        await load(c.signal, true);
      } finally {
        pending = false;
      }
    };
    void load(c.signal);
    const stop = subscribeRealtimeRefresh(refresh);
    return () => {
      c.abort();
      requestSerial.current++;
      stop();
    };
  }, [load]);
  useEffect(() => {
    if (tab !== "logs") return;
    const c = new AbortController();
    setError("");
    const q = new URLSearchParams({ page: String(logPage), ...applied, environment });
    const loadLogs = () =>
      apiJson<Logs>("/api/merchant/api-keys/logs?" + q, { signal: c.signal })
        .then(setLogs)
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    void loadLogs();
    const stop = subscribeRealtimeRefresh(loadLogs);
    return () => {
      c.abort();
      stop();
    };
  }, [tab, logPage, applied, environment]);
  function go(id: string) {
    setParams({ tab: id, environment });
    setError("");
    setMessage("");
  }
  function open(v: typeof modal, k: Key | null = null) {
    setSelected(k);
    setModal(v);
    setPlain("");
    setNewConnection(null);
    setError("");
  }
  function close() {
    if (busy) return;
    setModal(null);
    setSelected(null);
    setPlain("");
    setNewConnection(null);
  }
  const endpoint =
    data?.integration.transaction_endpoint ||
    new URL(apiUrl("/api/integrations/transactions"), window.location.origin).href;
  const canManage = !!data?.access.owner && data.merchant.status === "active";
  const availableDocuments = DOCUMENTS.filter((d) =>
    syntheticVat
      ? d[0] !== "non_vat_invoice"
      : !["vat_invoice", "vat_exempt_invoice", "zero_rated_invoice"].includes(d[0]),
  );
  const code = codeExample(lang, endpoint, generated.current, operation);
  async function copied(value: string) {
    try {
      await navigator.clipboard.writeText(value);
      setMessage("Copied to clipboard.");
      return true;
    } catch {
      setMessage("Clipboard is unavailable. Select and copy the text manually.");
      return false;
    }
  }
  function docSelect(value: string) {
    setDoc(value);
    generated.current = sampleEvent(value, syntheticVat, saleCategory);
    setTestPayload(JSON.stringify(generated.current, null, 2));
    setTestResult("");
  }
  async function test(e: FormEvent) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    setError("");
    setTestResult("");
    try {
      const payload = JSON.parse(testPayload);
      const r = await fetch(apiUrl("/api/developer/sandbox"), {
        method: "POST",
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({
          is_test: true,
          action: "ingestion",
          seller_vat_registered: syntheticVat,
          payload,
        }),
      });
      const body = await readJsonResponse(r);
      setTestResult(JSON.stringify(body, null, 2));
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const keyTable = (
    <div className="mi-scroll mi-key-table">
      <table>
        <thead>
          <tr>
            {[
              "#",
              "Key Name / Branch",
              "Environment",
              "API Key (Masked)",
              "Created / Expiry (PHT)",
              "Last Used (PHT)",
              "Status",
              "Actions",
            ].map((h) => (
              <th key={h}>{h}</th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data?.keys.map((k, i) => (
            <tr key={k.id}>
              <td>{i + 1}</td>
              <td>
                <strong>{k.name}</strong>
                <small className="mi-cell-sub">
                  {k.branch_code} · {k.branch_name}
                </small>
              </td>
              <td>
                <Status value={k.env} />
              </td>
              <td>
                <code>{k.key_preview}</code>
              </td>
              <td>
                {stamp(k.created)}
                <small className="mi-cell-sub">Expires {stamp(k.expires_at)}</small>
              </td>
              <td>{stamp(k.last_used_at)}</td>
              <td>
                <Status value={k.status} />
              </td>
              <td>
                <div className="mi-actions">
                  <button onClick={() => open("view", k)}>
                    <Eye size={13} />
                    View
                  </button>
                  {canManage && k.status !== "revoked" && (
                    <>
                      <button onClick={() => open("edit", k)}>
                        <Pencil size={13} />
                        Edit
                      </button>
                      <button className="mi-red" onClick={() => open("revoke", k)}>
                        <Trash2 size={13} />
                        Revoke
                      </button>
                    </>
                  )}
                </div>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
      {!data?.keys.length && (
        <div className="mi-empty">
          <Link2 size={30} />
          <strong>No API keys yet</strong>
          <p>
            Create a named Live or Sandbox API key after business activation. Your registered
            business is assigned automatically.
          </p>
        </div>
      )}
    </div>
  );
  const categorySelector = (
    <label>
      Example Sale Category
      <select
        aria-label="Example sale category"
        value={saleCategory}
        onChange={(e) => {
          const c = e.target.value as SaleCategory;
          setSaleCategory(c);
          if (c !== "regular") setDoc("sales_invoice");
          generated.current = sampleEvent(c === "regular" ? doc : "sales_invoice", syntheticVat, c);
          setTestPayload(JSON.stringify(generated.current, null, 2));
          setTestResult("");
        }}
      >
        {Object.entries(SALE_CATEGORIES).map(([k, l]) => (
          <option key={k} value={k}>
            {l}
          </option>
        ))}
      </select>
    </label>
  );
  const quick = (
    <Panel
      icon={Code2}
      title="Quick Integration"
      description="Use these backend examples to connect your existing system. Never expose an API key in browser code."
      action={
        <div className="mi-toolbar">
          <label>
            Language
            <select
              aria-label="Example language"
              value={lang}
              onChange={(e) => setLang(e.target.value)}
            >
              <option>PHP</option>
              <option>Node.js</option>
              <option>cURL</option>
            </select>
          </label>
          <button className="mi-outline" onClick={() => void copied(code)}>
            <Copy size={15} />
            Copy Code
          </button>
        </div>
      }
    >
      <div className="mi-code-layout">
        <div className="mi-operations">
          {[
            ["submit", "Submit Invoice / Receipt", FileText],
            ["batch", "Submit a Batch", UploadCloud],
            ["status", "Check Intake Status", ListChecks],
            ["wallet", "Check Wallet / Fee", Wallet],
          ].map(([id, title, Icon]) => (
            <button
              key={String(id)}
              aria-pressed={operation === id}
              onClick={() => setOperation(String(id))}
            >
              <Icon size={17} />
              {String(title)}
            </button>
          ))}
          <label>
            Example Seller Registration
            <select
              value={syntheticVat ? "vat" : "non_vat"}
              onChange={(e) => {
                const vat = e.target.value === "vat";
                setSyntheticVat(vat);
                const type = vat ? "vat_invoice" : "non_vat_invoice";
                setDoc(type);
                generated.current = sampleEvent(type, vat, saleCategory);
                setTestPayload(JSON.stringify(generated.current, null, 2));
                setTestResult("");
              }}
            >
              <option value="vat">VAT registered (synthetic)</option>
              <option value="non_vat">Non-VAT registered (synthetic)</option>
            </select>
          </label>
          {categorySelector}
          <label>
            Document Type
            <select
              disabled={saleCategory !== "regular"}
              value={saleCategory === "regular" ? doc : "sales_invoice"}
              onChange={(e) => docSelect(e.target.value)}
            >
              {availableDocuments.map((d) => (
                <option key={d[0]} value={d[0]}>
                  {d[1]}
                </option>
              ))}
            </select>
          </label>
          <p>
            Sample values are synthetic. Replace the sample profile ID and system using Source
            Profiles for Live source capture. Create each event identity once, then preserve its
            original JSON on retries.
          </p>
        </div>
        <pre className="mi-code" tabIndex={0} aria-label="Integration code example">
          <code>{code}</code>
        </pre>
      </div>
    </Panel>
  );
  const endpointPanel = (
    <Panel
      icon={Network}
      title="API Endpoints"
      description="Authentication is the scoped merchant key; no separate token-generation endpoint is required."
      action={
        <button className="mi-text" onClick={() => open("guide")}>
          View Full Documentation <ArrowRight size={14} />
        </button>
      }
    >
      <div className="mi-scroll">
        <table>
          <thead>
            <tr>
              <th>Method</th>
              <th>Endpoint</th>
              <th>Description</th>
              <th>Scope</th>
            </tr>
          </thead>
          <tbody>
            {endpoints.map((e) => (
              <tr key={e[1]}>
                <td>
                  <Status value={e[0]} />
                </td>
                <td>
                  <code>/api/integrations/transactions{e[1]}</code>
                </td>
                <td>{e[2]}</td>
                <td>
                  <code>{e[3]}</code>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </Panel>
  );
  const webhookPanel = (
    <Panel
      icon={Webhook}
      title="Webhook Configuration"
      description="Receive signed callbacks in your own backend. Live and Sandbox destinations are separate."
      action={
        <button className="mi-primary" onClick={() => go("webhooks")}>
          Configure Webhooks <ArrowRight size={14} />
        </button>
      }
    >
      <p>
        Save your HTTPS receiver, copy the signing secret, verify ownership, then enable delivery.
        Durable logs and retries preserve event identity.
      </p>
    </Panel>
  );
  return (
    <div className="merchant-api">
      <header className="mi-hero">
        <div>
          <p>
            Settings <span>›</span> API Integration
          </p>
          <h1>API Integration</h1>
          <h2>
            Connect your ERP, POS, or ecommerce system and save its transactions in your NUERS
            account.
          </h2>
        </div>
        <div className="mi-toolbar">
          <button className="mi-outline" onClick={() => open("guide")}>
            <FileText size={16} />
            API Documentation
          </button>
          <button className="mi-outline" disabled={loading || busy} onClick={() => void load()}>
            <RefreshCw size={16} />
            Refresh Status
          </button>
          <button className="mi-primary" disabled={!canManage} onClick={() => open("create")}>
            <Plus size={17} />
            Generate New API Key
          </button>
        </div>
      </header>
      {error && (
        <div className="mi-feedback bad" role="alert">
          <Info size={18} />
          <span>{error}</span>
          <button onClick={() => setError("")} aria-label="Dismiss error">
            ×
          </button>
        </div>
      )}
      {message && (
        <div className="mi-feedback" role="status">
          <CheckCircle2 size={18} />
          {message}
        </div>
      )}
      {data && !canManage && (
        <div className="mi-notice">
          <ShieldCheck size={19} />
          {data.access.owner ? (
            <span>
              Your business is {data.merchant.status}. Complete{" "}
              <Link to="/merchant/profile?tab=documents">Business Profile review</Link> before
              managing live credentials or submitting new financial events.
            </span>
          ) : (
            <span>
              Your role allows integration metadata review. The registered Merchant Admin manages
              credentials and source submissions.
            </span>
          )}
        </div>
      )}
      <div className="mi-stats">
        {[
          {
            title: "Active API Keys",
            value: data?.summary.active_keys,
            icon: Link2,
            note: `${data?.summary.total_keys ?? 0} total · active and unexpired`,
            state: data?.summary.active_keys ? "Active" : "No active keys",
          },
          {
            title: "Total API Requests",
            value: data?.summary.requests_month,
            icon: Activity,
            note: "Recorded intake attempts this month",
            state: "",
          },
          {
            title: "Average Response Time",
            value: data?.summary.avg_latency_ms == null ? "—" : data.summary.avg_latency_ms + " ms",
            icon: Clock,
            note: "Recorded intake attempts today",
            state: data?.summary.avg_latency_ms == null ? "No observations" : "",
          },
          {
            title: "Active Webhooks",
            value: data?.summary.active_webhooks,
            icon: Webhook,
            note: "Verified enabled destinations · " + environment,
            state: data?.integration.webhook_delivery_enabled
              ? "Configured endpoints"
              : "Dispatch paused",
          },
        ].map((s) => (
          <article key={s.title}>
            <span className="mi-icon">
              <s.icon size={27} />
            </span>
            <div>
              <h2>{s.title}</h2>
              <strong>{s.value ?? "—"}</strong>
              <small>{s.note}</small>
            </div>
            {s.state && <span className="mi-stat-state">{s.state}</span>}
          </article>
        ))}
      </div>
      {canManage && (
        <div className="mi-feedback" role="status">
          <CheckCircle2 size={18} />
          <span>
            <strong>Account active — API key creation ready.</strong> No additional account or
            source-profile approval is required. Choose a key name and environment to connect your
            system.
          </span>
        </div>
      )}
      <div className="mi-toolbar" style={{ marginBottom: 16 }}>
        <label>
          Integration Environment
          <select
            aria-label="Integration environment"
            value={environment}
            onChange={(e) => {
              setParams({ tab, environment: e.target.value });
              setLogPage(1);
              setLogFilter({ from: "", to: "", key: "", result: "" });
              setApplied({ from: "", to: "", key: "", result: "" });
            }}
          >
            <option value="live">Live — real records</option>
            <option value="sandbox">Sandbox — test records</option>
          </select>
        </label>
        <strong>
          {environment === "live" ? "Live workspace" : "Sandbox workspace · no wallet debit"}
        </strong>
      </div>
      <div className="mi-note">
        <ShieldCheck size={20} />
        <p>
          Choose the environment when generating a key.{" "}
          <Link to="/merchant/invoices?environment=live">View Live records</Link> ·{" "}
          <Link to="/merchant/invoices?environment=sandbox">View Sandbox records</Link>. Sandbox
          never debits your Live wallet.
        </p>
      </div>
      <nav className="mi-tabs" aria-label="API Integration sections">
        {tabs.map(([id, title, Icon]) => (
          <button key={id} aria-current={tab === id ? "page" : undefined} onClick={() => go(id)}>
            <Icon size={17} />
            {title}
          </button>
        ))}
      </nav>
      {loading && !data ? (
        <div className="mi-empty" role="status">
          <Loader2 className="mi-spin" />
          Loading integration workspace…
        </div>
      ) : (
        data && (
          <>
            {tab === "keys" && (
              <>
                <Panel
                  icon={Link2}
                  title="API Keys"
                  description="Live and Sandbox keys use separate records. Secrets are hashed and shown only once."
                  action={
                    <div className="mi-toolbar">
                      <button className="mi-outline" onClick={() => open("limits")}>
                        <Info size={15} />
                        API Key Limits
                      </button>
                      <button
                        className="mi-primary"
                        disabled={!canManage}
                        onClick={() => open("create")}
                      >
                        <Plus size={16} />
                        Generate New API Key
                      </button>
                      <button
                        className="mi-icon-button"
                        aria-label="Refresh API keys"
                        disabled={loading}
                        onClick={() => void load()}
                      >
                        <RefreshCw size={16} />
                      </button>
                    </div>
                  }
                >
                  {keyTable}
                </Panel>
                <section className="mi-panel">
                  <header>
                    <span className="mi-icon">
                      <FileText size={23} />
                    </span>
                    <div>
                      <h2>Find Your Source Connection Fields</h2>
                      <p>
                        Need source_profile_id or source_system for an existing ERP/POS invoice?
                        Your connection profile is automatic for a verified account when generating
                        a key; no separate admin source approval is required. Copy its exact values
                        in Source Profiles.
                      </p>
                    </div>
                    <button className="mi-primary" onClick={() => go("profiles")}>
                      Open Source Profiles <ArrowRight size={15} />
                    </button>
                  </header>
                </section>
                {quick}
                <div className="mi-bottom">
                  {endpointPanel}
                  {webhookPanel}
                </div>
              </>
            )}
            {tab === "profiles" && (
              <MerchantSourceProfiles
                profiles={data.source_profiles}
                environment={environment}
                loading={loading}
                onRefresh={() => void load()}
                onCopy={copied}
                onGenerate={() => open("create")}
                canGenerate={canManage}
              />
            )}
            {tab === "endpoints" && (
              <>
                {endpointPanel}
                {quick}
              </>
            )}
            {tab === "documents" && (
              <Panel
                icon={FileText}
                title="Invoice & Receipt Types"
                description="Map your source document to the same invoice classifications used in Create Invoice."
              >
                <div className="mi-scroll">
                  <table>
                    <thead>
                      <tr>
                        <th>Document Type</th>
                        <th>API Value</th>
                        <th>Event Trigger</th>
                        <th>Tax Classification</th>
                        <th>Example</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.integration.document_types.map((d) => (
                        <tr key={d.code}>
                          <td>{d.label}</td>
                          <td>
                            <code>{d.code}</code>
                          </td>
                          <td>
                            <code>{d.event}</code>
                          </td>
                          <td>{d.tax}</td>
                          <td>
                            <button
                              className="mi-text"
                              onClick={() => {
                                docSelect(d.code);
                                go("testing");
                              }}
                            >
                              Preview Example <ArrowRight size={14} />
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="mi-note">
                  <Info size={19} />
                  <p>
                    Payment receipts are supplementary and do not add a second primary sale.
                    Official Receipt is a payment alias. Credit/debit corrections require the
                    existing reviewed, linked-document workflow and are not accepted as arbitrary
                    source imports.
                  </p>
                </div>
                <Link className="mi-text" to="/merchant/invoices/create">
                  Open Create Invoice <ArrowRight size={14} />
                </Link>
              </Panel>
            )}
            {tab === "webhooks" && (
              <MerchantWebhookPanel
                key={environment}
                environment={environment}
                onChanged={() => void load()}
              />
            )}
            {tab === "ip" && (
              <Panel
                icon={MapPin}
                title="IP Whitelist"
                description="Exact IPv4 / IPv6 addresses allowed for each key. Empty lists allow any source IP with the valid scoped key."
              >
                <div className="mi-note">
                  <ShieldCheck size={18} />
                  <p>
                    Use your ERP/POS backend's outbound public IP. An IP rule is an additional
                    restriction and does not replace the secret key or branch checks.
                  </p>
                </div>
                <div className="mi-scroll">
                  <table>
                    <thead>
                      <tr>
                        <th>Key</th>
                        <th>Branch</th>
                        <th>Allowed IP Addresses</th>
                        <th>Action</th>
                      </tr>
                    </thead>
                    <tbody>
                      {data.keys.map((k) => (
                        <tr key={k.id}>
                          <td>{k.name}</td>
                          <td>{k.branch_code}</td>
                          <td>{k.ip_whitelist.join(", ") || "Any IP (key still required)"}</td>
                          <td>
                            <button
                              className="mi-outline"
                              disabled={!canManage || k.status === "revoked"}
                              onClick={() => open("edit", k)}
                            >
                              Edit Policy
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {!data.keys.length && <p className="mi-empty">No API keys configured.</p>}
                </div>
              </Panel>
            )}
            {tab === "testing" && (
              <Panel
                icon={FlaskConical}
                title="Synthetic Testing Tools"
                description="Validate the complete ingestion contract and line calculations. No key, wallet debit, source record, or BIR request is created."
              >
                <form onSubmit={test}>
                  <div className="mi-toolbar">
                    <label>
                      Example Seller Registration
                      <select
                        value={syntheticVat ? "vat" : "non_vat"}
                        onChange={(e) => {
                          const vat = e.target.value === "vat";
                          setSyntheticVat(vat);
                          const type = vat ? "vat_invoice" : "non_vat_invoice";
                          setDoc(type);
                          generated.current = sampleEvent(type, vat, saleCategory);
                          setTestPayload(JSON.stringify(generated.current, null, 2));
                          setTestResult("");
                        }}
                      >
                        <option value="vat">VAT registered (synthetic)</option>
                        <option value="non_vat">Non-VAT registered (synthetic)</option>
                      </select>
                    </label>
                    {categorySelector}
                    <label>
                      Document Type
                      <select
                        disabled={saleCategory !== "regular"}
                        value={saleCategory === "regular" ? doc : "sales_invoice"}
                        onChange={(e) => docSelect(e.target.value)}
                      >
                        {availableDocuments.map((d) => (
                          <option key={d[0]} value={d[0]}>
                            {d[1]}
                          </option>
                        ))}
                      </select>
                    </label>
                    <button
                      type="button"
                      className="mi-outline"
                      onClick={() =>
                        downloadText("nuers-synthetic-event.json", testPayload, "application/json")
                      }
                    >
                      <Download size={15} />
                      Download Example
                    </button>
                  </div>
                  <label className="mi-field">
                    Synthetic Source JSON
                    <textarea
                      className="mi-json"
                      required
                      maxLength={60000}
                      spellCheck={false}
                      value={testPayload}
                      onChange={(e) => setTestPayload(e.target.value)}
                    />
                  </label>
                  <p className="mi-caption">
                    Use SAMPLE-, DEMO-, or TEST- references. Do not enter real customer details or
                    credentials. Choose the synthetic seller registration explicitly. Production
                    uses reviewed account or original-source evidence; this selector does not change
                    your registration.
                  </p>
                  <button className="mi-primary" disabled={busy}>
                    <FlaskConical size={16} />
                    {busy ? "Validating…" : "Validate Without Saving"}
                  </button>
                </form>
                {testResult && (
                  <pre className="mi-code mi-result" aria-label="Synthetic validation result">
                    {testResult}
                  </pre>
                )}
              </Panel>
            )}
            {tab === "logs" && (
              <Panel
                icon={ListChecks}
                title="Usage & Request Logs"
                description="Your business's intake HTTP attempts only. Payloads and API secrets are excluded."
              >
                <form
                  className="mi-filters"
                  onSubmit={(e) => {
                    e.preventDefault();
                    setLogPage(1);
                    setApplied({ ...logFilter });
                  }}
                >
                  <label>
                    From
                    <input
                      type="date"
                      value={logFilter.from}
                      onChange={(e) => setLogFilter({ ...logFilter, from: e.target.value })}
                    />
                  </label>
                  <label>
                    To
                    <input
                      type="date"
                      min={logFilter.from}
                      value={logFilter.to}
                      onChange={(e) => setLogFilter({ ...logFilter, to: e.target.value })}
                    />
                  </label>
                  <label>
                    API Key
                    <select
                      value={logFilter.key}
                      onChange={(e) => setLogFilter({ ...logFilter, key: e.target.value })}
                    >
                      <option value="">All Keys</option>
                      {data.keys.map((k) => (
                        <option key={k.id} value={k.id}>
                          {k.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label>
                    Result
                    <select
                      value={logFilter.result}
                      onChange={(e) => setLogFilter({ ...logFilter, result: e.target.value })}
                    >
                      <option value="">All Results</option>
                      <option value="success">HTTP success</option>
                      <option value="error">HTTP error</option>
                    </select>
                  </label>
                  <button className="mi-primary">Apply Filters</button>
                </form>
                <div className="mi-scroll">
                  <table>
                    <thead>
                      <tr>
                        {[
                          "Time (PHT)",
                          "Environment",
                          "Source",
                          "Reference",
                          "Endpoint",
                          "HTTP",
                          "Latency",
                        ].map((h) => (
                          <th key={h}>{h}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {logs?.data.map((l) => (
                        <tr key={l.id}>
                          <td>{stamp(l.created_at)}</td>
                          <td>
                            <Status value={l.environment} />
                          </td>
                          <td>{l.source_system || "—"}</td>
                          <td>{l.request_reference || "—"}</td>
                          <td>
                            <code>
                              {l.method} {l.endpoint}
                            </code>
                          </td>
                          <td>
                            <Status value={l.status_code < 400 ? "active" : "error"} />{" "}
                            {l.status_code}
                          </td>
                          <td>{l.latency_ms} ms</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {!logs?.data.length && (
                    <p className="mi-empty">No intake request logs match these filters.</p>
                  )}
                </div>
                <div data-slot="table-pagination" className="mi-pagination">
                  <span>{logs?.total ?? 0} recorded attempts</span>
                  <button
                    className="mi-outline"
                    disabled={logPage === 1}
                    onClick={() => setLogPage((p) => p - 1)}
                  >
                    Previous
                  </button>
                  <span>
                    {logPage} / {logs?.last_page ?? 1}
                  </span>
                  <button
                    className="mi-outline"
                    disabled={!logs || logPage >= logs.last_page}
                    onClick={() => setLogPage((p) => p + 1)}
                  >
                    Next
                  </button>
                </div>
              </Panel>
            )}
            {tab === "retry" && (
              <Panel
                icon={UploadCloud}
                title="Manual Source Retry / Offline Queue"
                description="Import finalized transactions exported by your ERP/POS and resend their original identities after an outage."
              >
                <div className="mi-note">
                  <Info size={20} />
                  <p>
                    Automatic ERP buffering requires an installed source-system connector. This tool
                    cannot recover transactions that remain only inside a disconnected ERP. Export
                    its original event JSON, keep source backups, and transmit here when online.
                  </p>
                </div>
                <div className="mi-toolbar mi-retry-mode">
                  <label>
                    This Tool's Submission Mode
                    <select
                      value={mode}
                      onChange={(e) => setMode(e.target.value as "batch" | "realtime")}
                    >
                      <option value="batch">Batch / Manual (recommended)</option>
                      <option value="realtime">Attempt After Import</option>
                    </select>
                  </label>
                  <button className="mi-outline" onClick={() => open("guide")}>
                    <FileText size={15} />
                    Offline Instructions
                  </button>
                </div>
                {data.access.owner ? (
                  <ClientQueuePanel
                    key={environment}
                    environment={environment}
                    merchant={data.merchant.id}
                    mode={mode}
                    sourceOnly
                    onAccepted={() => void load()}
                  />
                ) : (
                  <p className="mi-note">
                    Ask the registered Merchant Admin to manage source submissions.
                  </p>
                )}
              </Panel>
            )}
            {tab === "settings" && (
              <Panel
                icon={Settings}
                title="Integration Settings"
                description="Current service rules and links to the maintained source settings."
              >
                <div className="mi-settings">
                  <article>
                    <FileText />
                    <div>
                      <h3>Seller Tax Registration</h3>
                      <p>
                        {data.merchant.vat_registered
                          ? "VAT registered: classify each line as VATable, exempt, or zero-rated."
                          : "Non-VAT registered: classify non-VAT / percentage-tax sales separately from exempt sales."}{" "}
                        Current registration is reviewed in Business Profile. A historical source
                        profile preserves its effective registration.
                      </p>
                    </div>
                    <Link className="mi-outline" to="/merchant/profile">
                      Business Profile
                    </Link>
                  </article>
                  <article>
                    <ShieldCheck />
                    <div>
                      <h3>Credential Policy</h3>
                      <p>
                        {data.integration.key_validity_days} days · branch-bound · exact IP
                        allowlists · owner-managed with login-session MFA.
                      </p>
                    </div>
                    <Link className="mi-outline" to="/merchant/security">
                      Account Security
                    </Link>
                  </article>
                  <article>
                    <Wallet />
                    <div>
                      <h3>Wallet & Intake Mode</h3>
                      <p>
                        Realtime and batch are connector preferences. Insufficient balance blocks
                        new eligible source writes.
                      </p>
                      <small>Published wallet mode: {walletMode}</small>
                    </div>
                    <button
                      className="mi-outline"
                      onClick={async () => {
                        try {
                          const x = await apiJson<{ wallet: { intake_mode: string } }>(
                            "/api/merchant/wallet",
                          );
                          setWalletMode(x.wallet.intake_mode);
                        } catch (e) {
                          setError((e as Error).message);
                        }
                      }}
                    >
                      Read Current Mode
                    </button>
                  </article>
                  <article>
                    <FileText />
                    <div>
                      <h3>Source Identity & Record Retention</h3>
                      <p>
                        Keep your stable event ID, original number and timestamp. Retry unchanged;
                        accepted originals cannot be overwritten.
                      </p>
                    </div>
                    <Link className="mi-outline" to="/merchant/wallet">
                      Open Wallet
                    </Link>
                  </article>
                </div>
                <div className="mi-note">
                  <FileText size={19} />
                  <div>
                    <h3>Source Profiles & Connection Fields</h3>
                    <p>
                      Your verified business automatically receives connection fields. Find and copy
                      source_profile_id, source_system and registered location in the dedicated
                      Source Profiles tab.
                    </p>
                    <button className="mi-text" onClick={() => go("profiles")}>
                      Open Source Profiles <ArrowRight size={14} />
                    </button>
                  </div>
                </div>
                <a
                  className="mi-primary"
                  href="/guides/NUERS-API-Connection-Guide.pdf?v=brand-fc40dead1e2c"
                  download="NUERS-API-Connection-Guide.pdf"
                >
                  <Download size={16} />
                  Download Connection Guide
                </a>
              </Panel>
            )}
          </>
        )
      )}
      <Dialog
        open={modal !== null}
        onOpenChange={(v) => {
          if (!v) close();
        }}
      >
        <DialogContent className={"mi-dialog " + (modal === "guide" ? "mi-guide-dialog" : "")}>
          <DialogHeader>
            <DialogTitle>
              {plain
                ? "Store Your New API Key"
                : modal === "create"
                  ? "Generate New API Key"
                  : modal === "edit"
                    ? "Edit API Key Policy"
                    : modal === "revoke"
                      ? "Revoke API Key?"
                      : modal === "guide"
                        ? "Connect Your System to NUERS"
                        : modal === "limits"
                          ? "API Key & Intake Limits"
                          : "API Key Details"}
            </DialogTitle>
            <DialogDescription>
              {modal === "guide"
                ? "Verified NUERS ingestion contract, supported documents, offline recovery, and wallet rules."
                : plain
                  ? "This secret is shown once. Store it in your backend secret manager."
                  : "Keys belong to your business, registered location and selected environment. Credential changes require the owner’s verified login session."}
            </DialogDescription>
          </DialogHeader>
          {error && (
            <div className="mi-feedback bad" role="alert">
              {error}
            </div>
          )}
          {plain ? (
            <>
              <div className="mi-secret">
                <code>{plain}</code>
              </div>
              <button className="mi-primary" onClick={() => void copied(plain)}>
                <Copy size={16} />
                Copy Secret Key
              </button>
              <div className="mi-note">
                <ShieldCheck size={18} />
                <p>
                  <strong>{newEnvironment === "live" ? "Live" : "Sandbox"} connection ready</strong>
                  <br />
                  This API key is your secret credential. Send it in the{" "}
                  <code>X-NUERS-API-Key</code> header from your ERP backend. No additional secret or
                  public key is required.
                </p>
              </div>
              {newConnection && (
                <>
                  <pre className="mi-guide" aria-label="Generated connection fields">
                    {JSON.stringify(
                      { environment: newEnvironment, endpoint, ...newConnection },
                      null,
                      2,
                    )}
                  </pre>
                  <button
                    className="mi-outline"
                    onClick={() =>
                      void copied(
                        JSON.stringify(
                          {
                            environment: newEnvironment,
                            endpoint,
                            api_key: plain,
                            ...newConnection,
                          },
                          null,
                          2,
                        ),
                      )
                    }
                  >
                    <Copy size={16} />
                    Copy ERP Server Configuration
                  </button>
                  <p className="mi-caption">
                    The server configuration contains your secret. Save it only in your ERP backend
                    secret manager. It also contains the non-secret source identifiers needed for
                    owned-system capture.
                  </p>
                </>
              )}
              <p className="mi-caption">
                Closing this dialog clears the displayed secret. NUERS cannot retrieve it again. To
                rotate, create a replacement, update your connector, and revoke the old key.
              </p>
            </>
          ) : modal === "create" || modal === "edit" ? (
            <KeyForm
              initialEnvironment={environment}
              record={selected}
              onSaved={async (secret, connection, keyEnv) => {
                setPlain(secret || "");
                setNewConnection(connection || null);
                setNewEnvironment(keyEnv || environment);
                if (!secret) setModal(null);
                await load();
              }}
              onError={setError}
              onBusy={setBusy}
            />
          ) : modal === "revoke" ? (
            <>
              <p>
                Revoke <strong>{selected?.name}</strong>? Its connector will stop authenticating.
                Saved source events remain available for retry with a valid replacement key for the
                same branch.
              </p>
              <button
                className="mi-danger"
                disabled={busy}
                onClick={async () => {
                  if (!selected) return;
                  setBusy(true);
                  try {
                    await apiJson("/api/merchant/api-keys/" + selected.id, { method: "DELETE" });
                    setModal(null);
                    setMessage("API key revoked.");
                    await load();
                  } catch (e) {
                    setError((e as Error).message);
                  } finally {
                    setBusy(false);
                  }
                }}
              >
                Confirm Revocation
              </button>
            </>
          ) : modal === "guide" ? (
            <>
              <div className="mi-toolbar">
                <a
                  className="mi-primary"
                  href="/guides/NUERS-API-Connection-Guide.pdf?v=brand-fc40dead1e2c"
                  download="NUERS-API-Connection-Guide.pdf"
                >
                  <Download size={16} />
                  Download Guide
                </a>
                <a
                  className="mi-outline"
                  href="/guides/NUERS-API-Developer-Documentation.pdf?v=brand-fc40dead1e2c"
                  download="NUERS-API-Developer-Documentation.pdf"
                >
                  <Download size={14} />
                  Detailed Developer PDF
                </a>
                <Link className="mi-outline" to="/api/docs">
                  Developer Documentation <ArrowRight size={14} />
                </Link>
              </div>
              <IntegrationReceiptGuide />
              <pre className="mi-guide">{integrationGuide(endpoint)}</pre>
            </>
          ) : modal === "limits" ? (
            <div className="mi-limits">
              <p>
                <strong>Key lifetime:</strong> 90 days. Revoke unused keys and rotate before expiry.
              </p>
              <p>
                <strong>Daily budget:</strong> configured per key (100–1,000,000). This is an
                admission limit, not a measured throughput guarantee.
              </p>
              <p>
                <strong>Batch:</strong> 1–50 events; 100 lines/event. Batch route adds 10
                requests/minute. Source JSON import: 50 events / 2 MB.
              </p>
              <p>
                <strong>Global protection:</strong> network and shared per-key quotas may reject
                traffic first. Honor Retry-After. Use staging for authorized capacity tests.
              </p>
              <p>
                <strong>Scopes:</strong> transactions:write for intake; transactions:read for status
                and wallet. Sandbox keys cannot write production.
              </p>
            </div>
          ) : (
            selected && (
              <dl className="mi-details">
                {[
                  ["Name", selected.name],
                  ["Masked key", selected.key_preview],
                  ["Environment", selected.env],
                  ["Branch", selected.branch_code + " · " + selected.branch_name],
                  ["Scopes", selected.permissions.join(", ")],
                  ["Allowed IPs", selected.ip_whitelist.join(", ") || "Any IP with valid key"],
                  ["Daily budget", String(selected.rate_limit)],
                  ["Expires", stamp(selected.expires_at)],
                  ["Status", selected.status],
                ].map(([k, v]) => (
                  <div key={k}>
                    <dt>{k}</dt>
                    <dd>{v}</dd>
                  </div>
                ))}
              </dl>
            )
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
function KeyForm({
  record,
  initialEnvironment,
  onSaved,
  onError,
  onBusy,
}: {
  record: Key | null;
  initialEnvironment: "live" | "sandbox";
  onSaved: (secret?: string, connection?: Connection, keyEnv?: "live" | "sandbox") => Promise<void>;
  onError: (message: string) => void;
  onBusy: (busy: boolean) => void;
}) {
  const [name, setName] = useState(record?.name || ""),
    [ips, setIps] = useState(record?.ip_whitelist.join("\n") || ""),
    [scopes, setScopes] = useState(
      record?.permissions.filter((s) =>
        ["transactions:write", "transactions:read"].includes(s),
      ) || ["transactions:write", "transactions:read"],
    ),
    [environment, setEnvironment] = useState<"live" | "sandbox">(record?.env || initialEnvironment),
    [rate, setRate] = useState(record?.rate_limit || 50000),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    if (busy) return;
    setBusy(true);
    onBusy(true);
    onError("");
    try {
      const body = record
        ? {
            name: name.trim(),
            scopes,
            ip_whitelist: ips
              .split(/[\n,]/)
              .map((x) => x.trim())
              .filter(Boolean),
            rate_limit_per_day: rate,
          }
        : { name: name.trim(), environment };
      const r = await apiJson<{ key: Key & { plain_key?: string }; connection?: Connection }>(
        "/api/merchant/api-keys" + (record ? "/" + record.id : ""),
        { method: record ? "PATCH" : "POST", body: JSON.stringify(body) },
      );
      await onSaved(r.key.plain_key, r.connection, r.key.env);
    } catch (e) {
      onError((e as Error).message);
    } finally {
      setBusy(false);
      onBusy(false);
    }
  }
  return (
    <form className="mi-form" onSubmit={submit}>
      <label>
        API Key Name
        <input
          autoFocus
          required
          maxLength={255}
          value={name}
          disabled={busy}
          placeholder="e.g. ERP Integration"
          onChange={(e) => setName(e.target.value)}
        />
      </label>
      {!record && (
        <label>
          Environment
          <select
            value={environment}
            disabled={busy}
            onChange={(e) => setEnvironment(e.target.value as "live" | "sandbox")}
          >
            <option value="sandbox">Sandbox — test records, no wallet charge</option>
            <option value="live">Live — real transactions and wallet fees</option>
          </select>
        </label>
      )}
      {record ? (
        <>
          <p className="mi-caption">
            {record.env === "live" ? "Production" : "Sandbox"} · Registered location{" "}
            {record.branch_code} · {record.branch_name}
          </p>
          <fieldset>
            <legend>Permissions</legend>
            {[
              ["transactions:write", "Submit finalized source events"],
              ["transactions:read", "Read status and wallet"],
            ].map(([id, label]) => (
              <label className="mi-checkbox" key={id}>
                <input
                  type="checkbox"
                  checked={scopes.includes(id)}
                  disabled={busy}
                  onChange={(e) =>
                    setScopes((p) => (e.target.checked ? [...p, id] : p.filter((x) => x !== id)))
                  }
                />
                <span>
                  <strong>{id}</strong>
                  <small>{label}</small>
                </span>
              </label>
            ))}
          </fieldset>
          <label>
            Allowed IP Addresses (optional)
            <textarea
              rows={3}
              value={ips}
              disabled={busy}
              onChange={(e) => setIps(e.target.value)}
              placeholder="Exact IPv4 or IPv6 address, one per line"
            />
          </label>
          <label>
            Requests Per Day
            <input
              type="number"
              required
              min={100}
              max={1000000}
              value={rate}
              disabled={busy}
              onChange={(e) => setRate(Number(e.target.value))}
            />
          </label>
        </>
      ) : (
        <div className="mi-note">
          <ShieldCheck size={20} />
          <p>
            {environment === "live"
              ? "Live submissions create real records. Eligible sales require enough wallet balance before acceptance. The daily request budget starts at 50,000."
              : "Sandbox submissions are saved only as test records, with no Live sales, wallet debit, legal invoice series or BIR queue. The daily request budget starts at 5,000. Use synthetic customer data."}{" "}
            Your source connection fields are generated automatically from the verified business. No
            separate admin approval is required. Both keys expire after 90 days and use your
            registered business context. Environment cannot be changed after creation. Configure IP
            restrictions and permissions after creation.
          </p>
        </div>
      )}
      <p className="mi-caption">
        Only your verified owner login can manage keys. An API key does not grant invoice issuance
        authority or BIR access.
      </p>
      <button
        className="mi-primary"
        disabled={busy || !name.trim() || (!!record && !scopes.length)}
      >
        <LockKeyhole size={16} />
        {busy ? "Saving…" : record ? "Save Key Policy" : "Generate API Key"}
      </button>
    </form>
  );
}
