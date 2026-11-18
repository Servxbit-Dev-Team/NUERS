import { useEffect, useRef, useState } from "react";
import { Link } from "react-router-dom";
import { Play, RotateCcw, Download, FlaskConical } from "lucide-react";
import { apiUrl } from "@/lib/api-url";
import { SearchableSelect } from "@/components/registration/searchable-select";
import { DeveloperShell, CodeBlock, downloadJson } from "./shared";
const initial = {
  external_reference: "TEST-SALE-0001",
  source_system: "Developer preview",
  document_type: "sales_invoice",
  amount: 1120,
  tax_type: "vatable",
  vat_rate: 12,
  vat_inclusive: true,
  customer_name: "Synthetic Customer",
  customer_tin: "999-999-999-000",
};
type Run = {
  operation: string;
  time: string;
  http: number;
  duration: number;
  body: Record<string, unknown>;
};
export function DeveloperSandbox() {
  const [editor, setEditor] = useState(JSON.stringify(initial, null, 2)),
    [action, setAction] = useState("preview"),
    [scenario, setScenario] = useState("duplicate"),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [result, setResult] = useState<Run | null>(null),
    [history, setHistory] = useState<Run[]>([]);
  const controller = useRef<AbortController | null>(null);
  useEffect(() => () => controller.current?.abort(), []);
  async function run() {
    setError("");
    let payload;
    try {
      payload = JSON.parse(editor);
      if (!payload || Array.isArray(payload) || typeof payload !== "object") throw Error();
    } catch {
      if (action !== "scenario") {
        setError("Enter a valid JSON object before running the request.");
        return;
      }
    }
    const c = new AbortController();
    controller.current = c;
    const timeout = window.setTimeout(() => c.abort(), 15000),
      start = performance.now();
    setBusy(true);
    try {
      const r = await fetch(apiUrl("/api/developer/sandbox"), {
        method: "POST",
        credentials: "omit",
        signal: c.signal,
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        body: JSON.stringify({
          is_test: true,
          action,
          ...(action === "scenario" ? { scenario } : { payload }),
        }),
      });
      const body = await r.json();
      const next = {
        operation: action === "scenario" ? `Scenario: ${scenario}` : action,
        time: new Date().toLocaleTimeString(),
        http: r.status,
        duration: Math.round(performance.now() - start),
        body,
      };
      setResult(next);
      setHistory((h) => [next, ...h].slice(0, 10));
    } catch (e) {
      setError(
        e instanceof Error && e.name === "AbortError"
          ? "Request timed out or was cancelled. No preview result is available."
          : "Unable to reach the sandbox. Check your connection and try again.",
      );
    } finally {
      window.clearTimeout(timeout);
      setBusy(false);
    }
  }
  return (
    <DeveloperShell>
      <section className="dev-hero">
        <div className="dev-container">
          <span className="dev-eyebrow">DEVELOPER TOOLS · SYNTHETIC DATA ONLY</span>
          <h1>API Sandbox</h1>
          <p>
            Validate a NUERS transaction request, inspect its tax calculation, and explore response
            handling in an isolated preview.
          </p>
          <div className="dev-actions">
            <Link className="dev-button secondary" to="/api/docs">
              Read API Documentation
            </Link>
            <Link className="dev-button secondary" to="/developers/integration">
              Integration Overview
            </Link>
          </div>
        </div>
      </section>
      <div className="dev-container dev-section">
        <div className="dev-alert">
          <FlaskConical />
          <span>
            No invoice is issued, no financial record is saved, and BIR is never contacted. Use
            invented names and the example TIN only. Never paste credentials or customer records.
          </span>
        </div>
        <div className="dev-sandbox-grid">
          <section className="dev-card">
            <h2>Request workbench</h2>
            <p>
              <code>POST /api/developer/sandbox</code> · No authentication
            </p>
            <SearchableSelect
              label="Operation"
              disabled={busy}
              required={false}
              value={action}
              onChange={setAction}
              items={[
                { value: "preview", label: "Validate & preview calculation" },
                { value: "validate", label: "Validate transaction contract" },
                { value: "scenario", label: "Explore a response scenario" },
              ]}
            />
            {action === "scenario" ? (
              <SearchableSelect
                label="Illustrative scenario"
                disabled={busy}
                required={false}
                value={scenario}
                onChange={setScenario}
                items={[
                  ["duplicate", "Duplicate reference · 200"],
                  ["conflict", "State conflict · 409"],
                  ["unauthorized", "Invalid credential · 401"],
                  ["forbidden", "Policy denied · 403"],
                  ["rate_limit", "Quota reached · 429"],
                  ["timeout", "Uncertain delivery · 504"],
                  ["production_gate", "Production readiness gate · 409"],
                ].map(([value, label]) => ({ value, label }))}
              />
            ) : (
              <>
                <div className="dev-editor-tools">
                  <button
                    disabled={busy}
                    onClick={() => {
                      try {
                        setEditor(JSON.stringify(JSON.parse(editor), null, 2));
                        setError("");
                      } catch {
                        setError("Cannot format invalid JSON.");
                      }
                    }}
                  >
                    Format JSON
                  </button>
                  <button
                    disabled={busy}
                    onClick={() =>
                      setEditor(
                        JSON.stringify(
                          { ...initial, tax_type: "non_vat", amount: 499.99 },
                          null,
                          2,
                        ),
                      )
                    }
                  >
                    Non-VAT example
                  </button>
                  <button
                    disabled={busy}
                    onClick={() =>
                      setEditor(JSON.stringify({ ...initial, tax_type: "zero_rated" }, null, 2))
                    }
                  >
                    Zero-rated example
                  </button>
                </div>
                <label className="dev-editor-label" htmlFor="sandbox-json">
                  Synthetic transaction JSON
                </label>
                <textarea
                  id="sandbox-json"
                  className="dev-json-editor"
                  spellCheck={false}
                  value={editor}
                  onChange={(e) => setEditor(e.target.value)}
                  disabled={busy}
                />
              </>
            )}
            <p className="dev-muted">
              Reference must start with TEST-, DEMO- or SAMPLE-. Payload limit: 64 KB. Preview
              amount limit: ₱1 billion. Public throttle: 20 requests per minute.
            </p>
            {error && (
              <p className="dev-alert" role="alert">
                {error}
              </p>
            )}
            <div className="dev-actions">
              <button className="dev-button" disabled={busy} onClick={run}>
                <Play size={16} />
                {busy ? "Running preview…" : "Run Sandbox Request"}
              </button>
              <button
                className="dev-button secondary"
                disabled={busy}
                onClick={() => {
                  setEditor(JSON.stringify(initial, null, 2));
                  setAction("preview");
                  setError("");
                  setResult(null);
                }}
              >
                <RotateCcw size={16} />
                Reset
              </button>
            </div>
          </section>
          <section className="dev-card" aria-live="polite">
            <h2>Response inspector</h2>
            {result ? (
              <>
                <div className="dev-chips">
                  <span>{result.operation}</span>
                  <span>Actual HTTP {result.http}</span>
                  <span>{result.duration} ms</span>
                  <span>{result.time}</span>
                  {typeof result.body.simulated_http_status === "number" && (
                    <span>Illustrative HTTP {result.body.simulated_http_status}</span>
                  )}
                </div>
                <CodeBlock
                  label="Actual sandbox response"
                  text={JSON.stringify(result.body, null, 2)}
                />
                <button
                  className="dev-button secondary"
                  onClick={() => downloadJson(result.body, "nuers-sandbox-response.json")}
                >
                  <Download size={16} />
                  Download Response
                </button>
              </>
            ) : (
              <div className="dev-empty">
                <FlaskConical size={38} />
                <h3>Your response will appear here</h3>
                <p>
                  Run a synthetic request to inspect the actual server response. Scenario status
                  codes appear in JSON; scenario requests themselves return HTTP 200.
                </p>
              </div>
            )}
          </section>
        </div>
        <section className="dev-card dev-history">
          <h2>Session activity</h2>
          <p>Last 10 requests in this open page only. Reloading clears this history.</p>
          {history.length ? (
            <div className="dev-table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Time</th>
                    <th>Operation</th>
                    <th>Actual HTTP</th>
                    <th>Duration</th>
                    <th>Result</th>
                    <th>Inspect</th>
                  </tr>
                </thead>
                <tbody>
                  {history.map((r, i) => (
                    <tr key={i}>
                      <td>{r.time}</td>
                      <td>{r.operation}</td>
                      <td>{r.http}</td>
                      <td>{r.duration} ms</td>
                      <td>{String(r.body.status || r.body.scenario || "Request error")}</td>
                      <td>
                        <button onClick={() => setResult(r)}>View response</button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : (
            <p>No requests yet.</p>
          )}
        </section>
        <section className="dev-section">
          <h2>What this proves—and what still needs testing</h2>
          <div className="dev-grid">
            <article className="dev-card">
              <h3>Shared field contract</h3>
              <p>
                The preview uses the same validation rules and tax calculation service as merchant
                ingestion. VAT-inclusive ₱1,120 at 12% yields ₱1,000 VATable sales and ₱120 VAT.
              </p>
            </article>
            <article className="dev-card">
              <h3>Separate operational acceptance</h3>
              <p>
                Preview does not test your key, IP policy, merchant authority, numbering,
                concurrency, line-item reconciliation, BIR schema or transport. A key marked sandbox
                does not isolate the real ingestion ledger.
              </p>
            </article>
            <article className="dev-card">
              <h3>Prepare your handover</h3>
              <p>
                Review duplicate handling, unknown outcomes, access controls and accounting checks
                with the integration guide. Never interpret a simulated response as a government
                acknowledgment.
              </p>
              <Link to="/api/docs#reliability">Review reliability requirements →</Link>
            </article>
          </div>
        </section>
      </div>
    </DeveloperShell>
  );
}
