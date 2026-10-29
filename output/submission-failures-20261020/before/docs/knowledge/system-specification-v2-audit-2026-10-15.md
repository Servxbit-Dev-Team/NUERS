# NUERS system audit against the complete EIS specification

**Audit date:** 15 October 2026. **Result: partially implemented; not ready for production acceptance.**

## Basis and limits

Reference: the user-supplied 17-page `NUERS_EIS_Complete_System_Documentation.pdf`, internally titled *Comprehensive BIR EIS Integration & Functional Specification, Version 2.0*. Original preserved under `sources/`; SHA-256 `f3b9309d63b314622ee548008c58d4d22c61a024810278828be7b8cdc82af7c6`. All 17 pages were text-extracted and visually reviewed. This internal specification explicitly says its screenshots are mock examples and that it is not BIR approval. This audit assesses implementation against that specification; it does not independently certify regulatory compliance or verify the circular's legal status.

Reviewed local Laravel controllers/services/migrations, React routes and screens, saved project evidence, test outcomes and selected browser workflows. The live Plesk installation, production firewall, storage encryption, operating-system hardening, external BIR interfaces and disaster-recovery sites were not tested. No taxpayer data was transmitted to BIR, credentials disclosed, production acknowledgments generated or live financial records changed for the audit. Security reproductions used an isolated in-memory SQLite database containing dummy data.

**Important:** a route, UI card, database column, green badge or passing local test alone does not satisfy a complete requirement. “Partial” means useful controls exist but the complete workflow/evidence is absent. No compliance percentage is assigned because requirements have different acceptance conditions and severity.

## Highest-priority findings

### F01 — Critical: anonymous generic reads bypass financial and identity access controls

**Requirement:** pp.2–3, and every screen's access/QA paragraph: authenticated role and tenant/branch isolation on every request.

**Evidence:** `app/Http/Controllers/Api/TableController.php`, `handle()` and `index()`; `routes/api.php` generic `/tables/{table}` route. Merchant reads and profile writes receive special checks, but GETs for profiles, client accounts, merchant transactions, transaction receipts and notifications have no equivalent mandatory authentication/ownership predicate. Client-supplied filters are optional and do not establish authorization. Generic nonfinancial client-account/notification mutations also lack a common deny-by-default actor policy.

**Isolated reproduction:** anonymous GET `/api/tables/profiles` returned HTTP 200 with one dummy profile; anonymous GET `/api/tables/merchant_transactions` returned HTTP 200 with one dummy financial row. Anonymous receipt GET also returned HTTP 200 (empty test table). Proof is `.local/eis-system-audit/isolated-findings.jsonl`; no real taxpayer records were used or printed. Dedicated newer admin endpoints passing permission tests do not close this older route.

**Recommendation:** first enforce authentication and server-selected tenant/role scope on every generic operation; preferably retire generic financial/profile access in favor of explicit controllers. Review client-account/notification mutation authorization. Add anonymous and cross-tenant negative tests for every route. This finding remains open; the audit did not silently rewrite all legacy APIs.

### F02 — High: the older merchant invoice path trusts client totals

**Requirement:** pp.6 and 16: authoritative line/tax computation and validated immutable issuance.

**Evidence:** `BusinessInvoiceController::store()` accepts supplied `line_total`, and `totalsFromRequest()` accepts supplied subtotal, VAT, total and other amounts. It does not independently recompute quantity × unit price and compare all tax/discount totals. The newer invoice-center draft path recomputes its limited supported calculation, but that does not protect the older merchant endpoint.

**Pure-method probe:** quantity 2 × unit price 10,000 centavos implies 20,000 centavos; supplied line/total of one centavo produced a total of PHP 0.01. No invoice was created. Production issuance currently remains gated, but the arithmetic path must be corrected before that gate opens.

**Recommendation:** one server-side calculation service with integer money/defined rounding, per-line tax classifications, explicit supported discounts and reconciliation of every total. Verify legal tax treatments and correction types against authoritative rules rather than the screenshots.

### F03 — High / production blocker: no verified BIR adapter or authoritative acceptance processing

**Requirement:** pp.2,8,16–17: official schema/interface, transport, response verification, unknown-result inquiry and BIR-supervised acceptance.

**Evidence:** `config/bir_compliance.php` sets `transport_implemented=false`; `BirCompliance::gate()` blocks production; `EisController::acknowledge()` permits explicitly synthetic certification acknowledgments only. The new integration workspace performs local diagnostics, not a BIR token exchange. Official endpoint/schema and provisioned sandbox credentials remain external dependencies.

**Assessment:** blocking production is correct. It is not completion of live submission, token refresh, signing/encryption, response authentication, uncertain-result inquiry or webhook delivery. New configuration tables do not enable these functions.

**Recommendation:** obtain approved interface material and authorized certification access, implement and test the adapter, retain redacted raw authority responses and prohibit treating HTTP 200/202 alone as BIR acceptance.

### F04 — High: issuance-to-delivery outbox and complete lifecycle are incomplete

**Requirement:** pp.2,8,16: atomic invoice + outbox persistence, durable consumers, stable source identity, no lost/double deliveries, Uncertain → inquiry before retry.

**Evidence:** merchant invoice creation and EIS queue creation are separate controller workflows. `EisController` atomically persists its own payload/transmission/retry intent and enforces document identity and payload hashes; bounded exponential retry and preserved original/recovery deadlines exist. No delivery job/consumer implementing the complete production lifecycle or atomic issued-document-to-outbox linkage was found. The original merchant issuance path uses a generated invoice ID and manual invoice-number checks rather than a consistent source idempotency contract across issuance and delivery.

**Recommendation:** transactional issuance outbox with tenant/branch/source/document keys, leased durable jobs, attempt records, unknown-result inquiry, deadline alerts, and crash/replay tests covering every commit boundary.

### F05 — High: privileged MFA, granular duties and dual approval are missing

**Requirement:** pp.2–3,13,15–16: privileged MFA, least privilege, branch authorization and two-person sensitive override approval.

**Evidence:** login is password/Google identity with bearer sessions; MFA flags and UI claims exist, but no complete challenge/enrollment/recovery/enforcement flow was found. The password login route also has no dedicated throttle declaration. Many new mutations distinguish admin/super-admin from BIR/read-only, but broad administrative roles are not the specification's complete compliance/transmission/auditor/security permission model. A second independent approver is not required for all sensitive operations.

**Session fix completed during this work:** same-browser device preserves a valid token, another device replaces it, invalid sessions redirect to Login, and network/403/stale-response conditions do not destroy a valid replacement token. This is not MFA. Browser localStorage and plaintext bearer storage in the users table remain hardening items.

**Recommendation:** enforce privileged MFA server-side, explicit permission/branch policies, independent approval records and restricted service identities. Migrate bearer persistence and browser session handling to an approved token/cookie design with a considered lifetime/revocation policy.

### F06 — High: operational prototype screens display unmeasured assurances

**Requirement:** pp.4,13–16: authoritative counters, measured infrastructure/DR/security evidence and real workflows.

**Evidence:** `src/pages/soc/dashboard.tsx` generates threat data with `Math.random()`; `src/pages/system/admin.tsx` generates CPU/memory charts; `src/pages/bcdr/center.tsx` contains hard-coded backup success, DR sites and recovery-test results. `src/pages/archive/center.tsx` declares fixed storage/integrity/legal-hold examples, while `src/pages/admin/reports.tsx` simulates generation/downloads with success toasts. `src/pages/admin/compliance.tsx` has hard-coded compliance figures. These screens are routed from the admin navigation. The newly implemented operations and integration pages use actual records and explicit unverified states, but older pages still need conversion.

**Recommendation:** label prototype data explicitly or replace it with measured persisted telemetry and functional workflows. Do not present sample security/DR/retention claims as verified production evidence.

### F07 — High: merchant identity edits bypass the new registry review contract

**Requirement:** pp.11–12: reviewed taxpayer identity/readiness, controlled changes, immutable audit evidence and concurrency.

**Evidence:** `BusinessAccountController::update()` allows merchant-side TIN, name, tax status, RDO and address edits directly in `merchants`. It does not use `registry_revision`, canonical duplicate-TIN validation or automatic re-evaluation of identity evidence. It records a different audit event with updated field names, not the registry's complete before/after review state. Server-side ownership checks exist, so this is not the same defect as F01.

**Recommendation:** route material identity edits through a pending-change/review workflow, with canonical TIN checks, optimistic revisions, preserved financial snapshots and revalidation of authorizations. Existing linked-account credential changes must remain separate.

### F08 — High: retention, evidence custody, backups and portability are incomplete

**Requirement:** pp.12–16: evidence files, legal holds, controlled migration/deletion, secure delivery, tested encrypted backups and RTO/RPO.

**Evidence:** evidence references and audit hashes exist; the new onboarding/PTI workflows do not yet provide a protected document upload/review repository. CSV/JSON exports exist, but legal holds, request approvals/deadlines, controlled migration confirmation and verified deletion are not end-to-end workflows. Local backup files/scripts are not proof of encrypted offsite backups or successful recovery/failover. The archive/BCDR screens contain examples.

**Recommendation:** protected object storage and immutable evidence manifests; tenant-scoped export jobs with approval/delivery audit; enforceable holds and exit/migration release conditions; actual restore/failover drills and independent measurements.

### F09 — Medium: capacity and time-normalization acceptance are unproven

**Requirement:** pp.2,16: UTC record timestamps with PHT display; 1M/hour sustained, 2,000 TPS bursts and node-loss acceptance cases.

**Evidence:** the application uses configurable server timezone and many `now()` writes; the audit journal explicitly writes UTC, while consistent UTC normalization is not enforced across all transactional tables. Registry/permit reporting includes collection-based aggregation and per-record queries that require scale assessment. No load, quorum-safe DB promotion, storage-node failure or restore evidence was produced by the functional tests.

**Recommendation:** one UTC persistence contract and timestamp migration strategy; measured end-to-end load tests, realistic record volumes, query plans, asynchronous exports, recovery drills and the independent DB quorum/fencing specified on p.16.

## Screen-by-screen traceability

| Specification | Screen / control | Assessment and implementation evidence | Remaining acceptance work |
|---|---|---|---|
| p.4 | Dashboard | Partial — real counters and tenant-scoped overview in `EnterpriseDashboardController`; links and refresh exist. | Complete date/tenant drilldowns and alert drawers; distinguish old seeded health checks from live measurements. |
| p.5 | Live Transaction Monitor | Partial — real five-second polling, pause/resume, filters, drawer and exports in `src/pages/monitoring/transmissions.tsx` / `LiveMonitoringController`. | Required cursor-based resumable stream and telemetry/deadline alert acceptance; polling is not the specified cursor stream. |
| pp.6,16 | Electronic Invoices & Receipts | Partial — center, preview/PDF, draft validation, permission gates and immutable financial-route restrictions. | F02, complete legally approved tax/type/numbering rules, controlled adjustment workflow and production issuer acceptance. |
| p.7 | Transactions | Partial — source-backed search/filters/details/exports with payment/BIR evidence separated. | Complete canonical event timeline and linked authoritative outbox/attempt/ack chain. |
| pp.8,16 | BIR Transmission Center | Partial / live blocked — duplicate identity/hash, retry queue, backoff, deadlines and synthetic certification boundary. | F03–F04, batch approvals, durable consumers, verified responses and timeout inquiry. |
| p.9 | Reconciliation & Exceptions | Partial — hash/payload comparison, evidence/reason and revision-checked review; no false BIR acceptance on manual resolution. | Reviewer assignment, complete canonical comparisons across correction/duplicate/missing cases and automated authority inquiry. |
| p.10 | Summary List of Sales | Partial — real summaries, supported grouping/filters and CSV. | Accounting-approved credit/debit/void/tax treatment and prescribed report conformance; a dashboard CSV is not a certified filing format. |
| p.11 | Registered Businesses | Partial — real registry, identity/status separation, revisions, import preview, profile/review/invitation modals. | F07, full branch administration, complete jurisdiction coverage and protected evidence custody. |
| p.12 | Taxpayer Onboarding | Partial — persisted stages, five evidence checks, reason/evidence review, controlled activation and invitations. | File repository, request-changes notifications, independently verified identity/integration tests and production signoff. |
| p.12 §6 | PTI / PTT Management | Partial — taxpayer authority registry, validity, evidence references, reviews/revocation, constrained device registration and CSV workflows. | Official verification, evidence upload, reminder notifications and a complete production authority linkage; do not infer BIR permission from local status. |
| p.13 | API Integrations | Partial — encrypted/masked provider credentials, revisions, endpoint/webhook config, redacted request logs and local diagnostics. | Broader POS/ERP policy acceptance tests, provider token exchange, signature verification, callback tests, signed webhook delivery and worker retries. Merchant ingestion already implements transactions:write scope, exact-IP checks and daily quotas (confirmed in the developer-portal follow-up). |
| p.13 | ESP Accreditation | Partial — persistent controls/stage gates, certificate/version/configuration evidence, annual review/renewal/material-change gates in `EspComplianceController` / `BirCompliance`. | External evidence verification and BIR-supervised approval; an admin record cannot issue accreditation. |
| p.13 | Compliance Management | Prototype screen plus partial underlying controls — real ESP controls exist separately. | Replace fixed compliance figures with owned/deadlined findings, corrective action and approvals. |
| p.13 | Security & Incidents | Partial backend / prototype dashboard — incident records, restoration/report references and critical 24-hour due calculation exist. | Real threat telemetry, notification/report delivery evidence, access review/VAPT and controlled closure. |
| pp.13–14 | Immutable Audit Logs | Partial — hash-linked journal, integrity verification, search/pagination/export; no edit/delete UI. | Complete event coverage, independent immutable custody/time sync, legal holds and export approvals. A hash chain alone is not a WORM guarantee. |
| p.14 | Data Governance & Portability | Prototype archive screen; partial functional extracts elsewhere. | F08: holds, lawful deletion, machine-readable exit job, delivery and migration confirmation. |
| p.14 | Analytics & Reports | Prototype report catalogue/scheduling; actual operations summaries exist separately. | Persisted generation/scheduling/delivery jobs, reconciliation and authoritative analytics. |
| p.14 | BIR Reports & Exports | Partial — sidebar currently leads to sales summaries/CSV. | Prescribed formats, approval, secure delivery and report-response deadline evidence. |
| p.14 | Infrastructure Health | Prototype dashboard; some recorded service-health data elsewhere. | Measured web/DB/storage/queue health, alert rules and maintenance approval. |
| pp.14–15 | Backup & Disaster Recovery | Prototype BCDR screen; local setup/backup material exists. | Verified encrypted/offsite backups, restore requests, drills, failover/fencing and actual RTO/RPO. |
| p.15 | Configurations & Changes | Prototype general settings plus revisioned integration/plan settings and accreditation configuration gates. | Material-change requests, risk/independent approval, versioned rollout/rollback and complete schema/template control. |
| p.15 | Users & Permissions | Partial — profile administration, merchant invitations, role checks and improved device sessions. | F01/F05, branch grants, privileged MFA, safe reset/access review and privilege-elevation approval. |
| p.15 | Client Support & SLA | Partial — real persisted chat/inbox, assignment, priority, unread/status and message history. | SLA clocks/escalation, outage announcements, approved communications and delivery evidence. |

## Production acceptance gates from p.16

| Gate | Assessment |
|---|---|
| A — ESP eligibility and accreditation path | Controls implemented; documentary/authority acceptance remains unverified. |
| B — taxpayer PTI/PTT and EIS requirements | Separate gates and local evidence register exist; actual taxpayer approval must be externally substantiated. |
| C — official interface and conformance | Blocked; official interface material and a verified adapter are not implemented in the local system. |
| D — accounting/tax/numbering | Not accepted; F02 and complete tax/correction/sequence validation remain. |
| E — load/security/failover/restore/privacy/interoperability | Not demonstrated; functional unit/feature tests do not prove these acceptance cases. |
| F — BIR-supervised tests and approvals | Not evidenced. No live accreditation or production approval is asserted. |

## Test and evidence record

- Latest complete Laravel run: **81 tests passed, 943 assertions** (`php artisan test`).
- Session client tests: **eight scenarios passed**, `node scripts/test-session-client.mjs`.
- Production frontend build: passed (`npm run build`); existing large-chunk and three runtime-asset-resolution warnings remain.
- PTI/API pages checked at reference desktop dimensions and 390px mobile; no document horizontal overflow. Permit modal fit mobile. Actual integration metadata, certification request and local diagnostics were inspected; no secrets were entered through the browser.
- Registry/onboarding and RDO directory checked with real local data; Caloocan/Barangay 179 auto-selection is RDO 027. Coverage is 105 whole-city + five barangay rules, not the entire country.
- Security and arithmetic reproductions used isolated dummy data and a pure calculation probe. No live records were modified by those probes.
- Source PDF visual contact sheets: `.local/eis-system-audit/contact-{1,2,3}.jpg`; searchable text preserved under `sources/`.

The passing suite does **not** disprove the findings: current tests exercise defined workflows and lack complete endpoint-wide access, accounting, production transport and infrastructure acceptance coverage.

## Recommended work order

1. Close F01 first: generic route authentication, ownership and mutation policies, then negative tests.
2. Centralize server arithmetic/numbering and reviewed taxpayer identity changes (F02/F07); implement privileged MFA and independent approvals (F05).
3. Build the atomic issuance outbox/durable lifecycle and official certification adapter (F03/F04), with strict sandbox/production separation.
4. Replace unmeasured dashboards, add protected evidence/retention/portability and genuine operational alerts (F06/F08).
5. Establish UTC consistency and run the p.16 load, failure, restore and exit acceptance suite (F09); collect authority-supervised signoffs before enabling production.

The document's open items on p.17 remain decisions/dependencies, not implementation facts. Preserve the production block until the relevant evidence exists.
