import { exportReport as openReportExport } from "@/components/reports/export-report";
import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useEffect, useState, type ElementType, type FormEvent } from "react";
import { Link, useLocation, useNavigate } from "react-router-dom";
import {
  Users,
  UserRound,
  UserRoundPlus,
  ShieldCheck,
  LockKeyhole,
  Building2,
  CalendarDays,
  Search,
  Download,
  Plus,
  Mail,
  Eye,
  Pencil,
  KeyRound,
  Ban,
  RefreshCw,
  ArrowRight,
  Crown,
  ShoppingCart,
  FileText,
  Code,
  Shield,
  Clock,
  CheckCircle2,
  Loader2,
  Bell,
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { apiJson } from "@/lib/api-url";
import "./merchant-dashboard.css";
import "./customers.css";
import "./users.css";
type Role = {
  id: string;
  name: string;
  description: string;
  color: string;
  permissions: string[];
  revision: number;
  user_count?: number;
  owner_only?: boolean;
};
type Member = {
  id: string;
  user_id: string | null;
  name: string;
  email: string;
  mobile: string;
  role_id: string;
  scope: string;
  branch_ids: string[];
  terminal_scope: string;
  terminal_ids: string[];
  status: string;
  revision: number;
  expires_at: string | null;
  last_login: string | null;
  mfa_enabled: boolean;
  protected: boolean;
};
type Branch = {
  id: string;
  name: string;
  code: string;
  bir_branch_code: string | null;
  status: string;
};
type Terminal = { id: string; name: string; code: string; branch_id: string; status: string };
type Data = {
  merchant: { id: string; name: string };
  summary: Record<string, number>;
  members: { data: Member[]; total: number; page: number; last_page: number };
  roles: Role[];
  permissions: Record<string, string>;
  branches: Branch[];
  terminals: Terminal[];
  invites: Member[];
  events: {
    id: string;
    subject_id: string;
    actor_id: string;
    action: string;
    created_at: string;
  }[];
  security: {
    password_min: number;
    idle_minutes: number;
    absolute_minutes: number;
    step_up_minutes: number | null;
    mfa_enforced: boolean;
    access_changes_ready: boolean;
    smtp_enabled: boolean;
  };
};
const stamp = (s: string | null) =>
  s
    ? new Date(s.includes("T") ? s : s.replace(" ", "T") + "Z").toLocaleString("en-PH", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "numeric",
        minute: "2-digit",
        timeZone: "Asia/Manila",
      })
    : "Never";
const roleIcons = [Crown, Building2, ShoppingCart, FileText, Shield, Eye, Code];
function Panel({
  title,
  subtitle,
  action,
  children,
  className = "",
}: {
  title: string;
  subtitle?: string;
  action?: React.ReactNode;
  children: React.ReactNode;
  className?: string;
}) {
  return (
    <section className={`mu-panel ${className}`}>
      <header>
        <div>
          <h2>{title}</h2>
          {subtitle && <p>{subtitle}</p>}
        </div>
        {action}
      </header>
      {children}
    </section>
  );
}
function Badge({ value }: { value: string }) {
  return (
    <span
      className={`mu-badge ${["active", "enrolled"].includes(value) ? "good" : ["invited", "expired"].includes(value) ? "pending" : "neutral"}`}
    >
      <i />
      {value === "invited" ? "Pending invite" : value.replaceAll("_", " ")}
    </span>
  );
}
export function MerchantUsersPage() {
  const location = useLocation(),
    navigate = useNavigate();
  const [data, setData] = useState<Data | null>(null),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [loading, setLoading] = useState(true),
    [filter, setFilter] = useState({ search: "", role: "", branch: "", status: "" }),
    [applied, setApplied] = useState(filter),
    [page, setPage] = useState(1),
    [per, setPer] = useState(8),
    [selected, setSelected] = useState<Member | null>(null),
    [roleId, setRoleId] = useState(""),
    [clock, setClock] = useState(new Date()),
    [modal, setModal] = useState<
      "invite" | "role" | "member" | "action" | "view" | "invites" | "export" | "actions" | null
    >(null),
    [action, setAction] = useState(""),
    [editRole, setEditRole] = useState<Role | null>(null),
    [discard, setDiscard] = useState(false),
    [dirty, setDirty] = useState(false);
  useEffect(() => {
    const search = new URLSearchParams(location.search).get("search") || "";
    setFilter((f) => ({ ...f, search }));
    setApplied((f) => ({ ...f, search }));
    setPage(1);
  }, [location.search]);
  useEffect(() => {
    setDirty(false);
    setDiscard(false);
  }, [modal]);
  useEffect(() => {
    const prevent = (e: BeforeUnloadEvent) => {
      if (dirty) e.preventDefault();
    };
    window.addEventListener("beforeunload", prevent);
    return () => window.removeEventListener("beforeunload", prevent);
  }, [dirty]);
  const query = new URLSearchParams({
    ...applied,
    page: String(page),
    per_page: String(per),
  }).toString();
  async function load() {
    setLoading(true);
    try {
      setData(await apiJson<Data>("/api/merchant/team?" + query));
      setError("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }
  useEffect(() => {
    const c = new AbortController();
    setLoading(true);
    const load = () =>
      apiJson<Data>("/api/merchant/team?" + query, { signal: c.signal })
        .then((d) => {
          setData(d);
          setError("");
          window.dispatchEvent(
            new CustomEvent("nuers:merchant-connection", {
              detail: { connected: true, updatedAt: new Date().toISOString() },
            }),
          );
        })
        .catch((e) => {
          if (e.name !== "AbortError") setError(e.message);
        })
        .finally(() => {
          if (!c.signal.aborted) setLoading(false);
        });
    void load();
    const stop = subscribeRealtimeRefresh(load);
    return () => {
      c.abort();
      stop();
    };
  }, [query]);
  useEffect(() => {
    const t = setInterval(() => setClock(new Date()), 1000);
    return () => clearInterval(t);
  }, []);
  const role = data?.roles.find((x) => x.id === (roleId || data.roles[0]?.id));
  const stats: [string, string, ElementType, string, string][] = [
    ["Total Users", "total", Users, "blue", "Accepted work accounts + owner"],
    ["Active Users", "active", UserRound, "green", "Current business access"],
    ["Pending Invites", "invited", UserRoundPlus, "orange", "Unexpired invitations"],
    ["Roles Configured", "roles", ShieldCheck, "purple", "Merchant permissions only"],
    ["MFA Enrolled Users", "mfa", LockKeyhole, "pink", "Active accounts with an authenticator"],
    [
      "Branch / Terminal Users",
      "scoped",
      Building2,
      "cyan",
      "Active users with a restricted scope",
    ],
  ];
  async function saved(msg: string) {
    setDiscard(false);
    setModal(null);
    setNotice(msg);
    setSelected(null);
    await load();
  }
  function memberAction(m: Member, a: string) {
    setSelected(m);
    setAction(a);
    setModal("action");
  }
  function edit(m: Member) {
    setSelected(m);
    setModal("member");
  }
  return (
    <div className="merchant-overview merchant-users">
      <section className="md-hero">
        <div className="md-welcome">
          <h1>
            <Users /> User Management
          </h1>
          <span>
            Manage merchant users, roles, permissions, access scopes, and security controls.
          </span>
        </div>
        <p className="md-motto">
          Secure Access.
          <br />
          Stronger Businesses.
        </p>
        <div className="md-clock">
          <CalendarDays size={32} />
          <div>
            <span>
              {clock.toLocaleDateString("en-PH", {
                weekday: "long",
                month: "long",
                day: "numeric",
                year: "numeric",
                timeZone: "Asia/Manila",
              })}
            </span>
            <strong>{clock.toLocaleTimeString("en-PH", { timeZone: "Asia/Manila" })}</strong>
            <small>{data && !error ? "NUERS responding" : "Checking connection"}</small>
          </div>
        </div>
      </section>
      {error && (
        <div className="mc-alert bad" role="alert">
          {error}
          <button onClick={() => void load()}>Retry</button>
        </div>
      )}
      {notice && (
        <div className="mc-alert good" role="status">
          {notice}
        </div>
      )}
      <div className="mu-stats">
        {stats.map(([title, key, Icon, color, detail]) => (
          <article key={key}>
            <span className={`mu-icon ${color}`}>
              <Icon />
            </span>
            <div>
              <h2 title={title}>{title}</h2>
              <strong>{data ? data.summary[key].toLocaleString() : "—"}</strong>
              <small>{detail}</small>
            </div>
          </article>
        ))}
      </div>
      {!data && loading ? (
        <div className="mc-empty">
          <Loader2 className="animate-spin" />
          Loading your merchant directory…
        </div>
      ) : (
        data && (
          <>
            <div className="mu-main">
              <Panel
                className="mu-directory"
                title="User Directory"
                subtitle="Business owner controls invitations and access grants. Financial records remain immutable."
                action={
                  <div className="mu-toolbar">
                    <button className="mc-primary" onClick={() => setModal("invite")}>
                      <UserRoundPlus />
                      Invite User
                    </button>
                    <button
                      onClick={() => {
                        setEditRole(null);
                        setModal("role");
                      }}
                    >
                      <Plus />
                      Create Role
                    </button>
                    <button onClick={() => setModal("export")}>
                      <Download />
                      Export Users
                    </button>
                    <button onClick={() => setModal("invites")}>
                      <Mail />
                      Pending Invites ({data.invites.length})
                    </button>
                  </div>
                }
              >
                <form
                  className="mu-filters"
                  onSubmit={(e) => {
                    e.preventDefault();
                    setApplied(filter);
                    setPage(1);
                  }}
                >
                  <label className="mu-search">
                    <span className="sr-only">Search users</span>
                    <Search />
                    <input
                      placeholder="Search by name, email, or user ID…"
                      value={filter.search}
                      onChange={(e) => setFilter({ ...filter, search: e.target.value })}
                    />
                  </label>
                  <select
                    aria-label="Filter role"
                    value={filter.role}
                    onChange={(e) => setFilter({ ...filter, role: e.target.value })}
                  >
                    <option value="">All Roles</option>
                    {data.roles.map((x) => (
                      <option key={x.id} value={x.id}>
                        {x.name}
                      </option>
                    ))}
                  </select>
                  <select
                    aria-label="Filter branch"
                    value={filter.branch}
                    onChange={(e) => setFilter({ ...filter, branch: e.target.value })}
                  >
                    <option value="">All Branches</option>
                    {data.branches.map((x) => (
                      <option key={x.id} value={x.id}>
                        {x.name}
                      </option>
                    ))}
                  </select>
                  <select
                    aria-label="Filter status"
                    value={filter.status}
                    onChange={(e) => setFilter({ ...filter, status: e.target.value })}
                  >
                    <option value="">All Statuses</option>
                    {["active", "invited", "expired", "suspended", "revoked"].map((x) => (
                      <option key={x}>{x}</option>
                    ))}
                  </select>
                  <button className="mc-primary">Search</button>
                  <button
                    type="button"
                    onClick={() => {
                      const f = { search: "", role: "", branch: "", status: "" };
                      setFilter(f);
                      setApplied(f);
                      setPage(1);
                      navigate("/merchant/users", { replace: true });
                    }}
                  >
                    Reset
                  </button>
                </form>
                <p className="mu-note" style={{ margin: "6px 0" }}>
                  Scroll horizontally to view all columns and user actions.
                </p>
                <div className="mu-table-scroll">
                  <table className="mu-table">
                    <thead>
                      <tr>
                        {[
                          "User ID",
                          "Full Name / Email",
                          "Mobile",
                          "Role",
                          "Assigned Branches",
                          "Terminal Scope",
                          "Status",
                          "Last Sign-in (PHT)",
                          "MFA",
                          "Actions",
                        ].map((x) => (
                          <th key={x}>{x}</th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {data.members.data.map((m) => (
                        <tr key={m.id}>
                          <td>
                            <button
                              className="mu-link"
                              onClick={() => {
                                setSelected(m);
                                setModal("view");
                              }}
                            >
                              {m.protected ? "OWNER" : `USR-${m.id.slice(0, 8)}`}
                            </button>
                          </td>
                          <td>
                            <strong>{m.name}</strong>
                            <span className="mu-email" title={m.email}>
                              {m.email}
                            </span>
                          </td>
                          <td>{m.mobile || "—"}</td>
                          <td>
                            <span
                              className={`mu-role ${data.roles.find((x) => x.id === m.role_id)?.color || "blue"}`}
                            >
                              {m.protected
                                ? "Merchant Admin"
                                : data.roles.find((x) => x.id === m.role_id)?.name || "Unavailable"}
                            </span>
                          </td>
                          <td>
                            {m.scope === "all"
                              ? "All Branches"
                              : m.branch_ids
                                  .map(
                                    (id) =>
                                      data.branches.find((x) => x.id === id)?.name ||
                                      "Unavailable branch",
                                  )
                                  .join(", ")}
                          </td>
                          <td>
                            {m.terminal_scope === "all"
                              ? "All Terminals"
                              : m.terminal_ids
                                  .map(
                                    (id) =>
                                      data.terminals.find((x) => x.id === id)?.code ||
                                      "Unavailable",
                                  )
                                  .join(", ")}
                          </td>
                          <td>
                            <Badge value={m.status} />
                          </td>
                          <td>{stamp(m.last_login)}</td>
                          <td>
                            <span
                              title={m.mfa_enabled ? "Authenticator enrolled" : "Not enrolled"}
                              className={m.mfa_enabled ? "mu-mfa" : "mu-muted"}
                            >
                              {m.mfa_enabled ? <CheckCircle2 size={17} /> : <Shield size={17} />}
                            </span>
                          </td>
                          <td>
                            <div className="mu-row-actions">
                              <button
                                aria-label={`View ${m.name}`}
                                onClick={() => {
                                  setSelected(m);
                                  setModal("view");
                                }}
                              >
                                <Eye />
                              </button>
                              {m.protected ? (
                                <LockKeyhole size={15} aria-label="Protected business owner" />
                              ) : (
                                <>
                                  <button
                                    title="Edit role and scope"
                                    disabled={["invited", "expired", "revoked"].includes(m.status)}
                                    onClick={() => edit(m)}
                                  >
                                    <Pencil />
                                  </button>
                                  <button
                                    aria-label={`Actions for ${m.name}`}
                                    onClick={() => {
                                      setSelected(m);
                                      setModal("actions");
                                    }}
                                  >
                                    •••
                                  </button>
                                </>
                              )}
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                  {!data.members.data.length && (
                    <div className="mc-empty">No users match these filters.</div>
                  )}
                </div>
                <div data-slot="table-pagination" className="mu-pagination">
                  <span>
                    {data.members.total
                      ? `Showing ${(page - 1) * per + 1} to ${Math.min(page * per, data.members.total)} of ${data.members.total} users`
                      : "No matching users"}
                  </span>
                  <div>
                    <button disabled={page === 1} onClick={() => setPage((p) => p - 1)}>
                      ‹
                    </button>
                    <strong>{page}</strong>
                    <span>of {data.members.last_page}</span>
                    <button
                      disabled={page >= data.members.last_page}
                      onClick={() => setPage((p) => p + 1)}
                    >
                      ›
                    </button>
                    <label>
                      Rows per page{" "}
                      <select
                        value={per}
                        onChange={(e) => {
                          setPer(Number(e.target.value));
                          setPage(1);
                        }}
                      >
                        {[8, 10, 25, 50].map((x) => (
                          <option key={x}>{x}</option>
                        ))}
                      </select>
                    </label>
                  </div>
                </div>
              </Panel>
              <Panel
                title="Role Definitions (RBAC)"
                subtitle="Merchant roles stay within this business."
                action={
                  <button
                    onClick={() => {
                      setEditRole(null);
                      setModal("role");
                    }}
                  >
                    <Plus />
                    Create Role
                  </button>
                }
              >
                <div className="mu-role-grid">
                  {data.roles.map((r, i) => {
                    const Icon = roleIcons[i % roleIcons.length];
                    return (
                      <button
                        key={r.id}
                        title={r.description}
                        className={`mu-role-card ${r.color} ${role?.id === r.id ? "selected" : ""}`}
                        onClick={() => setRoleId(r.id)}
                      >
                        <Icon />
                        <div>
                          <strong>{r.name}</strong>
                          <small>
                            {r.user_count ?? 0} active{" "}
                            {(r.user_count ?? 0) === 1 ? "user" : "users"}
                          </small>
                        </div>
                        <p>{r.description}</p>
                      </button>
                    );
                  })}
                </div>
              </Panel>
            </div>
            <div className="mu-bottom">
              <Panel
                title="Access Matrix / Permissions"
                subtitle="Select a role to review its enforced actions."
                action={
                  role &&
                  !role.owner_only && (
                    <button
                      onClick={() => {
                        setEditRole(role);
                        setModal("role");
                      }}
                    >
                      <Pencil />
                      Edit Role
                    </button>
                  )
                }
              >
                <label className="mu-field">
                  Selected role
                  <select value={role?.id || ""} onChange={(e) => setRoleId(e.target.value)}>
                    {data.roles.map((x) => (
                      <option key={x.id} value={x.id}>
                        {x.name}
                      </option>
                    ))}
                  </select>
                </label>
                <div className="mu-matrix">
                  {Object.entries(data.permissions).map(([key, text]) => (
                    <div key={key}>
                      <span>{text}</span>
                      <span className={role?.permissions.includes(key) ? "mu-mfa" : "mu-muted"}>
                        {role?.permissions.includes(key) ? (
                          <CheckCircle2 size={17} />
                        ) : (
                          <span>—</span>
                        )}
                      </span>
                    </div>
                  ))}
                </div>
              </Panel>
              <div className="mu-stack">
                <Panel
                  title="User Invitation & Approval"
                  subtitle="Invite, verify email ownership, then enroll MFA."
                >
                  <div className="mu-invite-actions">
                    <button className="mc-primary" onClick={() => setModal("invite")}>
                      <UserRoundPlus />
                      Invite User
                    </button>
                    <button onClick={() => setModal("invites")}>
                      <Mail />
                      Review Invitations
                    </button>
                  </div>
                  <p className="mu-note">
                    Single-use links expire after 72 hours. Existing personal or privileged accounts
                    cannot be converted into work accounts.
                  </p>
                </Panel>
                <Panel
                  title={`Pending Invitations (${data.invites.length})`}
                  action={
                    <button className="mu-link" onClick={() => setModal("invites")}>
                      View All <ArrowRight />
                    </button>
                  }
                >
                  {data.invites.slice(0, 3).map((m) => (
                    <article className="mu-invite" key={m.id}>
                      <span>{m.name.slice(0, 2).toUpperCase()}</span>
                      <div>
                        <strong>{m.name}</strong>
                        <small>{m.email}</small>
                      </div>
                      <Badge value={m.status} />
                    </article>
                  ))}
                  {!data.invites.length && (
                    <p className="mu-note">
                      No pending invitations. Invite a work user when needed.
                    </p>
                  )}
                </Panel>
              </div>
              <Panel
                title="Branch / Terminal Access Scope"
                subtitle="Exact registered branch and terminal restrictions."
              >
                <label className="mu-field">
                  Select User
                  <select
                    value={selected?.id || ""}
                    onChange={(e) =>
                      setSelected(data.members.data.find((x) => x.id === e.target.value) || null)
                    }
                  >
                    <option value="">Select a visible user</option>
                    {data.members.data.map((m) => (
                      <option key={m.id} value={m.id}>
                        {m.name}
                      </option>
                    ))}
                  </select>
                </label>
                <dl className="mu-scope">
                  <div>
                    <dt>Access scope</dt>
                    <dd>
                      {selected
                        ? selected.scope === "all"
                          ? "Business-wide"
                          : "Specific branches"
                        : "Select a user"}
                    </dd>
                  </div>
                  <div>
                    <dt>Assigned branches</dt>
                    <dd>
                      {selected
                        ? selected.scope === "all"
                          ? "All Branches"
                          : selected.branch_ids
                              .map(
                                (id) =>
                                  data.branches.find((b) => b.id === id)?.name || "Unavailable",
                              )
                              .join(", ")
                        : "—"}
                    </dd>
                  </div>
                  <div>
                    <dt>Terminal access</dt>
                    <dd>
                      {selected
                        ? selected.terminal_scope === "all"
                          ? "All Terminals"
                          : selected.terminal_ids
                              .map(
                                (id) =>
                                  data.terminals.find((t) => t.id === id)?.name || "Unavailable",
                              )
                              .join(", ")
                        : "—"}
                    </dd>
                  </div>
                </dl>
                <button
                  className="mc-primary"
                  disabled={
                    !selected ||
                    selected.protected ||
                    selected.status === "revoked" ||
                    ["invited", "expired"].includes(selected.status)
                  }
                  onClick={() => selected && edit(selected)}
                >
                  Edit Access Scope
                </button>
                <p className="mu-note">
                  Scoped roles can read / export matching documents. Historical records without
                  exact provenance are excluded.
                </p>
              </Panel>
              <Panel title="Security & Access Controls" subtitle="Current NUERS security policy.">
                <div className="mu-security">
                  {[
                    {
                      Icon: LockKeyhole,
                      title: "Multi-Factor Authentication",
                      detail: data.security.mfa_enforced
                        ? "Required for team operations and owner access changes"
                        : "Enforcement is disabled; review configuration",
                      state: data.security.mfa_enforced ? "Enforced" : "Review",
                    },
                    {
                      Icon: KeyRound,
                      title: "Password Policy",
                      detail: `At least ${data.security.password_min} characters, letters and numbers`,
                      state: "Enforced",
                    },
                    {
                      Icon: Clock,
                      title: "Session Lifetime",
                      detail: `${data.security.idle_minutes / 60}h idle · ${data.security.absolute_minutes / 1440} days maximum · device-bound`,
                      state: "Protected",
                    },
                    {
                      Icon: ShieldCheck,
                      title: "Sensitive Access Changes",
                      detail: `Owner-only · MFA verified for this login · reason required`,
                      state: "Audited",
                    },
                    {
                      Icon: Bell,
                      title: "Security Email Notifications",
                      detail: "Invitations, access changes and password recovery",
                      state: data.security.smtp_enabled ? "Configured" : "Configure SMTP",
                    },
                  ].map(({ Icon, title, detail, state }) => (
                    <article key={title}>
                      <Icon />
                      <div>
                        <strong>{title}</strong>
                        <small>{detail}</small>
                      </div>
                      <span>{state}</span>
                    </article>
                  ))}
                </div>
                <Link className="mu-link" to="/merchant/security">
                  My Account Security <ArrowRight />
                </Link>
              </Panel>
            </div>
            <Panel
              title="Recent Access Activity"
              subtitle="Append-only events linked to the platform audit chain."
              action={
                <button onClick={() => void load()}>
                  <RefreshCw />
                  Refresh
                </button>
              }
            >
              <div className="mu-events">
                {data.events.map((e) => (
                  <article key={e.id}>
                    <ShieldCheck size={16} />
                    <strong>{e.action.replaceAll("_", " ")}</strong>
                    <span title={e.subject_id}>{e.subject_id.slice(0, 8)}</span>
                    <time>{stamp(e.created_at)}</time>
                  </article>
                ))}
                {!data.events.length && <p className="mu-note">No team access changes recorded.</p>}
              </div>
            </Panel>
          </>
        )
      )}
      <Dialog
        open={!!modal}
        onOpenChange={(open) => {
          if (!open) {
            if (document.querySelector('.mu-dialog form[aria-busy="true"]')) return;
            if (dirty && ["invite", "role", "member", "action"].includes(modal || ""))
              setDiscard(true);
            else setModal(null);
          }
        }}
      >
        <DialogContent className="mu-dialog" onChangeCapture={() => setDirty(true)}>
          <DialogHeader>
            <DialogTitle>
              {modal === "invite"
                ? "Invite a Merchant User"
                : modal === "role"
                  ? editRole
                    ? "Edit Role"
                    : "Create Role"
                  : modal === "member"
                    ? "Edit Role & Access Scope"
                    : modal === "invites"
                      ? "Pending Invitations"
                      : modal === "export"
                        ? "Export User Directory"
                        : modal === "view"
                          ? "Merchant User Details"
                          : modal === "actions"
                            ? "User Actions"
                            : "Confirm Access Change"}
            </DialogTitle>
            <DialogDescription>
              {modal === "invite"
                ? "An email invitation grants access only after acceptance. Use a separate work email."
                : modal === "action"
                  ? "Review the user, change and reason before continuing. The business owner remains protected."
                  : "Access changes are validated, versioned and recorded in the audit trail."}
            </DialogDescription>
          </DialogHeader>
          {discard && (
            <div role="alert" className="mu-discard">
              <strong>Discard unsaved changes?</strong>
              <div>
                <button type="button" onClick={() => setDiscard(false)}>
                  Keep Editing
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setDiscard(false);
                    setModal(null);
                  }}
                >
                  Discard & Close
                </button>
              </div>
            </div>
          )}
          {data &&
            modal &&
            (!data.security.access_changes_ready &&
            ["invite", "member", "role", "action", "export"].includes(modal) ? (
              <div className="mu-form">
                <p>
                  Verify your authenticator before changing merchant access or exporting user
                  details. Your current role selections remain stored on the server.
                </p>
                <Link className="mc-primary" to="/security/mfa">
                  <ShieldCheck size={18} />
                  Verify MFA to Continue
                </Link>
              </div>
            ) : modal === "invite" || modal === "member" ? (
              <MemberForm
                key={selected?.id || "new"}
                data={data}
                member={modal === "member" ? selected : null}
                onSaved={saved}
              />
            ) : modal === "role" ? (
              <RoleForm data={data} role={editRole} onSaved={saved} />
            ) : modal === "actions" && selected ? (
              <div className="mu-action-list">
                <p>
                  <strong>{selected.name}</strong> · {selected.email}
                </p>
                {(selected.status === "active"
                  ? [
                      ["reset_password", "Email Password Reset"],
                      ["revoke_sessions", "Revoke Sessions"],
                      ["suspend", "Suspend Access"],
                      ["revoke", "Revoke Access"],
                    ]
                  : selected.status === "suspended"
                    ? [
                        ["activate", "Restore Access"],
                        ["revoke", "Revoke Access"],
                      ]
                    : ["invited", "expired"].includes(selected.status)
                      ? [
                          ["resend", "Resend Invitation"],
                          ["revoke", "Cancel Invitation"],
                        ]
                      : []
                ).map(([a, text]) => (
                  <button key={a} onClick={() => memberAction(selected, a)}>
                    {text}
                    <ArrowRight size={16} />
                  </button>
                ))}
              </div>
            ) : modal === "action" && selected ? (
              <ConfirmAction member={selected} action={action} onSaved={saved} />
            ) : modal === "view" && selected ? (
              <>
                <dl className="mu-detail">
                  {Object.entries({
                    Name: selected.name,
                    Email: selected.email,
                    Role: selected.protected
                      ? "Merchant Admin"
                      : data.roles.find((x) => x.id === selected.role_id)?.name || "Unavailable",
                    Status: selected.status,
                    "Last sign-in": stamp(selected.last_login),
                    "MFA enrolled": selected.mfa_enabled ? "Yes" : "No",
                    "Invitation expires": selected.expires_at
                      ? stamp(selected.expires_at)
                      : "Not applicable",
                    Revision: String(selected.revision),
                  }).map(([k, v]) => (
                    <div key={k}>
                      <dt>{k}</dt>
                      <dd>{v}</dd>
                    </div>
                  ))}
                </dl>
                {selected.protected && (
                  <p className="mu-note">
                    Business ownership cannot be edited, suspended or revoked through team roles.
                  </p>
                )}
              </>
            ) : modal === "invites" ? (
              <div className="mu-invites-list">
                {data.invites.map((m) => (
                  <article key={m.id}>
                    <strong>{m.name}</strong>
                    <span>{m.email}</span>
                    <Badge value={m.status} />
                    <small>Expires {stamp(m.expires_at)}</small>
                    <button onClick={() => memberAction(m, "resend")}>
                      <Mail />
                      Resend
                    </button>
                    <button onClick={() => memberAction(m, "revoke")}>
                      <Ban />
                      Cancel
                    </button>
                  </article>
                ))}
                {!data.invites.length && <p>No pending invitations.</p>}
              </div>
            ) : modal === "export" ? (
              <ExportForm onSaved={saved} />
            ) : null)}
        </DialogContent>
      </Dialog>
    </div>
  );
}
function useUnsaved(dirty: boolean) {
  useEffect(() => {
    const f = (e: BeforeUnloadEvent) => {
      if (dirty) e.preventDefault();
    };
    window.addEventListener("beforeunload", f);
    return () => window.removeEventListener("beforeunload", f);
  }, [dirty]);
}
function MemberForm({
  data,
  member,
  onSaved,
}: {
  data: Data;
  member: Member | null;
  onSaved: (s: string) => Promise<void>;
}) {
  const [name, setName] = useState(""),
    [email, setEmail] = useState(""),
    [mobile, setMobile] = useState(""),
    [role, setRole] = useState(
      member?.role_id ||
        data.roles.find((x) => x.name === "Viewer / Auditor")?.id ||
        data.roles.find((x) => !x.owner_only)?.id ||
        "",
    ),
    [scope, setScope] = useState(member?.scope || "all"),
    [branches, setBranches] = useState<string[]>(member?.branch_ids || []),
    [terminalScope, setTerminalScope] = useState(member?.terminal_scope || "all"),
    [terminals, setTerminals] = useState<string[]>(member?.terminal_ids || []),
    [reason, setReason] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  useUnsaved(!!(name || email || reason));
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      const p = {
        role_id: role,
        scope,
        branch_ids: scope === "all" ? [] : branches,
        terminal_scope: terminalScope,
        terminal_ids: terminalScope === "all" ? [] : terminals,
        reason,
        ...(member ? { revision: member.revision, action: "grants" } : { name, email, mobile }),
      };
      const r = await apiJson<{ message: string }>(
        member ? `/api/merchant/team/members/${member.id}` : "/api/merchant/team/invitations",
        { method: member ? "PATCH" : "POST", body: JSON.stringify(p) },
      );
      await onSaved(r.message);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const toggle = (xs: string[], id: string) =>
    xs.includes(id) ? xs.filter((x) => x !== id) : [...xs, id];
  return (
    <form className="mu-form" aria-busy={busy} onSubmit={submit}>
      {error && (
        <p className="mc-alert bad" role="alert">
          {error}
        </p>
      )}
      {!member && (
        <>
          <label>
            Full Name
            <input
              autoFocus
              required
              minLength={2}
              maxLength={120}
              value={name}
              onChange={(e) => setName(e.target.value)}
            />
          </label>
          <label>
            Work Email
            <input
              required
              type="email"
              maxLength={254}
              value={email}
              onChange={(e) => setEmail(e.target.value)}
            />
          </label>
          <label>
            Mobile (optional)
            <input maxLength={40} value={mobile} onChange={(e) => setMobile(e.target.value)} />
          </label>
        </>
      )}
      {member && (
        <p>
          <strong>{member.name}</strong> · {member.email} · revision {member.revision}
        </p>
      )}
      <label>
        Merchant Role
        <select value={role} onChange={(e) => setRole(e.target.value)}>
          {data.roles
            .filter((r) => !r.owner_only)
            .map((r) => (
              <option key={r.id} value={r.id}>
                {r.name}
              </option>
            ))}
        </select>
      </label>
      <p className="mu-note">{data.roles.find((r) => r.id === role)?.description}</p>
      <label>
        Branch Access
        <select
          value={scope}
          onChange={(e) => {
            setScope(e.target.value);
            setBranches([]);
            setTerminals([]);
          }}
        >
          <option value="all">All Branches</option>
          <option value="branches">Specific Branches (document-only roles)</option>
        </select>
      </label>
      {scope === "branches" && (
        <fieldset>
          <legend>Assigned active branches</legend>
          {data.branches
            .filter((x) => x.status === "active" && x.bir_branch_code)
            .map((b) => (
              <label key={b.id}>
                <input
                  type="checkbox"
                  checked={branches.includes(b.id)}
                  onChange={() => {
                    setBranches(toggle(branches, b.id));
                    setTerminals([]);
                  }}
                />
                {b.name} · {b.bir_branch_code}
              </label>
            ))}
          {!data.branches.some((x) => x.status === "active" && x.bir_branch_code) && (
            <p>
              No active branches with an exact branch code. Ask an administrator to review the
              registered business location.
            </p>
          )}
        </fieldset>
      )}
      <label>
        Terminal Access
        <select
          value={terminalScope}
          onChange={(e) => {
            setTerminalScope(e.target.value);
            setTerminals([]);
          }}
        >
          <option value="all">All Terminals</option>
          <option value="terminals">Specific Terminals (document-only roles)</option>
        </select>
      </label>
      {terminalScope === "terminals" && (
        <fieldset>
          <legend>Assigned active terminals</legend>
          {data.terminals
            .filter(
              (t) => t.status === "active" && (scope === "all" || branches.includes(t.branch_id)),
            )
            .map((t) => (
              <label key={t.id}>
                <input
                  type="checkbox"
                  checked={terminals.includes(t.id)}
                  onChange={() => setTerminals(toggle(terminals, t.id))}
                />
                {t.code} · {t.name}
              </label>
            ))}
        </fieldset>
      )}
      <label>
        Reason for this access grant
        <textarea
          required
          minLength={10}
          maxLength={1000}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      </label>
      <p className="mu-note">
        Owner MFA is required before saving. Narrow scopes are limited to document reads / exports.
        Role grants never authorize BIR issuance or acceptance.
      </p>
      <button
        className="mc-primary"
        disabled={busy || !role || (!member && !data.security.smtp_enabled)}
      >
        {busy ? <Loader2 className="animate-spin" /> : member ? <ShieldCheck /> : <Mail />}
        {busy ? "Saving…" : member ? "Save Access & Revoke Sessions" : "Send Invitation"}
      </button>
      {!data.security.smtp_enabled && !member && (
        <p role="alert">NUERS SMTP must be enabled before sending invitations.</p>
      )}
    </form>
  );
}
function RoleForm({
  data,
  role,
  onSaved,
}: {
  data: Data;
  role: Role | null;
  onSaved: (s: string) => Promise<void>;
}) {
  const [name, setName] = useState(role?.name || ""),
    [description, setDescription] = useState(role?.description || ""),
    [color, setColor] = useState(role?.color || "blue"),
    [permissions, setPermissions] = useState(role?.permissions || []),
    [reason, setReason] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  useUnsaved(!!reason);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await apiJson<{ message: string }>(
        role ? `/api/merchant/team/roles/${role.id}` : "/api/merchant/team/roles",
        {
          method: role ? "PUT" : "POST",
          body: JSON.stringify({
            name,
            description,
            color,
            permissions,
            reason,
            revision: role?.revision,
          }),
        },
      );
      await onSaved(r.message);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="mu-form" aria-busy={busy} onSubmit={submit}>
      {error && (
        <p role="alert" className="mc-alert bad">
          {error}
        </p>
      )}
      <label>
        Role Name
        <input
          required
          minLength={2}
          maxLength={80}
          value={name}
          onChange={(e) => setName(e.target.value)}
        />
      </label>
      <label>
        Description
        <textarea
          required
          maxLength={300}
          value={description}
          onChange={(e) => setDescription(e.target.value)}
        />
      </label>
      <label>
        Role Color
        <select value={color} onChange={(e) => setColor(e.target.value)}>
          {["blue", "green", "purple", "orange", "pink", "cyan", "slate"].map((x) => (
            <option key={x}>{x}</option>
          ))}
        </select>
      </label>
      <fieldset>
        <legend>Enforced Permissions</legend>
        {Object.entries(data.permissions).map(([id, label]) => (
          <label key={id}>
            <input
              type="checkbox"
              checked={permissions.includes(id)}
              onChange={() =>
                setPermissions((p) => (p.includes(id) ? p.filter((x) => x !== id) : [...p, id]))
              }
            />
            {label}
          </label>
        ))}
      </fieldset>
      <label>
        Reason
        <textarea
          required
          minLength={10}
          maxLength={1000}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      </label>
      <p className="mu-note">
        Issued documents cannot be edited or deleted through role grants. Team access, API
        credential changes, wallet funding and BIR authority remain owner / platform controlled.
      </p>
      <button className="mc-primary" disabled={busy}>
        {busy ? "Saving…" : "Save Role"}
      </button>
    </form>
  );
}
function ConfirmAction({
  member,
  action,
  onSaved,
}: {
  member: Member;
  action: string;
  onSaved: (s: string) => Promise<void>;
}) {
  const [reason, setReason] = useState(""),
    [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  useUnsaved(!!reason);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      const r = await apiJson<{ message: string }>(`/api/merchant/team/members/${member.id}`, {
        method: "PATCH",
        body: JSON.stringify({ action, reason, revision: member.revision }),
      });
      await onSaved(r.message);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="mu-form" aria-busy={busy} onSubmit={submit}>
      <p>
        <strong>{member.name}</strong>
        <br />
        {member.email}
      </p>
      <dl className="mu-detail">
        <div>
          <dt>Current status</dt>
          <dd>{member.status}</dd>
        </div>
        <div>
          <dt>Requested action</dt>
          <dd>{action.replaceAll("_", " ")}</dd>
        </div>
        <div>
          <dt>Record revision</dt>
          <dd>{member.revision}</dd>
        </div>
      </dl>
      {action === "reset_password" && (
        <p className="mu-note">
          NUERS emails a 30-minute, single-use recovery link. The owner cannot choose or view the
          user's password. Existing MFA remains enforced.
        </p>
      )}
      {error && (
        <p role="alert" className="mc-alert bad">
          {error}
        </p>
      )}
      <label>
        Reason
        <textarea
          autoFocus
          required
          minLength={10}
          maxLength={1000}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      </label>
      <button className="mc-primary" disabled={busy}>
        {busy ? "Saving…" : "Confirm " + action.replaceAll("_", " ")}
      </button>
    </form>
  );
}
function ExportForm({ onSaved }: { onSaved: (s: string) => Promise<void> }) {
  const [reason, setReason] = useState(""),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    try {
      if (
        !(await openReportExport({
          path: "/api/merchant/team/export?" + new URLSearchParams({ reason }),
          title: "Merchant User Directory",
          scope: "Complete directory of the current business, including the protected owner",
          filters: { purpose: reason },
        }))
      )
        return;
      await onSaved("Team directory exported and audited.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <form className="mu-form" aria-busy={busy} onSubmit={submit}>
      <p>
        This export contains the business's work-user details. Store and share it only with
        authorized recipients.
      </p>
      {error && <p role="alert">{error}</p>}
      <label>
        Purpose of Export
        <textarea
          required
          minLength={10}
          maxLength={1000}
          value={reason}
          onChange={(e) => setReason(e.target.value)}
        />
      </label>
      <button className="mc-primary" disabled={busy}>
        <Download />
        {busy ? "Exporting…" : "Choose PDF / CSV"}
      </button>
    </form>
  );
}
export function AcceptTeamInvitation() {
  const [token] = useState(() => {
      const p = new URLSearchParams(window.location.hash.slice(1));
      const t = p.get("token") || "";
      window.history.replaceState(null, "", window.location.pathname);
      return t;
    }),
    [data, setData] = useState<{
      name: string;
      email: string;
      business: string;
      role: string;
      scope: string;
      terminal_scope: string;
      notice_version: string;
    } | null>(null),
    [error, setError] = useState(""),
    [password, setPassword] = useState(""),
    [confirm, setConfirm] = useState(""),
    [accepted, setAccepted] = useState(false),
    [terms, setTerms] = useState(false),
    [busy, setBusy] = useState(false);
  useEffect(() => {
    apiJson<typeof data>("/api/team-invitations/preview", {
      method: "POST",
      body: JSON.stringify({ token }),
    })
      .then(setData)
      .catch((e) => setError(e.message));
  }, [token]);
  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError("");
    try {
      await apiJson("/api/team-invitations/accept", {
        method: "POST",
        body: JSON.stringify({
          token,
          password,
          password_confirmation: confirm,
          accept_terms: terms,
          notice_version: data?.notice_version,
        }),
      });
      setPassword("");
      setConfirm("");
      setAccepted(true);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <main className="mu-accept">
      <img src="/brand/nuers-official-logo.png" alt="NUERS" />
      <h1>Accept Merchant Invitation</h1>
      {error && (
        <p role="alert" className="mc-alert bad">
          {error}
        </p>
      )}
      {accepted ? (
        <>
          <p>
            Your work account is ready. Sign in with <strong>{data?.email}</strong> and enroll in
            MFA to access the assigned workspace.
          </p>
          <Link to="/login">
            Sign In to NUERS <ArrowRight size={16} />
          </Link>
        </>
      ) : data ? (
        <form className="mu-form" aria-busy={busy} onSubmit={submit}>
          <p>
            Hello {data.name}. You are joining <strong>{data.business}</strong> as{" "}
            <strong>{data.role}</strong>.
          </p>
          <dl className="mu-detail">
            <div>
              <dt>Work email</dt>
              <dd>{data.email}</dd>
            </div>
            <div>
              <dt>Access scope</dt>
              <dd>
                {data.scope === "all" ? "All branches" : "Assigned branches"} ·{" "}
                {data.terminal_scope === "all" ? "all terminals" : "assigned terminals"}
              </dd>
            </div>
          </dl>
          <label>
            New Work Password
            <input
              required
              type="password"
              autoComplete="new-password"
              minLength={12}
              maxLength={72}
              value={password}
              onChange={(e) => setPassword(e.target.value)}
            />
          </label>
          <label>
            Confirm Password
            <input
              required
              type="password"
              autoComplete="new-password"
              value={confirm}
              onChange={(e) => setConfirm(e.target.value)}
            />
          </label>
          <small>
            At least 12 characters with letters and numbers. MFA enrollment follows sign-in.
          </small>
          <label className="mu-terms">
            <input
              type="checkbox"
              required
              checked={terms}
              onChange={(e) => setTerms(e.target.checked)}
            />
            <span>
              I agree to the{" "}
              <Link to="/terms-of-service" target="_blank">
                Terms of Service
              </Link>{" "}
              and acknowledge the{" "}
              <Link to="/privacy-notice" target="_blank">
                Privacy Notice
              </Link>
              .
            </span>
          </label>
          <button className="mc-primary" disabled={busy}>
            {busy ? "Accepting…" : "Accept Invitation"}
          </button>
        </form>
      ) : !error ? (
        <p>Checking invitation…</p>
      ) : (
        <Link to="/login">Return to Sign In</Link>
      )}
    </main>
  );
}
