# EIS readiness remediation and re-audit

16 October 2026, 21:57 PHT · localhost only · pre-accreditation preparation.

The local transaction/document integrity fixes are implemented and re-audited. **This is preparation for accreditation, not BIR accreditation, verified EIS interoperability or authorization for production transmission.** The user confirmed that branch/COR and approval evidence are not available yet. No substitute approvals, taxpayer coverage or branch values were invented.

The [original focused audit](transactions-bir-eis-audit-2026-10-16.md) and [broader document audit](document-alignment-audit-2026-10-16.md) remain preserved historical findings. This report supersedes their local transaction/control observations only where the fixes and checks below provide evidence. It does not close unrelated accreditation requirements.

## Finding disposition

| Finding | Implemented result | Re-audit / remaining dependency |
|---|---|---|
| T01 — official BIR delivery/schema | Independent production gates for transport, authorized-interface verification and authentic-response verification stay false. Local validators identify themselves as local preflight. | Pending authorized interface, code/field mapping, credential/key custody, prescribed cryptography, delivery/inquiry, authentic responses and end-to-end certification. A flag or credential alone cannot activate readiness. |
| T02 — missing issuance source | Canonical document population includes transaction receipts and non-draft business invoices, with preserved source IDs and linked payment counts/amounts. | Both sources exercised in isolated tests; actual local population is 16, versus the original 13 transaction rows. |
| T03 — mutable historical seller | Ledger/export/preparation use original seller/date snapshots. New issuance snapshots branch/RDO. Supplemental historical branch reviews are append-only, fingerprinted, revision-guarded and audited; original invoices/amounts remain unchanged. | Identity-edit tests pass. Actual historical branch evidence remains absent. Conflicting embedded TIN branch evidence is rejected. Source edits invalidate prior evidence. |
| T04 — import-date deadlines | Operational targets use original document dates and documented taxpayer coverage/effective periods. Unknown coverage does not assert overdue; original/recovery targets and incident context stay distinct. | Imported-date, coverage and revision cases pass. Actual coverage records: zero. Targets remain operational review aids, not legal penalty determinations. |
| T05 — mixed environment history | Production history takes precedence over later certification attempts; full per-environment attempt history remains available. | Fixtures pass. Generated certification references and unverified ACK rows cannot establish production acceptance. |
| T06 — duplicate sales | Unique seller-TIN/branch/document-family/number identity spans both sources and duplicate registry records. Split payments remain payment events. Conflicts, supplementary documents, unsupported adjustments and inconsistent source amounts are excluded from the internal sales draft. Traceable credit notes subtract and debit notes add. | Duplicate, split-payment, adjustment and malformed-source tests pass. The report remains an internal draft, not a validated official BIR SLS output. |
| T07 — submitted totals trusted | Shared integer-centavo calculation validates lines, discounts, VAT allocation, inclusive/exclusive prices and totals across invoice paths. Inconsistent submitted totals fail. Withholding uses the tax-exclusive base. | Tampering and rounding fixtures pass. Current calculator supports ordinary 12% VAT and explicit tax buckets; special statutory discounts/rates and other regimes require their own approved rules/tests before use. |

## Additional integrity and access fixes

The generic data API now requires authentication, known-role authorization and server-derived ownership. Anonymous reads/writes, cross-tenant access and generic financial writes are blocked. Sensitive profile/wallet/TIN updates cannot bypass their controlled workflows. Generic deletion cannot erase linked identities. The legacy merchant-profile path cannot overwrite regulated identity; reviewed registry changes invalidate dependent permit/certification state for review. These address the locally reproduced A01/A02/A03 gaps and the duplicate-population portion of A09; they are not an independent security assessment of the entire application.

Client checksums are identified as checksums rather than private-key digital signatures. The server does not trust a client-supplied signature as proof of BIR signing.

## Secure internal preparation

Issuance and its internal outbox preparation commit atomically; outbox failure rolls issuance back. The internal normalized document uses `NUERS-normalized-document-3`, encrypted with Laravel application encryption at rest, with a canonical SHA-256 hash and source identity. This encryption is **not** a claimed BIR wire encryption protocol. Application-key custody/backup remains an operational requirement.

Preparation is idempotent within each source/schema/evidence revision. Conflicting content is rejected rather than overwriting evidence. Earlier preparation versions are preserved. The administrative branch-evidence workflow prepares a new reviewed revision without rewriting the issued source. Local source checks, pending authorities and official API blockers remain separate.

The EIS Readiness panel is available from Invoice Center and Transactions. Merchant users see only their own sources; administrative historical-evidence review is role restricted; BIR review role is read-only. Coverage evidence can be maintained through the administrator-only readiness API. No actual coverage or branch-evidence records were created during this work.

`php artisan eis:prepare-pending --limit=100` recovers missing internal copies. Its schedule is defined every minute with overlap prevention. Deployment still needs the normal Laravel scheduler service/cron, monitored execution, key custody and backups. No BIR delivery worker or production endpoint was activated.

## Actual local data re-audit

| Check | Verified result |
|---|---|
| Schema | 76 tables; zero pending migrations |
| Original payment/transaction rows | 13; preserved |
| Original successful payment amount | PHP 750,400.00; preserved |
| Canonical issued source population | 16: 13 transaction-receipt sources and 3 business-invoice sources |
| Internal financially reconciled sales draft | 13 records; PHP 750,400.00 |
| Duplicate identity groups in current data | 0 |
| BIR status | All 16 Not Submitted |
| Current internal preparation copies | 16 |
| Earlier preparation evidence | 32 preserved copies; these are versions, not extra invoices |
| Prepared-content hash mismatches | 0 |
| Full local source checks passed | 0, because verified historical branch snapshots are absent |
| Historical branch reviews / coverage evidence / accreditation records | 0 / 0 / 0 |
| Production transport / official interface / authentic-response implementation | false / false / false |
| Application audit-chain check | Passed within the application/database trust boundary |
| BIR transmission rows | 1 existing historical row; no BIR delivery performed in this work |

All 16 source records lack a verified historical five-digit branch snapshot. Three imported business-invoice records have inconsistent stored amounts/tax. Two of those records are also supplementary documents; these counts overlap. They are flagged/excluded from invoice sales as applicable. Original amounts were preserved. The 13 receipt-path invoice records reconcile financially using original line data; derivation of absent legacy line totals is permitted only when the recomputed amounts, VAT and all source tax buckets exactly reconcile.

These source-review findings must remain visible until genuine evidence is supplied. They are not solved by assigning a default branch or recomputing and overwriting historical invoice amounts. An actual incorrect issued document requires a traceable correction/adjustment and original-document basis.

## Database preservation and verification

Three additive migrations were applied locally after private full SQL backups. The database grew from 73 to 76 tables: reporting obligations, encrypted internal outbox and historical branch-evidence reviews. Each guarded application compared fingerprints of every original data table on its original columns; all matched. No seed/reset, production database change or live deployment was performed.

Private migration evidence: `.local/eis-readiness-fix-20261016/database-migration.json`, `database-migration-v2.json`, `database-migration-v3.json`. Latest backup: `.local/backups/NUERS_before_eis_branch_evidence_20261016_215642.sql`. Keep SQL backups, local environment/key files and financial evidence private.

Verification completed:

- **114 tests passed; 1,290 assertions**, isolated test databases: `php -d memory_limit=512M vendor/phpunit/phpunit/phpunit --colors=never`.
- **Both frontend builds passed**: `npm run build:all` (TypeScript, Laravel assets and static assets). Existing bundle-size and runtime asset-reference warnings remain; the referenced local assets are present.
- Guarded real-MySQL re-audit used a read-only transaction and aggregate results.
- Authenticated browser verified scoped EIS Readiness, pending API/coverage, preserved source-review errors and secure-copy state. Screenshot: `.local/eis-readiness-fix-20261016/readiness-preview.png`. This browser session is merchant scoped (13 records); administrator/backend population is 16.
- Health endpoint succeeds; anonymous generic financial read returns 401.

Private test/build logs: `.local/eis-readiness-fix-20261016/full-tests.log` and `build.log`. Aggregate machine-readable result: [re-audit JSON](../../deployment/eis-readiness-reaudit-2026-10-16.json).

## Remaining accreditation work

When BIR access and documentary evidence become available, complete the authorized contract/schema/code mapping, signing/encryption/key handling, reliable delivery and inquiry, real response authentication, duplicate/replay handling and controlled end-to-end tests before enabling production. Record actual taxpayer authority, coverage, original branch evidence and solution accreditation through reviewed workflows.

The earlier broader audit's independent VAPT/security assessment, privileged-access/MFA, real backup/restore and disaster recovery exercises, retention/exit controls, operational monitoring, concurrency/load/scalability evidence and documentary stages remain separate acceptance work. Passing these application regressions does not establish that those assessments or BIR accreditation have been completed.
