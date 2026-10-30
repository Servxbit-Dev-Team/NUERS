import { useState, type FormEvent } from "react";
import { CheckCircle2, ShieldCheck, LockKeyhole, AlertTriangle, Loader2 } from "lucide-react";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { apiJson } from "@/lib/api-url";
import {
  API,
  nice,
  stamp,
  type Node,
  type Action,
  type ActionDefinition,
  type Preflight,
} from "./types";
import { Status } from "./components";
export type Modal =
  | { kind: "action"; node: Node; action: string; service?: string }
  | { kind: "enroll"; node?: Node }
  | { kind: "maintenance"; node: Node }
  | { kind: "history"; record: Action };
export function ManagementDialog({
  modal,
  definitions,
  actorId,
  onClose,
  onSaved,
}: {
  modal: Modal;
  definitions: Record<string, ActionDefinition>;
  actorId: string;
  onClose: () => void;
  onSaved: (message: string) => void;
}) {
  const node = modal.kind === "history" ? undefined : modal.node;
  const [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [reason, setReason] = useState(""),
    [password, setPassword] = useState(""),
    [code, setCode] = useState(""),
    [confirmation, setConfirmation] = useState("");
  const [idempotency] = useState(() => crypto.randomUUID()),
    [preflight, setPreflight] = useState<Preflight | null>(null),
    [service, setService] = useState(
      modal.kind === "action" ? modal.service || node?.services[0]?.key || "" : "",
    ),
    [artifact, setArtifact] = useState(""),
    [runbook, setRunbook] = useState("");
  const [serverCode, setServerCode] = useState(node?.code || ""),
    [label, setLabel] = useState(node?.label || ""),
    [tier, setTier] = useState(node?.tier || "web"),
    [group, setGroup] = useState(node?.ha_group || "WEB-HA"),
    [site, setSite] = useState(node?.site === "Not assigned" ? "" : node?.site || ""),
    [environment, setEnvironment] = useState(node?.environment || "staging");
  const local = (d: Date) =>
    new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
  const [start, setStart] = useState(local(new Date())),
    [end, setEnd] = useState(local(new Date(Date.now() + 60 * 60 * 1000))),
    [decision, setDecision] = useState("approve");
  const action = modal.kind === "action" ? modal.action : "";
  const parameters = () =>
    Object.fromEntries(
      Object.entries({
        service: action.startsWith("service.") ? service : "",
        artifact_id: artifact,
        runbook_id: runbook,
      }).filter(([, v]) => v),
    );
  const payload = () => ({
    node_id: node?.id,
    action,
    reason,
    parameters: parameters(),
    idempotency_key: idempotency,
  });
  const needsArtifact = [
    "configuration.deploy",
    "network.change",
    "restore.test",
    "restore.production",
  ].includes(action);
  const history = modal.kind === "history" ? modal.record : null;
  const canReview =
    history &&
    ((history.state === "awaiting_approval" &&
      history.actor_id !== actorId &&
      !history.approvals.some((a) => a.actor_id === actorId)) ||
      (["awaiting_approval", "scheduled"].includes(history.state) && history.actor_id === actorId));
  async function inspect() {
    setBusy(true);
    setError("");
    try {
      setPreflight(
        await apiJson<Preflight>(API + "/preflight", {
          method: "POST",
          body: JSON.stringify(payload()),
        }),
      );
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  async function submit(e: FormEvent) {
    e.preventDefault();
    setError("");
    setBusy(true);
    try {
      const proof = await apiJson<{ proof_id: string }>(API + "/step-up", {
        method: "POST",
        body: JSON.stringify({ password, code }),
      });
      setPassword("");
      setCode("");
      if (modal.kind === "action") {
        const r = await apiJson<{ data: Action }>(API + "/actions", {
          method: "POST",
          body: JSON.stringify({ ...payload(), proof_id: proof.proof_id, confirmation }),
        });
        onSaved(
          `Action ${r.data.node_code}: ${nice(r.data.state)}. Track progress in Action History.`,
        );
      }
      if (modal.kind === "maintenance") {
        const r = await apiJson<{ message: string }>(
          API + `/servers/${modal.node.id}/maintenance`,
          {
            method: "POST",
            body: JSON.stringify({
              start: new Date(start).toISOString(),
              end: new Date(end).toISOString(),
              reason,
              revision: modal.node.revision,
              proof_id: proof.proof_id,
            }),
          },
        );
        onSaved(r.message);
      }
      if (modal.kind === "enroll") {
        const r = await apiJson<{ message: string }>(API + "/servers", {
          method: "POST",
          body: JSON.stringify({
            code: serverCode,
            label,
            tier,
            ha_group: group,
            site,
            environment,
            reason,
            proof_id: proof.proof_id,
          }),
        });
        onSaved(r.message);
      }
      if (history) {
        const r = await apiJson<{ data: Action }>(API + `/actions/${history.id}/review`, {
          method: "POST",
          body: JSON.stringify({
            decision: history.actor_id === actorId ? "cancel" : decision,
            note: reason,
            revision: history.revision,
            proof_id: proof.proof_id,
          }),
        });
        onSaved(`Action ${nice(r.data.state)}.`);
      }
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }
  const canSubmit = modal.kind !== "action" || (preflight?.passed && confirmation === node?.code);
  return (
    <Dialog
      open
      onOpenChange={(v) => {
        if (!v && !busy) onClose();
      }}
    >
      <DialogContent
        className="sm-dialog"
        onEscapeKeyDown={(e) => {
          if (busy) e.preventDefault();
        }}
        onPointerDownOutside={(e) => {
          if (busy) e.preventDefault();
        }}
      >
        <DialogHeader>
          <span className="sm-eyebrow">
            <ShieldCheck size={14} />
            NUERS INFRASTRUCTURE CONTROL
          </span>
          <DialogTitle>
            {modal.kind === "action"
              ? definitions[action]?.label
              : modal.kind === "enroll"
                ? "Add / Enroll Server"
                : modal.kind === "maintenance"
                  ? "Schedule Maintenance Window"
                  : "Action Record"}
          </DialogTitle>
          <DialogDescription>
            {node
              ? `${node.code} · ${node.label} · ${nice(node.environment)}`
              : "Typed requests, verified identities and a complete audit history."}
          </DialogDescription>
        </DialogHeader>
        <form onSubmit={submit} className="sm-form">
          {modal.kind === "enroll" && (
            <>
              <div className="sm-callout">
                This saves inventory only. An operator must verify ownership and install a
                certificate fingerprint in the controller configuration. IP addresses entered in a
                browser never become trusted management targets.
              </div>
              <div className="sm-form-grid">
                <label>
                  Server Code
                  <input
                    required
                    pattern="[A-Z]{2,8}-[0-9]{2,3}"
                    value={serverCode}
                    readOnly={!!node}
                    placeholder="WEB-03"
                    onChange={(e) => setServerCode(e.target.value.toUpperCase())}
                  />
                </label>
                <label>
                  Display Name
                  <input
                    required
                    maxLength={120}
                    value={label}
                    onChange={(e) => setLabel(e.target.value)}
                  />
                </label>
                <label>
                  Server Tier
                  <select value={tier} onChange={(e) => setTier(e.target.value as Node["tier"])}>
                    {["load_balancer", "web", "database", "storage"].map((t) => (
                      <option key={t} value={t}>
                        {nice(t)}
                      </option>
                    ))}
                  </select>
                </label>
                <label>
                  HA Group
                  <input
                    required
                    pattern="[A-Z0-9-]{3,50}"
                    value={group}
                    onChange={(e) => setGroup(e.target.value.toUpperCase())}
                  />
                </label>
                <label>
                  Environment
                  <select value={environment} onChange={(e) => setEnvironment(e.target.value)}>
                    <option value="staging">Staging</option>
                    <option value="production">Production</option>
                  </select>
                </label>
                <label>
                  Site / Fault Domain
                  <input
                    required
                    minLength={3}
                    maxLength={100}
                    value={site}
                    placeholder="Verified site label"
                    onChange={(e) => setSite(e.target.value)}
                  />
                </label>
              </div>
            </>
          )}
          {modal.kind === "maintenance" && (
            <>
              <div className="sm-callout">
                Schedule one node at a time. A maintenance window permits review; it does not drain
                traffic or run an operation. The other HA members must remain healthy and outside
                maintenance.
              </div>
              <div className="sm-form-grid">
                <label>
                  Starts (your local time)
                  <input
                    type="datetime-local"
                    required
                    value={start}
                    onChange={(e) => setStart(e.target.value)}
                  />
                </label>
                <label>
                  Ends (within 24 hours)
                  <input
                    type="datetime-local"
                    required
                    value={end}
                    onChange={(e) => setEnd(e.target.value)}
                  />
                </label>
              </div>
            </>
          )}
          {modal.kind === "action" && (
            <>
              <div className="sm-callout">
                <LockKeyhole size={17} />
                <span>
                  Preflight is read-only. Execution requires current telemetry, an enabled
                  controller and fresh verification. Critical requests require independent approval.
                </span>
              </div>
              <div className="sm-form-grid">
                {action.startsWith("service.") && (
                  <label>
                    Approved Service
                    <select
                      value={service}
                      onChange={(e) => {
                        setService(e.target.value);
                        setPreflight(null);
                      }}
                    >
                      {node?.services.map((s) => (
                        <option key={s.key}>{s.key}</option>
                      ))}
                    </select>
                  </label>
                )}
                <label>
                  Reviewed Runbook Reference
                  <input
                    maxLength={80}
                    placeholder="WEB-ROLLING-001"
                    value={runbook}
                    onChange={(e) => {
                      setRunbook(e.target.value);
                      setPreflight(null);
                    }}
                  />
                </label>
                {needsArtifact && (
                  <label>
                    Verified Artifact ID
                    <input
                      placeholder="Controller-registered artifact UUID"
                      value={artifact}
                      onChange={(e) => {
                        setArtifact(e.target.value);
                        setPreflight(null);
                      }}
                    />
                  </label>
                )}
              </div>
            </>
          )}
          {history && (
            <>
              <div className="sm-record-heading">
                <Status value={history.state} />
                <span>
                  {nice(history.risk)} risk · {stamp(history.created_at)} PHT
                </span>
              </div>
              <dl className="sm-kv">
                <div>
                  <dt>Correlation / Job ID</dt>
                  <dd className="sm-mono">{history.id}</dd>
                </div>
                <div>
                  <dt>Target / Action</dt>
                  <dd>
                    {history.node_code} · {nice(history.action)}
                  </dd>
                </div>
                <div>
                  <dt>Reason</dt>
                  <dd>{history.reason}</dd>
                </div>
                <div>
                  <dt>Approvals</dt>
                  <dd>
                    {history.approvals.filter((a) => a.decision === "approve").length} of{" "}
                    {history.preflight.approvals_required} required
                  </dd>
                </div>
                <div>
                  <dt>Request expires</dt>
                  <dd>{stamp(history.expires_at)} PHT</dd>
                </div>
              </dl>
              <PreflightList value={history.preflight} />
              {history.result && <pre>{JSON.stringify(history.result, null, 2)}</pre>}
              {history.approvals.map((a) => (
                <div className="sm-callout" key={a.actor_id}>
                  {nice(a.decision)} · {stamp(a.created_at)} PHT
                  <br />
                  {a.note}
                </div>
              ))}
              <details>
                <summary>Immutable request policy</summary>
                <pre>{JSON.stringify(history.policy, null, 2)}</pre>
              </details>
            </>
          )}
          {(!history || canReview) && (
            <>
              <label>
                {history ? "Review note" : "Reason / change reference"}
                <textarea
                  required
                  minLength={10}
                  maxLength={500}
                  value={reason}
                  onChange={(e) => setReason(e.target.value)}
                  placeholder="Explain the planned work, impact and approved change reference."
                />
              </label>
              {modal.kind === "action" && (
                <>
                  <button
                    type="button"
                    className="sm-outline"
                    disabled={busy || reason.trim().length < 10}
                    onClick={() => void inspect()}
                  >
                    <ShieldCheck size={16} />
                    Run Safety Preflight
                  </button>
                  {preflight && <PreflightList value={preflight} />}
                </>
              )}
              {(modal.kind !== "action" || preflight?.passed) && (
                <>
                  <div className="sm-callout sm-warning">
                    <LockKeyhole size={16} />
                    Verify this infrastructure operation with your current password and an enrolled
                    authenticator. This does not change ordinary login behavior.
                  </div>
                  <div className="sm-form-grid">
                    <label>
                      Current Password
                      <input
                        type="password"
                        autoComplete="current-password"
                        required
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                      />
                    </label>
                    <label>
                      Authenticator Code
                      <input
                        required
                        inputMode="numeric"
                        autoComplete="one-time-code"
                        pattern="[0-9]{6}"
                        maxLength={6}
                        value={code}
                        onChange={(e) => setCode(e.target.value)}
                      />
                    </label>
                  </div>
                  {modal.kind === "action" && (
                    <label>
                      Type {node?.code} to confirm the target
                      <input
                        required
                        autoComplete="off"
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                      />
                    </label>
                  )}
                  {history && history.actor_id !== actorId && (
                    <label>
                      Independent Decision
                      <select value={decision} onChange={(e) => setDecision(e.target.value)}>
                        <option value="approve">Approve request</option>
                        <option value="reject">Reject request</option>
                      </select>
                    </label>
                  )}
                </>
              )}
            </>
          )}
          {error && (
            <p className="sm-error" role="alert">
              {error}
            </p>
          )}
          <footer className="sm-dialog-footer">
            <button type="button" disabled={busy} onClick={onClose}>
              Close
            </button>
            {(!history || canReview) && (
              <button
                type="submit"
                className="sm-primary"
                disabled={
                  busy || !canSubmit || reason.trim().length < 10 || !password || code.length !== 6
                }
              >
                {busy ? <Loader2 size={16} className="animate-spin" /> : <ShieldCheck size={16} />}{" "}
                {modal.kind === "action"
                  ? "Submit Controlled Request"
                  : modal.kind === "maintenance"
                    ? "Save Maintenance Window"
                    : modal.kind === "enroll"
                      ? "Save Enrollment Request"
                      : history?.actor_id === actorId
                        ? "Cancel Request"
                        : "Record Review"}
              </button>
            )}
          </footer>
        </form>
      </DialogContent>
    </Dialog>
  );
}
export function PreflightList({ value }: { value: Preflight }) {
  return (
    <section className="sm-preflight">
      <div>
        <strong>{value.passed ? "Preflight passed" : "Action blocked by preflight"}</strong>
        <span>
          {nice(value.risk)} risk · {value.approvals_required} independent approval(s)
        </span>
      </div>
      <ul>
        {value.checks.map((c) => (
          <li key={c.label} className={c.passed ? "passed" : "blocked"}>
            {c.passed ? <CheckCircle2 size={15} /> : <AlertTriangle size={15} />}
            <span>{c.label}</span>
          </li>
        ))}
      </ul>
      <small>Evaluated {stamp(value.evaluated_at)} PHT. Checks run again before dispatch.</small>
    </section>
  );
}
