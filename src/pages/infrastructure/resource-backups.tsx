import { useCallback, useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
  Archive,
  ArrowUpRight,
  CheckCircle2,
  Database,
  RefreshCw,
  ShieldCheck,
} from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { apiJson } from "@/lib/api-url";
import type { ProviderRecoveryData } from "@/pages/bcdr/provider-recovery";
import { API, stamp, type Node } from "./types";
import { Empty, Panel } from "./components";

type BackupRequest = {
  id: string;
  request_id: string;
  node_code: string;
  job_id: string | null;
  status: string;
  created_at: string;
  completed_at: string | null;
};
type BackupData = {
  enabled: boolean;
  nodes: string[];
  retention_days: number;
  provider: ProviderRecoveryData;
  requests: BackupRequest[];
};
type Choice = { node: Node; kind: "backup" | "drill" | "configuration"; source?: string };
const resourceLabel = (node: Node) =>
  node.resource_type === "managed_database"
    ? "Database"
    : node.resource_type === "managed_filesystem"
      ? "Shared files"
      : null;
function description(node: Node) {
  switch (node.resource_type) {
    case "managed_database":
      return "Database snapshot · primary and standby are one protected RDS database.";
    case "managed_filesystem":
      return "Shared receipts, uploads and application files · AWS Backup recovery points.";
    case "object_storage":
      return "Object versions and recovery archives · use object-level recovery, not a server snapshot.";
    case "managed_load_balancer":
      return "Load balancer configuration · rebuild from the reviewed infrastructure definition.";
    default:
      return "Server rebuild and configuration recovery · database and shared files are protected separately.";
  }
}
const jobTone = (state: string) =>
  state === "COMPLETED"
    ? "complete"
    : ["FAILED", "ABORTED", "EXPIRED", "UNCERTAIN"].includes(state)
      ? "failed"
      : "pending";

export function ResourceBackups({ nodes }: { nodes: Node[] }) {
  const [data, setData] = useState<BackupData | null>(null),
    [error, setError] = useState(""),
    [notice, setNotice] = useState("");
  const [choice, setChoice] = useState<Choice | null>(null),
    [busy, setBusy] = useState(false),
    [history, setHistory] = useState<string | null>(null);
  const load = useCallback(async () => {
    setBusy(true);
    try {
      setData(await apiJson<BackupData>(API + "/backups"));
      setError("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }, []);
  useEffect(() => {
    void load();
  }, [load]);
  useEffect(() => {
    if (choice) return;
    const timer = setInterval(() => void load(), 60000);
    return () => clearInterval(timer);
  }, [load, choice]);
  const points = data?.provider.regions?.flatMap((r) => r.points) || [];
  const jobs = data?.provider.regions?.flatMap((r) => r.jobs) || [];
  return (
    <Panel
      title="Infrastructure Backup & Recovery"
      action={
        <div className="sm-inline-actions">
          <button disabled={busy} onClick={() => void load()}>
            <RefreshCw size={14} /> Refresh
          </button>
          <Link className="sm-link" to="/super-admin/backups">
            Recovery Center <ArrowUpRight size={14} />
          </Link>
        </div>
      }
    >
      <div className="sm-backups-workspace">
        {error && (
          <p className="sm-error" role="alert">
            {error}
          </p>
        )}
        {notice && (
          <p className="sm-callout" role="status">
            <CheckCircle2 size={18} /> {notice}
          </p>
        )}
        <p className="sm-muted">
          RDS and EFS backups run in AWS without shutting down the servers. Restore tests use a
          separate environment and are tracked in the Recovery Center.
        </p>
        {data && (
          <div className="sm-backup-summary">
            <div>
              <Database size={20} />
              <span>
                Completed today
                <strong>{data.provider.fresh ? data.provider.successful_today : "—"}</strong>
              </span>
            </div>
            <div>
              <Archive size={20} />
              <span>
                Recovery points
                <strong>{points.filter((p) => p.status === "COMPLETED").length}</strong>
              </span>
            </div>
            <div>
              <ShieldCheck size={20} />
              <span>
                Provider observation
                <strong>{data.provider.fresh ? "Current" : "Unavailable / stale"}</strong>
                <small>{stamp(data.provider.observed_at)} PHT</small>
              </span>
            </div>
          </div>
        )}
        {!data && !error && <Empty>Loading backup protection…</Empty>}
        {data &&
          nodes.map((node) => {
            const label = resourceLabel(node);
            const resourceJobs = jobs
              .filter((j) => j.resource === label)
              .sort((a, b) => b.created_at.localeCompare(a.created_at));
            const local = points
              .filter(
                (p) => p.resource === label && p.region === node.region && p.status === "COMPLETED",
              )
              .sort((a, b) => b.created_at.localeCompare(a.created_at));
            const offsite = points.filter(
              (p) => p.resource === label && p.region !== node.region && p.status === "COMPLETED",
            );
            const latest = resourceJobs[0];
            const requests = data.requests.filter((r) => r.node_code === node.code);
            return (
              <article key={node.id} className="sm-backup-resource">
                <div className="sm-backup-resource-heading">
                  <div>
                    <h3>
                      {node.code} <span>· {node.label}</span>
                    </h3>
                    <p>{description(node)}</p>
                  </div>
                  <span className="sm-backup-kind">{node.resource_type_label}</span>
                </div>
                <div className="sm-backup-facts">
                  {label ? (
                    <>
                      <div>
                        <small>Latest backup job</small>
                        <strong>
                          {latest ? (
                            <span className={`sm-backup-badge ${jobTone(latest.status)}`}>
                              {latest.status.replaceAll("_", " ")}
                            </span>
                          ) : (
                            "No observed job"
                          )}
                        </strong>
                        <small>
                          {latest
                            ? stamp(latest.completed_at || latest.created_at) + " PHT"
                            : "Refresh to check AWS observations"}
                        </small>
                      </div>
                      <div>
                        <small>Singapore recovery points</small>
                        <strong>{local.length}</strong>
                        <small>
                          {local[0]
                            ? stamp(local[0].created_at) + " PHT"
                            : "No completed point observed"}
                        </small>
                      </div>
                      <div>
                        <small>Sydney recovery points</small>
                        <strong>{offsite.length}</strong>
                        <small>Separate offsite copy schedule</small>
                      </div>
                    </>
                  ) : (
                    <div className="sm-backup-fact-wide">
                      <small>Recovery method</small>
                      <strong>
                        {node.resource_type === "object_storage"
                          ? "Object versions / archived copies"
                          : "Configuration and rebuild procedure"}
                      </strong>
                      <small>AWS Backup snapshot status does not apply to this resource.</small>
                    </div>
                  )}
                </div>
                <div className="sm-inline-actions">
                  {label ? (
                    <button
                      className="sm-primary"
                      disabled={!data.enabled || !data.nodes.includes(node.code) || !node.fresh}
                      onClick={() => setChoice({ node, kind: "backup" })}
                    >
                      <Database size={15} /> Create Backup
                    </button>
                  ) : (
                    <button onClick={() => setChoice({ node, kind: "configuration" })}>
                      Request Backup Review
                    </button>
                  )}
                  <button
                    onClick={() =>
                      setChoice({
                        node,
                        kind: "drill",
                        source: local[0]?.recovery_point || undefined,
                      })
                    }
                  >
                    Request Restore Test
                  </button>
                  {label && (
                    <button
                      aria-expanded={history === node.id}
                      onClick={() => setHistory(history === node.id ? null : node.id)}
                    >
                      {history === node.id ? "Hide" : "View"} Job History
                    </button>
                  )}
                  {node.console_url && (
                    <a
                      className="sm-link"
                      target="_blank"
                      rel="noopener noreferrer"
                      href={node.console_url}
                    >
                      Open in AWS <ArrowUpRight size={13} />
                    </a>
                  )}
                </div>
                {label && (!data.enabled || !data.nodes.includes(node.code) || !node.fresh) && (
                  <p className="sm-muted">
                    {!node.fresh
                      ? "Current resource telemetry is required before starting a backup."
                      : "On-demand execution is awaiting operator configuration. Existing backups remain visible."}
                  </p>
                )}
                {history === node.id && (
                  <div className="sm-backup-history">
                    <table>
                      <thead>
                        <tr>
                          <th>Requested / observed (PHT)</th>
                          <th>Job / request reference</th>
                          <th>Status</th>
                          <th>Completed (PHT)</th>
                        </tr>
                      </thead>
                      <tbody>
                        {requests
                          .filter((r) => !resourceJobs.some((j) => j.id === r.job_id))
                          .map((r) => (
                            <tr key={r.id}>
                              <td>{stamp(r.created_at)}</td>
                              <td>{r.job_id || r.request_id}</td>
                              <td>
                                <span className={`sm-backup-badge ${jobTone(r.status)}`}>
                                  {r.status}
                                </span>
                              </td>
                              <td>{stamp(r.completed_at)}</td>
                            </tr>
                          ))}
                        {resourceJobs.slice(0, 10).map((j) => (
                          <tr key={j.id}>
                            <td>{stamp(j.created_at)}</td>
                            <td>{j.id}</td>
                            <td>
                              <span className={`sm-backup-badge ${jobTone(j.status)}`}>
                                {j.status}
                              </span>
                            </td>
                            <td>{stamp(j.completed_at)}</td>
                          </tr>
                        ))}
                        {!resourceJobs.length && !requests.length && (
                          <tr>
                            <td colSpan={4}>No jobs or requests observed for this resource.</td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                    <p className="sm-muted">
                      Up to 10 recent observed jobs and 25 recent requests across the fleet. Full
                      provider history is available in AWS.
                    </p>
                  </div>
                )}
              </article>
            );
          })}
        {data && !nodes.length && <Empty>No resources match the selected filters.</Empty>}
        <p className="sm-muted">
          A completed backup confirms a recovery point was created. Recovery is verified separately
          by restoring and checking the data. On-demand backups stay in Singapore for 7 days; daily
          offsite copies follow the existing schedule.
        </p>
      </div>
      {choice && (
        <BackupDialog
          key={choice.node.id + choice.kind}
          choice={choice}
          onClose={() => setChoice(null)}
          onSaved={(text) => {
            setNotice(text);
            setHistory(choice.node.id);
            setChoice(null);
            void load();
          }}
        />
      )}
    </Panel>
  );
}

function BackupDialog({
  choice,
  onClose,
  onSaved,
}: {
  choice: Choice;
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const [requestId] = useState(() => crypto.randomUUID()),
    [proof, setProof] = useState<string | null>(null);
  const [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [checking, setChecking] = useState(choice.kind === "backup");
  const [sent, setSent] = useState(false),
    [expired, setExpired] = useState(false);
  const [scheduledAt, setScheduledAt] = useState(() => {
    const next = new Date(Date.now() + 86400000);
    return new Date(next.getTime() - next.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  });
  useEffect(() => {
    if (choice.kind !== "backup") return;
    const abort = new AbortController();
    let expiry: ReturnType<typeof setTimeout> | undefined;
    void apiJson<{ confirmation_id: string; expires_in: number }>(API + "/backups/prepare", {
      method: "POST",
      signal: abort.signal,
      body: JSON.stringify({ node_id: choice.node.id, request_id: requestId }),
    })
      .then((r) => {
        if (!abort.signal.aborted) {
          setProof(r.confirmation_id);
          expiry = setTimeout(() => setExpired(true), r.expires_in * 1000);
        }
      })
      .catch((e) => {
        if (!abort.signal.aborted) setError((e as Error).message);
      })
      .finally(() => {
        if (!abort.signal.aborted) setChecking(false);
      });
    return () => {
      abort.abort();
      if (expiry) clearTimeout(expiry);
    };
  }, [choice.kind, choice.node.id, requestId]);
  async function submit() {
    setBusy(true);
    setError("");
    setSent(true);
    try {
      if (choice.kind === "backup") {
        const result = await apiJson<{ message: string; job_id: string }>(
          API + "/backups/confirm",
          {
            method: "POST",
            timeoutMs: 30000,
            body: JSON.stringify({
              node_id: choice.node.id,
              request_id: requestId,
              confirmation_id: proof,
              confirmed: true,
            }),
          },
        );
        onSaved(`${choice.node.code}: ${result.message} Job: ${result.job_id}`);
      } else {
        const scope = `${choice.node.code} · ${choice.node.label}`.slice(0, 200);
        const result = await apiJson<{ data: { id: string }; message: string }>(
          "/api/admin/backups/requests",
          {
            method: "POST",
            body: JSON.stringify({
              kind: choice.kind === "drill" ? "drill" : "backup",
              request_id: requestId,
              reason:
                choice.kind === "drill"
                  ? `Request an isolated recovery test for ${choice.node.code}, validate integrity and record measured recovery results.`
                  : `Review and capture the configuration or object recovery protection for ${choice.node.code}.`,
              evidence_reference:
                choice.source ||
                `Server Management resource ${choice.node.code} · ${choice.node.provider_reference}`,
              payload:
                choice.kind === "drill"
                  ? {
                      environment: "dr",
                      scope,
                      target_label:
                        "Separate isolated recovery target; production must not be overwritten",
                      snapshot_id: null,
                      scheduled_at: new Date(scheduledAt).toISOString(),
                    }
                  : {
                      asset_type:
                        choice.node.resource_type === "object_storage"
                          ? "objects"
                          : "configuration",
                      environment: choice.node.environment,
                      scope,
                      target_label: "Private recovery storage; operator review required",
                    },
            }),
          },
        );
        onSaved(
          `${choice.node.code}: ${result.message} Reference: ${result.data.id}. Track this request in the Recovery Center.`,
        );
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  return (
    <Dialog
      open
      onOpenChange={(open) => {
        if (!open && !busy) onClose();
      }}
    >
      <DialogContent
        className="sm-dialog sm-power-confirm"
        onEscapeKeyDown={(e) => {
          if (busy) e.preventDefault();
        }}
        onPointerDownOutside={(e) => {
          if (busy) e.preventDefault();
        }}
      >
        <DialogHeader>
          <DialogTitle>
            {choice.kind === "backup"
              ? "Create backup"
              : choice.kind === "drill"
                ? "Request isolated restore test"
                : "Request backup review"}{" "}
            for {choice.node.code}?
          </DialogTitle>
          <DialogDescription>
            {choice.node.label} · {choice.node.environment}
          </DialogDescription>
        </DialogHeader>
        {choice.kind === "backup" ? (
          <p>
            AWS will create an encrypted backup in the Singapore production vault with 7-day
            retention. The server stays online. Completion is reported separately; this does not
            perform a restore test.
          </p>
        ) : (
          <p>
            This records a request for the recovery operator.{" "}
            {choice.kind === "drill"
              ? "The operator must select an isolated target, execute the restore, validate data and publish the result. This button does not restore or overwrite production."
              : "The operator will review configuration or object-level recovery protection and attach the outcome."}
          </p>
        )}
        {choice.kind === "drill" && (
          <label>
            Requested test time (your local timezone)
            <input
              type="datetime-local"
              value={scheduledAt}
              onChange={(e) => setScheduledAt(e.target.value)}
            />
            <small>The recovery operator confirms the schedule.</small>
          </label>
        )}
        {choice.source && (
          <p className="sm-backup-source">Source recovery point: {choice.source}</p>
        )}
        {checking && <p role="status">Checking backup readiness…</p>}
        {choice.kind === "backup" && !checking && expired && !sent && !error && (
          <p className="sm-error">Confirmation expired. Close this dialog and try again.</p>
        )}
        {error && (
          <p className="sm-error" role="alert">
            {error}
          </p>
        )}
        <footer className="sm-dialog-footer">
          <button disabled={busy} onClick={onClose}>
            No
          </button>
          <button
            className="sm-primary"
            disabled={
              busy ||
              checking ||
              (choice.kind === "backup" && (!proof || (expired && !sent))) ||
              (choice.kind === "drill" && !scheduledAt)
            }
            onClick={() => void submit()}
          >
            {busy ? "Submitting…" : "Yes"}
          </button>
        </footer>
      </DialogContent>
    </Dialog>
  );
}
