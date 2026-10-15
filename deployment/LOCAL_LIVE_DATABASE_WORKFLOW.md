# Local and Live Database Workflow

NUERS runs at **http://localhost:8001** from this checkout.

Restart the application and its isolated local database:

```bash
bash .local/start.sh
```

PHP and MySQL must be available on `PATH`; the database runtime uses Homebrew's `/opt/homebrew/opt/mysql/bin/mysqld`. MySQL listens only on `127.0.0.1:3308`. Other local MySQL services on ports 3306 and 3307 belong to separate environments.

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
