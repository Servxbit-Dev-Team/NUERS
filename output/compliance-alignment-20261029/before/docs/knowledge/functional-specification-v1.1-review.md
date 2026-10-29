# Functional specification v1.1: review and recommendations

Reviewed 15 October 2026 against checkout `e799b09b`. Scope: all 18 PDF pages, rendered illustrations, and selected Laravel/React implementation paths. This is a targeted implementation review, not a penetration test or a production certification.

## Source and authority

Document: NUERS-FSD-UI-001, version 1.1, dated 15 October 2026; status: product, engineering, compliance and security review. Original preserved in `sources/NUERS_Detailed_Illustrated_Functional_Specification_v1_1.pdf`.

SHA-256: `7199c97e9c58285576b6be99d8af73900a1813dd437acc9bafb1cd67bd71553c`.

The source explicitly says it is not BIR-issued or BIR-approved. Screenshots are design references; modal descriptions are proposed requirements. This review does not verify the referenced RMC No. 110-2026 against an official circular. A focused official-domain search did not yield an authoritative copy; do not interpret that as evidence the circular does not exist. Exact endpoints, schemas, authorization, deadlines, retention, hosting and marks remain decisions requiring official evidence. Existing configuration values are implementation assumptions, not independent regulatory proof.

## Recommendation

Adopt the document as a design and workflow baseline after resolving the open decisions. Build one complete, authenticated invoice lifecycle before expanding the remaining navigation modules. Prioritize access control, trustworthy document data, durable delivery and reconciliation ahead of presenting readiness percentages or approval claims.

Its strengths are the explicit tenant isolation requirement, immutable issued records, distinction between canonical data and printable receipts, separation of transport success from acceptance, and concrete failure scenarios on page 18. Its main weakness is lack of testable detail: repeated boilerplate states do not define permissions, state transitions, validation formulas, error contracts or measurable service objectives.

## Requirement map and observed gaps

| Area / source pages | Evidence in current checkout | Recommended next step |
| --- | --- | --- |
| Universal interactions, p.2 | Components exist, but screens differ in backend integration | Shared server pagination/filter contracts, keyboard access, error handling and unsaved-change protection |
| Login, p.3 | Email/password and Google flow exist; remember-me stores email; forgot-password opens help | Throttled authentication, secure session lifecycle, real reset flow, privileged MFA and session revocation |
| Dashboard, p.4 | Enterprise dashboard controller and role/tenant tests exist | Verify every metric shares scope and period; distinguish recorded metrics from examples |
| Live monitoring, p.5 | Controller and compliance tests exist | Add linked investigation cases and measure latency against agreed targets |
| Invoices, p.6 | `/invoices` imports `src/pages/invoices/center.tsx`, which contains static sample rows and totals; business invoice API exists separately | Wire the reference design to authorized records and canonical preview data |
| Receipt, pp.7-8 | Rich visual design; PDF picture includes approval badges and an unverified government-domain verification address | Use real record status, controlled branding and a real verification token; preserve immutable source values |
| Transactions, p.9 | Ledger and integration endpoints exist | One authoritative record relationship across invoice, receipt, transaction and transmission |
| Transmission, pp.10,12-13 | Gate explicitly disables production transport; certification acknowledgments are simulated | One canonical transmission service; durable outbox, workers, response ingestion and unknown-outcome reconciliation |
| Reconciliation, p.11 | Some retry/incident records exist | Explicit cases with assignment, evidence, reconciliation runs and reviewed resolution |
| Remaining modules, pp.14-17 | Many pages exist, but presence is not proof of backend functionality; BCDR screen contains fixed statistics and sample sites | Inventory each action as implemented, partial, prototype or unverified; replace unsupported health claims |
| Lifecycle and QA, p.18 | Some authorization, idempotency, audit and deadline tests exist | Add concurrent duplicates, crash recovery, unknown responses, real restore tests and accessibility acceptance |

## Priority findings

### P0: close API authorization gaps

`routes/api.php` exposes `/merchant-accounts` and `/tables/{table}` without route-level authentication. `AuthController::createMerchantAccount` can update an existing user's password and profile role without an actor authorization check in that method. This creates an apparent unauthorized account-change path and should be fixed before public deployment. No write request was executed during review.

`TableController::handle` checks profile writes, but generic reads and several non-profile mutations lack equivalent authentication/role/tenant enforcement. Frontend route guards do not protect API calls. Replace generic public CRUD with explicit policies and tenant-scoped queries. Add anonymous, wrong-role and cross-tenant negative tests for every operation, including list/count/export.

### P0: make invoice calculations and duplicate handling authoritative

`BusinessInvoiceController::totalsFromRequest` accepts caller-supplied totals and line totals. Define the money-unit contract explicitly (the current code converts centavos), recompute totals on the server from permitted inputs, reject inconsistencies, and specify rounding, discounts, VAT, withholding and corrections.

The invoice table has a merchant/invoice-number unique constraint, which is useful, but duplicate-number rejection does not provide a complete idempotency response contract. Integration intake checks an external reference before inserting; the initial transaction migration indexes that reference without making the merchant/reference combination unique. Review all deployed indexes, then enforce atomic uniqueness and request-hash conflict detection across concurrent requests. Return the same result for an identical retry; reject a reused key with changed data.

### P1: turn recorded queue state into durable delivery

`config/bir_compliance.php` explicitly sets `transport_implemented` to false. `EisController` persists transmission/retry state and labels manual certification acknowledgments as simulated. These are useful safeguards, but they do not establish live submission.

Recommended design: commit canonical invoice, audit event and outbox row in one database transaction; workers claim jobs with leases; record each attempt; reconcile ambiguous timeouts before retry; persist authenticated responses and map acceptance separately from HTTP success. A database outbox can bridge the commit-to-dispatch gap; queue after-commit alone does not eliminate every lost-dispatch case. Keep production blocked until the verified protocol and credentials are available.

### P1: treat visual claims as data

On PDF p.7, the illustration states “BIR Accredited,” “BIR VALIDATED,” a sample accreditation number, and `nuers.gov.ph/verify`. This review did not establish those claims or ownership of that address. Use sample labels in reference mode and actual evidence in operational output. A registry lookup, internal validation, transmitted record and accepted authority response must remain distinct states.

The receipt image totals PHP 21,000 and shows VAT-inclusive values; the dashboard image supplied subsequently previews a different PHP 12,450 record. Do not combine those samples into one operational document. Preview and print must use the selected record's own line items and totals.

### P1/P2: extend audit and operations evidence

`BirCompliance` implements a hash chain with a locked head and integrity verification. This is tamper-evident within its trust boundary; a privileged database actor who can rewrite the entire chain remains a separate threat. Add independent checkpoints, restricted writers and retention policy. Expand event coverage to login, configuration, approval, corrections, exports and case resolution, with actor/tenant/correlation identifiers and secret redaction.

BCDR screen values are constants, including sample backup health and recovery test results. Replace them with measured jobs and evidence. Specify approved RPO/RTO and failure model before selecting replication or failover architecture. Demonstrate restore, object readability and single-writer fencing through controlled tests.

## Proposed implementation sequence and exit checks

1. **Security foundation:** close account/table endpoint gaps, centralize policy enforcement, harden login. Exit: anonymous/wrong-role/cross-tenant requests fail without side effects.
2. **Invoices and receipt vertical slice:** match approved visual design, connect scoped records, calculate money server-side, implement idempotency, immutable snapshots and explicit correction relationships. Exit: repeated/concurrent submissions create one invoice; preview/download agree with saved totals; sample data is visibly identified.
3. **Transmission and reconciliation:** outbox worker, attempts, authenticated response processing, unknown-result handling, linked exceptions and alerts. Exit: crash recovery and duplicate prevention demonstrated against a verified certification environment.
4. **Operational evidence:** asynchronous scoped exports, delivery records, audit checkpoints, retention/legal holds and tested restores. Exit: measurable acceptance evidence, including access-negative cases and keyboard tests.
5. **Remaining modules:** implement navigation pages according to signed requirements and actual service data.

Do not estimate calendar completion from screenshots alone. Confirm role matrix, canonical document schema, status transitions, financial rules, expected load, protocol access, and approval owners first.

## Candidate data additions (design proposal only)

Reuse existing invoice/items, receipt, transmission, acknowledgment, retry, incident and audit tables. Do not create duplicates based solely on menu labels. Candidates after schema review: tenant-scoped idempotency requests, leased outbox jobs, transmission attempt evidence, reconciliation runs/cases/actions, invoice correction links/approvals, report/export jobs, receipt delivery attempts, and external audit checkpoints. Add unique tenant/key constraints and explicit tenant/document foreign keys. Migration design depends on verified contracts; none were applied for this review.

## Specification changes recommended for v1.2

Assign requirement IDs and accountable owners; define role/action/tenant matrix; split document lifecycle from transmission and delivery statuses; specify state-transition permissions; define exact fields, money units, rounding, validation and API examples; state concurrency/idempotency behavior; define pagination/export limits and timezones; set accessibility and performance targets; define privacy/redaction/retention rules; identify approved marks and authoritative regulatory sources; select one transmission layout because pp.10,12-13 represent the same module; convert p.18 scenarios to executable acceptance evidence.

## Technical references checked

- [Laravel 12 authentication](https://laravel.com/framework/docs/12.x/authentication): guards, protected routes and authentication throttling.
- [Laravel 12 Sanctum](https://laravel.com/framework/docs/12.x/sanctum): first-party SPA authentication option to assess against existing integrations.
- [Laravel 12 queues](https://laravel.com/framework/docs/12.x/queues): durable queue processing and after-commit dispatch behavior.

These references support implementation choices, not BIR legal approval.

## Review limitations and later work

This is a dated source/code comparison. Existing passing tests do not establish complete PDF conformity or infrastructure resilience. Production was not changed, account takeover was not attempted, and regulatory authenticity was not established. The user's subsequent dashboard and receipt PNGs are explicit visual implementation references; retain the distinction between design fidelity and verified operational claims.

## Follow-up implementation

The subsequent user-authorized design work replaces the static invoice center with a scoped API-backed workspace and a receipt/PDF renderer, and adds a public website with editable advertised subscription plans. See [implementation notes](website-design-and-pricing.md). Findings above describe the reviewed baseline; legacy API authorization and production transport findings remain separate outstanding work.
