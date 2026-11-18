import { useRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { StorageMonitor, type StorageOperations } from "./storage-monitor";
import { formatTin } from "@/lib/tin";
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogDescription,
} from "@/components/ui/dialog";
import { useEffect, useState } from "react";
import { apiJson } from "@/lib/api-url";
import { Button } from "@/components/ui/button";
import { Card, CardHeader, CardTitle, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { RefreshCw, ShieldCheck } from "lucide-react";
type Evidence = {
  storage_operations: StorageOperations;
  identity_claims: { id: string; user_id: string; tin: string; evidence_reference: string }[];
  observed_at: string;
  checks: { name: string; state: string; detail: string }[];
  outbox: { status: string; total: number }[];
  delivery: { state: string; total: number }[];
  pending_document_reviews: number;
  queue_jobs: number | null;
  failed_jobs: number | null;
  external_dependencies: string[];
  notifications: { id: string; message: string; severity: string }[];
  review_requests: {
    id: string;
    document_id: string;
    kind: string;
    reason: string;
    revision: number;
  }[];
};
export function OperationsReadiness() {
  const [identity, setIdentity] = useState<Evidence["identity_claims"][number] | null>(null),
    [identityDecision, setIdentityDecision] = useState("rejected"),
    [identityEvidence, setIdentityEvidence] = useState(""),
    [identityReason, setIdentityReason] = useState("");
  const [review, setReview] = useState<Evidence["review_requests"][number] | null>(null),
    [decision, setDecision] = useState("rejected"),
    [evidence, setEvidence] = useState(""),
    [adjustment, setAdjustment] = useState("");
  const [data, setData] = useState<Evidence | null>(null),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false);
  async function load(quiet = false) {
    if (!quiet) setBusy(true);
    setError("");
    try {
      setData(await apiJson<Evidence>("/api/operations/readiness"));
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load operational evidence.");
    } finally {
      setBusy(false);
    }
  }
  useEffect(() => {
    void load();
  }, []);
  useRealtimeRefresh(() => load(true));
  return (
    <div className="space-y-5 p-5">
      <div className="flex flex-wrap justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold flex gap-2 items-center">
            <ShieldCheck />
            Operational Readiness
          </h1>
          <p className="text-sm text-muted-foreground">
            Merchant data storage, delivery workers, integrity checks and recovery evidence. BIR
            connectivity is deferred.
          </p>
        </div>
        <Button disabled={busy} onClick={() => void load()} variant="outline">
          <RefreshCw className={busy ? "animate-spin" : ""} />
          Refresh
        </Button>
      </div>
      {error && (
        <p role="alert" className="text-red-600">
          {error}
        </p>
      )}
      {data && (
        <>
          <StorageMonitor value={data.storage_operations} />
          <p className="text-xs text-muted-foreground">
            Observed {new Date(data.observed_at).toLocaleString("en-PH")}
          </p>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {data.checks.map((c) => (
              <Card key={c.name}>
                <CardHeader className="pb-2">
                  <div className="flex justify-between gap-2">
                    <CardTitle className="text-base">{c.name}</CardTitle>
                    <Badge variant={c.state === "observed" ? "secondary" : "outline"}>
                      {c.state.replaceAll("_", " ")}
                    </Badge>
                  </div>
                </CardHeader>
                <CardContent>
                  <p className="text-sm text-muted-foreground">{c.detail}</p>
                </CardContent>
              </Card>
            ))}
          </div>
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Document and delivery queues</CardTitle>
              </CardHeader>
              <CardContent className="space-y-2 text-sm">
                {data.outbox.map((o) => (
                  <p key={o.status}>
                    {o.status.replaceAll("_", " ")}: <strong>{o.total}</strong>
                  </p>
                ))}
                {data.delivery.map((d) => (
                  <p key={d.state}>
                    Delivery {d.state}: <strong>{d.total}</strong>
                  </p>
                ))}
                <p>Pending document reviews: {data.pending_document_reviews}</p>
                <p>
                  Queue jobs: {data.queue_jobs ?? "Unavailable"} · Failed jobs:{" "}
                  {data.failed_jobs ?? "Unavailable"}
                </p>
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle className="text-base">
                  Remaining infrastructure and independent evidence
                </CardTitle>
              </CardHeader>
              <CardContent>
                <ul className="list-disc pl-5 space-y-2 text-sm">
                  {data.external_dependencies.map((e) => (
                    <li key={e}>{e}</li>
                  ))}
                </ul>
              </CardContent>
            </Card>
          </div>
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Taxpayer identity reviews</CardTitle>
            </CardHeader>
            <CardContent>
              {!(data.identity_claims || []).length ? (
                <p className="text-sm text-muted-foreground">No pending identity claims.</p>
              ) : (
                (data.identity_claims || []).map((c) => (
                  <div key={c.id} className="border-b py-3 text-sm">
                    <strong>
                      User {c.user_id} · TIN {formatTin(c.tin)}
                    </strong>
                    <p>{c.evidence_reference}</p>
                    <Button
                      size="sm"
                      variant="outline"
                      onClick={() => {
                        setIdentity(c);
                        setIdentityEvidence("");
                        setIdentityReason("");
                        setIdentityDecision("rejected");
                      }}
                    >
                      Review identity evidence
                    </Button>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Pending document reviews</CardTitle>
            </CardHeader>
            <CardContent>
              {!data.review_requests.length ? (
                <p className="text-sm text-muted-foreground">No pending review requests.</p>
              ) : (
                data.review_requests.map((r) => (
                  <div key={r.id} className="border-b py-3 text-sm">
                    <strong>
                      {r.kind} · {r.document_id}
                    </strong>
                    <p>{r.reason}</p>
                    <small>{r.id}</small>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        setReview(r);
                        setEvidence("");
                        setAdjustment("");
                        setDecision("rejected");
                      }}
                    >
                      Review request
                    </Button>
                  </div>
                ))
              )}
            </CardContent>
          </Card>
          <Card>
            <CardHeader>
              <CardTitle className="text-base">Operational reminders</CardTitle>
            </CardHeader>
            <CardContent>
              {!data.notifications.length ? (
                <p className="text-sm text-muted-foreground">
                  No reminders recorded. This does not establish continuous monitoring.
                </p>
              ) : (
                data.notifications.map((n) => (
                  <p className="text-sm py-2" key={n.id}>
                    {n.severity}: {n.message}
                  </p>
                ))
              )}
            </CardContent>
          </Card>
        </>
      )}
      <Dialog
        open={!!identity}
        onOpenChange={(open) => {
          if (!open) setIdentity(null);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Taxpayer identity evidence</DialogTitle>
            <DialogDescription>
              Validate the original taxpayer identity evidence independently. Approval does not
              activate the citizen account; activation remains a separate reviewed action.
            </DialogDescription>
          </DialogHeader>
          {error && <p role="alert">{error}</p>}
          <form
            className="space-y-4"
            onSubmit={async (e) => {
              e.preventDefault();
              if (!identity) return;
              setBusy(true);
              setError("");
              try {
                await apiJson(`/api/identity/claims/${identity.id}/review`, {
                  method: "POST",
                  body: JSON.stringify({
                    status: identityDecision,
                    reason: identityReason,
                    evidence_reference: identityEvidence,
                  }),
                });
                setIdentity(null);
                await load();
              } catch (e) {
                setError(e instanceof Error ? e.message : "Identity review could not be saved.");
              } finally {
                setBusy(false);
              }
            }}
          >
            <label className="block">
              Decision
              <select
                className="block border p-2"
                value={identityDecision}
                onChange={(e) => setIdentityDecision(e.target.value)}
              >
                <option value="rejected">Reject claim</option>
                <option value="approved">Approve verified identity evidence</option>
              </select>
            </label>
            <label className="block">
              Original verified evidence reference
              <textarea
                required
                minLength={10}
                maxLength={2000}
                className="block border p-2 w-full"
                value={identityEvidence}
                onChange={(e) => setIdentityEvidence(e.target.value)}
              />
            </label>
            <label className="block">
              Review reason
              <textarea
                required
                minLength={10}
                maxLength={2000}
                className="block border p-2 w-full"
                value={identityReason}
                onChange={(e) => setIdentityReason(e.target.value)}
              />
            </label>
            <Button type="submit" disabled={busy}>
              Record identity review
            </Button>
          </form>
        </DialogContent>
      </Dialog>
      <Dialog
        open={!!review}
        onOpenChange={(open) => {
          if (!open) setReview(null);
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Document review decision</DialogTitle>
            <DialogDescription>
              Preserve the original. Resolution requires an issued corrective document linked to the
              original and an independent reviewer.
            </DialogDescription>
          </DialogHeader>
          {error && <p role="alert">{error}</p>}
          <form
            className="space-y-4"
            onSubmit={async (e) => {
              e.preventDefault();
              if (!review) return;
              setBusy(true);
              setError("");
              try {
                await apiJson(`/api/documents/review-requests/${review.id}/decision`, {
                  method: "POST",
                  body: JSON.stringify({
                    revision: review.revision,
                    status: decision,
                    evidence_reference: evidence,
                    resolution_document_id: adjustment || null,
                  }),
                });
                setReview(null);
                await load();
              } catch (e) {
                setError(e instanceof Error ? e.message : "Review could not be recorded.");
              } finally {
                setBusy(false);
              }
            }}
          >
            <label className="block">
              Decision
              <select
                className="block border p-2"
                value={decision}
                onChange={(e) => setDecision(e.target.value)}
              >
                <option value="rejected">Reject request</option>
                <option value="resolved">Resolve through corrective document</option>
              </select>
            </label>
            <label className="block">
              Review evidence reference
              <textarea
                required
                minLength={10}
                maxLength={2000}
                className="block w-full border p-2"
                value={evidence}
                onChange={(e) => setEvidence(e.target.value)}
              />
            </label>
            {decision === "resolved" && (
              <label className="block">
                Issued corrective document ID
                <input
                  required
                  className="block border p-2 w-full"
                  value={adjustment}
                  onChange={(e) => setAdjustment(e.target.value)}
                />
              </label>
            )}
            <Button disabled={busy} type="submit">
              Record review decision
            </Button>
          </form>
        </DialogContent>
      </Dialog>
    </div>
  );
}
