# Local and Live Database Workflow

NUERS runs at **http://localhost:8001** from this checkout.

## Approved security and workflow remediation — 16 October 2026

The local schema now has **87 tables and no pending migrations** after four further reviewed migrations (000004–000007). The last migration replaces the expense number/TIN unique index with a tenant/original-source ID index; existing rows are preserved without inferred backfill. The latest backup is `.local/backups/NUERS_before_expense_source_provenance_20261016_234429.sql`. Original-column fingerprints matched before/after the workflow and expense provenance migrations; the security migration intentionally revoked legacy sessions. All **13 original financial transactions / PHP 750,400.00** remain preserved. Forty-eight encrypted preparation versions pass hash verification; 16 current preparations have blocked delivery jobs.

Authentication, membership, MFA, versioned intake/sequence/idempotency, review/hold/portability, upload, dependency and operational-evidence controls were updated locally. The official BIR adapter and all activation flags remain blocked. No live deployment or external BIR/webhook message was performed. Read the [current remediation re-audit](../docs/audits/NUERS_REMEDIATION_REAUDIT_2026-10-16.md) and [merchant contract](../docs/integrations/merchant-ingestion-1.md) before integration work. Earlier database/test snapshots below are historical.

## EIS preparation update — 16 October 2026

Three reviewed additive EIS migrations now bring the active localhost database from **73 to 76 tables**, with **zero pending migrations**. They add reporting obligations, encrypted/versioned internal document preparation and append-only historical branch-evidence reviews. Every original table's original-column row fingerprints matched before and after each migration; all 13 transactions and PHP 750,400.00 of successful payment amounts remain preserved.

Private backups precede each migration; the latest is `.local/backups/NUERS_before_eis_branch_evidence_20261016_215642.sql`. Verification reports are `.local/eis-readiness-fix-20261016/database-migration.json`, `database-migration-v2.json`, `database-migration-v3.json` and `reaudit.json`. Full isolated regression suite: **114 tests, 1,290 assertions**. Both frontend builds passed.

The 16 canonical source documents remain Not Submitted, with absent historical branch evidence and three inconsistent imported invoice records explicitly flagged. No approvals or coverage records were fabricated; the user confirmed this is accreditation preparation. Official BIR transport/interface/response-verification gates remain false. No live-server changes or BIR submissions were performed. See [remediation and re-audit](../docs/knowledge/eis-readiness-remediation-and-reaudit-2026-10-16.md) for scope and remaining requirements. Earlier dated snapshot/schema sections below are historical.

## Current schema update — 15 October 2026

The active checkout was verified against **NUERS on 127.0.0.1:3307**. Twelve reviewed migrations, `2026_10_15_000004` through `2026_10_15_000015`, were pending in this database and have now been applied. This adds **16 tables**, bringing the active database from **57 to 73 tables**, with no pending migrations.

The additions cover subscription plans; business registration, email verification, address and industry snapshots; support conversations/messages/events; reconciliation reviews; business registry workflows and RDO mappings; taxpayer authorizations/devices/connection observations; and integration connections/endpoints/webhooks. Existing merchant registry and user device-session fields were also added. Existing EIS configuration metadata was copied into the integration workspace as draft, without inferring approval or connectivity.

A full private SQL backup was saved before migration at `.local/backups/NUERS_before_table_updates_20261015_212446.sql`. Row fingerprints over the original columns of all **56 existing data tables** matched before and after, including all **13 financial transactions**. The migration ledger changed as expected. Verification details are stored in `.local/database-table-update-20261015.json`.

Post-update checks: **89 tests passed, 1,070 assertions** in isolated test databases. The running localhost health, registration-options and subscription-plan endpoints returned valid successful JSON; registration options include 124 RDOs and two published subscription plans. These changes were applied to localhost only.

Restart the application and its isolated local database:

```bash
bash .local/start.sh
```

PHP and MySQL must be available on `PATH`; `.local/start.sh` starts the isolated MySQL runtime on `127.0.0.1:3307`, using `.local/mysql` and `.local/mysql.sock`. Confirm the active `.env` and migration status before applying later updates; older snapshot notes below describe the earlier import workflow.

## Plesk snapshot

A fresh export of the `NUERS` database for `nuers.net` was created through Plesk on **15 October 2026 at 09:47 Asia/Manila**.

- Original export: `.local/backups/NUERS_2026-10-15_09-47-30.sql.zip`.
- Extracted SQL: `.local/backups/NUERS_2026-10-15_09-47-30.sql`.
- Active local database: `NUERS`.
- Unmodified local reference database: `NUERS_snapshot_verify`.
- Verification: `.local/snapshot-verification.json`.
- HTTP checks: `.local/smoke-results.json`.

The initial import matched all **52 source tables and 631 rows** by normalized row content, including duplicates. The current verification compares the original columns, excludes the three new migration entries, and excludes `users.api_token` and `users.updated_at` because one local sign-in rotated a session token and its timestamp. Source record counts, passwords, roles and financial records remain preserved. No seeders were run.

Two pending migrations were then applied locally:

- `2026_10_15_000002_add_rmc110_compliance_controls`: adds `esp_accreditations`, `esp_accreditation_controls`, `eis_operational_incidents`, `eis_audit_head`, and `eis_audit_events`, plus integration and transmission columns.
- `2026_10_15_000003_snapshot_invoice_issuance_authority`: adds permit and accreditation columns to invoices and receipts.

The compliance migrations produce **57 tables**. The later website subscription migration adds `website_subscription_plans`, bringing the local total to **58 tables**. The business registration migration (`2026_10_15_000005_create_business_registration_records.php`) adds `business_registrations` and `registration_email_verifications`, bringing the current local total to **60 tables**. The original Plesk database schema and records were not modified. This is an independent snapshot; local writes do not synchronize to Plesk.

## Local configuration

The git-ignored `.env` contains local database credentials, a fresh valid application key, local URLs, file sessions/cache, synchronous queues, and mail logging. Password hashes and account roles from Plesk are preserved; sign in with existing NUERS email/password credentials. Google OAuth credentials are not configured in this checkout, so Google sign-in requires separate configuration.

Private database files, exports, reference data, verification reports, and runtime logs live under the git-ignored `.local/` directory. The database admin configuration `.local/mysql-admin.cnf` and `.env` have owner-only permissions. Do not commit these files or copy local settings to production.

This operation copies the database. It does not refresh uploaded files from Plesk; existing assets in the checkout remain available.

## Validation

- `npm run build` passed.
- `php artisan test` passed: **57 tests, 463 assertions**, using a separate in-memory test database.
- Every migration reports `Ran`.
- Home, login, API health, session, login background, and dashboard header returned HTTP 200.
- The homepage and sign-in page were opened in the browser to verify rendering.

The environment uses PHP 8.5.10 and MySQL 9.5.0. Plesk's MariaDB export imported successfully. Preserve local changes before taking a later snapshot; do not overwrite the active local database just to restart the app.

Registration address follow-up: migration `2026_10_15_000006_add_registration_address_details.php` adds a nullable JSON address snapshot to `business_registrations` (still 60 local tables). Public geographic selectors use bundled PSGC Q1 2026 data; mapped RDOs are validated on the server. No Plesk writes or deployment.

Industry/legal/support follow-up: migrations `000007_add_registration_industry_details` and `000008_create_support_chat_tables` were applied locally. Industry descriptions are snapshotted; support adds three tables, bringing the local total to **63 tables**. Legal pages use the supplied NUERS operator name/email, and policy version `2026-10-15.2` is stored on new business registrations. See `docs/knowledge/legal-industries-and-support.md`.
