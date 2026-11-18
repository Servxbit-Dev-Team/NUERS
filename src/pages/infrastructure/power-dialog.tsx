import { useEffect, useState } from "react";
import { AlertTriangle, Loader2, ShieldCheck } from "lucide-react";
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription } from "@/components/ui/dialog";
import { apiJson } from "@/lib/api-url";
import { API, type Action, type Node, type Preflight } from "./types";

export function PowerDialog({ node, action, onClose, onSaved }: { node: Node; action: string; onClose: () => void; onSaved: (message: string) => void }) {
  const [idempotency] = useState(() => crypto.randomUUID());
  const [confirmation, setConfirmation] = useState<string | null>(null), [loading, setLoading] = useState(true), [busy, setBusy] = useState(false), [error, setError] = useState("");
  const label = action === "node.start" ? "Start" : action === "node.reboot" ? "Restart" : "Shut down";
  useEffect(() => {
    const controller = new AbortController(); let timer: ReturnType<typeof setTimeout> | undefined;
    apiJson<{ confirmation_id: string; preflight: Preflight; expires_in: number }>(API + "/power/prepare", { method: "POST", signal: controller.signal, timeoutMs: 25000, body: JSON.stringify({ node_id: node.id, action, idempotency_key: idempotency }) })
      .then(result => {
        if (controller.signal.aborted) return;
        // Do not enable confirmation when a preflight is blocked, even if an ID is returned.
        setConfirmation(result.preflight.passed ? result.confirmation_id : null);
        if (!result.preflight.passed) setError(result.preflight.checks.filter(check => !check.passed).map(check => check.label).join(". "));
        timer = setTimeout(() => { setConfirmation(null); setError("Confirmation expired. Close this dialog and try again."); }, result.expires_in * 1000);
      }).catch(e => { if (!controller.signal.aborted) setError((e as Error).message); })
      .finally(() => { if (!controller.signal.aborted) setLoading(false); });
    return () => { controller.abort(); if (timer) clearTimeout(timer); };
  }, [node.id, action, idempotency]);
  async function confirm() {
    if (!confirmation || busy) return;
    setBusy(true); setError("");
    try {
      const result = await apiJson<{ data: Action }>(API + "/power/confirm", { method: "POST", timeoutMs: 30000, body: JSON.stringify({ node_id: node.id, action, idempotency_key: idempotency, confirmation_id: confirmation, confirmed: true }) });
      if (result.data.state === "blocked") { setConfirmation(null); setError(result.data.preflight.checks.filter(check => !check.passed).map(check => check.label).join(". ")); }
      else onSaved(`${label} requested for ${node.code}. Track completion in Action History.`);
    } catch (e) { setError((e as Error).message); } finally { setBusy(false); }
  }
  return <Dialog open onOpenChange={open => { if (!open && !busy) onClose(); }}>
    <DialogContent className="sm-dialog sm-power-confirm" onEscapeKeyDown={e => { if (busy) e.preventDefault(); }} onPointerDownOutside={e => { if (busy) e.preventDefault(); }}>
      <DialogHeader><span className="sm-eyebrow"><ShieldCheck size={16} /> NUERS SERVER CONTROL</span><DialogTitle>{label} {node.code}?</DialogTitle><DialogDescription>{node.label} · {node.environment === "production" ? "Production" : "Staging"}</DialogDescription></DialogHeader>
      <p>{action === "node.start" ? "This will power on the server." : action === "node.reboot" ? "This will stop and start the server. It may take a few minutes." : "This server will remain stopped until you start it again."}</p>
      {node.environment === "production" && action !== "node.start" && <p>Traffic will move to the healthy web server before this server stops.</p>}
      {node.code === "WEB-01" && action !== "node.start" && <p className="sm-callout sm-warning"><AlertTriangle size={17} /> Email notifications and scheduled jobs pause while WEB-01 is stopped.</p>}
      {loading && <p role="status"><Loader2 size={16} className="animate-spin" /> Checking server readiness…</p>}
      {!loading && confirmation && <p className="sm-muted">Safety checks passed. This operation will be recorded in the audit trail.</p>}
      {error && <p role="alert" className="sm-error">{error}</p>}
      <footer className="sm-dialog-footer"><button type="button" disabled={busy} onClick={onClose}>No</button><button type="button" className={action === "node.shutdown" ? "sm-danger" : "sm-primary"} disabled={loading || busy || !confirmation} onClick={() => void confirm()}>{busy ? "Submitting…" : "Yes"}</button></footer>
    </DialogContent>
  </Dialog>;
}
