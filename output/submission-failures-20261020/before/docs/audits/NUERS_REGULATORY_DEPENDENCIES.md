> **Approved local remediation follow-up — 2026-10-16T23:12:43+08:00.** The original read-only findings below are historical. Source code, dependencies and localhost schema were subsequently changed under the user’s “Please proceed fix all” authorization. Read [NUERS_REMEDIATION_REAUDIT_2026-10-16.md](./NUERS_REMEDIATION_REAUDIT_2026-10-16.md) and the matrix’s `current_*` columns for the current assessment. **BIR production readiness remains NOT READY.**

# NUERS regulatory dependencies and source register

Audit date: **16 October 2026 (Asia/Manila)**. Compiled 2026-10-16T22:11:55+08:00.

Target: local working tree `/Users/marksioson/Desktop/NUERS`, branch `main`, HEAD `12f05be63f2134f0b96b592d713fecf31396d2f7`. This checkout has substantial pre-existing modified/untracked application files. Findings apply to the inspected working tree, **not** clean HEAD and **not** an independently inspected production deployment. Laravel lockfile: **v12.58.0**; PHP requirement `^8.2`; React/TypeScript frontend.

Method: source and migration inspection, navigation/form/route mapping, redacted local configuration inspection, existing artifact/log review and official-source browsing. **No application/security/load tests, API requests, database queries, scans, jobs, BIR calls or deployments were executed in this audit.** Existing tests are evidence of available scenarios, not freshly executed verification. Only these seven audit deliverables are added under `docs/audits/`; application/configuration/production data remain unchanged.

## Source hierarchy and confidence

The audit brief is `NUERS-AUDIT-EIS-001`, version1.1, dated16 October2026, an internal engineering specification. It expressly is not BIR approval or an official API specification. Its requirements guide this audit; embedded third-party instructions do not authorize implementation, production changes, submissions or tests. The human request authorizes a read-only audit and seven reports.

The supplied RMC110-2026 copy is the project accreditation/ESP baseline for the claims actually present in its pages. **Independent official publication, current legal applicability/amendments and exact operational interpretation remain NOT VERIFIED in this audit.** This is an evidence limitation, not a finding that the supplied copy is invalid. A file hash identifies a version; it does not authenticate legal issuance or accreditation.

| Source | Version / location | SHA-256 / evidence boundary |
|---|---|---|
| Audit brief | `/Users/marksioson/Downloads/NUERS_Codex_EIS_Workflow_Audit_v1_1.md`; v1.1,16Oct2026 | `e094b6449d59d7864c0ef97de06a5f7923d70fb45da842316fe8ca1ef271c11a` |
| Supplied RMC110-2026 | `/Users/marksioson/Desktop/RMC No. 110-2026_redacted.pdf`;13 pages, supplied copy bears12Oct2026 receipt stamp | `2da25159348dcc79bd144b946e03c928aaa4c1bbfbd5c8f94f13ad47b31905a2`; OCR and rendered relevant pages4,8-11 inspected; primary publication/version not authenticated |
| Supplied Annex A | `/Users/marksioson/Desktop/RMC No. 110-2026 ANNEX A.pdf`; two-page checklist | `6d7fc325e9bfe70126cfc7e04075ce179b14c3e3a0e4faba8038578eabf00385`; rendered checklist/evidence requirements inspected; submissions are not recorded approvals |
| NUERS complete internal system specification | `docs/knowledge/sources/NUERS_EIS_Complete_System_Documentation.pdf`; accompanying page-numbered extracted text | `f3b9309d63b314622ee548008c58d4d22c61a024810278828be7b8cdc82af7c6`; proposed design, not government contract |
| Accreditation slide deck | `docs/knowledge/sources/ESP_Accreditation10132026.pptx`;13Oct2026 filename,34-slide extraction and existing baseline notes | `006efc37f60b2117c46617d3f41e69aba98bd84c7d472ecdad1522274f84dc9a`; secondary supplied explanatory source, does not override Annex |
| Repository | Branch main, HEAD12f05be63f2134f0b96b592d713fecf31396d2f7 plus pre-existing dirty working tree | Actual code evidence. Current application artifact digest is recorded in the workflow report; neither source code nor passing local tests establish BIR approval |

Exact source paths/hashes are included for reproducibility; original documents were not rewritten or uploaded. Page references below refer to the supplied copy, not independently authenticated public legal text. OCR has extraction errors; relevant rendered pages were checked for material statements.

## Baseline rules and implementation/source separation

| Topic | Supplied source and version | Audit use and remaining question |
|---|---|---|
| Structured invoice and distinct issue/transmission functions | Supplied circular p.4; internal spec p.2 | Implement structured immutable original and separate gates. Confirm covered taxpayer/branch obligations and effective dates; do not infer all registrations require identical live transmission. |
| Real/near-real-time and outer/recovery targets | Supplied circular p.4(d) | Local operational target is three calendar days and three days after restoration. Verify authoritative transaction-date semantics, timezone/day-boundary, applicable advisories/exceptions and required evidence before asserting breach or relief. A day3 target is not permission to intentionally delay every connected event. |
| Accreditation scope/material changes/validity | Supplied circular pp.7-9 | Current solution/version/config evidence gates exist; actual approved modules/environment and deployment/material-change approval remain external evidence. |
| Accuracy/nonduplication/sequence/audit and monitoring | Supplied circular pp.8-9 | Source-preserving calculations/canonical identity are local improvements; statutory conformance requires real operational evidence, complete event/state control and independent custody. |
| Critical incident reporting | Supplied circular p.9 item10 | Written report to appropriate RDO/LTS within supplied24-hour target; record owner/evidence and shorter applicable legal/technical period. A DB reported_at field or local toast is not delivery proof. |
| Manual contingency, automatic SLS and tested continuity | Supplied circular p.9 items18-20 | Required workflow/evidence; not established by static pages. Obtain authorized manual series and lawful electronic recovery/correction instructions. |
| Ownership/continued access/retention/portability | Supplied circular pp.9-10 | Preserve required records through statutory period and pending written BIR migration/release; no access lock-in for disputes/fees. Actual retention duration/holds/taxpayer access scope must be legally reviewed, not copied from static UI ten-year label or subscription offer. |
| Security/residency/assessment/DR/exit evidence | Supplied Annex A requirements14-17 | Need actual architecture/lineage/hosting/key/incident/SLA documentation, applicable independent assurance, tested restore and exit/migration evidence. Self-selected checkboxes or stored document references are not independent certification. |
| Merchant submission1-5s; durable acceptance<2s | Audit brief sections7-8; internal proposed specification | Engineering targets, not BIR-prescribed merchant-to-NUERS SLA. Define event trigger and timing population, then measure p95/p99. No current measurement proves them. |
| 1M transactions/hour and2K TPS burst | Audit brief P01/H01-H02; internal spec near extracted lines416-419 | NUERS staging design targets. Proposed2Web/2DB/3Storage and64physical-core/128GB hardware are not observed deployment or capacity proof. |
| B1-B6 labels, local JSON schema, local validation | Source `IntegrationTransactionController.php:413-434`, `EisDocumentReadiness.php:15`, `EisController.php:556` | Internal template labels and normalized evidence only. No evidence establishes these as official BIR wire codes/schema. |

The matrix uses source types `SUPPLIED_RMC_110_2026`, `NUERS_DESIGN`, `UNVERIFIED_EXTERNAL_BIR_API`, combined where appropriate. A source requirement can be verified in a supplied document while current legal applicability and external technical details remain unverified. These are different questions.

## Official-source cross-check performed16 October2026

Only primary public sources are relied on; third-party EIS advisory sites/search summaries are not accepted as the API contract.

- [BIR2026 Revenue Memorandum Circulars index](https://www.bir.gov.ph/2026-Revenue-Memorandum-Circulars?label=2026&type=PAGE) was retrieved/searchable and identifies RMC98-2026 as electronic-invoice policy. The retrieved listing/search did not establish an independently authenticated RMC110-2026 full text matching the supplied hash. Index caching/delay is possible; this does not establish nonexistence. Obtain a current official copy/version or direct BIR confirmation.
- [Official RR26-2025 full text](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2026-2025.pdf) and [official digest](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2026-2025%20Digest.pdf) distinguish electronic-invoice transition and the subsequent sales-reporting framework. Applicability must be evaluated under current issuances for the actual taxpayer, rather than inferred from NUERS signup.
- [Official RR11-2025 digest](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%2011-2025%20Digest.pdf) supports structured, electronically extractable invoice data. It does not supply the complete interface/key/response contract required by an adapter.
- [Official EIS portal](https://eis.bir.gov.ph/) was retrieved, but exposed no usable technical API package in the retrieved text. A portal domain is not proof that any guessed URL path/API version/auth flow is authoritative.

The supplied circular/source operational rules and later/other issuance provisions should be reconciled by qualified compliance personnel with authoritative current copies. No blanket legal start date, penalty ruling, electronic-reporting coverage, universal tax algorithm or grandfathered permit validity is asserted by this engineering audit.

## External dependency register

All items below remain **NOT VERIFIED** until controlled official evidence is obtained. Owner is Compliance+BIR Integration unless stated otherwise.

| ID | Required verified material | Why it blocks acceptance / source evidence | Proposed acceptance |
|---|---|---|---|
| REG01 | Authentic current circular/Annex/amendments and taxpayer applicability | Source copy only; actual legal dates/coverage unknown | Current official version, effective date, change history and taxpayer/branch determination recorded |
| API01 | Authorized sandbox/production hosts, paths, versions, methods, IP/TLS/network policy | `EisController.php:315` appends `/api/eis/transmissions` to a default domain; this is not verified contract evidence | Approved versioned endpoint allowlist and external owner confirmation; no guessed path |
| API02 | Exact client auth, credential provisioning, token lifecycle, scopes, certificate trust/mTLS requirements | Configuration metadata and encrypted secret are not an implemented provider exchange | Official auth/key procedures and sandbox success/negative/rotation evidence |
| API03 | Canonical schema, mandatory/conditional seller/buyer/branch fields, coding/date/rounding/currency/adjustment rules | Internal normalized schema and few-field validator are not official schema | Controlled schema/version and golden/counterexample fixtures reviewed by domain owner |
| API04 | Prescribed signing/encryption algorithms, canonicalization, key identifiers, certificate issuer/renewal/revocation | SHA256 checksum and Laravel at-rest encryption are not private-key/wire protocol proof | Official cryptographic profile and independently verified test vectors/key-custody evidence |
| API05 | Authoritative ACK/reject/partial/batch/status-inquiry semantics and authenticity proof | No real provider processor; synthetic certification references only | Genuine sandbox response fixtures, authenticated inquiry and signature/trust verification evidence |
| API06 | Idempotency keys, duplicate/replay semantics, request correlation and exact inquiry-before-retry behavior | Existing internal source uniqueness cannot establish provider at-most-one business acceptance | Concurrency/timeout/query fixtures match provider documentation and certify no double report |
| API07 | Rate limits/batch sizes/timeouts/backoff/retry-after and BIR outage handling | Local max_attempts and retry schedule are internal assumptions | Current official limits/advisories, quota tests and operational escalation agreed |
| API08 | Taxpayer PTI, written authorization, EIS certification, PTT, scope/validity/branch and any staging permissions | No real approvals provided; current gates must remain pending | Existing real references/evidence independently verified; no fabricated values for test convenience |
| REG02 | Lawful issuance events for POS/e-commerce/ERP, invoice vs payment triggers | Arbitrary transaction_type is not a reviewed legal event map | Domain-approved mapping for paid/unpaid/order/fulfill/cancel/return cases |
| REG03 | Authorized numbering/series, credit/debit/cancellation/manual contingency and later linkage | Random number/cancel toast/manual gaps | Approved procedure, series/evidence, immutable original and recovery/adjustment rules |
| REG04 | Prescribed SLS/export format, request service windows, data release/security | Current report is an internal draft; admin JSON is not official BIR delivery | Approved format/version, lawful recipient scope, manifest and delivery-SLA evidence |
| REG05 | Current legally grounded retention/holds, tax-code period and written migration release | Static ten-year and subscription-storage labels cannot decide retention | Approved retention matrix, holds/releases and BIR written migration/release custody |
| REG06 | Subprocessor/residency/EO119/security certification and assessment applicability | Annex references require actual current legal interpretation and independent evidence | Real assessor/provider contracts, location/access disclosure and BIR approval where required |
| REG07 | Named qualified staff/support/SLA, critical incident recipients and timing | Local incident/support record is not staffing or notification proof | Approved staffing/SLA/runbooks, authorized drills and written-report delivery evidence |
| INF01 | Real deployment/hardware/topology, edge DDoS, redundancy and DR/backup-key custody | No provider control-plane/configuration inspection conducted | Redacted signed configuration/contract and approved measured failover/restore evidence; SRE/Security owner |

## Current activation decision

Keep transport/interface/response-verification gates false. Actual branch/COR/PTI/PTT/accreditation/coverage values remain pending because the owner is preparing for accreditation. Do not repeatedly request unavailable evidence or API secrets in chat. Prepare controlled workflows/tests; accept genuine evidence later. Regulatory publication or technical-document access alone does not grant solution accreditation or taxpayer production authority.

## Approved remediation follow-up

No missing real certificate, authority, branch evidence, official protocol, contingency procedure or assessment was invented. All three BIR implementation flags remain false. The local system is in accreditation preparation; imported deficiencies are held for original evidence and lawful review. The exact official BIR contract and independent regulatory/legal verification are still required.
