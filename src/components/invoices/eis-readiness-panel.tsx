import { useEffect, useState } from "react";
import {
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock3,
  LoaderCircle,
  ShieldCheck,
} from "lucide-react";
import { apiJson } from "@/lib/api-url";
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";

type Readiness = {
  source: string;
  id: string;
  number: string;
  business: string;
  local_validation_passed: boolean;
  local_errors: string[];
  blockers: string[];
  submission_applicability: string;
  source_review_revision: number;
  outbox: { status: string; prepared_at: string } | null;
};
type Result = {
  data: Readiness[];
  total: number;
  page: number;
  last_page: number;
  can_prepare: boolean;
  can_review_branch: boolean;
  notice: string;
};
export function EisReadinessPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const [result, setResult] = useState<Result | null>(null);
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [loading, setLoading] = useState(false);
  const [busy, setBusy] = useState("");
  const [error, setError] = useState("");
  const [reviewing, setReviewing] = useState("");
  const [branchCode, setBranchCode] = useState("");
  const [evidence, setEvidence] = useState("");
  useEffect(() => {
    if (!open) return;
    const controller = new AbortController();
    setLoading(true);
    setError("");
    apiJson<Result>(`/api/eis-readiness?page=${page}`, { signal: controller.signal })
      .then(setResult)
      .catch((e) => {
        if (e.name !== "AbortError") setError(e.message);
      })
      .finally(() => {
        if (!controller.signal.aborted) setLoading(false);
      });
    return () => controller.abort();
  }, [open, page, refresh]);
  async function prepare(row: Readiness) {
    setBusy(`${row.source}:${row.id}`);
    setError("");
    try {
      await apiJson(`/api/eis-readiness/${row.source}/${row.id}/prepare`, {
        method: "POST",
        body: "{}",
      });
      setRefresh((n) => n + 1);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unable to prepare the document.");
    } finally {
      setBusy("");
    }
  }
  async function recordBranch(row: Readiness) {
    setBusy(`${row.source}:${row.id}`);
    setError("");
    try {
      await apiJson(`/api/eis-readiness/${row.source}/${row.id}/branch-review`, {
        method: "POST",
        body: JSON.stringify({
          branch_code: branchCode,
          evidence_reference: evidence,
          revision: row.source_review_revision,
        }),
      });
      setReviewing("");
      setBranchCode("");
      setEvidence("");
      setRefresh((n) => n + 1);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Unable to record source evidence.");
    } finally {
      setBusy("");
    }
  }
  return (
    <Dialog
      open={open}
      onOpenChange={(value) => {
        if (!value && !busy) onClose();
      }}
    >
      <DialogContent className="ow-dialog er-dialog">
        <DialogHeader>
          <DialogTitle>EIS document readiness</DialogTitle>
          <DialogDescription>
            Review original invoice records and prepare secure copies while EIS API access is
            pending. Preparing a copy does not send it to BIR.
          </DialogDescription>
        </DialogHeader>
        <div className="er-pending">
          <Clock3 size={20} />
          <div>
            <strong>BIR API integration pending</strong>
            <p>
              Official schema mapping, API access, taxpayer authorities and verified BIR responses
              are required before live transmission.
            </p>
          </div>
        </div>
        {error && (
          <p role="alert" className="er-error">
            {error}
          </p>
        )}
        {loading ? (
          <p className="er-loading">
            <LoaderCircle className="ow-spin" />
            Checking document records…
          </p>
        ) : (
          <div className="er-records">
            {result?.data.map((row) => (
              <article key={`${row.source}:${row.id}`}>
                <header>
                  <div>
                    <strong>{row.number}</strong>
                    <small>{row.business}</small>
                  </div>
                  <span className={row.local_validation_passed ? "er-valid" : "er-review"}>
                    {row.local_validation_passed ? (
                      <CheckCircle2 size={15} />
                    ) : (
                      <ShieldCheck size={15} />
                    )}
                    {row.local_validation_passed ? "Local checks passed" : "Source review required"}
                  </span>
                </header>
                <p className="er-coverage">
                  Transmission coverage: {row.submission_applicability.replaceAll("_", " ")}
                </p>
                {row.local_errors.length > 0 && (
                  <ul>
                    {row.local_errors.map((message) => (
                      <li key={message}>{message}</li>
                    ))}
                  </ul>
                )}
                {result.can_review_branch &&
                  row.local_errors.some((message) => message.includes("branch snapshot")) && (
                    <div className="er-source-review">
                      {reviewing === `${row.source}:${row.id}` ? (
                        <form
                          onSubmit={(event) => {
                            event.preventDefault();
                            void recordBranch(row);
                          }}
                        >
                          <p>
                            Record verified historical evidence. The original invoice and its
                            amounts remain preserved.
                          </p>
                          <label>
                            BIR branch code
                            <input
                              required
                              pattern="[0-9]{5}"
                              maxLength={5}
                              inputMode="numeric"
                              value={branchCode}
                              onChange={(event) => setBranchCode(event.target.value)}
                              placeholder="Five digits from the source evidence"
                            />
                          </label>
                          <label>
                            Evidence reference and review basis
                            <textarea
                              required
                              minLength={10}
                              maxLength={2000}
                              value={evidence}
                              onChange={(event) => setEvidence(event.target.value)}
                              placeholder="COR/PTI or original invoice reference and verification basis"
                            />
                          </label>
                          <div>
                            <button
                              type="button"
                              disabled={!!busy}
                              onClick={() => setReviewing("")}
                            >
                              Cancel review
                            </button>
                            <button type="submit" disabled={!!busy}>
                              Record evidence & recheck
                            </button>
                          </div>
                        </form>
                      ) : (
                        <button
                          type="button"
                          disabled={!!busy}
                          onClick={() => {
                            setReviewing(`${row.source}:${row.id}`);
                            setBranchCode("");
                            setEvidence("");
                          }}
                        >
                          Review historical branch evidence
                        </button>
                      )}
                    </div>
                  )}
                <footer>
                  <span>
                    {row.outbox
                      ? row.outbox.status === "needs_source_review"
                        ? "Secure copy saved · source review required"
                        : "Secure copy saved · awaiting API integration"
                      : "Internal copy not prepared"}
                  </span>
                  {result.can_prepare && (
                    <button disabled={!!busy} onClick={() => void prepare(row)}>
                      {busy === `${row.source}:${row.id}`
                        ? "Preparing…"
                        : row.outbox
                          ? "Recheck secure copy"
                          : "Prepare internal copy"}
                    </button>
                  )}
                </footer>
              </article>
            ))}
            {!result?.data.length && <p>No issued documents are available in this workspace.</p>}
          </div>
        )}
        <div data-slot="table-pagination" className="er-pagination">
          <span>{result?.total || 0} issued source records</span>
          <button
            aria-label="Previous readiness page"
            disabled={page <= 1 || loading || !!busy}
            onClick={() => setPage((n) => n - 1)}
          >
            <ChevronLeft size={16} />
          </button>
          <span>
            Page {page} / {result?.last_page || 1}
          </span>
          <button
            aria-label="Next readiness page"
            disabled={page >= (result?.last_page || 1) || loading || !!busy}
            onClick={() => setPage((n) => n + 1)}
          >
            <ChevronRight size={16} />
          </button>
          <button disabled={!!busy} onClick={onClose}>
            Close
          </button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
