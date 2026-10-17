> **Approved local remediation follow-up — 2026-10-16T23:12:43+08:00.** The original read-only findings below are historical. Source code, dependencies and localhost schema were subsequently changed under the user’s “Please proceed fix all” authorization. Read [NUERS_REMEDIATION_REAUDIT_2026-10-16.md](./NUERS_REMEDIATION_REAUDIT_2026-10-16.md) and the matrix’s `current_*` columns for the current assessment. **BIR production readiness remains NOT READY.**

# NUERS EIS workflow audit report

Audit date: **16 October 2026 (Asia/Manila)**. Compiled 2026-10-16T22:11:55+08:00.

Target: local working tree `/Users/marksioson/Desktop/NUERS`, branch `main`, HEAD `12f05be63f2134f0b96b592d713fecf31396d2f7`. This checkout has substantial pre-existing modified/untracked application files. Findings apply to the inspected working tree, **not** clean HEAD and **not** an independently inspected production deployment. Laravel lockfile and installed vendor metadata: **v12.58.0**; PHP requirement `^8.2`; React/TypeScript frontend.

Method: source and migration inspection, navigation/form/route mapping, redacted local configuration inspection, existing artifact/log review and official-source browsing. **No application/security/load tests, API requests, database queries, scans, jobs, BIR calls or deployments were executed in this audit.** Existing tests are evidence of available scenarios, not freshly executed verification. Only these seven audit deliverables are added under `docs/audits/`; application/configuration/production data remain unchanged.

## Executive verdict

**NOT READY. Observed flow does not match the target end-to-end lifecycle.** Local arithmetic, canonical reporting, separate permit tracking and atomic internal preparation are implemented, but critical authorization gaps and absent delivery/recovery infrastructure prevent controlled pre-production readiness. Sandbox/API/documentary dependencies must remain pending.

**54 controls:** PASS: 2; PARTIAL: 27; FAIL: 13; NOT FOUND: 6; NOT VERIFIED: 6; NOT APPLICABLE: 0. These statuses include all G/T/I/Q/B/R/S/A/D/C/P/M/H/SEC IDs in the brief. PASS is narrowly source-backed and does not certify deployment/regulatory compliance. Matrix severity expresses the risk of the broader control gap; individual findings may assign a narrower confirmed issue a different severity. NOT FOUND means a required implementation was not located; NOT VERIFIED means external/runtime/performance evidence is unavailable. No control was marked NOT APPLICABLE merely to improve the result.

The earlier 114-test pass is a historical local regression result, not a security certification or an exhaustive audit. This wider read-only inspection identifies legacy routes and boundary cases outside those tests. It does not retract the specific improvements already made.

## Deliverables

- [NUERS_EIS_REQUIREMENTS_MATRIX.csv](NUERS_EIS_REQUIREMENTS_MATRIX.csv)
- [NUERS_CURRENT_VS_TARGET_FLOW.md](NUERS_CURRENT_VS_TARGET_FLOW.md)
- [NUERS_GAP_REMEDIATION_PLAN.md](NUERS_GAP_REMEDIATION_PLAN.md)
- [NUERS_TEST_PLAN.md](NUERS_TEST_PLAN.md)
- [NUERS_REGULATORY_DEPENDENCIES.md](NUERS_REGULATORY_DEPENDENCIES.md)
- [NUERS_MERCHANT_INGESTION_SECURITY_CAPACITY_AUDIT.md](NUERS_MERCHANT_INGESTION_SECURITY_CAPACITY_AUDIT.md)

## Top ten priority findings

| Priority | Finding | Severity | Controls |
|---|---|---|---|
| 1 | F01 - Anonymous RDO provisioning can overwrite existing account credentials | Critical | SEC03/SEC04/C01 |
| 2 | F02 - Legacy financial reads and expense writes remain unguarded | Critical | SEC03/S02/C01 |
| 3 | F03 - Self-asserted identity enters taxpayer authorization decisions | Critical | T01/SEC02/SEC03 |
| 4 | F04 - Merchant sandbox, branch and current authority are not enforced together | High | I01/T03/M03 |
| 5 | F05 - The end-to-end BIR delivery lifecycle stops at local preparation | Critical | Q02/B01/B03/B04/H05 |
| 6 | F06 - Source timestamps, idempotency namespace and sequence do not meet the intake target | High | I02/I04/M01/M02/M05/M06 |
| 7 | F07 - Validation and VAT presentation have cross-field gaps | High | I03/SEC02 |
| 8 | F08 - Human sessions and privileged actions lack complete lifecycle security | High | SEC04/SEC09/C01 |
| 9 | F09 - High-traffic, DDoS and infrastructure resilience remain unproved | High | P01/H01/H02/H03/SEC08/C02 |
| 10 | F10 - Cancellation, evidence custody and operational assurance still include UI-only claims | High | I05/D02/C03/P02 |

## Evidence confidence and exclusions

- Current source: 35 controller PHP files including the base/Auth controllers; 12 services; 41 migrations; 13 Feature test files plus one Unit test file; 134 literal API route declarations (Route::match expands to multiple HTTP methods), six literal web route declarations plus framework `/up`, and the preparation scheduler. No custom Jobs, Events, Policies or Http/Middleware directories or checked-in CI workflow were found. Laravel defaults still apply; absence of a custom directory alone is not absence of framework protection.
- No application bootstrap was executed. No current DB introspection, EXPLAIN, health request, browser action or production/Plesk session was used, since some GET handlers write audit/verification/cache state. Runtime schema and provider configuration are therefore not freshly verified.
- Existing `.local/eis-readiness-fix-20261016/reaudit.json` records 21:57:43 PHT, 76 tables, zero pending migrations, 16 canonical sources, 13 original transactions and PHP750,400.00; all16 Not Submitted, zero verified branch/coverage/accreditation records, three inconsistent legacy sources. These are prior local observations, not new queries. Counts overlap where sources are supplementary. No PII rows or secrets were copied into reports.
- Existing `full-tests.log` records 114 tests/1,290 assertions in an earlier isolated run; `phpunit.xml` configures SQLite memory and sync queues. This cannot demonstrate MySQL concurrent uniqueness, real broker crashes, RTO/RPO or high-load capacity. New adversarial/security tests remain proposals.
- Only nonsecret local `.env` settings were inspected: local/debug; MySQL; file cache/session; sync queue; non-secure localhost cookie. Those are development settings, not evidence that production uses them. `.env` permissions were600. No secret values were printed or copied.
- No infrastructure inventory, managed WAF/DDoS contract, firewall rules, origin ACLs, TLS measurements, shared-session/broker topology, DB quorum/witness/fencing, object quorum, backup-key custody, restore drills, official API package or independent assurance reports were supplied.
- All network requests in this audit were read-only retrieval/search of public official reference sites. No traffic was directed to NUERS/BIR API endpoints for functional or security testing.

## Source-confirmed findings

### F01 - Anonymous RDO provisioning can overwrite existing account credentials

**Critical; SEC03/SEC04/C01.**

The reachable POST declaration has no auth middleware. store() validates business fields, then looks up users by supplied email and updates the password/name; it also upserts a role. No authentication/authorization call precedes these writes. This is a source-confirmed unauthorized credential-change path. Network exposure on production was not tested.

**Inspection evidence:** [routes/api.php:134-136](/Users/marksioson/Desktop/NUERS/routes/api.php:134); [app/Http/Controllers/Api/RdoController.php:24-103](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/RdoController.php:24); [bootstrap/app.php:7-21](/Users/marksioson/Desktop/NUERS/bootstrap/app.php:7); [app/Http/Controllers/Controller.php:5-8](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Controller.php:5). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Disable or protect this legacy provisioning path through central policy, prohibit overwriting existing users, require verified staff provisioning and step-up/MFA, and audit every change.

**Acceptance test (not executed):** In disposable staging, anonymous/low-role requests cannot create/update RDO, user, password or role; unrelated existing account remains byte-identical. No request was sent during audit.

### F02 - Legacy financial reads and expense writes remain unguarded

**Critical; SEC03/S02/C01.**

New workspaces have explicit guards, but transactions/overview returns financial rows without an actor check. Legacy dashboard super-admin/BIR and stored tax-intelligence branches also lack a guard. RDO resolution accepts a requested code or falls back to the first office without requiring a user. SPA RequireRole cannot protect direct APIs. MerchantExpenseController::resolveMerchant also falls back to a tax-intelligence/default merchant when no valid login exists. POST /merchant/expenses upserts monetary/tax/claim-state fields, while GET /merchant/expenses invokes synchronization upserts. This is unauthorized financial mutation as well as disclosure; no request was sent.

**Inspection evidence:** [routes/api.php:89,126-127,134-136](/Users/marksioson/Desktop/NUERS/routes/api.php:89); [app/Http/Controllers/Api/TransactionController.php:19-69](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionController.php:19); [app/Http/Controllers/Api/DashboardController.php:22-35,908](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/DashboardController.php:22); [app/Http/Controllers/Api/TaxIntelligenceController.php:17-26](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxIntelligenceController.php:17); [app/Http/Controllers/Api/RdoController.php:249-269](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/RdoController.php:249). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Inventory and enforce central deny-by-default authentication plus role/object policy for every route and legacy alias. Provide separate deliberately minimized public projections.

**Acceptance test (not executed):** Anonymous, wrong-role and foreign-tenant cases for all methods/aliases deny before data/cache/write side effects; route coverage is exhaustive and automated.

Additional source-confirmed expense evidence: [MerchantExpenseController.php:16](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantExpenseController.php:16), `store`52-125, `resolveMerchant`127-168 and `syncFromTaxIntelligence`217-226. Anonymous/invalid-token resolution can select another taxpayer; do not execute these handlers during a read-only audit.

### F03 - Self-asserted identity enters taxpayer authorization decisions

**Critical; T01/SEC02/SEC03.**

Legacy signup validates meta as a loose array, then writes user-supplied full_name/organization/TIN and sets tin_bound_at. BusinessAccount merchant lookup falls back to matching profile organization/full_name when no verified ownership exists. Client-facing dashboard scope can use this self-asserted profile TIN. A user-controlled label or identifier can therefore become another taxpayer authorization boundary. This can also be reached through allowed profile-name changes.

**Inspection evidence:** [app/Http/Controllers/Api/AuthController.php:56-83](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:56); [app/Http/Controllers/Api/BusinessAccountController.php:189-213](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:189); [app/Http/Controllers/Api/ClientProfileController.php:33-55](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/ClientProfileController.php:33); [app/Http/Controllers/Api/EnterpriseDashboardController.php:45-53](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EnterpriseDashboardController.php:45); [app/Http/Controllers/Api/TableController.php:64-81](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:64). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Replace all name/contact/TIN identity fallbacks with reviewed membership IDs and verified taxpayer associations. Retire legacy signup or subject it to the verified registration policy. Treat TIN format as distinct from ownership proof.

**Acceptance test (not executed):** Synthetic account using another fixture taxpayer name/TIN cannot read/update that fixture business/profile/invoices/receipts, obtain credentials or bind ownership through any endpoint.

### F04 - Merchant sandbox, branch and current authority are not enforced together

**High; I01/T03/M03.**

The key contains an environment, but authenticateApiKey never checks it; the same intake persists into the real transaction/receipt tables. Branch strings are credential/payload labels, not necessarily a verified five-digit BIR branch. assertCanIssue chooses latest merchant integration without key-environment/branch context; maintained permit validation runs only if a matching permit is found, so missing maintained evidence is not itself a blocker. Global accreditation blocks current issuance, but does not repair this latent authorization defect.

**Inspection evidence:** [app/Http/Controllers/Api/IntegrationTransactionController.php:25-63,222-244,451-469](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:25); [app/Http/Controllers/Api/MerchantApiKeyController.php:154-188](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:154); [app/Services/BirCompliance.php:79-124](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:79). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Use separate sandbox storage or synthetic no-write preview; require exact tenant/branch/environment credential and permit linkage with mandatory maintained authority and current merchant state.

**Acceptance test (not executed):** Sandbox cannot write real sources; expired/missing/revoked/wrong-branch permits and suspended merchants deny without invoice/outbox/audit-success side effects.

### F05 - The end-to-end BIR delivery lifecycle stops at local preparation

**Critical; Q02/B01/B03/B04/H05.**

Atomic encrypted internal intent exists. No Jobs/Events worker, leased outbox relay, authenticated BIR adapter, status inquiry or authoritative response processor was found. The preparation scheduler does not drain or deliver the outbox. EIS retry rows are records rather than proof of executed delivery. Certification ACK is explicitly synthetic. A default endpoint path is assembled in source, but no verified official contract supports treating that path as real.

**Inspection evidence:** [app/Services/EisDocumentReadiness.php:176-198](/Users/marksioson/Desktop/NUERS/app/Services/EisDocumentReadiness.php:176); [routes/console.php:14-36](/Users/marksioson/Desktop/NUERS/routes/console.php:14); [config/bir_compliance.php:9-11](/Users/marksioson/Desktop/NUERS/config/bir_compliance.php:9); [app/Http/Controllers/Api/EisController.php:243-249,315,374-431](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:243); [app/Http/Controllers/Api/IntegrationWorkspaceController.php:236-246](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:236). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Obtain controlled official package, implement adapter/crypto and durable worker with leases, DLQ, recovery, unknown-outcome inquiry and response verification; keep current production gates closed until acceptance.

**Acceptance test (not executed):** Authorized sandbox fixtures cover lossless crash/restart, genuine rejection/ACK, timeout-inquiry and repeat delivery; no HTTP200-only acceptance or duplicate issue.

### F06 - Source timestamps, idempotency namespace and sequence do not meet the intake target

**High; I02/I04/M01/M02/M05/M06.**

There is no accepted original event timestamp, event type, canonical schema version or source event ID distinct from external_reference. issued_at becomes server now, so delayed offline events lose their authoritative original time. The unique key is merchant+reference rather than branch/source/type. Racing identical requests can both miss the pre-insert read; the DB unique prevents duplication but no intake QueryException recovery returns the committed winner. Generated invoice numbers are random rather than allocated series. Duplicate response omits original receipt/status query location.

**Inspection evidence:** [app/Services/IntegrationContract.php:12-34](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12); [app/Http/Controllers/Api/IntegrationTransactionController.php:34-49,65-86,159,192-211,437-448](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:34); [database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19-22](/Users/marksioson/Desktop/NUERS/database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Specify versioned connector event/inbox contract, stable complete idempotency domain, original timestamps, authorized sequence allocator and durable status API; handle unique-key race by returning the same resource.

**Acceptance test (not executed):** Ten concurrent equivalent requests yield one invoice/outbox and stable results; two source systems/branches follow intended namespace; delayed events preserve original dates and independent sync-age metrics.

### F07 - Validation and VAT presentation have cross-field gaps

**High; I03/SEC02.**

Server money recomputation is a real improvement. However declared document_type can bypass tax-based resolution, buyer TIN is a loose optional string, nested item descriptions/objects lack intake size/schema bounds, and original dates/schema are missing. Invoice Center maps vat_inclusive=false to non_vat rather than independently modeling price basis and VAT classification. Draft non-VAT/exempt totals also lack the corresponding header tax bucket in the insert. These are local domain gaps, not an assertion of an unverified official BIR schema.

**Inspection evidence:** [app/Services/InvoiceCalculator.php:25-90](/Users/marksioson/Desktop/NUERS/app/Services/InvoiceCalculator.php:25); [app/Services/IntegrationContract.php:12-34,52-84](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12); [app/Http/Controllers/Api/IntegrationTransactionController.php:369-410](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:369); [app/Http/Controllers/Api/InvoiceCenterController.php:146-170](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:146). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Use explicit validated canonical DTOs, independent tax category/price-basis fields, matching document type, bounded nested arrays/text and complete tax-bucket snapshots; retain fail-closed unsupported regimes.

**Acceptance test (not executed):** Valid exclusive VAT produces correct base/VAT/gross without becoming non-VAT; non-VAT draft line/header buckets reconcile; invalid required buyer/date/type semantics fail across all paths.

### F08 - Human sessions and privileged actions lack complete lifecycle security

**High; SEC04/SEC09/C01.**

Device login rotates/reuses a token, but bearer tokens are stored directly in users and browser localStorage; no server TTL or request-time device binding is enforced by the common lookup pattern. No privileged MFA challenge/step-up flow was found. Merchant suspension is checked in some login/session paths but not consistently in all raw token/API key checks. Generic signup password policy is weaker than reviewed business registration.

**Inspection evidence:** [app/Services/DeviceSession.php:24-40](/Users/marksioson/Desktop/NUERS/app/Services/DeviceSession.php:24); [app/Http/Controllers/Api/AuthController.php:30-90,110-123](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:30); [src/lib/session-store.ts:1-7](/Users/marksioson/Desktop/NUERS/src/lib/session-store.ts:1); [app/Http/Controllers/Api/TableController.php:344-352](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:344); [app/Http/Controllers/Api/MerchantApiKeyController.php:299-307](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:299). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Centralize authentication, membership/suspension/expiry checks; hash and scope tokens, enforce privileged MFA, secure browser session design, reauthentication and security event auditing.

**Acceptance test (not executed):** Revoked/expired/suspended tokens fail all controllers; device header alone cannot assert trust; privileged actions require MFA/step-up; no usable token in DB/report/log copies.

### F09 - High-traffic, DDoS and infrastructure resilience remain unproved

**High; P01/H01/H02/H03/SEC08/C02.**

Local runtime uses sync queue and local file cache/session; no deployed edge/WAF/LB/origin/firewall/quorum evidence or representative benchmark was supplied. Daily per-key log COUNT is a non-atomic quota, not short-window admission control. Static DR pass records and proposed hardware do not establish recovery or capacity. This is missing assurance, not proof that an external provider has no defenses.

**Inspection evidence:** [app/Http/Controllers/Api/IntegrationTransactionController.php:324-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:324); [config/queue.php:16-44](/Users/marksioson/Desktop/NUERS/config/queue.php:16); `.local/start.sh:6-14`; [src/pages/bcdr/center.tsx:13-41](/Users/marksioson/Desktop/NUERS/src/pages/bcdr/center.tsx:13); [docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:416-419](/Users/marksioson/Desktop/NUERS/docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:416). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** After security fixes, supply topology/edge evidence and stage production-like workers/shared state; obtain written approval for bounded load/failover plans. Define all thresholds before testing.

**Acceptance test (not executed):** Reproducible 1M/hour and 2K burst results plus real independent failover/restore evidence; provider-approved DDoS controls, origin isolation and graceful429 behavior verified.

### F10 - Cancellation, evidence custody and operational assurance still include UI-only claims

**High; I05/D02/C03/P02.**

Cancel waits and shows success without any backend mutation. Several operational pages use static or randomized figures. Archive labels ten-year mandatory retention/WORM/legal_hold without corresponding enforcement. No authorized manual-invoice recovery workflow was found. A user can therefore mistake a presentation for an executed regulated operation.

**Inspection evidence:** [src/pages/merchant/invoice-detail.tsx:136-146](/Users/marksioson/Desktop/NUERS/src/pages/merchant/invoice-detail.tsx:136); [src/pages/system/admin.tsx:25-45](/Users/marksioson/Desktop/NUERS/src/pages/system/admin.tsx:25); [src/pages/bcdr/center.tsx:13-41](/Users/marksioson/Desktop/NUERS/src/pages/bcdr/center.tsx:13); [src/pages/archive/center.tsx:15-50](/Users/marksioson/Desktop/NUERS/src/pages/archive/center.tsx:15); [src/pages/engine/index.tsx:28-89](/Users/marksioson/Desktop/NUERS/src/pages/engine/index.tsx:28). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Replace simulated claims with explicit unavailable/demo status until durable audited implementations exist; implement controlled cancellation, legal holds, retention, source custody and approved manual contingency.

**Acceptance test (not executed):** Each success UI maps to persisted authorized evidence; unavailable actions cannot show success; legal hold blocks deletion and contingency recovery preserves linkage.

### F11 - Monitoring and acceptance rules differ between pages

**High; B03/P02/R01.**

Transactions uses canonical sources, production-preferred history and verification fields. Live Monitoring still starts from transaction rows, chooses latest mixed-environment transmission and uses ACK existence/reference without the same verification requirements. Enterprise BIR metrics similarly use weaker checks. Later certification records can mask production evidence in these readers; standalone invoices remain absent from their transaction-based metrics.

**Inspection evidence:** [app/Http/Controllers/Api/TransactionWorkspaceController.php:28-40](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:28); [app/Http/Controllers/Api/LiveMonitoringController.php:103-125](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:103); [app/Http/Controllers/Api/EnterpriseDashboardController.php:65-72](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EnterpriseDashboardController.php:65); [app/Services/IssuedDocumentLedger.php:25-87](/Users/marksioson/Desktop/NUERS/app/Services/IssuedDocumentLedger.php:25). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Share canonical populations and authoritative per-environment acceptance policy across every dashboard/export; separate transaction volume, invoice volume and actual BIR evidence.

**Acceptance test (not executed):** One fixture matrix of production+certification histories/standalone invoices yields consistent per-page status/counts; unverified ACK never counts as authoritative anywhere.

### F12 - Portability, legal holds and independent audit trust remain incomplete

**High; D01/D02/A01.**

Admin JSON export is broad, but not a complete taxpayer-scoped migration product and omits new internal outbox/reporting obligations/branch reviews. No legally reviewed hold/release engine exists. Hash chain/head reside in one mutable DB trust boundary; a privileged actor can rewrite both. Global audit-head locking also serializes audited writes.

**Inspection evidence:** [app/Http/Controllers/Api/LiveMonitoringController.php:164-201](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:164); [app/Services/BirCompliance.php:150-179](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:150); [src/pages/archive/center.tsx:15-50](/Users/marksioson/Desktop/NUERS/src/pages/archive/center.tsx:15). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Add complete manifests/secure scoped exports, continued-access rights and hold/release policies; externalize immutable checkpoints/evidence and benchmark audit partition strategy.

**Acceptance test (not executed):** Tenant export round-trip preserves all source/preparation/audit metadata; held records survive termination; independent checkpoint detects rewritten/truncated DB history.

### F13 - Reconciliation and incident workflows lack complete case/escalation controls

**High; R01/R02/C01/Q03.**

Read-time comparisons/review reasons/revisions and incident reporting/restoration references exist. No durable scheduled full-population reconciliation, assigned exception owner/priority/SLA or evidence-validated closure is enforced. Critical written incident reports depend on a manually recorded timestamp/reference; no automatic regulatory notification is asserted.

**Inspection evidence:** [app/Http/Controllers/Api/OperationsReportController.php:15-98,205-225](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:15); [app/Http/Controllers/Api/EspComplianceController.php:108-137](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:108); [routes/console.php:14-36](/Users/marksioson/Desktop/NUERS/routes/console.php:14). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Add durable case ownership/escalation, scheduled completeness checks and independent closure approvals; retain manual written-report custody and route reminders.

**Acceptance test (not executed):** Missing/mismatch/stale/uncertain records open persistent assigned cases; no silent close; incident due reminders reach responsible staff and report evidence is verifiable.

### F14 - SQL injection assurance is incomplete despite parameterized queries

**Medium; SEC01/SEC02.**

No confirmed exploitable unbound request-to-raw-SQL sink was identified in inspected first-party runtime PHP. Raw expressions mostly use constants/trusted helper identifiers and bind user values. Generic column/filter/order identifiers lack explicit allowlists, pagination is unbounded when omitted, and malformed schemas can reach DB errors. Query builder quoting is not a reason to call those defects proven SQL injection. Static import PREPARE chooses constant DDL, not runtime user SQL.

**Inspection evidence:** [app/Http/Controllers/Api/TableController.php:22-29,288-317](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:22); [app/Http/Controllers/Api/IntegrationTransactionController.php:267,312-314](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:267); [database/sql/fix_tax_graph_edges_live_import.sql:11-25](/Users/marksioson/Desktop/NUERS/database/sql/fix_tax_graph_edges_live_import.sql:11). Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Allowlist identifiers/operators/limits, constrain inputs, add complete sink inventory/SAST and authorized isolated DB-specific negative tests.

**Acceptance test (not executed):** SQL syntax payloads remain inert values; identifiers return422; no database errors, data disclosure or changed tenant scope for malformed inputs.

### F15 - Uploads, future webhook egress and assessment evidence need hardening

**High; SEC05/SEC06/SEC07/SEC10.**

Image MIME/size and certificate parsing controls exist. Avatars/logos are written directly under public/uploads with client-derived extensions and no re-encoding/AV path; branding may intentionally be public, evidence must not be. Webhook configuration rejects literal private hosts but does not implement DNS/redirect-safe delivery. No active webhook sender is present, so no active webhook SSRF exploit is claimed. No repository CSP/independent scan pipeline evidence proves XSS/dependency safety.

**Inspection evidence:** [app/Http/Controllers/Api/ClientProfileController.php:81-105](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/ClientProfileController.php:81); [app/Http/Controllers/Api/BusinessAccountController.php:92-124](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:92); [app/Http/Controllers/Api/IntegrationWorkspaceController.php:98-101,158-177,207-246](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:98); `public/.htaccess:1-25`. Method: follow the registered route and controller/helper calls, inspect schema/state side effects and associated UI; no exploit executed.

**Proposed remediation:** Use normalized image encoding/private regulated evidence, expiring authorized downloads, transaction/object cleanup and provider-certified egress policies before delivery; obtain independent assessments.

**Acceptance test (not executed):** Polyglots/extension/size/private evidence access cases pass in isolation; DNS-rebind/redirect/metadata cases fail before request; dated SCA/SAST/VAPT and deployed header evidence reviewed.


### F16 - Unverified expense claims and side-effectful legacy financial reads

**High integrity impact in addition to F02 Critical access failure; SEC02/S01/R01.** `MerchantExpenseController.php:59-111` accepts submitted input VAT/withholding/validation/claim/reconciliation states as client data and upserts by source reference+buyer TIN without independently verifying the supplier original, line arithmetic, authorization or approval. GET synchronizes expense rows from several sources (`index`16-22 and synchronization217-226); those operations have no enclosing reviewed transaction/audit in the shown read path. Legacy `MerchantTaxCenterController.php:19-47` and tax-intelligence reports use separate sales/expense aggregation rules rather than the canonical document/readiness service. Modern internal SLS safeguards must not be assumed to protect all tax-report pages.

Inspection method: read the route, resolution fallback, updateOrInsert payload and reporting entry points; no runtime request or mutation. Proposed remediation: enforce verified tenant membership, side-effect-free read APIs, independent source/tax validation and reviewed claim transitions, idempotent background synchronization and canonical report reconciliation. Proposed acceptance: anonymous/foreign/low-role expense writes and bogus tax-credit claims fail; GET changes no rows; duplicate source/payment scenarios cannot inflate output or input VAT; independent expected ledger agrees with every tax-report page.

## Complete control disposition

### G01 - Verified ESP solution/version/modules/environment/validity activation gate

**PARTIAL / Critical.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Compliance + Platform.

Evidence: [app/Services/BirCompliance.php:23-76,92-142](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:23); [config/bir_compliance.php:3-11](/Users/marksioson/Desktop/NUERS/config/bir_compliance.php:3); [tests/Feature/RmcComplianceTest.php:188](/Users/marksioson/Desktop/NUERS/tests/Feature/RmcComplianceTest.php:188).

Assessment / remaining gap / remediation: Keep production blocked; bind actual externally verified solution/module/environment evidence to deployed artifact; prevent legacy metadata substituting for evidence.

Acceptance (proposed, not run): Unapproved/expired/wrong module/version/environment cannot issue or transmit; actual reviewer identity and certificate scope independently verified.

### G02 - Material-change prior approval and deployment revalidation

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Release Engineering + Compliance.

Evidence: [app/Services/BirCompliance.php:58-65](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:58); [app/Http/Controllers/Api/BusinessRegistryController.php:224-238](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:224); `.github/workflows NOT FOUND`.

Assessment / remaining gap / remediation: Material-change flag and identity invalidation exist; add change requests, independent approval and release/deployment enforcement.

Acceptance (proposed, not run): Unapproved material change blocks deploy/activation; approved hash/version is traceable and rollback preserves evidence.

### T01 - Taxpayer legal identity and branch verification before use

**FAIL / Critical.** Source: `SUPPLIED_RMC_110_2026`. Owner: Identity + Onboarding.

Evidence: [app/Http/Controllers/Api/AuthController.php:56-83](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:56); [app/Http/Controllers/Api/BusinessAccountController.php:189-213](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:189); [app/Http/Controllers/Api/BusinessRegistryController.php:244](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:244); [app/Http/Controllers/Api/BusinessRegistrationController.php:72-109](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistrationController.php:72).

Assessment / remaining gap / remediation: Retire legacy self-asserted TIN binding/name-based merchant association; use verified server-owned membership and formal taxpayer evidence.

Acceptance (proposed, not run): Invented organization/name/TIN at signup cannot confer another taxpayer data or merchant ownership; verified intake remains pending independent review.

### T02 - Separate PTI/PTT and terminal/device records

**PASS / Critical.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Compliance.

Evidence: [database/migrations/2026_10_15_000012_create_taxpayer_authorizations.php:11](/Users/marksioson/Desktop/NUERS/database/migrations/2026_10_15_000012_create_taxpayer_authorizations.php:11); [app/Http/Controllers/Api/TaxpayerAuthorizationController.php:115-189](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:115); [app/Services/BirCompliance.php:96-107](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:96); [tests/Feature/TaxpayerWorkspaceTest.php:50,72](/Users/marksioson/Desktop/NUERS/tests/Feature/TaxpayerWorkspaceTest.php:50).

Assessment / remaining gap / remediation: Preserve distinct permit kinds and device relationship; validate real approvals separately.

Acceptance (proposed, not run): PTI never substitutes for PTT; terminal label is not authority; expired/revoked authorities reject applicable operations. PASS is structural, not proof of any permit.

### T03 - Issuance and transmission independently validate current taxpayer authority

**FAIL / Critical.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Compliance + API.

Evidence: [app/Services/BirCompliance.php:79-142](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:79); [app/Http/Controllers/Api/IntegrationTransactionController.php:52,63,222-244,451-469](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:52); [app/Http/Controllers/Api/TaxpayerAuthorizationController.php:115-164](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:115).

Assessment / remaining gap / remediation: Require current exact taxpayer/branch/environment permit records, legal status and written authority; do not accept absence of maintained permit as success or select latest integration without environment.

Acceptance (proposed, not run): Revoked/missing/wrong branch/environment/merchant permits and suspended taxpayer reject issuance; PTI alone does not authorize transmission.

### I01 - Authenticated tenant and branch scoped intake

**FAIL / Critical.** Source: `NUERS_DESIGN`. Owner: API Security.

Evidence: [routes/api.php:88](/Users/marksioson/Desktop/NUERS/routes/api.php:88); [app/Http/Controllers/Api/IntegrationTransactionController.php:25-30,222-244,451-469](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:25); [app/Http/Controllers/Api/MerchantApiKeyController.php:147-200](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:147).

Assessment / remaining gap / remediation: Hash lookup/scope/IP checks exist; enforce key environment, stable membership, branch authority and merchant status before creation.

Acceptance (proposed, not run): Foreign tenant/branch, revoked key, sandbox key on real ledger and suspended merchant all reject without side effects.

### I02 - Tenant/branch/source/document idempotency

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: API + Ledger.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:34-49,126-131](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:34); [database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19-22](/Users/marksioson/Desktop/NUERS/database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19); [app/Http/Controllers/Api/InvoiceCenterController.php:149-155](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:149).

Assessment / remaining gap / remediation: Current uniqueness is merchant+external reference; add branch/source/family contract and concurrent duplicate conflict recovery returning same complete resource.

Acceptance (proposed, not run): Ten identical concurrent requests return one logical invoice/outbox and stable receipt/status location; intentional same external ID across distinct allowed namespaces behaves as specified.

### I03 - Canonical document/type/tax/date/number/schema validation

**PARTIAL / High.** Source: `NUERS_DESIGN;UNVERIFIED_EXTERNAL_BIR_API`. Owner: Invoice Domain + Compliance.

Evidence: [app/Services/InvoiceCalculator.php:12-90](/Users/marksioson/Desktop/NUERS/app/Services/InvoiceCalculator.php:12); [app/Services/IntegrationContract.php:12-84](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12); [app/Http/Controllers/Api/BusinessInvoiceController.php:126-189](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:126); [app/Http/Controllers/Api/InvoiceCenterController.php:146-170](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:146).

Assessment / remaining gap / remediation: Arithmetic reconciliation exists; enforce bounded nested schema, buyer/TIN/date/number semantics, type-to-tax compatibility and explicit independent tax/price-basis fields; obtain official mapping.

Acceptance (proposed, not run): Malformed nested objects, mismatched declared document/tax types, invalid required TIN/date and unsupported regimes fail; inclusive and exclusive VAT do not change seller tax category.

### I04 - Immutable issued ledger, unique sequence and validated lifecycle

**PARTIAL / High.** Source: `NUERS_DESIGN;SUPPLIED_RMC_110_2026`. Owner: Ledger.

Evidence: [app/Http/Controllers/Api/BusinessInvoiceController.php:168-199,255-285](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:168); [app/Http/Controllers/Api/IntegrationTransactionController.php:126-185,437-448](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:126); [database/migrations/2026_06_02_000014_create_business_invoices_tables.php:64](/Users/marksioson/Desktop/NUERS/database/migrations/2026_06_02_000014_create_business_invoices_tables.php:64).

Assessment / remaining gap / remediation: Transactions and invoice unique index exist; introduce authorized branch/family sequence allocation and append-only transition events; random generated numbers are not sequence control.

Acceptance (proposed, not run): Concurrent issuance cannot reuse/skip/corrupt authorized sequence; invalid transitions fail and every transition records actor/original reference atomically.

### I05 - Lawful corrections/cancellation preserve original and linkage

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Invoice Domain + UX.

Evidence: [app/Http/Controllers/Api/BusinessInvoiceController.php:180-183](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:180); [app/Services/EisDocumentReadiness.php:57-64](/Users/marksioson/Desktop/NUERS/app/Services/EisDocumentReadiness.php:57); [src/pages/merchant/invoice-detail.tsx:136-146](/Users/marksioson/Desktop/NUERS/src/pages/merchant/invoice-detail.tsx:136); [routes/api.php:73-75](/Users/marksioson/Desktop/NUERS/routes/api.php:73).

Assessment / remaining gap / remediation: Linked credit/debit creation exists; replace fake cancellation success with durable authorized review/adjustment workflow and original invoice handoff.

Acceptance (proposed, not run): Cancellation button persists request and displays durable status or explicit failure; original amounts preserved; correction links original and approved reason.

### Q01 - Invoice and internal outbox persisted atomically

**PASS / Critical.** Source: `NUERS_DESIGN`. Owner: Ledger.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:65-185](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:65); [app/Http/Controllers/Api/BusinessInvoiceController.php:191-285](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:191); [app/Services/EisDocumentReadiness.php:176-198](/Users/marksioson/Desktop/NUERS/app/Services/EisDocumentReadiness.php:176); [tests/Feature/EisReadinessAlignmentTest.php:266-280](/Users/marksioson/Desktop/NUERS/tests/Feature/EisReadinessAlignmentTest.php:266).

Assessment / remaining gap / remediation: Observed current-schema issuance encloses outbox preparation in same DB transaction. Add deployment schema preflight because hasTable compatibility guards skip preparation if schema absent.

Acceptance (proposed, not run): Rollback fixture requires invoice/items/outbox all absent after outbox failure; repeat for API path in MySQL. PASS covers observed transaction structure, not queue delivery.

### Q02 - Durable delivery worker, crash recovery and DLQ

**FAIL / Critical.** Source: `NUERS_DESIGN`. Owner: Delivery Platform.

Evidence: [routes/console.php:14-36](/Users/marksioson/Desktop/NUERS/routes/console.php:14); [config/queue.php:16-44](/Users/marksioson/Desktop/NUERS/config/queue.php:16); `app/Jobs NOT FOUND`; `app/Events NOT FOUND`; [app/Services/EisDocumentReadiness.php:176-198](/Users/marksioson/Desktop/NUERS/app/Services/EisDocumentReadiness.php:176).

Assessment / remaining gap / remediation: Build leased outbox relay/worker with retry budget, DLQ, restart recovery and tenant fairness. Preparation scheduler only creates missing internal copies; local queue is sync.

Acceptance (proposed, not run): Crash at every handoff preserves intent; poison messages quarantined with owner; no loss/duplicate issue after restart.

### Q03 - Real-time deadline and outage recovery monitoring

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Operations + Compliance.

Evidence: [app/Services/EisReportingPolicy.php:12-47](/Users/marksioson/Desktop/NUERS/app/Services/EisReportingPolicy.php:12); [app/Services/BirCompliance.php:145-147](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:145); [app/Http/Controllers/Api/EisController.php:391-404](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:391); [app/Http/Controllers/Api/EspComplianceController.php:108-137](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:108).

Assessment / remaining gap / remediation: Original-date/coverage and restoration targets exist; add source event timestamps, persistent age/risk alert routing and actual scheduled delivery; verify applicable deadline interpretation.

Acceptance (proposed, not run): Connected pipeline attempts promptly; unknown coverage not falsely overdue; outage escalation and original/recovery targets preserved.

### B01 - Authorized BIR adapter and sandbox/production separation

**FAIL / Critical.** Source: `UNVERIFIED_EXTERNAL_BIR_API`. Owner: BIR Integration.

Evidence: [config/bir_compliance.php:9-11](/Users/marksioson/Desktop/NUERS/config/bir_compliance.php:9); [app/Http/Controllers/Api/EisController.php:243-249,315](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:243); [app/Http/Controllers/Api/IntegrationWorkspaceController.php:236-246](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:236).

Assessment / remaining gap / remediation: Obtain authenticated official package/version and implement verified endpoints/auth/schema/cryptography; remove reliance on guessed default URL/path. Keep production false.

Acceptance (proposed, not run): Approved external contract tests and real sandbox interoperability evidence; sandbox cannot route real data/credentials to production.

### B02 - Complete request/attempt/response evidence

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Delivery + Observability.

Evidence: [database/migrations/2026_06_02_000010_create_bir_eis_readiness_tables.php:82-160](/Users/marksioson/Desktop/NUERS/database/migrations/2026_06_02_000010_create_bir_eis_readiness_tables.php:82); [app/Http/Controllers/Api/EisController.php:296-346](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:296); [app/Http/Controllers/Api/IntegrationTransactionController.php:338-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:338).

Assessment / remaining gap / remediation: Tables/correlation/hash exist; record real per-attempt request, response, latency, authentication result and redacted errors with retention; logging failure must not mask committed result.

Acceptance (proposed, not run): Every actual attempt has immutable correlation and payload hash; log errors contain no credentials/PII; merchant response reflects committed state after logging failure.

### B03 - Authoritative response semantics and explicit Uncertain state

**PARTIAL / Critical.** Source: `NUERS_DESIGN;UNVERIFIED_EXTERNAL_BIR_API`. Owner: BIR Integration.

Evidence: [app/Http/Controllers/Api/EisController.php:410-431](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:410); [app/Http/Controllers/Api/TransactionWorkspaceController.php:28-40](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:28); [app/Http/Controllers/Api/LiveMonitoringController.php:103-125](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:103); [app/Http/Controllers/Api/EnterpriseDashboardController.php:65-72](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EnterpriseDashboardController.php:65).

Assessment / remaining gap / remediation: Production manual ACK blocked; build authentic-response verifier and shared state projection. All readers must require same proof; implement submitted/rejected/uncertain transitions.

Acceptance (proposed, not run): HTTP 200 alone never acknowledged; uncertified ACK cannot alter any dashboard/report; timeout enters Uncertain until authoritative inquiry.

### B04 - Safe retry/backoff/inquiry before replay

**PARTIAL / High.** Source: `NUERS_DESIGN;UNVERIFIED_EXTERNAL_BIR_API`. Owner: Delivery.

Evidence: [app/Http/Controllers/Api/EisController.php:374-407](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:374); [database/migrations/2026_06_02_000010_create_bir_eis_readiness_tables.php:120-130](/Users/marksioson/Desktop/NUERS/database/migrations/2026_06_02_000010_create_bir_eis_readiness_tables.php:120).

Assessment / remaining gap / remediation: Bounded administrative retry exists; add worker-consumed due times, jitter, circuit breaker and authenticated status inquiry for uncertain delivery.

Acceptance (proposed, not run): Worker death after send triggers inquiry rather than blind replay; rate-limit responses honor verified Retry-After; poison failures stop within budget.

### R01 - Issued/outbox/sent/ACK completeness reconciliation

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Reconciliation.

Evidence: [app/Http/Controllers/Api/OperationsReportController.php:15-98](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:15); [app/Services/IssuedDocumentLedger.php:25-87](/Users/marksioson/Desktop/NUERS/app/Services/IssuedDocumentLedger.php:25); [routes/console.php:14-36](/Users/marksioson/Desktop/NUERS/routes/console.php:14).

Assessment / remaining gap / remediation: Canonical/read-time comparisons exist; add durable scheduled reconciliation across both outbox versions and attempts, missing-intent detection and stale/uncertain cases.

Acceptance (proposed, not run): Known missing outbox/payload/ACK and mismatched hashes generate durable exceptions without silently repairing history.

### R02 - Exception ownership priority evidence approvals history

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Operations.

Evidence: [app/Http/Controllers/Api/OperationsReportController.php:205-225](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:205); [database/migrations/2026_10_15_000009_create_reconciliation_reviews.php:11](/Users/marksioson/Desktop/NUERS/database/migrations/2026_10_15_000009_create_reconciliation_reviews.php:11).

Assessment / remaining gap / remediation: Revision/reason/audit exist; add assigned owner, priority/SLA, evidence attachment custody and restricted independent closure checks.

Acceptance (proposed, not run): Resolving/ignoring unresolved regulatory failures requires evidence/authorized approval; concurrent edits conflict; complete case history remains queryable.

### S01 - Automatic accurate scoped SLS and prescribed exports

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026;UNVERIFIED_EXTERNAL_BIR_API`. Owner: Reporting + Compliance.

Evidence: [app/Http/Controllers/Api/OperationsReportController.php:101-196](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:101); [app/Services/IssuedDocumentLedger.php:64-87](/Users/marksioson/Desktop/NUERS/app/Services/IssuedDocumentLedger.php:64); [tests/Feature/OperationsWorkspaceTest.php:61,90,101](/Users/marksioson/Desktop/NUERS/tests/Feature/OperationsWorkspaceTest.php:61).

Assessment / remaining gap / remediation: Internal sales draft recomputes population; add prescribed BIR format/version, scheduler, taxpayer export access and explicit handling of source-review exclusions. Migrate legacy MerchantTaxCenter and tax-intelligence readers to canonical source rules and verified expense-claim evidence.

Acceptance (proposed, not run): Sources/adjustments reconcile exactly; no duplicate payments inflate sales; approved official format/schema and taxpayer/branch permissions pass.

### S02 - Authorized BIR read/export access and access audit

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026`. Owner: Compliance + IAM.

Evidence: [app/Services/BirCompliance.php:13-20](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:13); [app/Http/Controllers/Api/LiveMonitoringController.php:147-201](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:147); [app/Http/Controllers/Api/InvoiceCenterController.php:89-110](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:89).

Assessment / remaining gap / remediation: Audited administrative exports exist; establish externally verified BIR staff scope, step-up/least privilege, secure delivery and request SLA tracking.

Acceptance (proposed, not run): Only authorized officials receive lawful scope; all exports audited; complete summaries/extracts meet applicable approved time windows.

### A01 - Append-only tamper-evident synchronized audit

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Security + Data Platform.

Evidence: [app/Services/BirCompliance.php:150-179](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:150); `database/migrations/2026_10_15_000002_add_rmc110_compliance_controls.php`; [tests/Feature/RmcComplianceTest.php:155](/Users/marksioson/Desktop/NUERS/tests/Feature/RmcComplianceTest.php:155).

Assessment / remaining gap / remediation: Hash chain under global lock exists; add independent immutable replication/checkpoints, DB privileges, NTP evidence and exhaustive security-event coverage; benchmark head contention.

Acceptance (proposed, not run): Mutation/tail truncation detected against external checkpoint; DB admin cannot silently rewrite trust anchor; clock skew alarms and acceptable audit throughput.

### D01 - Taxpayer ownership and complete portability independent of fees

**PARTIAL / High.** Source: `SUPPLIED_RMC_110_2026`. Owner: Data Governance.

Evidence: [app/Http/Controllers/Api/LiveMonitoringController.php:164-201](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:164); [app/Http/Controllers/Api/InvoiceCenterController.php:89-110](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:89); [src/pages/archive/center.tsx:15-50](/Users/marksioson/Desktop/NUERS/src/pages/archive/center.tsx:15).

Assessment / remaining gap / remediation: Admin JSON/merchant document CSV exist; full taxpayer migration export must include new outbox/branch/coverage evidence, manifest, hashes and secure recipient workflow without fee lockout.

Acceptance (proposed, not run): Unpaid/suspended taxpayer can obtain authorized complete export; import round-trip preserves all IDs/content/audit links; no foreign tenant rows.

### D02 - Retention rules and legal holds override deletion

**NOT FOUND / High.** Source: `SUPPLIED_RMC_110_2026`. Owner: Data Governance + Legal.

Evidence: [src/pages/archive/center.tsx:15-50](/Users/marksioson/Desktop/NUERS/src/pages/archive/center.tsx:15); `app/Jobs NOT FOUND`; `database/migrations (no legal_holds/retention enforcement table found)`.

Assessment / remaining gap / remediation: Implement legally reviewed versioned retention, hold lifecycle, BIR written release dependency, immutable storage and reviewed purge authorization. Static ten-year/WORM labels are not controls.

Acceptance (proposed, not run): Active hold prevents every purge/archive lifecycle; account termination/fees cannot delete required records; release is evidenced and independently approved.

### C01 - Privileged security tenant confidentiality and incident control

**FAIL / Critical.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Security + IAM.

Evidence: [app/Http/Controllers/Api/RdoController.php:24-103](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/RdoController.php:24); [app/Http/Controllers/Api/AuthController.php:30-90](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:30); [app/Http/Controllers/Api/EspComplianceController.php:108-137](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:108); [src/lib/session-store.ts:1-7](/Users/marksioson/Desktop/NUERS/src/lib/session-store.ts:1).

Assessment / remaining gap / remediation: Close anonymous account/financial routes; enforce MFA/step-up, central membership/suspension/expiry policies, event redaction and incident escalation.

Acceptance (proposed, not run): All role/tenant negative cases pass; privileged login requires MFA; critical incident notification has owner and verified written-report evidence within applicable target.

Additional source-confirmed expense evidence: [MerchantExpenseController.php:16](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantExpenseController.php:16), `store`52-125, `resolveMerchant`127-168 and `syncFromTaxIntelligence`217-226. Anonymous/invalid-token resolution can select another taxpayer; do not execute these handlers during a read-only audit.

### C02 - Verified backup/restore failover and RTO/RPO

**NOT VERIFIED / High.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: SRE.

Evidence: [src/pages/bcdr/center.tsx:13-41](/Users/marksioson/Desktop/NUERS/src/pages/bcdr/center.tsx:13); `.local/eis-readiness-fix-20261016/database-migration-v3.json`; `deployment/LOCAL_LIVE_DATABASE_WORKFLOW.md`.

Assessment / remaining gap / remediation: Obtain deployed backup topology, encryption/key custody, offsite immutable copies and signed restore/failover drill results; do not use static UI drill values as evidence.

Acceptance (proposed, not run): Restore representative DB/documents/keys in isolated staging and measure real RTO/RPO; reconcile hash/counts; test independent site failure after approval.

### C03 - Authorized manual contingency and recovery linkage

**NOT FOUND / High.** Source: `SUPPLIED_RMC_110_2026`. Owner: Invoice Domain + Compliance.

Evidence: [app/Http/Controllers/Api/EspComplianceController.php:108-137](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:108); `routes/api.php`; [app/Services/IntegrationContract.php:12-34](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12).

Assessment / remaining gap / remediation: Incident recording exists but no manual-invoice registry/recovery ingestion/linked reconciliation workflow found; implement after approved legal procedure.

Acceptance (proposed, not run): Authorized manual series captured during outage; original dates/numbers and recovery links retained; no double issuance or retroactive silent source edit.

### P01 - Proven 1M/hour and 2K TPS full lifecycle capacity

**NOT VERIFIED / High.** Source: `NUERS_DESIGN`. Owner: Performance Engineering.

Evidence: [docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:416-419](/Users/marksioson/Desktop/NUERS/docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:416); `phpunit.xml`; `app/Jobs NOT FOUND`; `no benchmark harness/results found`.

Assessment / remaining gap / remediation: Define isolated representative staging workload/thresholds and obtain authorized sustained/burst/soak runs; hardware proposal and historical unit tests are not proof.

Acceptance (proposed, not run): 1,000,000 complete durable transactions/hour, 2,000 TPS burst and bounded recovery measured across pipeline; p50/p95/p99, errors, IOPS/replication/queue/backlog included.

### P02 - Real probes queue age deadline risk error budget alerts

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Observability + SRE.

Evidence: [app/Http/Controllers/Api/LiveMonitoringController.php:20-61](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:20); [app/Http/Controllers/Api/EnterpriseDashboardController.php:140-146](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EnterpriseDashboardController.php:140); [src/pages/system/admin.tsx:25-45](/Users/marksioson/Desktop/NUERS/src/pages/system/admin.tsx:25).

Assessment / remaining gap / remediation: Persisted transaction TPS/health snapshots exist; add probes with freshness, queue-age histograms, intake lag, error budgets and actionable alert delivery; remove simulated system metrics.

Acceptance (proposed, not run): Stale probe becomes Unknown; controlled queue delay and BIR outage produce routed tenant/operator alerts; dashboards agree on canonical sources and production state.

### M01 - POS/e-commerce/ERP correct invoice event trigger mapping

**NOT FOUND / High.** Source: `NUERS_DESIGN;UNVERIFIED_EXTERNAL_BIR_API`. Owner: Connector Engineering + Tax.

Evidence: [app/Services/IntegrationContract.php:15-34](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:15); [app/Http/Controllers/Api/IntegrationTransactionController.php:369-410](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:369); `resources/data/developer-openapi.json`.

Assessment / remaining gap / remediation: Define connector-specific event map/legal issuance basis; arbitrary transaction_type is not a lifecycle mapping or upstream inbox.

Acceptance (proposed, not run): Cart/order-created/unpaid/paid/fulfilled/refunded/cancelled fixtures follow reviewed issuance policy; source version and event stored.

### M02 - Measured real-time merchant durable-acceptance latency

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Connector + Performance.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:19,65-211,338-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:19); [app/Services/IntegrationContract.php:12-34](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12).

Assessment / remaining gap / remediation: Synchronous immediate intake exists; add original event time/source ID/schema/correlation acceptance timestamps and p95/p99 measurement. No 1-5 second or <2 second guarantee established.

Acceptance (proposed, not run): Representative event-to-durable acceptance measured independently of BIR; p95/p99 meet approved engineering SLA under agreed load.

### M03 - Merchant credential tenant branch environment isolation

**FAIL / Critical.** Source: `NUERS_DESIGN`. Owner: API Security.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:222-244,451-469](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:222); [app/Http/Controllers/Api/MerchantApiKeyController.php:154-188](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:154); [app/Services/BirCompliance.php:79-124](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:79).

Assessment / remaining gap / remediation: Use exact credential-bound production/sandbox/branch/authority membership; maintain key expiration/revocation and merchant legal status consistently.

Acceptance (proposed, not run): Wrong tenant/branch/environment and revoked/expired keys fail before any invoice; suspended merchant cannot use existing bearer/key.

### M04 - Encrypted persistent offline queue restart/reconnect recovery

**NOT FOUND / High.** Source: `NUERS_DESIGN`. Owner: Connector Engineering.

Evidence: `app/Jobs NOT FOUND`; `src (no merchant persistent offline outbox/service-worker implementation found)`; [app/Services/IntegrationContract.php:12-34](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12).

Assessment / remaining gap / remediation: Implement encrypted tamper-evident source queue, stable IDs, original timestamp, bounded backoff/jitter and signed recovery evidence.

Acceptance (proposed, not run): Disconnect/restart/reconnect loses no authorized events; duplicate/reordering cases preserve original sequence and sync age.

### M05 - Concurrent merchant idempotency and sequence

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Ledger + Connectors.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:34-49,126-131,437-448](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:34); [database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19-22](/Users/marksioson/Desktop/NUERS/database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19).

Assessment / remaining gap / remediation: Add complete source/branch/type idempotency namespace and conflict recovery; durable sequence rather than random invoice numbers.

Acceptance (proposed, not run): Concurrent retries return same document and intent without 500; distinct connectors/branches cannot collide unintentionally.

### M06 - Durable acceptance response stable resource and status query

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: API Contract.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:41-49,192-211](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:41); [routes/api.php:88](/Users/marksioson/Desktop/NUERS/routes/api.php:88); [app/Services/EisDocumentReadiness.php:176-198](/Users/marksioson/Desktop/NUERS/app/Services/EisDocumentReadiness.php:176).

Assessment / remaining gap / remediation: 201 follows commit, not BIR; add explicit acceptance/issuance/outbox/BIR states, Location/status endpoint authenticated by API key and full duplicate response contract.

Acceptance (proposed, not run): Merchant polls same stable resource with its scoped key after timeout; response cannot imply BIR ACK; duplicates include original receipt/status references.

### M07 - Signed reliable webhooks verification replay prevention

**NOT FOUND / High.** Source: `NUERS_DESIGN`. Owner: Connectors + Security.

Evidence: [app/Http/Controllers/Api/IntegrationWorkspaceController.php:207-246](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:207); `database/migrations/2026_10_15_000013_create_integration_workspace.php`; `app/Jobs NOT FOUND`.

Assessment / remaining gap / remediation: Only encrypted signing-secret configuration exists; implement delivery/inbound verification, timestamp/nonce dedup, bounded retries and attempt logs.

Acceptance (proposed, not run): Bad/old/replayed signatures fail; worker restart maintains delivery; DNS/redirect egress restrictions block internal destinations.

### M08 - Backpressure tenant quotas atomic limits and fair scheduling

**FAIL / High.** Source: `NUERS_DESIGN`. Owner: API Platform + SRE.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:324-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:324); [routes/api.php:76-88](/Users/marksioson/Desktop/NUERS/routes/api.php:76); `config/cache.php`; `app/Jobs NOT FOUND`.

Assessment / remaining gap / remediation: Daily log COUNT is non-atomic, post-auth and unbounded for bursts; add shared atomic per-IP/key/tenant route budgets, 429/Retry-After and durable bounded admission/fair queues.

Acceptance (proposed, not run): Concurrent tenants cannot exceed quota or starve peers; invalid-key flood limited before DB amplification; queue saturation rejects safely and recovers.

### M09 - Intake lag source rejection deadline and tenant escalation

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Observability.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:338-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:338); [app/Http/Controllers/Api/MerchantApiKeyController.php:389-459](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:389); [app/Http/Controllers/Api/LiveMonitoringController.php:20-61](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:20).

Assessment / remaining gap / remediation: Request latency/counts exist; record event/accept/issue/send/ACK spans, queue age and per-tenant stale/rejection alerts with routed owner.

Acceptance (proposed, not run): Outdated merchant events generate tenant escalation independently of BIR deadline; p95/p99/error metrics tied to correlation and stored evidence.

### M10 - Authorized manual/offline issuance and recovery

**NOT FOUND / High.** Source: `SUPPLIED_RMC_110_2026;NUERS_DESIGN`. Owner: Compliance + Connectors.

Evidence: [app/Services/IntegrationContract.php:12-34](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12); `routes/api.php`; [app/Http/Controllers/Api/EspComplianceController.php:108-137](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:108).

Assessment / remaining gap / remediation: Define authorized contingency series, signed recovery batch, original evidence and manual-to-electronic linkage. Do not infer permission from offline connectivity.

Acceptance (proposed, not run): Outage simulation proves manual permissions and traceable later reconciliation without duplicate sale or original-date rewrite.

### H01 - 1M/hour end-to-end sustained benchmark

**NOT VERIFIED / High.** Source: `NUERS_DESIGN`. Owner: Performance.

Evidence: [docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:419](/Users/marksioson/Desktop/NUERS/docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:419); `.local/eis-readiness-fix-20261016/full-tests.log`; `no load benchmark evidence found`.

Assessment / remaining gap / remediation: Create approved benchmark plan/workload and collect reproducible staging results; distinguish NUERS durable acceptance from external BIR quotas.

Acceptance (proposed, not run): One-hour run produces >=1M completed durable in-scope records with agreed error/latency budgets and reconciled counts; longer soak follows.

### H02 - 2K TPS burst graceful degradation and backlog recovery

**NOT VERIFIED / High.** Source: `NUERS_DESIGN`. Owner: Performance + SRE.

Evidence: [app/Http/Controllers/Api/IntegrationTransactionController.php:324-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:324); `app/Jobs NOT FOUND`; `no burst results found`.

Assessment / remaining gap / remediation: Set burst duration, concurrency, fairness, queue limits, retry budgets and recovery targets before authorized test.

Acceptance (proposed, not run): Burst acceptance/rejection measured with no loss, overload retry storm or tenant starvation; backlog drains within approved target.

### H03 - Web/DB/storage/site failure and actual RTO/RPO

**NOT VERIFIED / High.** Source: `NUERS_DESIGN`. Owner: Infrastructure.

Evidence: [docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:416-418](/Users/marksioson/Desktop/NUERS/docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.extracted.txt:416); [src/pages/bcdr/center.tsx:13-41](/Users/marksioson/Desktop/NUERS/src/pages/bcdr/center.tsx:13); `deployment/LOCAL_LIVE_DATABASE_WORKFLOW.md`.

Assessment / remaining gap / remediation: Supply provider topology, LB, witness/quorum/fencing, storage quorum, network/power isolation and offsite restore evidence. Proposed 2/2/3 nodes are not deployment proof.

Acceptance (proposed, not run): Authorized independent node/site drills produce no split brain and measured RTO/RPO, restored object/DB integrity and documented rollback.

### H04 - DB throughput indexes queries locking invoice sequencing

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Database + Performance.

Evidence: [app/Services/BirCompliance.php:155-161](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:155); [app/Services/IssuedDocumentLedger.php:25-87](/Users/marksioson/Desktop/NUERS/app/Services/IssuedDocumentLedger.php:25); [app/Http/Controllers/Api/OperationsReportController.php:15-25,101-138](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:15); [database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19-22](/Users/marksioson/Desktop/NUERS/database/migrations/2026_10_16_000001_prepare_eis_document_readiness.php:19).

Assessment / remaining gap / remediation: Profile global audit-head/merchant/registry locks, correlated scans/window identities and all-row PHP report aggregation; define composite indexes and plans on representative data.

Acceptance (proposed, not run): Staging EXPLAIN/lock/IOPS/p95 metrics and concurrent sequence tests show bounded memory, no unexplained deadlocks and target durable throughput.

### H05 - Queue durability fairness poison handling and backpressure

**FAIL / Critical.** Source: `NUERS_DESIGN`. Owner: Delivery Platform.

Evidence: [config/queue.php:16-44](/Users/marksioson/Desktop/NUERS/config/queue.php:16); [routes/console.php:14-36](/Users/marksioson/Desktop/NUERS/routes/console.php:14); `app/Jobs NOT FOUND`; [app/Services/EisDocumentReadiness.php:192-195](/Users/marksioson/Desktop/NUERS/app/Services/EisDocumentReadiness.php:192).

Assessment / remaining gap / remediation: Implement real broker/worker contract and outbox lease, reaper, DLQ, per-tenant fairness, backlog bounds and age alarms.

Acceptance (proposed, not run): Crash/replay/poison and overload tests preserve intents and audited state; fair retry recovery without creating duplicate invoices.

### SEC01 - SQL injection parameterization and identifier allowlists

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Application Security.

Evidence: [app/Http/Controllers/Api/TableController.php:22-29,288-317](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:22); [app/Http/Controllers/Api/TransactionWorkspaceController.php:64-90](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:64); [database/sql/fix_tax_graph_edges_live_import.sql:11-25](/Users/marksioson/Desktop/NUERS/database/sql/fix_tax_graph_edges_live_import.sql:11).

Assessment / remaining gap / remediation: Bound values and static raw SQL dominate; no confirmed request-to-unbound-SQL sink identified. Generic filter/order identifiers lack explicit per-table allowlists; add validation and isolated negative/SAST coverage.

Acceptance (proposed, not run): Every raw sink traced to bound values or trusted identifiers; malformed columns/sorts return422 and no SQL errors; authorized DB-driver-specific injection tests pass.

### SEC02 - All form fields server schemas validation allowlists

**FAIL / High.** Source: `NUERS_DESIGN`. Owner: Application + QA.

Evidence: [app/Http/Controllers/Api/AuthController.php:58-82](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:58); [app/Http/Controllers/Api/TableController.php:40-83,124-153](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TableController.php:40); [app/Services/IntegrationContract.php:12-34](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12); [app/Http/Controllers/Api/InvoiceCenterController.php:157-170](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:157).

Assessment / remaining gap / remediation: Harden nested meta/items, generic account types/status/length/range and request-size limits; independently model VAT classification vs price basis; enforce required fields/state semantics. Expense tax totals and claim/validation statuses are currently accepted without independent authoritative verification.

Acceptance (proposed, not run): Unknown nested privilege/identity fields rejected; malformed/oversized/NaN/type-confused data fail without writes; server and UI show field-specific errors.

Additional source-confirmed expense evidence: [MerchantExpenseController.php:16](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantExpenseController.php:16), `store`52-125, `resolveMerchant`127-168 and `syncFromTaxIntelligence`217-226. Anonymous/invalid-token resolution can select another taxpayer; do not execute these handlers during a read-only audit.

### SEC03 - Tenant/object authorization on every endpoint/export/job

**FAIL / Critical.** Source: `NUERS_DESIGN`. Owner: IAM + Security.

Evidence: [routes/api.php:89,126,127,134-135](/Users/marksioson/Desktop/NUERS/routes/api.php:89); [app/Http/Controllers/Api/RdoController.php:24-103,249-269](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/RdoController.php:24); [app/Http/Controllers/Api/DashboardController.php:22-35](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/DashboardController.php:22); [app/Http/Controllers/Api/TransactionController.php:19-69](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionController.php:19); [app/Http/Controllers/Api/BusinessAccountController.php:189-213](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:189).

Assessment / remaining gap / remediation: Central deny-by-default middleware and verified membership required; close unguarded legacy routes and unverified name/TIN fallbacks; review broad administrative exports. Merchant expense resolution falls back to a default taxpayer without auth; both POST upsert and GET synchronization must be protected and GET made side-effect free.

Acceptance (proposed, not run): Anonymous/wrong-role/cross-tenant calls to every registered method fail before side effects; newly registered account cannot claim existing taxpayer identity.

Additional source-confirmed expense evidence: [MerchantExpenseController.php:16](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantExpenseController.php:16), `store`52-125, `resolveMerchant`127-168 and `syncFromTaxIntelligence`217-226. Anonymous/invalid-token resolution can select another taxpayer; do not execute these handlers during a read-only audit.

### SEC04 - Authentication session MFA CSRF and credential lifecycle

**FAIL / Critical.** Source: `NUERS_DESIGN`. Owner: IAM.

Evidence: [app/Http/Controllers/Api/RdoController.php:24-74](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/RdoController.php:24); [app/Http/Controllers/Api/AuthController.php:30-90](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:30); [app/Services/DeviceSession.php:24-40](/Users/marksioson/Desktop/NUERS/app/Services/DeviceSession.php:24); [src/lib/session-store.ts:1-7](/Users/marksioson/Desktop/NUERS/src/lib/session-store.ts:1); [config/session.php:21-204](/Users/marksioson/Desktop/NUERS/config/session.php:21); [routes/web.php:6-8](/Users/marksioson/Desktop/NUERS/routes/web.php:6).

Assessment / remaining gap / remediation: Remove anonymous password reset/provisioning; enforce privileged MFA, token hashing/expiry/revocation/step-up and policy on every request; preserve OAuth state/session protections.

Acceptance (proposed, not run): Anonymous RDO cannot change credentials; stolen/expired/suspended token rejected everywhere; MFA step-up on privilege changes; OAuth state and CSRF boundaries tested.

### SEC05 - XSS encoding CSP CSV and export safety

**PARTIAL / Medium.** Source: `NUERS_DESIGN`. Owner: Frontend + Security.

Evidence: [src/components/ui/chart.tsx:95](/Users/marksioson/Desktop/NUERS/src/components/ui/chart.tsx:95); [src/lib/session-store.ts:5](/Users/marksioson/Desktop/NUERS/src/lib/session-store.ts:5); [app/Http/Controllers/Api/InvoiceCenterController.php:95-109](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:95); [app/Http/Controllers/Api/LiveMonitoringController.php:174-180](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LiveMonitoringController.php:174); `public/.htaccess:1-25`.

Assessment / remaining gap / remediation: React text escaping/CSV neutralization exist; establish CSP/security headers and verify chart CSS accepts only trusted config; review all legacy export paths and token exposure.

Acceptance (proposed, not run): Stored/reflected payloads render as text; every export neutralizes formulas including leading whitespace; deployed CSP/headers and rich-text rules verified.

### SEC06 - Safe uploads downloads MIME content size storage auth

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Storage + Security.

Evidence: [app/Http/Controllers/Api/ClientProfileController.php:81-105](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/ClientProfileController.php:81); [app/Http/Controllers/Api/BusinessAccountController.php:92-124](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:92); [app/Http/Controllers/Api/IntegrationWorkspaceController.php:158-177](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:158); [config/filesystems.php:31-59](/Users/marksioson/Desktop/NUERS/config/filesystems.php:31).

Assessment / remaining gap / remediation: Image MIME/2MB and public-cert parsing/128KB checks exist; use safe normalized/re-encoded image extensions, malware handling, private evidence storage and authorized expiring downloads. Public branding assets may remain intentionally public.

Acceptance (proposed, not run): Polyglot/oversize/extension mismatches rejected; evidence not public; failed DB/object commit cleaned or tracked; cert trust/validity independently checked.

### SEC07 - Webhook signatures replay protection SSRF and egress

**FAIL / High.** Source: `NUERS_DESIGN`. Owner: Integration Security.

Evidence: [app/Http/Controllers/Api/IntegrationWorkspaceController.php:98-101,207-246](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:98); [app/Http/Controllers/Api/LocationController.php:54-64](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/LocationController.php:54); `app/Jobs NOT FOUND`.

Assessment / remaining gap / remediation: Disabled callbacks are not implemented signed/replay-safe delivery. Before enabling, resolve/validate destinations, constrain redirects/private metadata ranges and pin egress policy; bound public lookup inputs. No active arbitrary-URL SSRF is asserted.

Acceptance (proposed, not run): Bad signatures/replay and DNS-rebinding/redirect/private-range cases rejected in isolated tests; no third-party network calls during audit.

### SEC08 - Verified edge DDoS WAF rate budgets and origin shielding

**NOT VERIFIED / High.** Source: `NUERS_DESIGN`. Owner: Infrastructure Security.

Evidence: `public/.htaccess:1-25`; [routes/api.php:35-41,60-61,88](/Users/marksioson/Desktop/NUERS/routes/api.php:35); [app/Http/Controllers/Api/IntegrationTransactionController.php:324-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:324); `no provider/firewall/WAF config supplied`.

Assessment / remaining gap / remediation: Obtain provider L3/L4 mitigation, WAF/L7 policies, origin ACLs and shared application quotas; restrict management/DB/storage networks and verify TLS. Local config cannot prove deployed defenses.

Acceptance (proposed, not run): Redacted deployed edge/origin evidence reviewed; authorized non-disruptive staging checks confirm budgets, proxy/IP trust and origin shielding; volumetric tests require separate provider approval.

### SEC09 - Secrets encryption managed keys rotation redaction

**PARTIAL / High.** Source: `NUERS_DESIGN`. Owner: Security + Platform.

Evidence: [app/Http/Controllers/Api/MerchantApiKeyController.php:168-200](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:168); [app/Http/Controllers/Api/IntegrationWorkspaceController.php:138-175](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:138); [app/Services/EisDocumentReadiness.php:192-195](/Users/marksioson/Desktop/NUERS/app/Services/EisDocumentReadiness.php:192); [app/Http/Controllers/Api/IntegrationTransactionController.php:338-366](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationTransactionController.php:338); [src/lib/session-store.ts:5](/Users/marksioson/Desktop/NUERS/src/lib/session-store.ts:5).

Assessment / remaining gap / remediation: API keys hashed/secrets+outbox encrypted; human bearer tokens plaintext/no expiry; no vault/KMS custody/rotation evidence; redact at ingestion and secure key backup. Local .env permissions600 do not prove production.

Acceptance (proposed, not run): DB/log export has no usable bearer/PII/secret; key rotation/recovery preserves required ciphertext; environment keys separated; access audit and vault controls verified.

### SEC10 - Vulnerability management patching assessment and incidents

**PARTIAL / High.** Source: `NUERS_DESIGN;SUPPLIED_RMC_110_2026`. Owner: Security Assurance.

Evidence: `composer.lock`; `package-lock.json`; [app/Http/Controllers/Api/EspComplianceController.php:108-137](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:108); `.github/workflows NOT FOUND`; [src/pages/soc/operations.tsx:15-48](/Users/marksioson/Desktop/NUERS/src/pages/soc/operations.tsx:15).

Assessment / remaining gap / remediation: Version locks and incident evidence exist; add SBOM/SCA/SAST, independent authorized VAPT, patch SLAs, real alerts/tabletops and assessment custody. No scanner/exploit executed.

Acceptance (proposed, not run): Dated assessor evidence and remediation retests, signed SBOM/current advisory checks, routed drill and incident-report evidence; static SOC rows are not assurance.

## Regulatory/source boundaries

Supplied RMC110-2026 is used as the supplied accreditation baseline, subject to independent official publication/applicability verification. The internal complete system specification and audit brief supply design requirements, including 1-5 second merchant sync, <2 second durable acceptance and capacity targets; those values are not claimed BIR mandates. Exact API endpoints, JSON fields, cryptography, response codes and status semantics remain separately unverified. See [regulatory dependency register](NUERS_REGULATORY_DEPENDENCIES.md) for original hashes, official-source lookups and conflicts.

## Stop boundary

This audit makes no remediation, credential, configuration, database or deployment change. Proposed phases, affected paths and acceptance tests are reviewable in the remediation plan. Separate owner approval is required to start implementation and separate explicit staging authorization is required before executing security/load/failover tests.

## Audit snapshot identity and read-only verification

A sorted manifest of 325 audit-relevant first-party source/config/migration/test/frontend/view/deployment/lock files was hashed as `relative_path + space + SHA256(file)` joined by newlines (no trailing newline). Aggregate SHA256: `55d3465615ffbbf1c874ee50a4586f20cdd9e19247d33d703c790e313a2a355c`. This fingerprints the inspected dirty tree, not a Git commit or regulated approval. Original source-document hashes are in the dependency register.

Final baseline comparison: SHA256 hashes of all 33,227 pre-existing non-audit files indexed by Git tracked/unignored discovery remained unchanged; no new non-audit project files were created. Temporary report-authoring scripts/manifests live only under `/tmp`. Only the seven requested `docs/audits/` files were added. This source hash comparison does not claim to fingerprint the running database; no database connection was made.

## Approved remediation follow-up

The current finding dispositions F01–F16 and evidence are in the remediation re-audit. Critical public RDO/financial/identity paths were repaired. Official interoperability, real documentary evidence and operational assurance remain blocking.
