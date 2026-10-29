import { AlertTriangle, CheckCircle2, Database, HardDrive, ShieldCheck } from "lucide-react";
import { useEffect, useState } from "react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";

type Check = { id: string; name: string; state: string; detail: string; critical: boolean };
type Observation<T> = { observed_at: string | null; fresh: boolean; age_seconds: number | null; data: T | null };
export type StorageOperations = {
  mode: string; bir_transmission: string;
  runtime: Observation<{ acceptance_ready: boolean; checks: Check[]; queues: { id: string; due: number; oldest_due_seconds: number; attention_last_24h: number }[]; intake_records: { environment: string; total: number }[] }>;
  integrity: Observation<{ valid: boolean; audit_chain_valid: boolean; infrastructure_audit_valid: boolean; wallet_mismatches: number }>;
  backup: Observation<{ encrypted: boolean; scope: string; size_bytes: number; sha256: string; restore_verified: boolean }>;
  recovery_test: Observation<{ environment: string; verified: boolean; duration_seconds: number }>;
  offsite_storage: string; failover: string;
};

const date = (v: string | null) => v ? new Date(v).toLocaleString("en-PH", { timeZone: "Asia/Manila" }) + " PHT" : "No observation recorded";

export function StorageMonitor({ value }: { value: StorageOperations }) {
  const [clock, setClock] = useState(Date.now());
  useEffect(() => { const timer = window.setInterval(() => setClock(Date.now()), 15000); return () => window.clearInterval(timer); }, []);
  const current = <T,>(observation: Observation<T>, seconds: number) => ({...observation, fresh: observation.fresh && !!observation.observed_at && clock - Date.parse(observation.observed_at) <= seconds * 1000});
  const runtime = current(value.runtime, 180), integrity = current(value.integrity, 7200), backup = current(value.backup, 26 * 3600);
  const healthy = runtime.fresh && runtime.data?.acceptance_ready;
  return <section className="space-y-4" aria-label="NUERS storage operations">
    <div className="rounded-xl border border-blue-200 bg-blue-50 p-4 flex gap-3"><Database className="shrink-0 text-blue-600"/><div><h2 className="font-semibold text-blue-950">NUERS merchant data storage</h2><p className="text-sm text-blue-900">Merchant records are stored in NUERS. Live and Sandbox remain separate. BIR connectivity is deferred; no BIR transmission is enabled.</p></div></div>
    <div className="grid gap-4 md:grid-cols-3">
      <Card><CardHeader><CardTitle className="text-base flex items-center gap-2">{healthy ? <CheckCircle2 className="text-emerald-600"/> : <AlertTriangle className="text-amber-600"/>}Storage operations</CardTitle></CardHeader><CardContent><strong>{healthy ? "Core storage checks passed" : runtime.fresh ? "Action required" : "Monitoring stale or unavailable"}</strong><p className="text-xs text-muted-foreground mt-2">{date(runtime.observed_at)}</p><p className="text-xs text-muted-foreground mt-2">Scheduler observations expire after three minutes. This does not certify capacity or availability.</p></CardContent></Card>
      <Card><CardHeader><CardTitle className="text-base flex gap-2 items-center"><ShieldCheck/>Stored record integrity</CardTitle></CardHeader><CardContent><strong>{integrity.fresh && integrity.data?.valid ? "Audit and wallet checks passed" : integrity.fresh ? "Integrity review required" : "Verification needed"}</strong><p className="text-xs text-muted-foreground mt-2">{date(integrity.observed_at)}</p><p className="text-xs text-muted-foreground mt-2">Checks both audit chains and wallet hashes, sequence and balances. No financial record is rewritten.</p></CardContent></Card>
      <Card><CardHeader><CardTitle className="text-base flex gap-2 items-center"><HardDrive/>Recovery protection</CardTitle></CardHeader><CardContent><strong>{backup.fresh && backup.data?.encrypted ? "Recent encrypted local backup" : "Local backup evidence needed"}</strong><p className="text-xs text-muted-foreground mt-2">{date(backup.observed_at)}</p><p className="text-xs text-muted-foreground mt-2">Offsite storage and failover are not provisioned. Local backups alone do not protect against losing the server.</p><a className="text-sm text-blue-600 mt-3 inline-block" href="/super-admin/backups">Backup & recovery evidence →</a></CardContent></Card>
    </div>
    {runtime.data && <Card><CardHeader><CardTitle className="text-base">Current storage and worker checks</CardTitle></CardHeader><CardContent><div className="grid gap-3 md:grid-cols-2">{runtime.data.checks.map(c => <div key={c.id} className="rounded-lg border p-3"><div className="flex justify-between gap-2"><h3 className="font-medium text-sm">{c.name}</h3><Badge variant="outline">{!runtime.fresh ? "stale" : c.state === "observed" ? "passed" : "action required"}</Badge></div><p className="mt-2 text-xs text-muted-foreground">{c.detail}</p></div>)}</div><div className="overflow-x-auto mt-4"><table className="w-full text-sm"><caption className="text-left font-medium mb-2">Email and webhook delivery</caption><thead><tr className="border-b text-left"><th className="p-2">Worker</th><th className="p-2">Due</th><th className="p-2">Oldest due</th><th className="p-2">Needs review · 24h</th></tr></thead><tbody>{runtime.data.queues.map(q => <tr key={q.id} className="border-b"><td className="p-2 capitalize">{q.id}</td><td className="p-2">{q.due}</td><td className="p-2">{q.oldest_due_seconds}s</td><td className="p-2">{q.attention_last_24h}</td></tr>)}</tbody></table></div><p className="text-xs text-muted-foreground mt-3">A worker heartbeat confirms that it ran. SMTP acceptance and webhook delivery require their own recorded outcomes; a missing external receiver is not reported as a successful delivery.</p></CardContent></Card>}
  </section>;
}
