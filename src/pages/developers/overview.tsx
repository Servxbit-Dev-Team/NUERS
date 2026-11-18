import { Link } from "react-router-dom";
import {
  ArrowRight,
  BookOpen,
  FlaskConical,
  ShieldCheck,
  KeyRound,
  FileJson,
  Workflow,
  CheckCircle2,
  Database,
  Radio,
  Building2,
  Clock3,
} from "lucide-react";
import { DeveloperShell, CodeBlock, SpecDownload } from "./shared";
const steps = [
  [
    "01",
    "Confirm the business boundary",
    "Complete merchant registration and account verification. The verified owner can generate a named API key; NUERS prepares the default owned-system connection automatically. Copy the registered location and source identifiers from API Integration.",
  ],
  [
    "02",
    "Choose the correct credential",
    "User sessions authorize workspace and administrative routes. Merchant integration API keys authorize intake, batch, status and wallet endpoints according to scope and environment. BIR provider credentials are a third credential class and remain separate.",
  ],
  [
    "03",
    "Validate synthetic requests",
    "Use the isolated sandbox to check transaction field rules and calculation output. Use TEST- source references and synthetic data. The public preview never calls the financial ingestion endpoint.",
  ],
  [
    "04",
    "Plan replay and reconciliation",
    "Persist each source reference before sending. Reuse a reference only for the same business transaction. Understand that ingestion deduplication and EIS payload-identity deduplication have different contracts.",
  ],
  [
    "05",
    "Complete the production gates",
    "Use source_capture for original documents already issued by your owned system. New NUERS issuance and BIR reporting retain separate authority requirements. Test mapping, wallet funding, retries, customer receipts and receiver signatures before launch.",
  ],
  [
    "06",
    "Operate with evidence",
    "Keep request IDs, source references, redacted status/latency records and audit evidence. Record uncertainty before retry, preserve original deadlines and coordinate credential replacement.",
  ],
];
export function IntegrationOverview() {
  return (
    <DeveloperShell>
      <section className="dev-hero">
        <div className="dev-container">
          <span className="dev-eyebrow">NUERS DEVELOPER PLATFORM</span>
          <h1>
            Connect your business.
            <br />
            <em>Keep every record traceable.</em>
          </h1>
          <p>
            A practical integration guide for POS, ERP, e-commerce and internal business
            applications: authentication, request contracts, synthetic testing, readiness and
            operational handover.
          </p>
          <div className="dev-actions">
            <Link className="dev-button" to="/api/docs">
              <BookOpen size={18} />
              Read the API Reference
            </Link>
            <Link className="dev-button secondary" to="/api/sandbox">
              <FlaskConical size={18} />
              Open the Sandbox
            </Link>
          </div>
          <div className="dev-hero-facts">
            <span>
              <CheckCircle2 size={16} />
              Implemented routes documented
            </span>
            <span>
              <ShieldCheck size={16} />
              Synthetic, isolated preview
            </span>
            <span>
              <Radio size={16} />
              Live BIR transport pending
            </span>
          </div>
        </div>
      </section>
      <div className="dev-container dev-overview">
        <section className="dev-section">
          <div className="dev-section-title">
            <div>
              <span className="dev-eyebrow">DESIGN THE CONNECTION</span>
              <h2>One workflow. Clear system boundaries.</h2>
            </div>
            <p>
              NUERS application APIs manage internal business records. The official BIR EIS
              interface has its own authority, schema and certification requirements.
            </p>
          </div>
          <div className="dev-flow">
            {[
              [Building2, "Your POS / ERP", "Stable source identity"],
              [KeyRound, "NUERS authentication", "Tenant, scope and IP checks"],
              [FileJson, "Record validation", "Field and authority gates"],
              [Database, "Document & queue evidence", "Separate recorded states"],
              [Radio, "BIR adapter", "Requires verified implementation"],
            ].map(([Icon, t, d]) => {
              const I = Icon as typeof Building2;
              return (
                <article key={String(t)}>
                  <I />
                  <h3>{String(t)}</h3>
                  <p>{String(d)}</p>
                </article>
              );
            })}
          </div>
          <p className="dev-note">
            Source → authenticated tenant API → validation → atomic record and wallet acceptance →
            NUERS acknowledgement → connector status. Customer PDF email and signed callback intents
            are queued after acceptance. BIR reporting has separate readiness controls.
          </p>
        </section>
        <section className="dev-section">
          <div className="dev-section-title">
            <div>
              <span className="dev-eyebrow">FROM FIRST REQUEST TO HANDOVER</span>
              <h2>Your implementation checklist.</h2>
            </div>
            <p>
              Use the same checklist with your developer, business administrator and compliance
              reviewer.
            </p>
          </div>
          <div className="dev-step-grid">
            {steps.map(([n, h, p]) => (
              <article className="dev-card" key={n}>
                <b className="dev-step">{n}</b>
                <h3>{h}</h3>
                <p>{p}</p>
              </article>
            ))}
          </div>
        </section>
        <section className="dev-section dev-two">
          <div>
            <span className="dev-eyebrow">IDENTITY & ACCESS</span>
            <h2>
              Three credentials.
              <br />
              Three different purposes.
            </h2>
            <p>
              Keep secrets on the server, in approved secret storage. Never put merchant or provider
              credentials in browser code, screenshots, source control or test payloads.
            </p>
            <Link className="dev-text-link" to="/api/docs#authentication">
              Read authentication guidance
              <ArrowRight size={16} />
            </Link>
          </div>
          <div className="dev-credential-list">
            <article>
              <KeyRound />
              <div>
                <h3>Merchant API key</h3>
                <p>
                  <code>X-NUERS-API-Key</code> or Bearer on transaction ingestion. The server
                  resolves the merchant from the key, enforces <code>transactions:write</code>,
                  exact source-IP policy and daily quota.
                </p>
              </div>
            </article>
            <article>
              <ShieldCheck />
              <div>
                <h3>NUERS user session</h3>
                <p>
                  Used by business workspaces and authorized administrative endpoints. A
                  user-session token is not a merchant ingestion key. Device-session replacement and
                  logout rules apply.
                </p>
              </div>
            </article>
            <article>
              <Radio />
              <div>
                <h3>BIR provider credential</h3>
                <p>
                  Issued through the authorized BIR process. Storing a provider secret or
                  certificate fingerprint does not create a live connection or prove accreditation.
                </p>
              </div>
            </article>
          </div>
        </section>
        <section className="dev-section">
          <div className="dev-section-title">
            <div>
              <span className="dev-eyebrow">IMPLEMENTED CONTRACT</span>
              <h2>Start with a synthetic preview.</h2>
            </div>
            <p>
              The sandbox validates the shared transaction field rules and calculates a response
              preview. Actual issuance remains gated and is never invoked from the public tester.
            </p>
          </div>
          <CodeBlock
            label="Public sandbox · no credentials"
            text={
              'curl -X POST "$NUERS_BASE_URL/api/developer/sandbox" \\\n  -H "Content-Type: application/json" \\\n  -H "Accept: application/json" \\\n  -d \'{"is_test":true,"action":"preview","payload":{"external_reference":"TEST-POS-000001","amount":1120,"tax_type":"vatable","vat_inclusive":true,"seller_vat_registered":true}}\''
            }
          />
          <div className="dev-actions">
            <Link className="dev-button" to="/api/sandbox">
              Try This Request
              <ArrowRight size={16} />
            </Link>
            <SpecDownload />
          </div>
        </section>
        <section className="dev-section">
          <div className="dev-section-title">
            <div>
              <span className="dev-eyebrow">RELIABILITY CONTRACT</span>
              <h2>Plan for failures before go-live.</h2>
            </div>
          </div>
          <div className="dev-step-grid">
            <article className="dev-card">
              <Workflow />
              <h3>Replay & idempotency</h3>
              <p>
                Preserve the original source event and payload. Canonical replay checks return the
                original IDs for an exact accepted retry; conflicting content returns 409. POST
                /api/integrations/transactions/batch accepts 1–50 events atomically. Keep Live and
                Sandbox outboxes separate.
              </p>
            </article>
            <article className="dev-card">
              <Clock3 />
              <h3>Timeouts & deadlines</h3>
              <p>
                A timeout is not evidence of rejection. Reconcile an uncertain outcome before
                replay. Preserve original transaction deadlines and record outage/recovery evidence;
                do not reset a deadline with every retry.
              </p>
            </article>
            <article className="dev-card">
              <ShieldCheck />
              <h3>Key replacement</h3>
              <p>
                Create a replacement key, update authorized consumers, verify the switch, then
                revoke the old key. There is no atomic rotate flag. Exact-IP allowlists do not
                interpret CIDR ranges.
              </p>
            </article>
          </div>
        </section>
        <section className="dev-section">
          <div className="dev-section-title">
            <div>
              <span className="dev-eyebrow">CAPABILITY STATUS</span>
              <h2>Know what you can test today.</h2>
            </div>
          </div>
          <div className="dev-table-scroll">
            <table>
              <thead>
                <tr>
                  <th>Capability</th>
                  <th>Current behavior</th>
                  <th>Production prerequisite</th>
                </tr>
              </thead>
              <tbody>
                {[
                  [
                    "Transaction field preview",
                    "Available · no records persisted",
                    "No credential needed; synthetic data only",
                  ],
                  [
                    "Merchant transaction ingestion",
                    "Atomic acceptance, replay and status",
                    "Verified active merchant, scoped key, valid source context and wallet funding",
                  ],
                  [
                    "Internal EIS validation / queue",
                    "Administrative, certification-labelled",
                    "Authorized administrative session; explicit test payload for certification",
                  ],
                  [
                    "Official BIR delivery / token exchange",
                    "Pending verified adapter",
                    "Official interface, credentials, conformance and approvals",
                  ],
                  [
                    "Signed webhook delivery",
                    "Owner-configured delivery with bounded retries",
                    "Verified HTTPS receiver, enabled environment subscription and signature/replay validation",
                  ],
                  [
                    "Bulk transaction submission",
                    "Atomic batches of 1–50 complete events",
                    "One registered location and sufficient aggregate Live funding",
                  ],
                  [
                    "Customer e-receipts",
                    "Automatic branded PDF for an accepted Live event with customer_email",
                    "Valid mapped checkout email; Sandbox does not send mail",
                  ],
                ].map((r) => (
                  <tr key={r[0]}>
                    {r.map((c) => (
                      <td key={c}>{c}</td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </section>
        <section className="dev-callout">
          <div>
            <h2>Prepare a reviewable integration package.</h2>
            <p>
              Include your taxpayer/branch scope, sequence and tax rules, request samples,
              replay/error tests, credential custody, retention/export plan, monitoring, rollback
              and support owners. Complete official certification before live BIR operations.
            </p>
          </div>
          <Link className="dev-button" to="/api/docs">
            Explore the Reference
            <ArrowRight size={16} />
          </Link>
        </section>
      </div>
    </DeveloperShell>
  );
}
