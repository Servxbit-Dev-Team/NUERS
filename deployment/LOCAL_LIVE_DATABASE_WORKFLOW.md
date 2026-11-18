# Local and Live Database Workflow

## Current local refresh — AWS production, 14 November 2026

Localhost now runs the actual AWS production build from `/srv/nuers/releases/production-20261106-analytics-grouping2` in Singapore. Both production web nodes had identical source hashes. The earlier Plesk server is no longer authoritative for the public site.

The active local database is **`NUERS_aws_20261114`** on **127.0.0.1:3308**. The AWS RDS export ran from **15:13:30 to 15:15:30 Asia/Manila on 14 November 2026** using a consistent transaction. All **154 tables / 74,939 rows** matched by complete row content, with **48 matching triggers**, no routines and no scheduled events. The previous `NUERS_live_20261102` database remains intact. The old-server `NUERS_live_20261114` staging database is not the active AWS snapshot.

All **7,057 deployed files and uploaded documents** matched the server snapshot hashes. The exact live JavaScript and CSS entries are `main-DCofh3HT.js` and `main-mwrEibA1.css`; localhost serves those same production assets. Eight HTTP checks passed, the homepage rendered without browser console errors, all **331 PHP files** passed syntax checks, and samples from **17 populated encrypted columns** decrypted successfully without displaying values. PHP dependencies were installed from the AWS lockfile. Authenticated business workflows were not exercised.

**Frontend source limitation:** the deployed AWS release contains only 14 TSX source files, not the complete editable frontend source tree. Those files were overlaid on the existing development checkout; development-only files, tests, scripts and documentation were preserved. Production compiled assets are authoritative and were not rebuilt. Runtime parity is verified, but complete editable source parity cannot be certified. Recover the complete matching source release before running `npm run build`, because the retained older development sources could otherwise replace the verified production build.

The AWS-managed RDS administrator credential was used only within AWS for the read-only export and was neither printed nor copied locally. The original export remains unchanged. Its local import derivative removes the MariaDB client sandbox directive and 12 obsolete SQL-mode tokens, and binds 48 trigger definers to the local importer; row INSERT values are unchanged. No migrations or seeders ran. The approved APP_KEY is private and all local exports are Git-ignored. Runtime document storage is also excluded from Git.

Local URLs, database credentials, cache and sessions remain local. Email uses the log driver; external notification dispatch, cloud infrastructure execution, backup execution and database event scheduling remain disabled. No worker or scheduler was started. Temporary AWS export files, their three S3 versions, and both SSM database tunnels were removed after the local copy was verified. Live application files and database records were not changed.

Private rollback files and verification artifacts: `.local/backups/aws-live-sync-2026-11-14/`. Current report: `.local/aws-live-sync-verification-2026-11-14.json`. Restart with `bash .local/start.sh` and open http://localhost:8001. This is a dated independent snapshot, not continuous synchronization.

## Latest local refresh — 2 November 2026

Localhost was refreshed directly from the current live deployment at `/var/www/vhosts/nuers.net/httpdocs`. The active isolated local database is **`NUERS_live_20261102`**, on **127.0.0.1:3308**. The previous `NUERS_live_20261027` database remains intact.

Verification: **592 current live source files**, **3,145 asset/upload files**, **153 tables / 59,586 rows** with matching per-table row counts, and **48 triggers**. There were no stored routines or events in the complete export. All **300 PHP files** passed syntax checks, the frontend build passed, nine HTTP checks returned 200, and 14 populated encrypted-column samples decrypted successfully without displaying values.

The restricted runtime database account could not dump scheduled events. After explicit user approval, the complete read-only export used Plesk's existing administrator credential on the server only; that credential was neither displayed nor copied locally. The approved application encryption key was preserved privately for local snapshot compatibility. Original database export is unchanged. The local import copy removes the obsolete NO_AUTO_CREATE_USER SQL-mode token from 12 statements and binds 48 DDL definers to the local importer so triggers remain executable. Row INSERT values are not modified. Local database events and general notification delivery remain disabled; no worker or scheduler was started.

Current live remains authoritative. The prior local-only immediate registration OTP optimization is absent from live and was not reapplied. It remains in the pre-refresh source backup and `.local/otp-fix/`. Local URLs, credentials and build tooling intentionally differ from production; frontend assets were rebuilt for localhost. No live application files or database records were changed, and no Git commit/push was performed.

Private backups and detailed checks: `.local/backups/live-sync-2026-11-02/`. Final report: `.local/live-sync-verification-2026-11-02.json`. Previous `.env`, local source, and previous local database export are preserved. Restart with `bash .local/start.sh` and open http://localhost:8001. This is a dated snapshot, not continuous synchronization.

## Latest local refresh — 27 October 2026

The local checkout was refreshed directly from the deployed application in `/var/www/vhosts/nuers.net/httpdocs`, rather than assuming Git origin matched deployment. All 603 checked source files match the live server's SHA-256 hashes. PHP dependencies were installed from the live lockfile, frontend dependencies were installed with `npm ci`, and the localhost build passed.

The active local database is now **`NUERS_live_20261027`**, on **127.0.0.1:3308**. Its **143 tables and 45,685 rows** match the consistent live export's per-table row counts. The former local database `NUERS` remains intact. The original export is unchanged; the import copy only removes the obsolete `NO_AUTO_CREATE_USER` SQL-mode token from 12 statements for MySQL 9.5 compatibility. No seeders or migrations were run against the imported snapshot.

The live application encryption key was copied privately after explicit user approval. Values were not displayed or committed. Decryption samples from 14 populated encrypted columns passed. Local URLs/database credentials remain local, sessions/cache use files, default mail uses the log driver, and queue processing is configured for the database with no worker or scheduler started. Locally rebuilt frontend hashes intentionally differ from the production build. Google/provider environment secrets were not otherwise copied.

Private rollback artifacts and verification reports are in `.local/backups/live-sync-2026-10-27/`. The final summary is `.local/live-sync-verification-2026-10-27.json`. Prior local application files, prior `.env`, and the old local database export are preserved there. Private uploads/storage and the approved key remain Git-ignored. No live files or records were changed, and no Git commit/push was performed for this refresh.

Restart with `bash .local/start.sh`; open http://localhost:8001. This is a dated independent snapshot, not ongoing live synchronization.

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
