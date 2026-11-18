import { DocumentDownloads } from "@/components/documentation/document-downloads";
import { IntegrationReceiptGuide } from "@/components/merchant/integration-receipt-guide";
import { useEffect, useState } from "react";
import { Link, useLocation } from "react-router-dom";
import { Search, ArrowRight, Download, BookOpen, FlaskConical, AlertTriangle } from "lucide-react";
import {
  DeveloperShell,
  useCatalog,
  CodeBlock,
  FieldTable,
  SpecDownload,
  downloadJson,
  type Endpoint,
} from "./shared";
import { SearchableSelect } from "@/components/registration/searchable-select";
const errorText: Record<number, string> = {
  200: "Request completed, or a documented duplicate returned. Inspect the JSON state.",
  201: "Record created when the required gates pass. This is not BIR acceptance.",
  202: "Accepted for local processing. Not an authoritative BIR acknowledgment.",
  401: "Missing or invalid credential. Reauthenticate the correct credential class; do not blindly retry.",
  402: "Insufficient prepaid wallet funds. No financial records commit. Retain the source on the client and retry the identical identity after funding.",
  403: "Role, scope, key status or exact-IP restriction denied access.",
  404: "Record or linked merchant not found in the authorized context.",
  409: "Authority/state/revision conflict. Review the blocker and reconcile before resubmitting.",
  413: "Request exceeds the public sandbox size limit.",
  422: "Validation failed. Correct the named fields; preserve the original source identity.",
  429: "Quota or request throttle reached. Honor Retry-After when supplied and use bounded backoff.",
  500: "Server/configuration failure. Capture redacted context and contact support.",
};
function codeFor(e: Endpoint, lang: string) {
  const base = window.location.origin,
    body = JSON.stringify(e.sample || {}, null, 2),
    header =
      e.auth === "Merchant API key"
        ? "X-NUERS-API-Key: YOUR_MERCHANT_KEY"
        : e.auth === "Public"
          ? null
          : "Authorization: Bearer YOUR_USER_SESSION";
  if (lang === "javascript")
    return `// Server-side example. Keep real credentials out of browser code.\nconst response = await fetch(process.env.NUERS_BASE_URL + ${JSON.stringify(e.path)}, {\n  method: ${JSON.stringify(e.method)},\n  headers: { Accept: "application/json",${e.method === "GET" || e.method === "DELETE" ? "" : ' "Content-Type": "application/json",'}${header ? "\n    " + JSON.stringify(header.split(": ")[0]) + ": " + (e.auth === "Merchant API key" ? "process.env.NUERS_MERCHANT_KEY" : '"Bearer " + process.env.NUERS_USER_SESSION') + "," : ""}\n  },${e.sample ? "\n  body: JSON.stringify(" + body + ")," : ""}\n});\nconst result = await response.json();\nif (!response.ok) throw new Error(result.message || "Request failed");\n// Inspect approved fields only. Never log a credential-bearing response.`;
  if (lang === "python")
    return `# Server-side example. Never log real credentials.\nimport os, requests\nresponse = requests.request(${JSON.stringify(e.method)},\n    os.environ["NUERS_BASE_URL"] + ${JSON.stringify(e.path)},\n    headers={"Accept": "application/json"${header ? ", " + JSON.stringify(header.split(": ")[0]) + ": " + (e.auth === "Merchant API key" ? 'os.environ["NUERS_MERCHANT_KEY"]' : '"Bearer " + os.environ["NUERS_USER_SESSION"]') : ""}},${
      e.sample
        ? "\n    json=" +
          JSON.stringify(e.sample, null, 2)
            .replace(/\btrue\b/g, "True")
            .replace(/\bfalse\b/g, "False")
            .replace(/\bnull\b/g, "None") +
          ","
        : ""
    }\n    timeout=30)\nresponse.raise_for_status()\n# Inspect approved fields only; never print a credential-bearing response.`;
  return `# Set NUERS_BASE_URL to your actual deployment origin (example: ${base}).\ncurl -X ${e.method} "$NUERS_BASE_URL${e.path}" \\\n  -H "Accept: application/json"${header ? ' \\\n  -H "' + header.replace("YOUR_MERCHANT_KEY", "$NUERS_MERCHANT_KEY").replace("YOUR_USER_SESSION", "$NUERS_USER_SESSION") + '"' : ""}${e.sample ? ' \\\n  -H "Content-Type: application/json" \\\n  --data-binary @request.json' : ""}${e.sample ? "\n\n# request.json\n" + body : ""}`;
}
export function DeveloperDocs() {
  const { data, error, retry } = useCatalog();
  const location = useLocation();
  const [search, setSearch] = useState(""),
    [group, setGroup] = useState("all"),
    [selected, setSelected] = useState("ingest"),
    [tab, setTab] = useState("request"),
    [lang, setLang] = useState("curl");
  const endpoint = data?.endpoints.find((e) => e.id === selected) || data?.endpoints[0];
  const filtered = (data?.endpoints || []).filter(
    (e) =>
      (group === "all" || e.group === group) &&
      [e.path, e.title, e.auth, e.group].join(" ").toLowerCase().includes(search.toLowerCase()),
  );
  useEffect(() => {
    if (location.hash) {
      const id = location.hash.slice(1);
      document.getElementById(id)?.scrollIntoView({ block: "start" });
    }
  }, [location.hash, data]);
  function collection() {
    if (!data) return;
    downloadJson(
      {
        info: {
          name: "NUERS integration reference",
          schema: "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
          description: data.description,
        },
        variable: [
          { key: "base_url", value: window.location.origin },
          { key: "merchant_key", value: "" },
          { key: "user_session", value: "" },
        ],
        item: data.endpoints.map((e) => ({
          name: e.title,
          request: {
            method: e.method,
            header: [
              { key: "Accept", value: "application/json" },
              ...(e.sample ? [{ key: "Content-Type", value: "application/json" }] : []),
              ...(e.auth === "Public"
                ? []
                : e.auth === "Merchant API key"
                  ? [{ key: "X-NUERS-API-Key", value: "{{merchant_key}}" }]
                  : [{ key: "Authorization", value: "Bearer {{user_session}}" }]),
            ],
            url: "{{base_url}}" + e.path,
            description: e.description + "\n" + e.notes.join("\n"),
            ...(e.sample
              ? {
                  body: {
                    mode: "raw",
                    raw: JSON.stringify(e.sample, null, 2),
                    options: { raw: { language: "json" } },
                  },
                }
              : {}),
          },
        })),
      },
      "nuers-postman-collection.json",
    );
  }
  return (
    <DeveloperShell>
      <section className="dev-page-heading dev-container">
        <span className="dev-eyebrow">
          DEVELOPER REFERENCE · {data?.version || "2026-10-19.receipts-status"}
        </span>
        <h1>API Documentation</h1>
        <p>
          The implemented NUERS integration contract: authentication, fields, responses, operating
          limits and readiness. Official BIR endpoints and schemas are a separate specification.
        </p>
        <div className="dev-actions">
          <a
            className="dev-button secondary"
            href="/guides/NUERS-API-Connection-Guide.pdf?v=brand-fc40dead1e2c"
          >
            <Download size={16} />
            Merchant PDF Guide
          </a>
          <SpecDownload />
          <button className="dev-button secondary" disabled={!data} onClick={collection}>
            <Download size={16} />
            Postman Collection
          </button>
          <Link className="dev-button" to="/api/sandbox">
            <FlaskConical size={16} />
            Test a Synthetic Request
          </Link>
        </div>
      </section>
      <div className="dev-container dev-doc-layout">
        <aside className="dev-doc-sidebar">
          <h2>In this guide</h2>
          <nav aria-label="Documentation chapters">
            {[
              ["quickstart", "Quick start"],
              ["document-downloads", "Document downloads"],
              ["customer-receipts", "NUERS status & customer email"],
              ["authentication", "Authentication & credentials"],
              ["reference", "Endpoint reference"],
              ["reliability", "Idempotency & retries"],
              ["errors", "Errors & quotas"],
              ["environments", "Environments & webhooks"],
              ["security", "Security & handover"],
              ["versioning", "Versions & support"],
            ].map(([id, label]) => (
              <a key={id} href={"#" + id}>
                {label}
              </a>
            ))}
          </nav>
          <div className="dev-sidebar-status">
            <BookOpen />
            <b>Integration-focused reference</b>
            <p>
              {data?.endpoints.length || 0} documented operations. The downloaded contract documents
              this subset; it is not every internal administrative endpoint.
            </p>
          </div>
        </aside>
        <div className="dev-doc-main">
          <section id="quickstart" className="dev-doc-section">
            <h2>Quick start</h2>
            <p>
              Use the actual origin hosting your NUERS instance. No government API hostname or
              /api/v1 route is implied by this documentation.
            </p>
            <CodeBlock label="Current deployment origin" text={window.location.origin} />
            <ol className="dev-numbered">
              <li>Read the credential boundaries and route prerequisites below.</li>
              <li>Use the public sandbox with synthetic data and no credential.</li>
              <li>
                After account verification, generate a named Live or Sandbox key in Merchant → API
                Integration. Copy the automatically prepared source connection values for your owned
                ERP/POS.
              </li>
              <li>
                Send the saved original event, display NUERS Success/Error with its reason, and poll
                the returned status URL. Include customer_email to request the branded customer PDF.
              </li>
            </ol>
            <p>
              <Link to="/developers/integration">Read the complete integration overview →</Link>
            </p>
          </section>
          <section id="document-downloads" className="dev-doc-section">
            <DocumentDownloads />
          </section>
          <section id="customer-receipts" className="dev-doc-section">
            <IntegrationReceiptGuide />
          </section>
          <section id="authentication" className="dev-doc-section">
            <h2>Authentication & credential boundaries</h2>
            <div className="dev-table-scroll">
              <table>
                <thead>
                  <tr>
                    <th>Credential</th>
                    <th>Header</th>
                    <th>Where it applies</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Merchant integration key</td>
                    <td>
                      <code>X-NUERS-API-Key: …</code>
                      <br />
                      or <code>Authorization: Bearer …</code>
                    </td>
                    <td>
                      <code>POST /api/integrations/transactions</code>. Use one credential;
                      conflicting header and Bearer values are rejected. Writes require
                      transactions:write; status and wallet require transactions:read. Active
                      status, environment, registered location, exact-IP policy and daily quota are
                      checked.
                    </td>
                  </tr>
                  <tr>
                    <td>User session</td>
                    <td>
                      <code>Authorization: Bearer …</code>
                    </td>
                    <td>
                      Business workspace/key management and authorized administrative endpoints. The
                      user's merchant association or administrative role determines access.
                    </td>
                  </tr>
                  <tr>
                    <td>BIR provider credential</td>
                    <td>Defined by the official provider interface</td>
                    <td>
                      Separate approved BIR transport. Do not use a NUERS user session or merchant
                      key as a BIR credential.
                    </td>
                  </tr>
                  <tr>
                    <td>Public sandbox</td>
                    <td>No credential</td>
                    <td>
                      Credential-bearing requests are rejected; only isolated synthetic preview is
                      available.
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <p>
              API keys are stored as hashes and shown once at creation. Replace a key by creating a
              new one, migrating authorized clients, then revoking the old key. There is no
              implemented rotate flag. The IP allowlist is exact matching, not CIDR. Browser
              sessions use a device identifier: login on another device replaces the active session;
              invalid sessions sign out automatically.
            </p>
          </section>
          <section id="reference" className="dev-doc-section">
            <div className="dev-reference-heading">
              <h2>Endpoint reference</h2>
              <span>
                {filtered.length} / {data?.endpoints.length || 0} operations
              </span>
            </div>
            <div className="dev-reference-filters">
              <label>
                <span>Search endpoints</span>
                <div className="dev-search">
                  <Search size={16} />
                  <input
                    placeholder="Path, title, credential…"
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                  />
                </div>
              </label>
              <SearchableSelect
                required={false}
                label="API group"
                value={group}
                onChange={setGroup}
                items={[
                  { value: "all", label: "All groups" },
                  ...[...new Set((data?.endpoints || []).map((e) => e.group))].map((g) => ({
                    value: g,
                    label: g,
                  })),
                ]}
              />
            </div>
            {error ? (
              <div className="dev-alert" role="alert">
                {error}
                <button onClick={retry}>Retry</button>
              </div>
            ) : !data ? (
              <p role="status">Loading API contracts…</p>
            ) : (
              <>
                <div className="dev-endpoint-picker">
                  {filtered.map((e) => (
                    <button
                      key={e.id}
                      className={selected === e.id ? "active" : ""}
                      onClick={() => {
                        setSelected(e.id);
                        setTab("request");
                      }}
                    >
                      <span className={"dev-method " + e.method}>{e.method}</span>
                      <code>{e.path}</code>
                      <span>{e.title}</span>
                    </button>
                  ))}
                  {!filtered.length && <p>No endpoint matches your filters.</p>}
                </div>
                {endpoint && (
                  <article className="dev-endpoint-detail">
                    <div className="dev-endpoint-title">
                      <span className={"dev-method " + endpoint.method}>{endpoint.method}</span>
                      <code>{endpoint.path}</code>
                    </div>
                    <h3>{endpoint.title}</h3>
                    <p>{endpoint.description}</p>
                    <div className="dev-chips">
                      <span>{endpoint.auth}</span>
                      <span>{endpoint.group}</span>
                    </div>
                    <div className="dev-tabs" role="tablist" aria-label="Endpoint details">
                      {["request", "response", "errors", "examples"].map((t) => (
                        <button
                          role="tab"
                          aria-selected={tab === t}
                          key={t}
                          onClick={() => setTab(t)}
                        >
                          {t.charAt(0).toUpperCase() + t.slice(1)}
                        </button>
                      ))}
                    </div>
                    <div role="tabpanel">
                      {tab === "request" && (
                        <>
                          {endpoint.query.length > 0 && (
                            <>
                              <h4>Query parameters</h4>
                              <FieldTable fields={endpoint.query} />
                            </>
                          )}
                          {endpoint.fields.length > 0 ? (
                            <>
                              <h4>JSON request fields</h4>
                              <FieldTable fields={endpoint.fields} />
                              {endpoint.sample && (
                                <CodeBlock
                                  text={JSON.stringify(endpoint.sample, null, 2)}
                                  label="Illustrative request body"
                                />
                              )}
                            </>
                          ) : (
                            !endpoint.query.length && (
                              <p>
                                No JSON request body. Replace any path identifier with an authorized
                                record ID.
                              </p>
                            )
                          )}
                        </>
                      )}
                      {tab === "response" && (
                        <>
                          <p>
                            Examples are illustrative, not live taxpayer data or proof of BIR
                            acceptance.
                          </p>
                          {endpoint.response ? (
                            <CodeBlock
                              text={JSON.stringify(endpoint.response, null, 2)}
                              label="Illustrative response excerpt"
                            />
                          ) : (
                            <p>
                              This operation returns current authorized record metadata or a success
                              result. Use the operation notes and inspect the real response; no
                              synthetic BIR reference is supplied here.
                            </p>
                          )}
                        </>
                      )}
                      {tab === "errors" && (
                        <div className="dev-error-list">
                          {(endpoint.errors.length ? endpoint.errors : [500]).map((code) => (
                            <p key={code}>
                              <b>{code}</b>
                              {errorText[code]}
                            </p>
                          ))}
                        </div>
                      )}
                      {tab === "examples" && (
                        <>
                          <div className="dev-tabs" aria-label="Example language">
                            {["curl", "javascript", "python"].map((l) => (
                              <button aria-pressed={lang === l} key={l} onClick={() => setLang(l)}>
                                {l === "curl" ? "cURL" : l === "javascript" ? "Node.js" : "Python"}
                              </button>
                            ))}
                          </div>
                          <CodeBlock
                            label="Review before executing · secrets are placeholders"
                            text={codeFor(endpoint, lang)}
                          />
                        </>
                      )}
                    </div>
                    {endpoint.notes.length > 0 && (
                      <div className="dev-operation-notes">
                        <h4>Operation notes & limitations</h4>
                        <ul>
                          {endpoint.notes.map((n) => (
                            <li key={n}>{n}</li>
                          ))}
                        </ul>
                      </div>
                    )}
                  </article>
                )}
              </>
            )}
          </section>
          <section id="reliability" className="dev-doc-section">
            <h2>Idempotency, replay & uncertainty</h2>
            <h3>Merchant ingestion</h3>
            <p>
              Persist <code>external_reference</code> in your source system before sending. Keep
              source_event_id, source_system, registered location, environment and the original
              payload unchanged. The server checks canonical content under the
              merchant/location/system/document-family/reference identity and independently checks
              source-event identity. Exact accepted retries return HTTP 200 and the original IDs
              without a second fee or customer email. Changed content returns 409. Atomic batches
              accept 1–50 events for one location, all or none.
            </p>
            <h3>Internal EIS queue</h3>
            <p>
              Queue identity includes seller, branch, document type and document number within the
              environment. Identical identity/hash returns the existing transmission; changed
              content returns 409. Issuance and queue intent are currently separate workflows; do
              not assume an end-to-end transactional outbox is certified.
            </p>
            <h3>Delivery uncertainty</h3>
            <p>
              A connection timeout or 5xx can occur after an upstream side effect. Record the source
              reference and uncertainty, inspect the existing record/attempt and reconcile before
              retry. Poll the returned status_url using the same environment and a transactions:read
              key. If no ID was received, retry the identical original request to recover its
              acknowledgement. Keep Pending confirmation for unknown outcomes.
            </p>
          </section>
          <section id="errors" className="dev-doc-section">
            <h2>Errors, quotas & response handling</h2>
            <div className="dev-error-list">
              {Object.entries(errorText).map(([code, text]) => (
                <p key={code}>
                  <b>{code}</b>
                  {text}
                </p>
              ))}
            </div>
            <CodeBlock
              label="Illustrative merchant validation error"
              text={JSON.stringify(
                {
                  nuers_status: "error",
                  code: "VALIDATION_FAILED",
                  message: "The customer email field must be a valid email address.",
                  error: {
                    code: "VALIDATION_FAILED",
                    reason: "The customer email field must be a valid email address.",
                    retryable: false,
                    outcome: "rejected",
                  },
                  errors: {
                    customer_email: ["The customer email field must be a valid email address."],
                  },
                },
                null,
                2,
              )}
            />
            <p>
              Transaction ingestion uses the configured daily key quota and resets against the
              application’s day boundary. Its atomic shared-database admission counts attempted key
              requests; it is not a published per-second throughput guarantee. Public sandbox
              throttle: 20 requests/minute, 64 KB payload cap. Do not assume every 429 includes
              Retry-After. Preserve original deadlines under backoff and escalate before breach.
              NUERS deducts the current published flat fee from the merchant wallet for each
              finalized Live sale at or above the configured minimum (₱0 means every sale), with
              unlimited storage and five-year record retention; the public preview does not
              calculate fees or charge transactions.
            </p>
          </section>
          <section id="environments" className="dev-doc-section">
            <h2>Environments, status & webhooks</h2>
            <p>
              <b>Isolated preview:</b> the public sandbox performs no financial writes and no BIR
              calls. Simulated status codes are returned inside the response with explicit test
              markers.
            </p>
            <p>
              <b>Merchant key environment:</b> Live and Sandbox use the same documented URLs, with
              the environment derived from the key. Sandbox stores encrypted test events separately
              and never changes Live transactions, wallet balances, invoice numbering, reports or
              customer mail. Verify environment before acknowledging your local outbox;
              cross-environment IDs return 404.
            </p>
            <p>
              <b>Internal certification:</b> queue operations require explicit synthetic data.
              Manual test acknowledgments are certification-only and are never live BIR acceptance.
            </p>
            <p>
              <b>Production:</b> the published BIR wire protocol adapter is implemented. BIR-issued
              credentials, per-company authority, certification tests and actual production
              acceptance are still separate prerequisites. Outbound transmission stays disabled
              until explicitly released.
            </p>
            <p>
              <b>Webhooks:</b> Configure a public HTTPS receiver you control under Merchant → API
              Integration → Webhooks. Verify ownership with the signed challenge, then enable
              delivery for the selected environment. Validate the HMAC signature against the raw
              body, timestamp and event ID; deduplicate durably before returning 2xx. Delivery has
              bounded retries and logs; poll status as a fallback. See the downloadable webhook
              guide for the exact receiver contract.
            </p>
            <a href="/guides/NUERS-Webhook-Connection-Guide.pdf?v=brand-fc40dead1e2c">
              Download webhook guide
            </a>
          </section>
          <section id="security" className="dev-doc-section">
            <h2>Security & production handover</h2>
            <ul className="dev-checklist">
              <li>
                Use HTTPS outside localhost. Store keys server-side and redact credentials,
                authorization headers, private keys and unnecessary customer identifiers from logs.
              </li>
              <li>
                Enforce the intended tenant/branch and minimal scope; verify exact outbound source
                IPs. Key policy metadata does not itself establish taxpayer authority.
              </li>
              <li>
                Review approved document numbering, supported line/tax calculations, correction
                rules and duplicate behavior against your accounting requirements.
              </li>
              <li>
                Collect security, restore/failover, load, retention/export, outage and operational
                signoff evidence. Local field validation is not security or BIR certification.
              </li>
              <li>
                Provide support owners, request correlation, source references and redacted errors.
                Do not send secrets or taxpayer payloads through public support channels.
              </li>
            </ul>
            <div className="dev-alert">
              <AlertTriangle size={20} />
              <span>
                Production gates stay closed until the required evidence and adapters are verified.
                Before launch, test your own connector’s mappings, retry handling, webhook receiver
                and customer email flow. A documented API response is not certification of an
                external system.
              </span>
            </div>
          </section>
          <section className="dev-doc-section">
            <h2>Manual discounts</h2>
            <p>
              Send <code>manual_discount</code> on the document or an item:{" "}
              <code>
                {JSON.stringify({ type: "amount", value: "112.00", reason: "Loyalty discount" })}
              </code>
              . Use <code>percentage</code> for a percentage. Keep original unit prices; send the
              discounted final gross including VAT. A ₱1,120 VAT-inclusive item less ₱112 returns
              ₱1,008 gross, ₱900 taxable base and ₱108 VAT. See pages 37–38 of the merchant guide
              for allocation, validation and reporting rules.
            </p>
          </section>
          <section className="dev-doc-section">
            <h2>CWT and outage recovery</h2>
            <p>
              For buyer-confirmed CWT, send withholding_tax in PHP and withholding_basis_reference.
              Send withholding_details with withholding_agent=true, classification, atc (two
              uppercase letters and three digits), rate (percentage), base, amount and
              evidence_reference. Decimal money fields have at most two decimals. amount must equal
              withholding_tax and rounded base × rate / 100. CWT reduces net_payable; gross_amount,
              sales, VAT and the wallet fee basis stay unchanged. Existing amount-and-basis
              integrations remain supported; use the structured buyer confirmation for new mappings.
            </p>
            <p>
              For CWT supplied or corrected after issuance, open Merchant → Compliance Operations →
              CWT adjustment memos. Select the issued original and record buyer/payor confirmation.
              Issuing a registered credit memo requires current authority and a credit-memo series.
              The original stays unchanged; sales and VAT adjustments are zero. Subsequent
              corrections reference the current CWT amount, with stale drafts rejected.
            </p>
            <p>
              Record an outage and its authorized manual invoices under Merchant → Compliance
              Operations, then record verified restoration. On a replacement Live invoice, send
              manual_recovery_id from that register and the original manual number in
              manual_invoice_number. The replacement must match merchant, branch, buyer and gross
              amount, explicitly reference the manual invoice and be dated after restoration. One
              manual invoice links to one replacement. A mismatch rolls back the new event and fee.
              Sandbox cannot link Live recovery records. The register tracks the three-calendar-day
              recovery deadline; it does not issue manual authority or claim BIR transmission.
            </p>
            <p>
              For an increase, issue an additional electronic invoice referencing the original; for
              a decrease, use a credit memo. The native merchant form does not create debit memos
              for new adjustments. Original records remain immutable. The intake API does not accept
              credit-memo document types; use the controlled merchant workflow for a NUERS-issued
              adjustment.
            </p>
            <p>
              Live storage intake remains available while ESP accreditation enforcement is prepared
              but disabled. This is an operational setting, not a statement of BIR authorization.
              Official NUERS issuance and BIR transmission retain separate authority checks.
            </p>
          </section>
          <section id="versioning" className="dev-doc-section">
            <h2>Versions, downloads & support</h2>
            <p>
              Contract snapshot: {data?.version || "2026-10-19.receipts-status"}. Application paths
              are currently unversioned; do not add a /v1 prefix. The maintained OpenAPI, endpoint
              catalogue, merchant guide and PDF describe the same deployed contract. Recheck the
              contract after a release before migrating clients. The Postman collection uses empty
              credential variables and never includes saved keys.
            </p>
            <div className="dev-actions">
              <SpecDownload />
              <a className="dev-button secondary" href="mailto:nuersofficial@gmail.com">
                Contact Developer Support
                <ArrowRight size={16} />
              </a>
            </div>
          </section>
        </div>
      </div>
    </DeveloperShell>
  );
}
