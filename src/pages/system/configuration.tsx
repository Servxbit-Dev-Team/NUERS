import { exportReport as openReportExport } from "@/components/reports/export-report";
import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { NotificationOperations } from "./notification-operations";
import { useEffect, useRef, useState, type ElementType } from "react";
import { Link } from "react-router-dom";
import {
  ArrowRight,
  Bell,
  CheckCircle2,
  Database,
  Download,
  FileText,
  Flag,
  History,
  Layers,
  Mail,
  RefreshCw,
  Save,
  Search,
  Send,
  Settings,
  ShieldCheck,
  Users,
  TriangleAlert,
} from "lucide-react";
import { apiJson } from "@/lib/api-url";
import { toast } from "sonner";
import "./configuration.css";

type Smtp = {
  host: string;
  port: number;
  encryption: string;
  username: string;
  password?: string;
  from_address: string;
  from_name: string;
  enabled: boolean;
  has_password: boolean;
};
type Configuration = {
  smtp: Smtp;
  revision: number;
  tested: boolean;
  tested_at: string | null;
  environment: string;
  mail_counts: { state: string; total: number }[];
  changes: {
    id: string;
    module: string;
    action: string;
    revision: number;
    changed_fields: string;
    created_at: string;
    actor: string | null;
  }[];
  system: {
    timezone: string;
    queue: string;
    eis_transport: boolean;
    maintenance: boolean;
    release_reference: string | null;
  };
};
const categories: { title: string; description: string; icon: ElementType; href?: string }[] = [
  {
    title: "General Settings",
    description: "System behavior and current environment",
    icon: Settings,
  },
  {
    title: "BIR EIS Integration",
    description: "Interface readiness and transmission controls",
    icon: Layers,
    href: "/super-admin/eis-readiness",
  },
  {
    title: "Tax & Document Rules",
    description: "Document rules and issuance authority",
    icon: FileText,
    href: "/super-admin/pti-ptt",
  },
  {
    title: "User & Access Policies",
    description: "Access control, roles and permissions",
    icon: Users,
    href: "/super-admin/users-rbac",
  },
  {
    title: "Security Controls",
    description: "Security policies and session settings",
    icon: ShieldCheck,
    href: "/super-admin/security",
  },
  {
    title: "SMTP Configuration",
    description: "Customer email copies and SMTP testing",
    icon: Mail,
  },
  {
    title: "Backup & Recovery",
    description: "Recorded resilience and recovery evidence",
    icon: Database,
    href: "/super-admin/backups",
  },
  {
    title: "Notifications",
    description: "Email coverage, queue and delivery outcomes",
    icon: Bell,
  },
  {
    title: "Feature & Release Controls",
    description: "Deployment evidence and release status",
    icon: Flag,
  },
];
function stamp(value?: string | null) {
  return value
    ? new Date(
        value.replace(" ", "T") + (value.endsWith("Z") || value.includes("+") ? "" : "Z"),
      ).toLocaleString("en-PH", {
        timeZone: "Asia/Manila",
        dateStyle: "medium",
        timeStyle: "short",
      })
    : "Not recorded";
}
export function SystemConfiguration() {
  const [data, setData] = useState<Configuration | null>(null),
    [form, setForm] = useState<Smtp | null>(null),
    [error, setError] = useState("");
  const [loading, setLoading] = useState(false),
    [saving, setSaving] = useState(false),
    [testing, setTesting] = useState(false),
    [testEmail, setTestEmail] = useState(""),
    [testMessage, setTestMessage] = useState("");
  const [active, setActive] = useState("SMTP Configuration"),
    [dirty, setDirty] = useState(false);
  const smtpRef = useRef<HTMLElement>(null),
    systemRef = useRef<HTMLElement>(null),
    auditRef = useRef<HTMLElement>(null),
    releaseRef = useRef<HTMLElement>(null);
  const liveAllowed = useRef(true);
  liveAllowed.current = !saving && !testing && !dirty;
  async function load(quiet = false) {
    if (!quiet) setLoading(true);
    setError("");
    try {
      const result = await apiJson<Configuration>("/api/admin/configuration");
      if (quiet && !liveAllowed.current) return;
      setData(result);
      if (!quiet) {
        setForm({ ...result.smtp, password: "" });
        setDirty(false);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unable to load configuration.");
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    void load();
  }, []);
  useRealtimeRefresh(() => load(true), !saving && !testing && !dirty);
  function change<K extends keyof Smtp>(key: K, value: Smtp[K]) {
    setForm((current) => (current ? { ...current, [key]: value } : current));
    setDirty(true);
    setTestMessage("");
  }
  const count = (state: string) =>
    Number(data?.mail_counts.find((row) => row.state === state)?.total || 0);
  const field = (
    title: string,
    key: "host" | "username" | "password" | "from_address" | "from_name",
    placeholder = "",
    type = "text",
  ) => (
    <label className="nconfig-field">
      <span>{title}</span>
      <input
        type={type}
        required={key !== "password" || !form?.has_password}
        value={form?.[key] || ""}
        readOnly={key === "from_name"}
        onChange={(e) => change(key, e.target.value)}
        maxLength={key === "password" ? 1000 : key === "from_name" ? 100 : 254}
        autoComplete={key === "password" ? "new-password" : "off"}
        placeholder={placeholder}
        disabled={saving || testing || loading}
      />
    </label>
  );
  const locked = saving || testing || loading;
  return (
    <div className="nuers-configuration">
      <div className="nconfig-hero">
        <nav aria-label="Breadcrumb">
          <Link to="/super-admin">Dashboard</Link>
          <span>›</span>System Administration<span>›</span>
          <strong>Configurations & Changes</strong>
        </nav>
        <h1>Configurations & Changes</h1>
        <p>
          Manage system settings, email delivery and audit-tracked configuration updates across
          NUERS.
        </p>
      </div>
      {error && (
        <div className="nconfig-error" role="alert">
          {error}
        </div>
      )}
      <div className="nconfig-metrics">
        {[
          {
            title: "SMTP Configuration",
            value: data ? (data.smtp.enabled ? "Enabled" : "Disabled") : "—",
            detail: data?.revision ? `Saved revision ${data.revision}` : "Awaiting configuration",
            icon: Settings,
            color: "blue",
          },
          {
            title: "Recorded Changes",
            value: data ? String(data.changes.length) : "—",
            detail: "Latest saved configuration changes",
            icon: FileText,
            color: "amber",
          },
          {
            title: "Customer Copies Accepted",
            value: data ? String(count("smtp_accepted")) : "—",
            detail: "Accepted by the SMTP server",
            icon: CheckCircle2,
            color: "green",
          },
          {
            title: "Unconfirmed Copies",
            value: data
              ? String(
                  count("queued") +
                    count("uncertain") +
                    count("sending") +
                    count("failed") +
                    count("preparing"),
                )
              : "—",
            detail: "Review before sending again",
            icon: TriangleAlert,
            color: "red",
          },
          {
            title: "Last Successful SMTP Test",
            value: data?.tested_at ? stamp(data.tested_at) : "Not tested",
            detail: data?.tested
              ? "Current connection tested"
              : "Test current settings before enabling",
            icon: Mail,
            color: "purple",
          },
        ].map(({ title, value, detail, icon: Icon, color }) => (
          <article key={title}>
            <span className={`nconfig-icon ${color}`}>
              <Icon />
            </span>
            <div>
              <h2>{title}</h2>
              <strong>{value}</strong>
              <small>{detail}</small>
            </div>
          </article>
        ))}
      </div>
      <div className="nconfig-columns">
        <div className="nconfig-main">
          <section className="nconfig-panel">
            <header>
              <h2>Configuration Categories</h2>
              <span>Platform controls</span>
            </header>
            <div className="nconfig-categories">
              {categories.map(({ title, description, icon: Icon, href }) =>
                href ? (
                  <Link key={title} to={href}>
                    <span>
                      <Icon />
                    </span>
                    <div>
                      <strong>{title}</strong>
                      <small>{description}</small>
                    </div>
                  </Link>
                ) : (
                  <button
                    key={title}
                    className={active === title ? "active" : ""}
                    onClick={() => {
                      setActive(title);
                      if (title === "Notifications") {
                        document
                          .getElementById("notification-operations")
                          ?.scrollIntoView({ behavior: "smooth" });
                        return;
                      }
                      (title === "SMTP Configuration"
                        ? smtpRef
                        : title === "General Settings"
                          ? systemRef
                          : releaseRef
                      ).current?.scrollIntoView({ behavior: "smooth", block: "start" });
                    }}
                  >
                    <span>
                      <Icon />
                    </span>
                    <div>
                      <strong>{title}</strong>
                      <small>{description}</small>
                    </div>
                  </button>
                ),
              )}
            </div>
          </section>
          <section className="nconfig-panel" ref={systemRef}>
            <header>
              <h2>System Configuration</h2>
              <span>Current deployment settings</span>
              <b className="nconfig-pill">{data?.environment || "Loading"}</b>
            </header>
            <div className="nconfig-system-grid">
              {[
                ["Environment Mode", data?.environment],
                ["Server Timezone", data?.system.timezone],
                ["Queue Connection", data?.system.queue],
                [
                  "Maintenance Mode",
                  data ? (data.system.maintenance ? "Enabled" : "Disabled") : "—",
                ],
              ].map(([title, value]) => (
                <label className="nconfig-field" key={title}>
                  <span>{title}</span>
                  <input readOnly value={value || "—"} />
                </label>
              ))}
            </div>
            <p className="nconfig-help">
              Deployment, tax and retention controls retain their reviewed configuration. Use the
              linked control workspaces to review the supporting evidence.
            </p>
          </section>
          <section className="nconfig-panel nconfig-smtp" ref={smtpRef}>
            <header>
              <h2>
                <Mail size={18} />
                SMTP Configuration
              </h2>
              <span>Secure transactional email delivery</span>
              <b className={`nconfig-pill ${data?.smtp.enabled ? "" : "muted"}`}>
                {data?.smtp.enabled ? "Delivery enabled" : "Delivery disabled"}
              </b>
            </header>
            {!form ? (
              <p className="nconfig-help">
                {loading ? "Loading SMTP settings…" : "Refresh to load SMTP settings."}
              </p>
            ) : (
              <form
                onSubmit={async (e) => {
                  e.preventDefault();
                  if (locked) return;
                  setSaving(true);
                  setError("");
                  try {
                    const result = await apiJson<Configuration>("/api/admin/configuration/smtp", {
                      method: "PUT",
                      body: JSON.stringify({
                        ...form,
                        password: form.password || null,
                        revision: data?.revision ?? 0,
                      }),
                    });
                    setData(result);
                    setForm({ ...result.smtp, password: "" });
                    setDirty(false);
                    toast.success("SMTP configuration saved.");
                  } catch (err) {
                    setError(err instanceof Error ? err.message : "Could not save SMTP settings.");
                  } finally {
                    setSaving(false);
                  }
                }}
              >
                <div className="nconfig-smtp-grid">
                  {field("SMTP Host", "host", "smtp.example.com")}
                  <label className="nconfig-field">
                    <span>SMTP Port</span>
                    <select
                      disabled={locked}
                      value={form.port}
                      onChange={(e) => {
                        const port = Number(e.target.value);
                        change("port", port);
                        change("encryption", port === 465 ? "tls" : "starttls");
                      }}
                    >
                      <option value={587}>587 · STARTTLS</option>
                      <option value={465}>465 · TLS</option>
                      <option value={2525}>2525 · STARTTLS</option>
                    </select>
                  </label>
                  <label className="nconfig-field">
                    <span>Encryption</span>
                    <input
                      readOnly
                      value={form.encryption === "tls" ? "TLS (implicit)" : "STARTTLS (required)"}
                    />
                  </label>
                  {field("SMTP Username", "username", "mailbox@example.com")}
                  {field(
                    "SMTP Password / App Password",
                    "password",
                    form.has_password
                      ? "Saved securely · leave blank to keep"
                      : "Enter app password",
                    "password",
                  )}
                  {field("Sender Email", "from_address", "receipts@example.com", "email")}
                  {field("Sender Name", "from_name", "NUERS")}
                </div>
                <div className="nconfig-smtp-bottom">
                  <label className="nconfig-toggle">
                    <input
                      type="checkbox"
                      checked={form.enabled}
                      disabled={locked || (!data?.tested && !form.enabled)}
                      onChange={(e) => change("enabled", e.target.checked)}
                    />
                    <span>
                      <strong>Enable NUERS email delivery</strong>
                      <small>Save disabled, send a successful test, then enable.</small>
                    </span>
                  </label>
                  <button className="nconfig-button primary" disabled={locked || !dirty}>
                    <Save size={16} />
                    {saving ? "Saving…" : "Save Configuration"}
                  </button>
                </div>
                <p className="nconfig-help">
                  Credentials are encrypted and never returned by the API. TLS certificate
                  verification is required. Changes are recorded in the audit trail.
                </p>
              </form>
            )}
            <form
              className="nconfig-test"
              onSubmit={async (e) => {
                e.preventDefault();
                if (locked || dirty) return;
                setTesting(true);
                setError("");
                setTestMessage("");
                try {
                  const result = await apiJson<{ message: string }>(
                    "/api/admin/configuration/smtp/test",
                    {
                      method: "POST",
                      body: JSON.stringify({ email: testEmail, revision: data?.revision }),
                    },
                  );
                  setTestMessage(result.message);
                  await load();
                } catch (err) {
                  setError(err instanceof Error ? err.message : "SMTP test failed.");
                } finally {
                  setTesting(false);
                }
              }}
            >
              <div>
                <h3>Test SMTP Delivery</h3>
                <p>Send a test email using the saved configuration.</p>
              </div>
              <label className="nconfig-field">
                <span>Test recipient email</span>
                <input
                  required
                  type="email"
                  maxLength={254}
                  value={testEmail}
                  placeholder="you@example.com"
                  disabled={locked}
                  onChange={(e) => setTestEmail(e.target.value)}
                />
              </label>
              <button className="nconfig-button" disabled={locked || dirty || !data?.revision}>
                <Send size={16} />
                {testing ? "Sending test…" : "Send Test Email"}
              </button>
            </form>
            {dirty && <p className="nconfig-help">Save your changes before testing.</p>}
            {testMessage && (
              <p className="nconfig-test-result" role="status">
                {testMessage}
              </p>
            )}
          </section>
          <NotificationOperations />
          <section className="nconfig-panel" ref={auditRef}>
            <header>
              <h2>Configuration Audit Log</h2>
              <span>Latest 20 applied changes · Philippine time</span>
            </header>
            <div className="nconfig-table">
              <table>
                <thead>
                  <tr>
                    <th>Revision</th>
                    <th>Configuration Item</th>
                    <th>Changed Fields</th>
                    <th>Changed By</th>
                    <th>Timestamp</th>
                    <th>Status</th>
                  </tr>
                </thead>
                <tbody>
                  {data?.changes.map((row) => (
                    <tr key={row.id}>
                      <td>SMTP-{row.revision.toString().padStart(4, "0")}</td>
                      <td>{row.module}</td>
                      <td>{JSON.parse(row.changed_fields).join(", ") || "No value change"}</td>
                      <td>{row.actor || row.id}</td>
                      <td>{stamp(row.created_at)}</td>
                      <td>
                        <span className="nconfig-pill">{row.action}</span>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {!data?.changes.length && (
                <p className="nconfig-empty">No saved SMTP configuration changes yet.</p>
              )}
            </div>
          </section>
        </div>
        <aside className="nconfig-aside">
          <section className="nconfig-panel">
            <header>
              <h2>Email Setup Workflow</h2>
            </header>
            <p className="nconfig-help">Configure and verify the outbound mail connection.</p>
            <div className="nconfig-workflow">
              {[
                [FileText, "Configure", "Save settings"],
                [Search, "Test", "Check mailbox"],
                [CheckCircle2, "Enable", "Allow copies"],
                [Send, "Send", "Track outcome"],
              ].map(([Icon, title, description], i) => {
                const I = Icon as ElementType;
                return (
                  <div key={String(title)}>
                    <span>
                      <I />
                    </span>
                    <strong>{String(title)}</strong>
                    <small>{String(description)}</small>
                    {i < 3 && <ArrowRight className="nconfig-step-arrow" size={15} />}
                  </div>
                );
              })}
            </div>
          </section>
          <div className="nconfig-aside-pair">
            <section className="nconfig-panel">
              <header>
                <h2>Environment Status</h2>
              </header>
              <ul className="nconfig-environments">
                <li>
                  <span>Application environment</span>
                  <b>{data?.environment || "—"}</b>
                </li>
                <li>
                  <span>SMTP delivery</span>
                  <b className={data?.smtp.enabled ? "good" : "pending"}>
                    {data?.smtp.enabled ? "Enabled" : "Disabled"}
                  </b>
                </li>
                <li>
                  <span>Current SMTP test</span>
                  <b className={data?.tested ? "good" : "pending"}>
                    {data?.tested ? "Accepted" : "Required"}
                  </b>
                </li>
                <li>
                  <span>BIR EIS transport</span>
                  <b className="pending">
                    {data?.system.eis_transport ? "Review evidence" : "Not enabled"}
                  </b>
                </li>
                <li>
                  <span>Queue driver</span>
                  <b>{data?.system.queue || "—"}</b>
                </li>
              </ul>
            </section>
            <section className="nconfig-panel">
              <header>
                <h2>Quick Actions</h2>
              </header>
              <div className="nconfig-quick">
                <button className="primary" disabled={locked || dirty} onClick={() => void load()}>
                  <RefreshCw />
                  Refresh Configuration<small>Retrieve current saved settings</small>
                </button>
                <button onClick={() => smtpRef.current?.scrollIntoView({ behavior: "smooth" })}>
                  <Mail />
                  Configure SMTP<small>Manage customer email delivery</small>
                </button>
                <button
                  disabled={!data}
                  onClick={() => {
                    const exported = {
                      revision: data?.revision,
                      smtp: { ...data?.smtp, username: "[redacted]" },
                      system: data?.system,
                    };
                    void openReportExport({
                      title: "System Configuration",
                      scope: "Current saved configuration with credentials excluded",
                      data: exported,
                    });
                  }}
                >
                  <Download />
                  Export Configuration<small>Download settings without credentials</small>
                </button>
                <button onClick={() => auditRef.current?.scrollIntoView({ behavior: "smooth" })}>
                  <History />
                  View Audit Trail<small>Review recorded configuration changes</small>
                </button>
              </div>
            </section>
          </div>
          <section className="nconfig-panel" ref={releaseRef}>
            <header>
              <h2>Release & Deployment Evidence</h2>
            </header>
            <p className="nconfig-help">
              {data?.system.release_reference ||
                "No verified release evidence reference is configured for this deployment."}
            </p>
            <Link className="nconfig-link" to="/super-admin/system-health">
              View operational readiness <ArrowRight size={14} />
            </Link>
          </section>
          <section className="nconfig-reminder">
            <TriangleAlert size={23} />
            <div>
              <h2>Configuration Reminder</h2>
              <p>
                SMTP changes take effect for new customer-copy requests. Test the saved connection
                before enabling delivery.
              </p>
              <ol>
                <li>Use the provider’s app password where required.</li>
                <li>Verify the sender address and recipient inbox.</li>
                <li>Review uncertain outcomes before sending again.</li>
                <li>Email delivery preserves payment and BIR status.</li>
              </ol>
            </div>
          </section>
        </aside>
      </div>
    </div>
  );
}
