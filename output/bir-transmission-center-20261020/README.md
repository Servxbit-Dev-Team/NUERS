# BIR Transmission Center release evidence

Release: `2026-10-20.bir-transmission-center.1`
Deployed: 20 October 2026, 3:11:43 PM PHT.
Target: https://nuers.net/super-admin/eis-transmissions

## Delivered

- Dedicated company document workspace, searchable company and detailed filters, peso totals, pagination, selection and filtered CSV.
- Frozen previews, up to 100 documents per company batch, durable background preparation, per-document results, idempotency, source-change protection and audit notes.
- Local outbox and delivery-job visibility, reporting coverage and response evidence, separate production/certification history.
- Permission/MFA boundaries and production adapter gates retained.
- Operator documentation: `docs/knowledge/bir-transmission-center.md`.

## Verification

- PHP regression suite: **95 tests, 844 assertions**, including indexed/legacy ledgers, company isolation, duplicate preparation, source changes, expiry, invalid sources, date boundaries and interrupted batch recovery.
- `npm run build:all`: passed. Existing bundle-size warning remains.
- Synthetic browser workflow: six-document company batch processed with five locally valid and one needing source review; actual delivery remained disabled.
- Layout checked at 1440px and 760px; viewport override restored.
- Local additive migration applied.
- Live installer: all 27 file hashes verified, immutable financial/identity record hashes unchanged, MFA and environment preserved, private rollback archive saved.
- Live read-only verification: 4,337 canonical documents; company filter, document detail, readiness blockers and multi-selection reviewed in browser. Golden Bay filter returned 2,834 documents. No production batch or transmission was submitted.
- Existing Plesk scheduler retained as `httpdocs/artisan schedule:run --no-ansi`; the one-time installer form was cancelled after Run Now.

See `tests-final.log`, `build.log`, `deployment-result.json`, `live-workspace.png` and `qa-batch-results.png`.

## Remaining external dependency

Actual BIR transmission is **not enabled**. The official API contract, verified adapter, credential/certificate provisioning, company/branch authority, reporting coverage and certification are still required. Locally prepared status does not mean BIR accepted. Release gates cannot be bypassed by this UI.
