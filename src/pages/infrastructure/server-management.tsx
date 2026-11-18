import { exportReport as openReportExport } from "@/components/reports/export-report";
import { subscribeRealtimeRefresh, useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { useCallback, useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import {
  Activity,
  Server,
  RefreshCw,
  Plus,
  Search,
  LayoutGrid,
  Table2,
  Settings,
  Terminal,
  Database,
  FileText,
  ShieldCheck,
  Cpu,
  MemoryStick,
  HardDrive,
  CheckCircle2,
  AlertCircle,
  Download,
  CalendarClock,
  ChevronRight,
  RotateCw,
  Power,
  Loader2,
  History,
  ArrowUpRight,
  MoreVertical,
} from "lucide-react";
import { apiJson } from "@/lib/api-url";
import {
  API,
  nice,
  stamp,
  bytes,
  uptime,
  type Overview,
  type Node,
  type Detail,
  type Action,
  type Event,
} from "./types";
import { Empty, Gauges, NodeCard, Panel, Status, Pagination, tierIcon } from "./components";
import { ManagementDialog, type Modal } from "./dialogs";
import { FleetMonitoring } from "./fleet-monitoring";
import { ResourceBackups } from "./resource-backups";
import { CapacityWorkspace } from "./capacity";
import "./server-management.css";
const tabs = [
  ["Servers", Server],
  ["Services", Settings],
  ["Console Access", Terminal],
  ["Backups", Database],
  ["Monitoring", Activity],
  ["Logs", FileText],
  ["Security", ShieldCheck],
  ["Settings", Settings],
  ["Providers & Expansion", Plus],
  ["Action History", History],
] as const;
const detailTabs = [
  "Overview",
  "Services",
  "Disk & Storage",
  "Network",
  "Backups",
  "Logs",
  "Settings",
] as const;
export default function ServerManagement() {
  const [data, setData] = useState<Overview | null>(null),
    [loading, setLoading] = useState(true),
    [error, setError] = useState(""),
    [notice, setNotice] = useState(""),
    [selected, setSelected] = useState(""),
    [detail, setDetail] = useState<Detail | null>(null),
    [detailError, setDetailError] = useState("");
  const [tab, setTab] = useState("Servers"),
    [detailTab, setDetailTab] = useState("Overview"),
    [view, setView] = useState("grid"),
    [search, setSearch] = useState(""),
    [tier, setTier] = useState("all"),
    [provider, setProvider] = useState("all"),
    [environment, setEnvironment] = useState("all"),
    [health, setHealth] = useState("all"),
    [site, setSite] = useState("all"),
    [maintenance, setMaintenance] = useState("all"),
    [sort, setSort] = useState("inventory"),
    [page, setPage] = useState(1),
    [range, setRange] = useState("1h"),
    [showFilters, setShowFilters] = useState(false);
  const [modal, setModal] = useState<Modal | null>(null),
    [actions, setActions] = useState<{ data: Action[]; total: number; last_page: number } | null>(
      null,
    ),
    [events, setEvents] = useState<{ data: Event[]; total: number; last_page: number } | null>(
      null,
    ),
    [historyPage, setHistoryPage] = useState(1),
    [historyState, setHistoryState] = useState(""),
    [recordsError, setRecordsError] = useState(""),
    [exporting, setExporting] = useState(false),
    [clock, setClock] = useState(new Date());
  const refresh = useCallback(async (quiet = false) => {
    if (!quiet) setLoading(true);
    try {
      const r = await apiJson<Overview>(API + "/servers");
      setData(r);
      setSelected((old) =>
        r.nodes.some((n) => n.id === old)
          ? old
          : r.nodes.find((n) => n.code === "WEB-01")?.id || r.nodes[0]?.id || "",
      );
      setError("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setLoading(false);
    }
  }, []);
  useEffect(() => {
    void refresh();
    const tick = setInterval(() => setClock(new Date()), 1000);
    return () => clearInterval(tick);
  }, [refresh]);
  useRealtimeRefresh(() => refresh(true), !modal && !exporting);
  useEffect(() => {
    if (!selected || tab === "Monitoring") return;
    const c = new AbortController();
    setDetail(null);
    const load = () =>
      apiJson<Detail>(API + `/servers/${selected}?range=${range}`, { signal: c.signal })
        .then((r) => {
          if (!c.signal.aborted) {
            setDetail(r);
            setDetailError("");
          }
        })
        .catch((e) => {
          if (!c.signal.aborted) setDetailError(e.message);
        });
    void load();
    const stop = subscribeRealtimeRefresh(load);
    return () => {
      c.abort();
      stop();
    };
  }, [selected, range, tab]);
  useEffect(() => {
    setPage(1);
  }, [search, tier, provider, health, site, maintenance, environment, sort]);
  useEffect(() => {
    if (!["Action History", "Logs"].includes(tab)) return;
    const c = new AbortController();
    const q =
      tab === "Action History"
        ? `/actions?page=${historyPage}&state=${historyState}`
        : `/events?page=${historyPage}`;
    const load = () =>
      apiJson(API + q, { signal: c.signal })
        .then((r) => {
          if (!c.signal.aborted) {
            tab === "Action History" ? setActions(r) : setEvents(r);
            setRecordsError("");
          }
        })
        .catch((e) => {
          if (!c.signal.aborted) setRecordsError(e.message);
        });
    void load();
    const stop = subscribeRealtimeRefresh(load);
    return () => {
      c.abort();
      stop();
    };
  }, [tab, historyPage, historyState]);
  const node = data?.nodes.find((n) => n.id === selected);
  const s = data?.summary;
  const filtered = useMemo(() => {
    const rows = (data?.nodes || []).filter(
      (n) =>
        (tier === "all" || n.tier === tier) &&
        (provider === "all" || n.provider === provider) &&
        (environment === "all" || n.environment === environment) &&
        (health === "all" || n.status === health) &&
        (site === "all" || n.site === site) &&
        (maintenance === "all" || n.maintenance === (maintenance === "yes")) &&
        [
          n.code,
          n.label,
          n.site,
          n.provider_label,
          n.provider_reference,
          n.region,
          n.telemetry.hostname,
          n.telemetry.ip,
        ]
          .filter(Boolean)
          .join(" ")
          .toLowerCase()
          .includes(search.toLowerCase()),
    );
    if (sort === "code") rows.sort((a, b) => a.code.localeCompare(b.code));
    if (sort === "health") rows.sort((a, b) => a.status.localeCompare(b.status));
    return rows;
  }, [data, tier, provider, environment, health, site, maintenance, search, sort]);
  const shown = filtered.slice((page - 1) * 12, page * 12);
  const changeTab = (t: string) => {
    setTab(t);
    setHistoryPage(1);
    setRecordsError("");
  };
  const action = (a: string, n = node, service?: string) => {
    if (n?.executable_actions.includes(a))
      setModal({ kind: "action", node: n, action: a, service });
  };
  async function exportReport() {
    setExporting(true);
    try {
      await openReportExport({
        path: API + "/export",
        title: "Server Inventory",
        scope: "Current infrastructure inventory; only fresh observed telemetry is reported",
      });
    } finally {
      setExporting(false);
    }
  }
  function serviceTable(n: Node) {
    if (n.managed_service)
      return (
        <Empty>
          This managed resource has no operating-system service controls. Use its supported
          configuration or recovery workflow.
        </Empty>
      );
    return (
      <div className="sm-table-wrap">
        <table>
          <thead>
            <tr>
              <th>Service</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            {n.services.map((s) => (
              <tr key={s.key}>
                <td>
                  <Settings size={14} />
                  {nice(s.key)}
                </td>
                <td>
                  <Status value={s.state} />
                </td>
                <td>
                  <button
                    onClick={() => action("service.restart", n, s.key)}
                    disabled={!n.executable_actions.includes("service.restart")}
                    title="Remote execution requires a separately approved controller. Open the AWS resource to manage it with your AWS permissions."
                  >
                    <RotateCw size={12} />
                    Restart
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }
  const allOnline = !!s && s.total > 0 && s.online + s.stopped === s.total;
  return (
    <div className="sm-page">
      <div className="sm-breadcrumb">
        <Link to="/super-admin">System Administration</Link>
        <ChevronRight size={12} />
        <span>Server Management</span>
      </div>
      <header className="sm-heading">
        <div>
          <h1>Server Management</h1>
          <p>
            Monitor, manage, and control NUERS servers. Manage services, resources, backups and
            configurations.
          </p>
        </div>
        <div className="sm-heading-actions">
          <div className={`sm-health ${allOnline ? "good" : ""}`}>
            <ShieldCheck size={20} />
            <span>
              <strong>
                {allOnline
                  ? "Infrastructure Connected"
                  : s?.offline
                    ? "Server Attention Required"
                    : "Infrastructure Setup"}
              </strong>
              <small>
                {s
                  ? `${s.online} online · ${s.stopped} stopped · ${s.total} resources`
                  : "Loading inventory…"}
              </small>
            </span>
          </div>
          <div className="sm-clock">
            <small>
              {clock.toLocaleDateString("en-PH", {
                timeZone: "Asia/Manila",
                weekday: "long",
                month: "short",
                day: "numeric",
                year: "numeric",
              })}
            </small>
            <strong>{clock.toLocaleTimeString("en-PH", { timeZone: "Asia/Manila" })}</strong>
            <small>System Time (PHT)</small>
          </div>
          <button disabled={loading} onClick={() => void refresh()}>
            <RefreshCw size={14} className={loading ? "animate-spin" : ""} />
            Refresh
          </button>
          <button
            className="sm-primary"
            disabled={!data}
            onClick={() => setModal({ kind: "enroll" })}
          >
            <Plus size={15} />
            Add Server
          </button>
          <details className="sm-more">
            <summary aria-label="More server management actions">
              <MoreVertical size={18} />
            </summary>
            <div>
              <button
                onClick={() => {
                  changeTab("Monitoring");
                  setHealth("offline");
                }}
              >
                <AlertCircle size={14} />
                Open Health Alerts
              </button>
              <button
                disabled={!node}
                onClick={() => node && setModal({ kind: "maintenance", node })}
              >
                <CalendarClock size={14} />
                Maintenance Window
              </button>
              <button disabled={!data || exporting} onClick={() => void exportReport()}>
                <Download size={14} />
                Export Inventory
              </button>
              <Link to="/super-admin/backups">
                Backup & Disaster Recovery
                <ArrowUpRight size={13} />
              </Link>
            </div>
          </details>
        </div>
      </header>
      {error && (
        <div className="sm-error" role="alert">
          {error} <button onClick={() => void refresh()}>Retry</button>
        </div>
      )}
      {notice && (
        <div className="sm-notice" role="status">
          {notice}
          <button onClick={() => setNotice("")} aria-label="Dismiss notification">
            ×
          </button>
        </div>
      )}
      <div className="sm-kpis">
        {[
          {
            label: "Total Resources",
            value: s?.total ?? "—",
            sub: s
              ? `${s.enrolled} enrolled · ${s.total - s.enrolled} planned / pending`
              : "Loading inventory",
            Icon: Server,
            color: "blue",
          },
          {
            label: "Online",
            value: s?.online ?? "—",
            sub: s ? `${s.unknown} without current telemetry` : "Health not measured",
            Icon: CheckCircle2,
            color: "green",
          },
          {
            label: "Offline",
            value: s?.offline ?? "—",
            sub: s
              ? `${s.degraded} degraded · ${s.stopped} stopped · ${s.maintenance} maintenance`
              : "Health not measured",
            Icon: AlertCircle,
            color: "red",
          },
          {
            label: "Average CPU Usage",
            value: s?.cpu == null ? "—" : `${s.cpu}%`,
            sub: `${s?.cpu_samples ?? 0} reporting servers`,
            Icon: Cpu,
            color: "blue",
          },
          {
            label: "Average Memory Usage",
            value: s?.memory == null ? "—" : `${s.memory}%`,
            sub: `${s?.memory_samples ?? 0} reporting servers`,
            Icon: MemoryStick,
            color: "purple",
          },
          {
            label: "Storage Used",
            value: bytes(s?.disk_used),
            sub:
              s?.disk_total != null
                ? `of ${bytes(s.disk_total)} · observed filesystem capacity; elastic storage separate`
                : "No capacity telemetry",
            Icon: HardDrive,
            color: "light",
          },
        ].map((k) => (
          <article key={k.label}>
            <span className={`sm-kpi-icon ${k.color}`}>
              <k.Icon size={25} />
            </span>
            <div>
              <strong>{k.value}</strong>
              <h2>{k.label}</h2>
              <small>{k.sub}</small>
            </div>
          </article>
        ))}
      </div>
      <div className="sm-toolbar">
        <nav aria-label="Server management sections">
          {tabs.map(([title, Icon]) => (
            <button
              key={title}
              className={tab === title ? "active" : ""}
              aria-pressed={tab === title}
              onClick={() => changeTab(title)}
            >
              <Icon size={15} />
              {title}
            </button>
          ))}
        </nav>
        <div className="sm-view-toggle">
          <button
            className={view === "grid" ? "active" : ""}
            onClick={() => setView("grid")}
            aria-pressed={view === "grid"}
          >
            <LayoutGrid size={14} />
            Grid View
          </button>
          <button
            className={view === "table" ? "active" : ""}
            onClick={() => setView("table")}
            aria-pressed={view === "table"}
          >
            <Table2 size={14} />
            Table View
          </button>
        </div>
        <label className="sm-search">
          <Search size={15} />
          <input
            aria-label="Search servers"
            placeholder="Search server by name, IP, or role…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
          />
        </label>
      </div>
      <div className="sm-filter-row">
        <span className="sm-muted">
          <i
            className={`sm-dot ${data?.controller.configured || data?.controller.observation_configured ? "good" : ""}`}
          />
          {data?.controller.configured || data?.controller.observation_configured
            ? `Telemetry ${nice(data.controller.sync_state)}`
            : "No controller connected"}{" "}
          ·{" "}
          {data?.controller.last_sync_at
            ? `Last sync ${stamp(data.controller.last_sync_at)} PHT`
            : "Planned inventory · no live telemetry"}
        </span>
        <button onClick={() => setShowFilters((v) => !v)} aria-expanded={showFilters}>
          Filters {filtered.length !== data?.nodes.length ? `(${filtered.length} results)` : ""}
        </button>
        <select
          aria-label="Environment"
          value={environment}
          onChange={(e) => setEnvironment(e.target.value)}
        >
          <option value="all">All environments</option>
          <option value="production">Production</option>
          <option value="staging">Staging</option>
        </select>
      </div>
      {showFilters && (
        <div className="sm-filters">
          <label>
            Provider
            <select value={provider} onChange={(e) => setProvider(e.target.value)}>
              <option value="all">All providers</option>
              {Object.entries(data?.provider_catalog.providers || {}).map(([key, name]) => (
                <option key={key} value={key}>
                  {name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Tier
            <select value={tier} onChange={(e) => setTier(e.target.value)}>
              <option value="all">All tiers</option>
              {["load_balancer", "web", "database", "storage"].map((v) => (
                <option key={v} value={v}>
                  {nice(v)}
                </option>
              ))}
            </select>
          </label>
          <label>
            Health
            <select value={health} onChange={(e) => setHealth(e.target.value)}>
              <option value="all">All health states</option>
              {[
                "online",
                "offline",
                "stopped",
                "degraded",
                "maintenance",
                "not_configured",
                "stale",
              ].map((v) => (
                <option key={v} value={v}>
                  {nice(v)}
                </option>
              ))}
            </select>
          </label>
          <label>
            Site
            <select value={site} onChange={(e) => setSite(e.target.value)}>
              <option value="all">All sites</option>
              {[...new Set(data?.nodes.map((n) => n.site))].map((v) => (
                <option key={v}>{v}</option>
              ))}
            </select>
          </label>
          <label>
            Maintenance
            <select value={maintenance} onChange={(e) => setMaintenance(e.target.value)}>
              <option value="all">All servers</option>
              <option value="yes">In maintenance</option>
              <option value="no">Outside maintenance</option>
            </select>
          </label>
          <label>
            Sort
            <select value={sort} onChange={(e) => setSort(e.target.value)}>
              <option value="inventory">Infrastructure tier</option>
              <option value="code">Server code</option>
              <option value="health">Health state</option>
            </select>
          </label>
          <button
            onClick={() => {
              setSearch("");
              setTier("all");
              setProvider("all");
              setHealth("all");
              setSite("all");
              setEnvironment("all");
              setMaintenance("all");
              setSort("inventory");
            }}
          >
            Reset
          </button>
        </div>
      )}
      {loading && !data && <Empty>Loading the secure server inventory…</Empty>}
      {data && tab === "Servers" && (
        <>
          {view === "grid" ? (
            <div className="sm-inventory">
              <div className="sm-node-grid primary">
                {shown
                  .filter((n) => ["load_balancer", "web"].includes(n.tier))
                  .map((n) => (
                    <NodeCard
                      key={n.id}
                      node={n}
                      selected={n.id === selected}
                      onSelect={() => {
                        setSelected(n.id);
                        setDetailTab("Overview");
                      }}
                    />
                  ))}
              </div>
              <div className="sm-node-grid secondary">
                {shown
                  .filter((n) => !["load_balancer", "web"].includes(n.tier))
                  .map((n) => (
                    <NodeCard
                      key={n.id}
                      node={n}
                      selected={n.id === selected}
                      onSelect={() => {
                        setSelected(n.id);
                        setDetailTab("Overview");
                      }}
                    />
                  ))}
              </div>
            </div>
          ) : (
            <Panel title="Server Inventory">
              <div className="sm-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Server / Role</th>
                      <th>Environment</th>
                      <th>Site</th>
                      <th>Address / Resource</th>
                      <th>Health</th>
                      <th>CPU / RAM</th>
                      <th>Last Telemetry (PHT)</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {shown.map((n) => (
                      <tr key={n.id}>
                        <td>
                          <strong>{n.code}</strong>
                          <small>{n.label}</small>
                        </td>
                        <td>{nice(n.environment)}</td>
                        <td>{n.site}</td>
                        <td>{n.telemetry.ip || n.provider_reference || "Not configured"}</td>
                        <td>
                          <Status value={n.status} />
                        </td>
                        <td>
                          {n.fresh
                            ? `${n.telemetry.cpu_percent == null ? "—" : `${Math.round(n.telemetry.cpu_percent)}%`} / ${n.telemetry.memory_percent == null ? "—" : `${Math.round(n.telemetry.memory_percent)}%`}`
                            : "—"}
                        </td>
                        <td>{stamp(n.observed_at)}</td>
                        <td>
                          <button
                            onClick={() => {
                              setSelected(n.id);
                              setDetailTab("Overview");
                            }}
                          >
                            Manage
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </Panel>
          )}
          {!filtered.length && <Empty>No servers match these filters.</Empty>}
          {filtered.length > 12 && (
            <Pagination
              page={page}
              pages={Math.ceil(filtered.length / 12)}
              total={filtered.length}
              onChange={setPage}
            />
          )}
          {node && (
            <div className="sm-detail-grid">
              <Panel
                title="Server Details"
                action={<Status value={node.status} />}
                className="sm-details"
              >
                <div className={`sm-server-heading sm-tier-${node.tier}`}>
                  <span className="sm-server-icon">
                    {(() => {
                      const Icon = tierIcon[node.tier];
                      return <Icon size={34} />;
                    })()}
                  </span>
                  <div>
                    <h2>{node.code}</h2>
                    <p>{node.label}</p>
                    <small>
                      {nice(node.environment)} Environment · {node.site}
                    </small>
                  </div>
                  <div className="sm-server-controls">
                    {node.console_url && (
                      <a
                        className="sm-link"
                        href={node.console_url}
                        target="_blank"
                        rel="noopener noreferrer"
                      >
                        Manage in AWS <ArrowUpRight size={14} />
                      </a>
                    )}
                    {node.supported_actions.includes("node.start") && (
                      <button
                        className="sm-primary"
                        disabled={!node.executable_actions.includes("node.start")}
                        onClick={() => action("node.start")}
                      >
                        <Power size={13} /> Start
                      </button>
                    )}
                    {node.supported_actions.includes("node.reboot") && (
                      <button
                        disabled={!node.executable_actions.includes("node.reboot")}
                        onClick={() => action("node.reboot")}
                      >
                        <RotateCw size={13} /> Restart
                      </button>
                    )}
                    {node.supported_actions.includes("node.shutdown") && (
                      <button
                        className="sm-danger"
                        disabled={!node.executable_actions.includes("node.shutdown")}
                        onClick={() => action("node.shutdown")}
                      >
                        <Power size={13} /> Shutdown
                      </button>
                    )}
                    <select
                      aria-label="More server actions"
                      value=""
                      onChange={(e) => {
                        if (e.target.value === "maintenance")
                          setModal({ kind: "maintenance", node });
                        else if (e.target.value) action(e.target.value);
                      }}
                    >
                      <option value="">More Actions</option>
                      <option value="maintenance">Maintenance window</option>
                      {Object.entries(data.actions)
                        .filter(
                          ([key, a]) =>
                            a.tiers.includes(node.tier) &&
                            node.supported_actions.includes(key) &&
                            !["node.start", "node.reboot", "node.shutdown"].includes(key),
                        )
                        .map(([key, a]) => (
                          <option
                            key={key}
                            value={key}
                            disabled={!node.executable_actions.includes(key)}
                          >
                            {a.label}
                          </option>
                        ))}
                    </select>
                  </div>
                </div>
                <p className="sm-muted sm-pad">
                  Observations refresh every minute. Power controls use your verified Super Admin
                  session and a Yes / No confirmation. Production restarts and shutdowns require a
                  healthy peer; safety checks run automatically.
                </p>
                {node.telemetry.database_nodes === 2 && (
                  <div className="sm-pad">
                    <h3>Database pair · 2 nodes</h3>
                    <div className="sm-overview-grid">
                      <div className="sm-detail-box">
                        <h3>DB-01 · Primary</h3>
                        <p>{node.telemetry.primary_zone} · Active read/write database</p>
                      </div>
                      <div className="sm-detail-box">
                        <h3>DB-02 · Standby</h3>
                        <p>{node.telemetry.standby_zone} · AWS-managed synchronous standby</p>
                      </div>
                    </div>
                    <p className="sm-muted">
                      One RDS Multi-AZ deployment with two database nodes. AWS manages failover; the
                      standby has no separate endpoint or independently reported CPU metrics.
                    </p>
                  </div>
                )}
                <nav className="sm-detail-tabs" aria-label="Server details">
                  {detailTabs.map((t) => (
                    <button
                      key={t}
                      className={detailTab === t ? "active" : ""}
                      onClick={() => setDetailTab(t)}
                    >
                      {t}
                    </button>
                  ))}
                </nav>
                {detailError && (
                  <p className="sm-error" role="alert">
                    {detailError}
                  </p>
                )}
                {detailTab === "Overview" && (
                  <div className="sm-overview-grid">
                    <div className="sm-detail-box">
                      <h3>Basic Information</h3>
                      <dl className="sm-kv">
                        {[
                          ["Provider", node.provider_label],
                          ["Resource type", node.resource_type_label],
                          ["Provider reference", node.provider_reference || "Not assigned"],
                          ["Region / data location", node.region || "Not assigned"],
                          ["Private network", node.network_zone || "Not verified"],
                          ["Hostname", node.telemetry.hostname || "Not configured"],
                          ["IP Address", node.telemetry.ip || "Not configured"],
                          [
                            "Role",
                            node.telemetry.role ? nice(node.telemetry.role) : "Not verified",
                          ],
                          ["Environment", nice(node.environment)],
                          ["Operating System", node.telemetry.os || "Not reported"],
                          ["Kernel Version", node.telemetry.kernel || "Not reported"],
                          [
                            "Uptime",
                            node.fresh
                              ? uptime(node.telemetry.uptime_seconds)
                              : "No current telemetry",
                          ],
                          ["HA Group", node.ha_group],
                          ["Last Telemetry", stamp(node.observed_at)],
                          ["Metric Observation", stamp(node.telemetry.metric_observed_at)],
                          ["Availability", node.telemetry.redundancy || "See provider resource"],
                        ].map(([k, v]) => (
                          <div key={k}>
                            <dt>{k}</dt>
                            <dd>{v}</dd>
                          </div>
                        ))}
                      </dl>
                    </div>
                    <div className="sm-detail-box">
                      <div className="sm-box-heading">
                        <h3>Resource Usage</h3>
                        <Range value={range} change={setRange} />
                      </div>
                      <Gauges node={node} samples={detail?.samples || []} />
                      <small className="sm-muted">
                        Observed telemetry only · no values inferred from planned hardware.
                      </small>
                    </div>
                  </div>
                )}
                {detailTab === "Services" && serviceTable(node)}
                {detailTab === "Disk & Storage" && (
                  <div className="sm-detail-box">
                    <h3>Disk & Storage</h3>
                    <dl className="sm-kv">
                      <div>
                        <dt>Host filesystem capacity</dt>
                        <dd>
                          {node.fresh ? bytes(node.telemetry.disk_total_bytes) : "Not measured"}
                        </dd>
                      </div>
                      <div>
                        <dt>Used</dt>
                        <dd>
                          {node.fresh ? bytes(node.telemetry.disk_used_bytes) : "Not measured"}
                        </dd>
                      </div>
                      <div>
                        <dt>Planned hardware</dt>
                        <dd>{node.planned_specs || "Not specified"}</dd>
                      </div>
                      <div>
                        <dt>{node.managed_service ? "Provider health" : "Ceph health"}</dt>
                        <dd>
                          {node.fresh &&
                          (node.managed_service
                            ? node.telemetry.checks?.provider_available
                            : node.telemetry.checks?.quorum_healthy &&
                              node.telemetry.checks?.pg_clean)
                            ? "Reported healthy"
                            : "Not verified"}
                        </dd>
                      </div>
                    </dl>
                    <p className="sm-muted">
                      {node.managed_service
                        ? "Managed storage usage is measured by its provider. Buckets are not fixed-size disks; RDS standby storage is not additional usable capacity."
                        : "Host filesystem totals are not Ceph logical usable capacity or backup capacity."}
                    </p>
                  </div>
                )}
                {detailTab === "Network" && (
                  <div className="sm-detail-box">
                    <h3>Network Observation</h3>
                    <dl className="sm-kv">
                      <div>
                        <dt>Reported IP</dt>
                        <dd>{node.telemetry.ip || "Not configured"}</dd>
                      </div>
                      <div>
                        <dt>Received / transmitted</dt>
                        <dd>
                          {node.fresh
                            ? `${bytes(node.telemetry.network_rx_bytes)} / ${bytes(node.telemetry.network_tx_bytes)}`
                            : "Not measured"}
                        </dd>
                      </div>
                      <div>
                        <dt>Recovery access</dt>
                        <dd>
                          {node.fresh && node.telemetry.checks?.out_of_band_ready
                            ? "Controller verified"
                            : "Not verified"}
                        </dd>
                      </div>
                    </dl>
                    {node.supported_actions.includes("network.change") && (
                      <button onClick={() => action("network.change")}>
                        Review Network Change
                      </button>
                    )}
                  </div>
                )}
                {detailTab === "Backups" && <BackupContent node={node} onAction={action} />}
                {detailTab === "Logs" && (
                  <>
                    <p className="sm-muted sm-pad">
                      Node enrollment and maintenance audit events. Raw OS logs require a separately
                      authorized log collector.
                    </p>
                    {detail?.events.map((e) => (
                      <div className="sm-event" key={e.id}>
                        <FileText size={15} />
                        <div>
                          <strong>{nice(e.event)}</strong>
                          <small>{stamp(e.occurred_at)} PHT</small>
                        </div>
                      </div>
                    ))}
                    {!detail?.events.length && <Empty>No node audit events recorded.</Empty>}
                  </>
                )}
                {detailTab === "Settings" && (
                  <div className="sm-detail-box">
                    <h3>Enrollment & Maintenance</h3>
                    <dl className="sm-kv">
                      <div>
                        <dt>Enrollment</dt>
                        <dd>{nice(node.enrollment)}</dd>
                      </div>
                      <div>
                        <dt>Fingerprint</dt>
                        <dd className="sm-mono">{node.fingerprint || "No verified identity"}</dd>
                      </div>
                      <div>
                        <dt>Maintenance</dt>
                        <dd>
                          {node.maintenance_start
                            ? `${stamp(node.maintenance_start)} – ${stamp(node.maintenance_end)} PHT`
                            : "Not scheduled"}
                        </dd>
                      </div>
                    </dl>
                    <div className="sm-inline-actions">
                      <button onClick={() => setModal({ kind: "maintenance", node })}>
                        <CalendarClock size={14} />
                        Schedule Window
                      </button>
                      <button
                        disabled={node.enrollment === "enrolled"}
                        onClick={() => setModal({ kind: "enroll", node })}
                      >
                        Prepare Enrollment
                      </button>
                    </div>
                  </div>
                )}
              </Panel>
              <Panel
                title="Running Services"
                action={<button onClick={() => setDetailTab("Services")}>Manage Services</button>}
              >
                {serviceTable(node)}
                {!node.fresh && (
                  <p className="sm-muted sm-pad">
                    Service names come from the approved tier registry. Running status is
                    unavailable until fresh telemetry arrives.
                  </p>
                )}
                <div className="sm-control-note">
                  <ShieldCheck size={19} />
                  <span>
                    Provider operations follow AWS permissions. Direct NUERS execution requires a
                    configured controller and safety review.
                  </span>
                </div>
              </Panel>
            </div>
          )}
        </>
      )}
      {data && tab === "Services" && (
        <div className="sm-services-grid">
          {filtered.map((n) => (
            <Panel key={n.id} title={n.code + " · " + n.label} action={<Status value={n.status} />}>
              {serviceTable(n)}
            </Panel>
          ))}
          {!filtered.length && <Empty>No matching servers.</Empty>}
        </div>
      )}
      {data && tab === "Monitoring" && (
        <Panel
          title="All Servers · Status & Performance"
          action={<Range value={range} change={setRange} />}
        >
          <FleetMonitoring
            nodes={filtered}
            range={range}
            generatedAt={data.generated_at}
            onSelect={(id) => {
              setSelected(id);
              changeTab("Servers");
            }}
          />
        </Panel>
      )}
      {data && tab === "Backups" && <ResourceBackups nodes={filtered} />}
      {data && tab === "Console Access" && (
        <Panel title="Recorded Console Access">
          <div className="sm-console">
            <Terminal size={45} />
            <h2>Provider console access</h2>
            {node?.console_url && (
              <a
                className="sm-link"
                href={node.console_url}
                target="_blank"
                rel="noopener noreferrer"
              >
                Open {node.code} in AWS <ArrowUpRight size={14} />
              </a>
            )}
            <p>
              Console access requires a verified bastion, session recording, fresh MFA, a
              maintenance window and an independent approver. This application does not expose a
              shell.
            </p>
            <label>
              Target Server
              <select value={selected} onChange={(e) => setSelected(e.target.value)}>
                {data.nodes.map((n) => (
                  <option key={n.id} value={n.id}>
                    {n.code} · {n.label}
                  </option>
                ))}
              </select>
            </label>
            <button
              className="sm-primary"
              disabled={!node?.executable_actions.includes("console.open")}
              onClick={() => action("console.open")}
            >
              <ShieldCheck size={15} />
              Review Console Access
            </button>
            <small>
              {node?.fresh && node.telemetry.checks?.bastion_recording
                ? "Recording capability reported; preflight still required."
                : "Bastion / session recording not verified."}
            </small>
          </div>
        </Panel>
      )}
      {data && tab === "Security" && (
        <>
          <Panel title="Access & Operational Safeguards">
            <div className="sm-security-grid">
              {[
                ["Super Administrator only", "Enforced on every infrastructure API endpoint."],
                [
                  "One-use verification",
                  "Password and authenticator proof expires after five minutes.",
                ],
                [
                  "Independent approval",
                  "Critical operations need a separate administrator; production restores need two.",
                ],
                [
                  "Pinned server identity",
                  "Browser inventory requests cannot authorize an agent or management URL.",
                ],
                [
                  "HA safety checks",
                  "Stale health, degraded peers, absent fencing or unsafe quorum block execution.",
                ],
                [
                  "Durable action history",
                  "Ambiguous outcomes retain the HA lock until authenticated reconciliation.",
                ],
              ].map(([title, body]) => (
                <article key={title}>
                  <ShieldCheck size={22} />
                  <h3>{title}</h3>
                  <p>{body}</p>
                </article>
              ))}
            </div>
          </Panel>
          <Panel title="Provider & Recovery Dependencies">
            <div className="sm-table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Capability</th>
                    <th>Status</th>
                    <th>Required evidence</th>
                  </tr>
                </thead>
                <tbody>
                  {[
                    [
                      "Provider power / KVM",
                      "Verified provider API entitlement and out-of-band recovery",
                    ],
                    ["MySQL failover", "Independent witness, GTID/lag validation and fencing"],
                    [
                      "Ceph maintenance",
                      "MON quorum, clean placement groups and sufficient replicas",
                    ],
                    [
                      "Recorded terminal",
                      "Verified bastion with JIT identity and protected recordings",
                    ],
                    [
                      "Off-host audit preservation",
                      "WORM destination and independent integrity checkpoints",
                    ],
                  ].map(([k, v]) => (
                    <tr key={k}>
                      <td>{k}</td>
                      <td>
                        <Status value="not_configured" />
                      </td>
                      <td>{v}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Panel>
        </>
      )}
      {data && tab === "Providers & Expansion" && (
        <CapacityWorkspace catalog={data.provider_catalog} />
      )}
      {data && tab === "Settings" && (
        <Panel title="Management Controller Configuration">
          <div className="sm-settings-grid">
            <div>
              <h3>Connection status</h3>
              <dl className="sm-kv">
                <div>
                  <dt>Telemetry collector</dt>
                  <dd>
                    {data.controller.observation_configured
                      ? "AWS signed observations"
                      : data.controller.configured
                        ? "mTLS controller"
                        : "Not configured"}
                  </dd>
                </div>
                <div>
                  <dt>Operational execution</dt>
                  <dd>{data.controller.execution_enabled ? "Enabled by operator" : "Disabled"}</dd>
                </div>
                <div>
                  <dt>Protocol</dt>
                  <dd>{data.controller.protocol}</dd>
                </div>
                <div>
                  <dt>Telemetry freshness</dt>
                  <dd>{data.controller.freshness_seconds} seconds</dd>
                </div>
                <div>
                  <dt>Refresh interval</dt>
                  <dd>AWS collection: 60 seconds; host observations: 30 seconds</dd>
                </div>
              </dl>
            </div>
            <div>
              <h3>Operator setup</h3>
              <ol>
                <li>Deploy an independent controller on the private management network.</li>
                <li>Configure verified mTLS certificates and a protected signing-key file.</li>
                <li>Verify and pin each node fingerprint and environment out of band.</li>
                <li>Run read-only telemetry validation and isolated action tests.</li>
                <li>Approve specific capabilities before enabling the dispatch worker.</li>
              </ol>
              <p className="sm-muted">
                Secrets and provider endpoints are configured outside the browser. Setup and
                runbooks are in <code>docs/infrastructure/</code>.
              </p>
            </div>
          </div>
        </Panel>
      )}
      {data && tab === "Action History" && (
        <Panel
          title="Action History"
          action={
            <select
              aria-label="Filter action state"
              value={historyState}
              onChange={(e) => {
                setHistoryState(e.target.value);
                setHistoryPage(1);
              }}
            >
              <option value="">All states</option>
              {[
                "awaiting_approval",
                "scheduled",
                "running",
                "uncertain",
                "succeeded",
                "failed",
                "blocked",
                "cancelled",
                "rejected",
                "expired",
                "rolled_back",
              ].map((s) => (
                <option key={s} value={s}>
                  {nice(s)}
                </option>
              ))}
            </select>
          }
        >
          {recordsError ? (
            <p className="sm-error" role="alert">
              {recordsError}
            </p>
          ) : (
            <>
              <div className="sm-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Requested (PHT)</th>
                      <th>Target</th>
                      <th>Action / Reason</th>
                      <th>Risk</th>
                      <th>Status</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {actions?.data.map((a) => (
                      <tr key={a.id}>
                        <td>{stamp(a.created_at)}</td>
                        <td>{a.node_code}</td>
                        <td>
                          <strong>{nice(a.action)}</strong>
                          <small>{a.reason}</small>
                        </td>
                        <td>{nice(a.risk)}</td>
                        <td>
                          <Status value={a.state} />
                        </td>
                        <td>
                          <button onClick={() => setModal({ kind: "history", record: a })}>
                            View / Review
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!actions?.data.length && (
                <Empty>
                  No infrastructure actions recorded. Use a server control to inspect its
                  requirements.
                </Empty>
              )}
              <Pagination
                page={historyPage}
                pages={actions?.last_page || 1}
                total={actions?.total || 0}
                onChange={setHistoryPage}
              />
            </>
          )}
        </Panel>
      )}
      {data && tab === "Logs" && (
        <Panel
          title="Infrastructure Audit Log"
          action={<span className="sm-muted">Append-only hash chain</span>}
        >
          {recordsError ? (
            <p role="alert" className="sm-error">
              {recordsError}
            </p>
          ) : (
            <>
              <div className="sm-table-wrap">
                <table>
                  <thead>
                    <tr>
                      <th>Time (PHT)</th>
                      <th>Event</th>
                      <th>Actor</th>
                      <th>Evidence</th>
                    </tr>
                  </thead>
                  <tbody>
                    {events?.data.map((e) => (
                      <tr key={e.id}>
                        <td>{stamp(e.occurred_at)}</td>
                        <td>{nice(e.event)}</td>
                        <td className="sm-mono">{e.actor_id}</td>
                        <td>
                          <details>
                            <summary>View record</summary>
                            <pre>{JSON.stringify(e.details, null, 2)}</pre>
                            <small className="sm-mono">SHA-256: {e.entry_hash}</small>
                          </details>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!events?.data.length && (
                <Empty>
                  No infrastructure audit events yet. Raw system logs need a verified log collector.
                </Empty>
              )}
              <Pagination
                page={historyPage}
                pages={events?.last_page || 1}
                total={events?.total || 0}
                onChange={setHistoryPage}
              />
            </>
          )}
        </Panel>
      )}
      <footer className="sm-page-footer">
        <span>
          <ShieldCheck size={14} />
          Secure infrastructure control · NUERS 1.0.0
        </span>
        <span>
          {loading ? (
            <>
              <Loader2 size={12} className="animate-spin" />
              Refreshing…
            </>
          ) : data ? (
            `Inventory refreshed ${stamp(data.generated_at)} PHT`
          ) : (
            "Inventory unavailable"
          )}
        </span>
      </footer>
      {modal && data && (
        <ManagementDialog
          key={JSON.stringify(modal)}
          modal={modal}
          definitions={data.actions}
          actorId={data.actor_id}
          providerCatalog={data.provider_catalog}
          onClose={() => setModal(null)}
          onSaved={(message) => {
            setModal(null);
            setNotice(message);
            void refresh();
          }}
        />
      )}
    </div>
  );
}
function Range({ value, change }: { value: string; change: (v: string) => void }) {
  return (
    <div className="sm-range" aria-label="Telemetry time range">
      {[
        ["1h", "1 Hour"],
        ["6h", "6 Hours"],
        ["24h", "1 Day"],
        ["7d", "7 Days"],
      ].map(([v, label]) => (
        <button
          key={v}
          className={v === value ? "active" : ""}
          aria-pressed={v === value}
          onClick={() => change(v)}
        >
          {label}
        </button>
      ))}
    </div>
  );
}
function BackupContent({ node }: { node: Node; onAction: (a: string, n?: Node) => void }) {
  return <ResourceBackups nodes={[node]} />;
}
