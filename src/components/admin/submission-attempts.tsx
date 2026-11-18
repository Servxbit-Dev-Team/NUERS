import { useEffect, useState } from "react";
import { AlertCircle, ChevronLeft, ChevronRight, RefreshCw, Search, X } from "lucide-react";
import { apiFetch, readJsonResponse } from "@/lib/api-url";
import { subscribeRealtimeRefresh } from "@/lib/use-realtime-refresh";
import { formatTin } from "@/lib/tin";
import "./submission-attempts.css";

type Attempt = {
  id: string;
  created_at: string;
  status_code: number;
  request_reference: string | null;
  source_event_id: string | null;
  source_system: string | null;
  endpoint: string;
  error_message: string | null;
  error_code: string | null;
  outcome: string | null;
  branch_code: string | null;
  business_name: string;
  tin: string | null;
};
type Result = {
  environment: string;
  attempts: {
    data: Attempt[];
    total: number;
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
  };
};
const reasons: Record<number, string> = {
  400: "Invalid request.",
  401: "Authentication failed.",
  402: "Insufficient wallet balance.",
  403: "Access or submission permission denied.",
  409: "Conflicting source identity or content.",
  413: "Request too large.",
  415: "Unsupported content type.",
  422: "Submission validation failed.",
  429: "Request quota exceeded.",
};
function date(value: string) {
  return new Date(value.includes("T") ? value : value.replace(" ", "T") + "Z").toLocaleString(
    "en-PH",
    { timeZone: "Asia/Manila", dateStyle: "medium", timeStyle: "short" },
  );
}

export function SubmissionAttempts({ onClose }: { onClose: () => void }) {
  const [environment, setEnvironment] = useState("live");
  const [draft, setDraft] = useState("");
  const [search, setSearch] = useState("");
  const [status, setStatus] = useState("");
  const [page, setPage] = useState(1);
  const [refresh, setRefresh] = useState(0);
  const [data, setData] = useState<Result | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    let running = false;
    async function load(quiet = false) {
      if (running) return;
      running = true;
      if (!quiet) setBusy(true);
      try {
        const query = new URLSearchParams({
          environment,
          page: String(page),
          search,
          ...(status ? { status } : {}),
        });
        const response = await apiFetch(`/api/admin/submission-attempts?${query}`, {
          signal: controller.signal,
          cache: "no-store",
        });
        const next = await readJsonResponse<Result>(response, "Unable to load failed submissions.");
        if (!controller.signal.aborted) {
          setData(next);
          setError("");
        }
      } catch (e) {
        if (!controller.signal.aborted)
          setError(e instanceof Error ? e.message : "Unable to load failed submissions.");
      } finally {
        running = false;
        if (!controller.signal.aborted) setBusy(false);
      }
    }
    setData(null);
    load();
    const stop = subscribeRealtimeRefresh(() => load(true));
    return () => {
      controller.abort();
      stop();
    };
  }, [environment, search, status, page, refresh]);
  const rows = data?.attempts;
  return (
    <section
      className="submission-attempts ne-panel"
      id="submission-attempts"
      aria-labelledby="submission-attempts-title"
    >
      <div className="sa-heading">
        <div>
          <h2 id="submission-attempts-title">
            <AlertCircle size={20} /> Failed API submissions
          </h2>
          <p>Requests received by NUERS that were rejected or could not be confirmed.</p>
        </div>
        <button onClick={onClose} aria-label="Close failed submissions">
          <X size={18} />
        </button>
      </div>
      <div className="sa-notice">
        Each row is one API attempt; a batch counts as one attempt. Failed attempts remain in this
        history after a successful retry. They are not additional sales or wallet charges. Requests
        that never reach NUERS must be checked in the sending system.
      </div>
      <form
        className="sa-filters"
        onSubmit={(e) => {
          e.preventDefault();
          setSearch(draft.trim());
          setPage(1);
        }}
      >
        <label>
          Environment
          <select
            value={environment}
            onChange={(e) => {
              setEnvironment(e.target.value);
              setPage(1);
            }}
          >
            <option value="live">Live</option>
            <option value="sandbox">Sandbox</option>
          </select>
        </label>
        <label className="sa-search">
          Merchant or source reference
          <input
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            maxLength={120}
            placeholder="Business name, TIN, reference or event ID"
          />
        </label>
        <label>
          HTTP response
          <select
            value={status}
            onChange={(e) => {
              setStatus(e.target.value);
              setPage(1);
            }}
          >
            <option value="">All failures</option>
            {Object.entries({ ...reasons, 500: "Unknown outcome", 503: "Service unavailable" }).map(
              ([code, name]) => (
                <option key={code} value={code}>
                  {code} · {name}
                </option>
              ),
            )}
          </select>
        </label>
        <button type="submit">
          <Search size={15} />
          Search
        </button>
        <button type="button" disabled={busy} onClick={() => setRefresh((x) => x + 1)}>
          <RefreshCw size={15} />
          Refresh
        </button>
      </form>
      {error && (
        <div role="alert" className="ne-error">
          {error}
          {data ? " Showing the last loaded results." : ""}
        </div>
      )}
      <div className="sa-scroll">
        <table>
          <thead>
            <tr>
              <th>Received (PHT)</th>
              <th>Merchant</th>
              <th>Source / Reference</th>
              <th>Result</th>
              <th>Reason / Next step</th>
            </tr>
          </thead>
          <tbody>
            {rows?.data.map((row) => {
              const unknown =
                row.outcome === "unknown" || row.status_code >= 500 || row.status_code === 408;
              return (
                <tr key={row.id}>
                  <td>
                    {date(row.created_at)}
                    <small>
                      {environment === "live" ? "Live" : "Sandbox"} ·{" "}
                      {row.endpoint.endsWith("/batch") ? "Batch attempt" : "Single attempt"}
                    </small>
                  </td>
                  <td>
                    <strong>{row.business_name}</strong>
                    <small>
                      {formatTin(row.tin) || "TIN not recorded"}
                      {row.branch_code ? ` · ${row.branch_code}` : ""}
                    </small>
                  </td>
                  <td>
                    {row.request_reference || "Reference not supplied"}
                    <small>{row.source_system || "Source not supplied"}</small>
                    {row.source_event_id && <small>Event: {row.source_event_id}</small>}
                    <small>Request: {row.id}</small>
                  </td>
                  <td>
                    <span className="sa-failure">
                      {row.status_code} · {unknown ? "Unconfirmed" : "Rejected"}
                    </span>
                    <small>{row.error_code || "Historical request log"}</small>
                  </td>
                  <td>
                    {row.error_message ||
                      reasons[row.status_code] ||
                      "Original detailed reason was not retained."}
                    <small>
                      {unknown
                        ? "Check the source event status before retrying unchanged; acceptance may be unknown."
                        : row.status_code === 429
                          ? "Wait for Retry-After, then retry the same event with backoff."
                          : "Correct the cause in the source system, then retry using the original event identity."}
                      {!row.error_message &&
                        " Only the HTTP response was retained for this historical attempt."}
                    </small>
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {!rows?.data.length && (
          <div className="sa-empty">
            {busy
              ? "Loading submission history…"
              : error && !data
                ? "Submission history unavailable."
                : "No failed submissions match these filters."}
          </div>
        )}
      </div>
      <div className="sa-pagination">
        <span>
          {rows
            ? `${rows.from || 0}–${rows.to || 0} of ${rows.total.toLocaleString("en-PH")} attempts`
            : "—"}
        </span>
        <div>
          <button
            disabled={busy || page <= 1}
            aria-label="Previous submission page"
            onClick={() => setPage((p) => p - 1)}
          >
            <ChevronLeft size={16} />
          </button>
          <span>
            Page {page} of {rows?.last_page || 1}
          </span>
          <button
            disabled={busy || !rows || page >= rows.last_page}
            aria-label="Next submission page"
            onClick={() => setPage((p) => p + 1)}
          >
            <ChevronRight size={16} />
          </button>
        </div>
      </div>
    </section>
  );
}
