# NUERS document alignment audit

**16 October 2026 · Localhost · Asia/Manila**

**Partially aligned. The current implementation is not ready for production acceptance or a claim of complete BIR or ESP compliance.** The updated database and several operational workflows are functional, but critical legacy API access gaps, financial reporting defects and incomplete delivery/security/continuity controls remain.

## Current implementation status

| Check | Verified result |
|---|---|
| Database schema | 73 tables, zero pending migrations |
| Existing financial records | 13 transactions; actual records were not modified by this audit |
| Accreditation record | None recorded in the localhost database |
| Documentary evidence | 0 of 17 Annex A controls verified |
| Mandatory stages | 0 of seven stages verified |
| Production BIR transmission | Blocked; verified transport is not implemented |
| Application audit journal | Hash chain intact within its application/database trust boundary |
| Existing tests | 89 passed, 1,070 assertions |
| Additional diagnostic probes | Five isolated cases, 27 assertions, reproduced the gaps below |

Controls that exist include the 17-item checklist, ordered stage review, separate taxpayer authority gates, original/restoration deadline tracking, bounded retries, real monitoring, structured exports, reviewed registry/permit workflows, registration verification and an application hash chain. These controls are useful implementation evidence; the recorded approval counts and open findings limit production acceptance.

## Findings and required corrections

### A02 Anonymous client account writes and deletion cascade

**Severity:** Critical.

Isolated anonymous PATCH changed dummy account status and balance. Anonymous DELETE removed the dummy client account and its linked users/profiles rows. Both returned HTTP 200.

**Consequence:** Unauthenticated callers can modify account state and erase linked login identity if this route is reachable. No real records were used in these tests.

**Requirement basis:** RMC pp.2,8-11: integrity, authorized access, preservation and confidentiality; Functional v1.1 pp.2-3,18; v2 pp.2-3,12-15: role/tenant scope and controlled changes.

**Code evidence:** [TableController.php line 67](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:67), [TableController.php line 186](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:186).

**Correction:** Retire generic account writes or require authenticated, explicit role/tenant authorization and allowed fields on every operation. Preserve identities and evidence via an approved deactivation workflow; prevent deletion without controlled policy.

**Acceptance check:** Anonymous/wrong-role/cross-tenant PATCH and DELETE fail before any side effect. Admin deletion and account deactivation follow reviewed preservation policy.

### A01 Anonymous generic identity and financial reads

**Severity:** Critical.

Isolated unauthenticated GETs for profiles and merchant_transactions returned HTTP 200 and dummy rows. Merchant special checks and profile write checks do not protect the other generic GET operations.

**Consequence:** Financial and identity records can be exposed without authentication; optional request filters are not tenant authorization.

**Requirement basis:** RMC pp.2,6,9-10: security, confidentiality and authorized data access; Functional v1.1 p.2; v2 pp.2-3 and per-screen access requirements.

**Code evidence:** [TableController.php line 30](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:30), [TableController.php line 76](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:76).

**Correction:** Apply centralized authentication, default-deny role policies, server-derived tenant/branch filters and response-field allowlists to all generic reads; preferably replace these routes with explicit scoped controllers.

**Acceptance check:** Anonymous reads return 401, disallowed roles return 403, and another tenant cannot read records by omitting/changing filters.

### A03 Legacy merchant invoice calculation trusts submitted totals

**Severity:** High.

Pure-method probe supplied quantity 2 x unit price 10000 centavos, but line_total/total_amount 1 centavo. The calculator accepted PHP 0.01 instead of recomputing PHP 200. No invoice was created.

**Consequence:** Once issuance permissions are granted, the legacy path can persist inconsistent amounts. The newer draft-center calculator does not protect this endpoint.

**Requirement basis:** RMC pp.4,8,11: accurate, complete invoice and sales data; Functional v2 pp.6,16; v1.1 p.18: authoritative validation before issuance.

**Code evidence:** [BusinessInvoiceController.php line 149](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:149), [BusinessInvoiceController.php line 449](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:449).

**Correction:** Use one server-side integer-money calculation service with approved tax/discount classifications, explicit rounding, and reconciliation/rejection of supplied totals.

**Acceptance check:** Tampered lines, totals, VAT and discounts cannot change authoritative computed amounts; preview, stored document, API and exports agree.

### A09 Sales summary counts duplicate references and omits standalone issued invoices

**Severity:** High.

An isolated fixture with two successful transaction rows carrying the same invoice identity returned sales PHP 224 while both were flagged duplicate. A separate issued business_invoices row was absent because the report is based on merchant_transactions.

**Consequence:** The current operational sales report cannot establish complete/nonduplicated SLS compliance across all issuance paths.

**Requirement basis:** RMC pp.8-9: non-duplication and automatic SLS; Functional v2 p.10: exclude double counting and apply approved credit/debit/void treatment.

**Code evidence:** [OperationsReportController.php line 18](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:18), [OperationsReportController.php line 97](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:97), [TransactionWorkspaceController.php line 24](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:24).

**Correction:** Define the canonical issued-document reporting ledger, stable document identity, conflict-review handling, complete source coverage and approved adjustment rules; validate the prescribed BIR output format.

**Acceptance check:** Known duplicates/conflicts do not silently inflate approved totals; every in-scope issued document is reported exactly once, with traceable credit/debit/void rules and reconciliation to source.

### A04 Verified BIR transport signing and authoritative responses are absent

**Severity:** Production blocker.

transport_implemented is false and production transmission is blocked. The browser helper called signInvoice produces a SHA-256 digest/checksum, not a private-key digital signature. Integration configuration/local diagnostics do not implement official token exchange or BIR response authentication.

**Consequence:** No verified live BIR submission, acceptance, signing/encryption protocol, token refresh or timeout-result inquiry can be claimed.

**Requirement basis:** RMC pp.3-9: approved end-to-end issuance/transmission and testing; Functional v2 pp.2,8,16-17; v1.1 p.18: authenticated official responses and uncertainty handling.

**Code evidence:** [bir_compliance.php line 9](/Users/marksioson/Desktop/NUERS/config/bir_compliance.php:9), [BirCompliance.php line 126](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:126), [EisController.php line 222](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:222), [invoice-utils.ts line 404](/Users/marksioson/Desktop/NUERS/src/lib/invoice-utils.ts:404).

**Correction:** Obtain verified official interface/schema material and authorized certification credentials; implement a reviewed adapter, true signing/key management, authenticated response mapping and unknown-result inquiry. Keep production blocked until acceptance evidence exists.

**Acceptance check:** BIR-authorized certification tests demonstrate schema, authentication, signing/encryption, acknowledgment, rejection, replay and ambiguous timeout behavior.

### A05 Atomic issuance and durable delivery are incomplete

**Severity:** High.

Invoice issuance and administrative EIS queuing are separate workflows. Retry rows and bounded backoff exist, but no production delivery Job/consumer or scheduled outbox dispatcher was found; runtime queue connection is sync.

**Consequence:** Recorded deadlines do not guarantee automatic delivery/recovery or deadline escalation; cross-boundary crashes and uncertain acknowledgments have no completed acceptance flow.

**Requirement basis:** RMC pp.4,8-9: timely queuing, retries, reconciliation and recovery; Functional v2 pp.2,8,16; v1.1 p.18: atomic issuance/outbox and durable jobs.

**Code evidence:** [BusinessInvoiceController.php line 181](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:181), [IntegrationTransactionController.php line 61](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:61), [EisController.php line 372](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:372), [console.php line 1](/Users/marksioson/Desktop/NUERS/routes/console.php:1).

**Correction:** Persist canonical issuance, audit event and uniquely keyed outbox atomically; add leased durable workers, attempt evidence, inquiry-before-replay, escalation and crash recovery tests.

**Acceptance check:** Crash/replay tests at each commit boundary demonstrate no lost/double documents; verified restoration preserves original deadline and completes authorized recovery.

### A06 Privileged MFA and separation of duties are incomplete

**Severity:** High.

Password/Google identity and same-device token behavior exist. No complete enforced MFA challenge/enrollment/recovery implementation was found. Password login has no dedicated throttle declaration; generic API defaults are not an account-aware login lockout policy. Broad staff roles do not implement the complete operator/auditor/security/branch duty matrix or two-person sensitive approvals.

**Consequence:** Device binding and role checks do not satisfy the specified stronger privileged authentication and separation of duties.

**Requirement basis:** Functional v1.1 pp.2-3,18 and v2 pp.2-3,13,15-16; RMC p.6 and Annex A 14-15: demonstrable identity/access/security controls.

**Code evidence:** [AuthController.php line 30](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:30), [api.php line 59](/Users/marksioson/Desktop/NUERS/routes/api.php:59), [DeviceSession.php line 27](/Users/marksioson/Desktop/NUERS/app/Services/DeviceSession.php:27), [BirCompliance.php line 13](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:13).

**Correction:** Add server-enforced privileged MFA, account/IP throttling, explicit action/tenant/branch policies, independent approver records and an approved bearer/cookie/token lifecycle.

**Acceptance check:** Privileged actions fail without the required second factor and approval; cross-role/branch cases fail; stale/revoked credentials cannot retain access.

### A08 Merchant profile edits bypass reviewed taxpayer identity changes

**Severity:** High.

The owner-scoped account endpoint can directly update merchant TIN, business name, tax/RDO/address fields. It does not invoke the registry revision/review or a complete authority revalidation workflow.

**Consequence:** A reviewed taxpayer record can change through a separate route while existing approval references remain associated with its merchant ID.

**Requirement basis:** RMC pp.4,8: taxpayer identity, authority and traceability; Functional v2 pp.11-12: reviewed identity and readiness.

**Code evidence:** [BusinessAccountController.php line 40](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:40), [BusinessAccountController.php line 69](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:69).

**Correction:** Route material identity edits through pending-change review, canonical duplicate-TIN validation, revisions, before/after audit evidence and re-evaluation of linked authorities.

**Acceptance check:** Material taxpayer identity changes cannot preserve prior production eligibility without the required review; historical financial snapshots remain unchanged.

### A07 Operational screens contain unmeasured assurances

**Severity:** High.

BCDR declares fixed successful backups, recovery results/sites and readiness. SOC/system charts use Math.random. Generic report generation reports success via timers/toasts. Main dashboard health is read from stored health rows, not an active measurement/freshness acceptance check.

**Consequence:** These rendered screens do not establish current backup, DR, security, SLA or compliance performance. The newer real monitor does not make the legacy screens authoritative.

**Requirement basis:** RMC pp.7-9 and Annex A 15-16: demonstrated operational/security/continuity evidence; Functional v2 pp.4,13-16: authoritative telemetry and real workflows.

**Code evidence:** [center.tsx line 13](/Users/marksioson/Desktop/NUERS/src/pages/bcdr/center.tsx:13), [center.tsx line 69](/Users/marksioson/Desktop/NUERS/src/pages/bcdr/center.tsx:69), [dashboard.tsx line 25](/Users/marksioson/Desktop/NUERS/src/pages/soc/dashboard.tsx:25), [admin.tsx line 26](/Users/marksioson/Desktop/NUERS/src/pages/system/admin.tsx:26), [reports.tsx line 76](/Users/marksioson/Desktop/NUERS/src/pages/admin/reports.tsx:76), [compliance.tsx line 25](/Users/marksioson/Desktop/NUERS/src/pages/admin/compliance.tsx:25), [EnterpriseDashboardController.php line 137](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EnterpriseDashboardController.php:137).

**Correction:** Clearly label prototypes/unverified values or replace them with persisted measured telemetry, real report jobs, freshness rules and actual restore/failover evidence.

**Acceptance check:** Every operational assurance links to a current measured job/result/evidence record; failed or absent evidence cannot display verified success.

### A10 Evidence custody legal holds and migration workflows are incomplete

**Severity:** High.

Evidence references, CSV/JSON exports and a hash-chained application journal exist. No complete protected evidence-file repository, enforceable hold lifecycle, approved migration release/deletion flow or regulator request/delivery deadline workflow was demonstrated. Archive examples and local backups are not proof of these controls.

**Consequence:** Reference fields and export buttons alone do not establish protected evidence custody or the complete preservation/access obligations.

**Requirement basis:** RMC pp.9-10 and Annex A 14-17: continued access, complete secure extracts, legal holds and migration conditions; Functional v2 pp.12-16.

**Code evidence:** [EspComplianceController.php line 73](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:73), [LiveMonitoringController.php line 164](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:164), [center.tsx line 30](/Users/marksioson/Desktop/NUERS/src/pages/archive/center.tsx:30).

**Correction:** Implement protected evidence objects/manifests, independent audit checkpoints, enforceable holds, scoped secure export/delivery jobs, request deadlines and controlled migration/retention release conditions.

**Acceptance check:** Holds prevent all relevant deletion paths; complete exports remain available through unpaid fees/exit; authorized delivery and migration confirmation are auditable.

### A11 Capacity residency security assurance and time normalization remain unproven

**Severity:** Unverified acceptance.

Runtime persists many records under Asia/Manila while the audit journal explicitly uses UTC. Operational reports load collections and aggregate them in application memory. Functional tests do not establish independent assessments, data residency, measured load, restore/failover or the historical three-year capacity threshold.

**Consequence:** No enterprise performance or infrastructure compliance acceptance can be concluded from table counts, screenshots or 89 functional tests.

**Requirement basis:** RMC pp.6-9; Annex A 14-17; Functional v2 pp.2,16: UTC persistence, realistic load and failure acceptance.

**Code evidence:** [BirCompliance.php line 155](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:155), [OperationsReportController.php line 17](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:17), [OperationsReportController.php line 116](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:116).

**Correction:** Define one time-persistence contract and conversion plan; test realistic data/query/export volumes; obtain independently verifiable security, residency, three-year track record and BIR-supervised continuity/performance evidence.

**Acceptance check:** Approved load/recovery targets are measured end to end, residency/access arrangements are evidenced, and UTC/PHT boundary tests cover all transactional paths.

## Requirement matrix

| Area | Assessment | Evidence and remaining work |
|---|---|---|
| Database schema | updated | 73 tables; zero pending migrations; 13 existing transactions in read-only inventory. Migration completeness is not document compliance. |
| Annex A and seven mandatory stages | implemented bookkeeping; evidence pending | 17 controls and seven ordered review stages with evidence/revalidation gates. Runtime has zero accreditation records, 0/17 verified documentary controls and 0/7 verified stages. |
| ESP solution/version/configuration validity | partial | Current validity, annual review, renewal lead and material-change blockers exist. Actual BIR-issued certificate and independently verified scope were not established. |
| PTI, PTT, EIS certification and taxpayer authority | partial | Separate issuance/transmission gates and maintained permit status checks; permit snapshots on new approved issuance. Actual authority evidence and full material-identity revalidation are not established. |
| Three-calendar-day deadline and outage recovery | partial | Original deadline preserved; separate incident-linked restoration deadline; bounded retry rules. No completed automatic worker/escalation/production recovery acceptance. |
| Critical incident reporting | partial | Detection, restoration, written report reference and 24-hour reporting due time tracked. Actual RDO/LTS report submission and delivery evidence remain operational requirements. |
| Live monitor and transaction workspace | partial | Real database counters, five-second polling, filtering, pause/resume, paging, trace drawer and exports. Cursor-based resumable delivery and production backlog/deadline telemetry acceptance remain incomplete. |
| Invoice arithmetic and issuance integrity | not aligned across all paths | Authority gates and snapshots exist; isolated A03 proves the legacy arithmetic gap. Canonical recomputation, idempotent issuance/outbox and controlled complete correction lifecycle required. |
| Automatic SLS and sales reports | partial with reproduced correctness defects | Stored-ledger summary/group/CSV workflow exists. A09: duplicates still counted and standalone issued invoice source omitted; official SLS and adjustment rules not accepted. |
| Reconciliation | partial | Persisted exception reviews, reasons/revisions, hash/amount/TIN comparisons and test-vs-production distinctions. No verified BIR inquiry, acceptance response workflow or complete unknown-result resolution. |
| Registration and business registry | partial | Structured address/industry, verification proof, published plan snapshot, reviewed registry and RDO mappings. These are not BIR legal-existence verification; the legacy material identity route remains a bypass. |
| Integration and device configuration | partial | Stored configuration, encrypted new provider secrets, redacted logs and device-session behavior. Provider transport, real signing, signed webhook delivery and complete granular client authorization remain separate. |
| Immutable/time-synchronized audit | partial | Application journal hash chain verifies intact with locked head. No independent immutable custody/checkpoint proof or complete time synchronization acceptance. |
| Access control and privileged authentication | not aligned | New role-scoped endpoints exist. A01/A02 generic routes bypass protection; MFA/duty separation is incomplete. |
| Data ownership, access and portability | partial | Financial row mutation restrictions and structured authenticated extracts exist. Unauthorized account paths, legal holds, secure delivery and migration confirmation still require completion. |
| Manual contingency invoicing | not demonstrated | Operational outage/incident records exist. Recording/uploading/reconciling/linking manual outage invoices is not a completed end-to-end workflow. |
| Backups, restore, failover, security and residency | unverified; some UI prototype claims | Private local SQL backups and prototype screens exist. Actual offsite encryption, restore drills, RTO/RPO, external assurance and approved infrastructure are not established. |
| BIR production transmission | blocked/incomplete | Runtime can_transmit=false; transport_implemented=false; certification ACKs labeled simulated. Requires real approved interface, credentials, authenticated transport/response handling and BIR acceptance testing. |
| Performance and three-year track record | unverified | Configuration records thresholds; functional tests exercise local behavior. The circular historical five-million/one-thousand annual supported-system evidence and supervised load/continuity tests are not established; internal v2 load targets are separate project targets. |
| Public representations | mixed | Registry match is distinguished from cryptographic/BIR verification; monitor shows pending gates. Legacy continuity, SOC, compliance and report screens still display unmeasured assurances. |

## Remediation order

1. Close anonymous generic account/identity/financial endpoints and their mutation/cascade paths.
2. Unify financial calculation and canonical complete/nonduplicated SLS reporting.
3. Enforce taxpayer identity change review, granular authorization, privileged MFA and independent approvals.
4. Complete atomic issuance/outbox, durable workers, official BIR adapter, authentic responses and uncertain-result reconciliation.
5. Replace prototype operational assurances with measured evidence; complete evidence custody, holds, portability and manual contingency workflows.
6. Complete independent security/residency/capacity and BIR-supervised performance/continuity acceptance before production.

## Sources and interpretation

The supplied circular and Annex A establish the baseline for this technical comparison. The accreditation deck supports it. The NUERS specifications define project requirements and expressly identify themselves as internal documents. Official BIR protocol schemas and certification access remain separate dependencies.

- [Supplied RMC circular, 13 pages](</Users/marksioson/Desktop/RMC No. 110-2026_redacted.pdf>).
- [Supplied Annex A, two pages](</Users/marksioson/Desktop/RMC No. 110-2026 ANNEX A.pdf>).
- [ESP accreditation presentation, 34 slides](/Users/marksioson/Downloads/ESP_Accreditation10132026.pptx).
- [NUERS functional specification v1.1, 18 pages](/Users/marksioson/Desktop/NUERS/docs/knowledge/sources/NUERS_Detailed_Illustrated_Functional_Specification_v1_1.pdf).
- [NUERS complete EIS specification v2.0, 17 pages](/Users/marksioson/Desktop/NUERS/docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.pdf).

Annex A has 17 documentary requirements; slides 32–33 omit joint-venture item 8 and renumber later items. The circular and Annex take precedence for this comparison. The circular’s historical annual supported-system capacity requirement is distinct from the internal specification’s proposed load targets.

No matching publication was located by the focused official-domain search during this audit. The audit assesses the supplied baseline; it does not independently authenticate the circular or establish legal accreditation.

## Validation and scope

The existing suite passed, but the additional isolated probes demonstrate missing negative cases. Anonymous identity/financial reads, anonymous account modification/deletion, the invoice calculation mismatch and sales-summary source/duplicate defects were exercised using dummy data in SQLite `:memory:`. The real localhost database was accessed only for aggregate inventory, approval-gate and journal-integrity reads. No real user, financial record or approval was changed.

The reviewed source baseline is commit `12f05be63f2134f0b96b592d713fecf31396d2f7`. This audit added reports and private diagnostic files; it applied no application fixes. Plesk, live infrastructure, external BIR interfaces, independent security attestations and physical disaster-recovery capability were outside the tested runtime. No compliance percentage is assigned because software behavior, legal authority and external evidence have different acceptance conditions.

Verification artifacts: [Structured audit and source hashes](/Users/marksioson/Desktop/NUERS/deployment/document-alignment-audit-2026-10-16.json), [Dummy-data diagnostic results](/Users/marksioson/Desktop/NUERS/.local/document-audit-20261016/probe-results.jsonl), [Read-only runtime inventory](/Users/marksioson/Desktop/NUERS/.local/document-audit-20261016/runtime-status.json).
