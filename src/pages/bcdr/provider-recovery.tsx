import { useMemo, useState } from "react";
import {
  Archive,
  Cloud,
  Database,
  Download,
  ExternalLink,
  ShieldCheck,
  TriangleAlert,
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

type RecordRow = {
  id: string;
  kind: string;
  resource: string;
  resource_type: string;
  region: string;
  status: string;
  created_at: string;
  completed_at: string | null;
  recovery_point: string | null;
  source_point?: string | null;
  size_bytes: number | null;
  encrypted: boolean | null;
  retention_days: number | null;
  expires_at: string | null;
  destination_region?: string;
};
export type ProviderRecoveryData = {
  available: boolean;
  fresh: boolean;
  observed_at: string | null;
  lookback_days: number;
  successful_today: number;
  failed_today: number;
  regions: {
    region: string;
    vault_locked: boolean;
    jobs: RecordRow[];
    points: RecordRow[];
    copies: RecordRow[];
    truncated: boolean;
  }[];
  rules: {
    name: string;
    schedule: string;
    timezone: string;
    retention_days: number | null;
    copy_regions: { region: string; retention_days: number | null }[];
  }[];
  scheduled_resources: string[];
  database: {
    multi_az: boolean;
    state: string;
    primary_zone: string;
    standby_zone: string;
    retention_days: number;
    earliest_restore_at: string | null;
    latest_restore_at: string | null;
  };
};
const stamp = (value?: string | null) =>
  value
    ? new Date(value).toLocaleString("en-PH", {
        timeZone: "Asia/Manila",
        dateStyle: "medium",
        timeStyle: "short",
      })
    : "Not reported";
const regionName = (value: string) =>
  value === "ap-southeast-1" ? "Singapore" : value === "ap-southeast-2" ? "Sydney" : value;
const bytes = (value: number | null) =>
  value == null || value === 0
    ? "Not reported"
    : value >= 1073741824
      ? `${(value / 1073741824).toFixed(2)} GiB`
      : `${(value / 1048576).toFixed(2)} MiB`;
const consoleUrl = (region: string, page = "jobs") =>
  `https://${region}.console.aws.amazon.com/backup/home?region=${region}#/${page}`;
function Status({ value }: { value: string }) {
  return (
    <span
      className={`bdr-pill ${value === "COMPLETED" ? "good" : ["FAILED", "ABORTED", "EXPIRED"].includes(value) ? "bad" : "pending"}`}
    >
      {value.replaceAll("_", " ")}
    </span>
  );
}

export function ProviderRecoveryWorkspace({
  data,
  onExport,
  onRequest,
  exporting,
}: {
  data: ProviderRecoveryData;
  onExport: () => void;
  onRequest: (kind: string) => void;
  exporting: boolean;
}) {
  const [tab, setTab] = useState<"jobs" | "points" | "copies">("jobs");
  const [search, setSearch] = useState(""),
    [region, setRegion] = useState(""),
    [asset, setAsset] = useState(""),
    [status, setStatus] = useState("");
  const [page, setPage] = useState(1),
    [detail, setDetail] = useState<RecordRow | null>(null);
  const jobs = data.regions.flatMap((r) => r.jobs),
    points = data.regions.flatMap((r) => r.points),
    copies = data.regions.flatMap((r) => r.copies);
  const filtered = useMemo(
    () =>
      data.regions
        .flatMap((r) => r[tab])
        .filter(
          (r) =>
            (!region || r.region === region) &&
            (!asset || r.resource_type === asset) &&
            (!status || r.status === status) &&
            `${r.id} ${r.resource} ${r.recovery_point || ""}`
              .toLowerCase()
              .includes(search.toLowerCase()),
        )
        .sort((a, b) => b.created_at.localeCompare(a.created_at)),
    [data, tab, search, region, asset, status],
  );
  const pages = Math.max(1, Math.ceil(filtered.length / 10)),
    current = Math.min(page, pages);
  const offsite = points.filter((r) => r.region === "ap-southeast-2" && r.status === "COMPLETED");
  const latestCopies = ["RDS", "EFS"].map(
    (type) =>
      copies
        .filter((r) => r.resource_type === type && r.destination_region === "ap-southeast-2")
        .sort((a, b) => b.created_at.localeCompare(a.created_at))[0],
  );
  const rows = filtered.slice((current - 1) * 10, current * 10);
  const trend = Array.from({ length: 7 }, (_, i) => {
    const day = new Date(Date.now() - (6 - i) * 86400000).toLocaleDateString("en-CA", {
      timeZone: "Asia/Manila",
    });
    const matches = jobs.filter(
      (j) =>
        new Date(j.completed_at || j.created_at).toLocaleDateString("en-CA", {
          timeZone: "Asia/Manila",
        }) === day,
    );
    return {
      day,
      completed: matches.filter((j) => j.status === "COMPLETED").length,
      failed: matches.filter((j) => ["FAILED", "ABORTED", "EXPIRED"].includes(j.status)).length,
    };
  });
  const peak = Math.max(1, ...trend.map((d) => d.completed + d.failed));
  const reset = () => {
    setSearch("");
    setRegion("");
    setAsset("");
    setStatus("");
    setPage(1);
  };
  return (
    <div className="bdr-provider">
      <div className={data.fresh ? "bdr-success" : "bdr-error"} role="status">
        <strong>
          {data.fresh ? "AWS backup monitoring connected" : "AWS observation is stale"}
        </strong>{" "}
        · Observed {stamp(data.observed_at)} PHT.{" "}
        {data.fresh
          ? "Schedules, job status and recovery points come from AWS."
          : "These are the last observed records; current protection cannot be confirmed."}
      </div>
      <div className="bdr-metrics">
        {[
          [
            "Completed Backups Today",
            data.fresh ? data.successful_today : "—",
            "AWS jobs · PHT",
            Database,
            "blue",
          ],
          [
            "Failed Backups Today",
            data.fresh ? data.failed_today : "—",
            "AWS failed / aborted / expired",
            TriangleAlert,
            "red",
          ],
          [
            "Database Redundancy",
            data.fresh ? (data.database.multi_az ? "2 nodes" : "1 node") : "—",
            "Primary + synchronous standby",
            Database,
            "green",
          ],
          [
            "Recovery Points",
            points.filter((p) => p.status === "COMPLETED").length,
            "Observed in both regions",
            Archive,
            "purple",
          ],
          ["Sydney Copies", offsite.length, "Completed recovery points", Cloud, "blue"],
          [
            "Tested RPO / RTO",
            "Not measured",
            "Actual restore drill still required",
            ShieldCheck,
            "amber",
          ],
        ].map(([label, value, sub, Icon, color]) => {
          const MetricIcon = Icon as typeof Database;
          return (
            <article key={String(label)}>
              <span className={`bdr-metric-icon ${color}`}>
                <MetricIcon />
              </span>
              <div>
                <h2>{String(label)}</h2>
                <strong>{String(value)}</strong>
                <small>{String(sub)}</small>
              </div>
            </article>
          );
        })}
      </div>
      <div className="bdr-provider-grid">
        <section className="bdr-panel">
          <header>
            <h2>
              <ShieldCheck size={18} />
              Active AWS Backup Configuration
            </h2>
            <a href={consoleUrl("ap-southeast-1", "backupplans")} target="_blank" rel="noreferrer">
              Manage in AWS <ExternalLink size={13} />
            </a>
          </header>
          <p className="bdr-subtitle">
            Protected resources:{" "}
            {data.scheduled_resources.join(", ") || "No matching selection observed"}.
          </p>
          {data.rules.map((rule) => (
            <div className="bdr-provider-rule" key={rule.name}>
              <strong>{rule.name}</strong>
              <dl>
                <dt>Schedule</dt>
                <dd>
                  {rule.schedule === "cron(0 17 * * ? *)" && ["UTC", "Etc/UTC"].includes(rule.timezone)
                    ? "Daily · 1:00 AM PHT (17:00 UTC)"
                    : `${rule.schedule} · ${rule.timezone}`}
                </dd>
                <dt>Singapore retention</dt>
                <dd>{rule.retention_days ?? "Not reported"} days</dd>
                <dt>Offsite copy retention</dt>
                <dd>
                  {rule.copy_regions
                    .map(
                      (c) => `${regionName(c.region)} · ${c.retention_days ?? "Not reported"} days`,
                    )
                    .join(", ") || "No copy rule observed"}
                </dd>
              </dl>
            </div>
          ))}
          <dl className="bdr-provider-dl">
            <dt>RDS point-in-time recovery</dt>
            <dd>{data.database.retention_days} days</dd>
            <dt>Latest restorable time</dt>
            <dd>{stamp(data.database.latest_restore_at)} PHT</dd>
            <dt>Backup encryption</dt>
            <dd>
              {points.length && points.every((p) => p.encrypted === true)
                ? "All observed recovery points encrypted"
                : "Review per recovery point"}
            </dd>
            <dt>AWS Backup Vault Lock</dt>
            <dd>
              {data.regions
                .map(
                  (r) => `${regionName(r.region)}: ${r.vault_locked ? "enabled" : "not enabled"}`,
                )
                .join(" · ")}
            </dd>
          </dl>
          <p className="bdr-provider-note">
            Backup retention is separate from statutory document retention. Vault Lock status here
            applies to AWS Backup vaults, not S3 Object Lock.
          </p>
        </section>
        <section className="bdr-panel">
          <header>
            <h2>
              <Cloud size={18} />
              Primary & Recovery Status
            </h2>
          </header>
          <div className="bdr-provider-state">
            <strong>Singapore database</strong>
            <span>
              {data.database.state} · {data.database.primary_zone}
            </span>
          </div>
          <div className="bdr-provider-state">
            <strong>Database standby</strong>
            <span>
              {data.database.multi_az
                ? `${data.database.standby_zone} · synchronous`
                : "No standby observed"}
            </span>
          </div>
          {latestCopies.map((copy, i) => (
            <div key={i} className="bdr-provider-state">
              <strong>{i === 0 ? "Database" : "Shared files"} → Sydney</strong>
              <span>
                {copy ? (
                  <>
                    <Status value={copy.status} />
                    <small>{stamp(copy.completed_at || copy.created_at)} PHT</small>
                  </>
                ) : (
                  "No copy job observed"
                )}
              </span>
            </div>
          ))}
          <p className="bdr-provider-note">
            Sydney holds recovery copies; it is not a running failover database. AWS completion
            confirms backup creation, not successful application recovery.
          </p>
          <div className="bdr-provider-actions">
            <a href={consoleUrl("ap-southeast-1")} target="_blank" rel="noreferrer">
              Manage backups in AWS <ExternalLink size={14} />
            </a>
            <button onClick={() => onRequest("backup")}>Create backup request</button>
            <button onClick={() => onRequest("drill")}>Request recovery test</button>
            <button onClick={() => onRequest("incident")}>Declare DR incident</button>
            <button disabled={exporting} onClick={onExport}>
              <Download size={14} />
              Export PDF / CSV
            </button>
          </div>
        </section>
      </div>
      <section className="bdr-panel">
        <header>
          <h2>Backup Activity · Last 7 Days</h2>
          <span>
            Completed <i className="bdr-legend-good" /> Failed <i className="bdr-legend-bad" />
          </span>
        </header>
        <div className="bdr-provider-chart">
          {trend.map((d) => (
            <div key={d.day} aria-label={`${d.day}: ${d.completed} completed, ${d.failed} failed`}>
              <strong>{d.completed + d.failed}</strong>
              <div className="bdr-provider-bar">
                <i style={{ height: `${(d.completed / peak) * 100}%` }} />
                <i className="failed" style={{ height: `${(d.failed / peak) * 100}%` }} />
              </div>
              <small>{d.day.slice(5)}</small>
            </div>
          ))}
        </div>
      </section>
      <section className="bdr-panel">
        <header>
          <h2>Backup & Recovery Records</h2>
          <span>Observed window · {data.lookback_days} days for jobs</span>
        </header>
        <div className="bdr-provider-tabs">
          {(
            [
              ["jobs", "Backup jobs", jobs.length],
              ["points", "Recovery points", points.length],
              ["copies", "Offsite copy jobs", copies.length],
            ] as const
          ).map(([key, label, count]) => (
            <button
              className={tab === key ? "active" : ""}
              key={key}
              onClick={() => {
                setTab(key);
                setPage(1);
              }}
            >
              {label} ({count})
            </button>
          ))}
        </div>
        <div className="bdr-provider-filters">
          <label>
            Search
            <input
              placeholder="Job / recovery point / resource"
              value={search}
              onChange={(e) => {
                setSearch(e.target.value);
                setPage(1);
              }}
            />
          </label>
          <label>
            Region
            <select
              value={region}
              onChange={(e) => {
                setRegion(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All regions</option>
              <option value="ap-southeast-1">Singapore</option>
              <option value="ap-southeast-2">Sydney</option>
            </select>
          </label>
          <label>
            Resource
            <select
              value={asset}
              onChange={(e) => {
                setAsset(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All resources</option>
              <option>RDS</option>
              <option>EFS</option>
            </select>
          </label>
          <label>
            Status
            <select
              value={status}
              onChange={(e) => {
                setStatus(e.target.value);
                setPage(1);
              }}
            >
              <option value="">All statuses</option>
              {["COMPLETED", "RUNNING", "CREATED", "PENDING", "FAILED", "ABORTED", "EXPIRED"].map(
                (s) => (
                  <option key={s}>{s}</option>
                ),
              )}
            </select>
          </label>
          <button onClick={reset}>Reset</button>
        </div>
        <div className="bdr-table">
          <table>
            <thead>
              <tr>
                <th>Reference</th>
                <th>Resource</th>
                <th>Location</th>
                <th>Created (PHT)</th>
                <th>Completed (PHT)</th>
                <th>Status</th>
                <th>Details</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={`${r.region}-${r.id}`}>
                  <td title={r.id}>{r.id.length > 36 ? `…${r.id.slice(-32)}` : r.id}</td>
                  <td>
                    {r.resource}
                    <small>{r.resource_type}</small>
                  </td>
                  <td>
                    {regionName(r.region)}
                    {r.destination_region && ` → ${regionName(r.destination_region)}`}
                  </td>
                  <td>{stamp(r.created_at)}</td>
                  <td>{stamp(r.completed_at)}</td>
                  <td>
                    <Status value={r.status} />
                  </td>
                  <td>
                    <button onClick={() => setDetail(r)}>View details</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
          {!rows.length && (
            <p className="bdr-table-empty">No observed records match these filters.</p>
          )}
        </div>
        <div className="bdr-provider-pagination">
          <span>
            {filtered.length} matching records{" "}
            {data.regions.some((r) => r.truncated) &&
              "· observation limit reached; full history is in AWS"}
          </span>
          <div>
            <button disabled={current === 1} onClick={() => setPage(current - 1)}>
              Previous
            </button>
            <span>
              {current} / {pages}
            </span>
            <button disabled={current === pages} onClick={() => setPage(current + 1)}>
              Next
            </button>
          </div>
        </div>
      </section>
      <Dialog
        open={!!detail}
        onOpenChange={(open) => {
          if (!open) setDetail(null);
        }}
      >
        <DialogContent className="bdr-dialog sm:max-w-3xl max-h-[85vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>
              {detail?.resource} · {detail?.kind === "point" ? "Recovery point" : "Backup record"}
            </DialogTitle>
            <DialogDescription>AWS observation · {stamp(data.observed_at)} PHT</DialogDescription>
          </DialogHeader>
          {detail && (
            <>
              <dl className="bdr-provider-dl bdr-provider-detail">
                {Object.entries({
                  Reference: detail.id,
                  Status: detail.status,
                  Region: regionName(detail.region),
                  Created: stamp(detail.created_at),
                  Completed: stamp(detail.completed_at),
                  "Recovery point": detail.recovery_point || "Not reported",
                  "Source point": detail.source_point || "Not applicable",
                  "Provider-reported size": bytes(detail.size_bytes),
                  Encrypted:
                    detail.encrypted == null
                      ? "See recovery point"
                      : detail.encrypted
                        ? "Yes"
                        : "No",
                  Retention:
                    detail.retention_days == null
                      ? "See recovery point"
                      : `${detail.retention_days} days`,
                  Expiry: stamp(detail.expires_at),
                  "Application restore test": "Not verified by this AWS record",
                }).map(([label, value]) => (
                  <div key={label}>
                    <dt>{label}</dt>
                    <dd>{value}</dd>
                  </div>
                ))}
              </dl>
              <a href={consoleUrl(detail.region, "backupvaults")} target="_blank" rel="noreferrer">
                Open recovery vaults in AWS <ExternalLink size={14} />
              </a>
            </>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
