# Local and Live Database Workflow

Local NUERS uses an independent copy of the live Plesk database. Local edits stay on this computer.

## Run locally

Open `http://localhost:8001`. Port 8000 was already occupied when this environment was configured.

To restart the app and its database from the repository root:

```bash
bash .local/start.sh
```

The local database is `NUERS` on `127.0.0.1:3307`. Its files are in `.local/mysql`; the dedicated app account and password are in the git-ignored `.env`. The server listens only on loopback. Logs are in `.local/logs`.

## Live snapshot

The Plesk export completed on **15 October 2026 at 05:44:36 Asia/Manila**:

- Original export: `.local/backups/NUERS_2026-10-15_05-44-22.sql.zip`.
- Original uploaded-file archive: `.local/backups/NUERS_local_files_20261015.zip`.
- Verification: `.local/snapshot-verification.json`.
- API checks: `.local/smoke-results.json`.

The import was verified against the original export: all **52 tables and 630 rows** matched by normalized row content, including duplicate counts. All 21 downloaded public files matched their local counterparts, including the frontend build and all six uploaded logos/avatars. Plesk reported deployed commit `c13ecb2`, which matches this checkout.

This is a snapshot, not continuous synchronization. Later live changes require another export and a new local import. Preserve the current local database before refreshing it. Do not run seeders or migrations merely to start the snapshot; they can change its schema or records.

## Local environment differences

The app uses local URLs and database credentials, development mode, and mail logging. The downloaded live `.env` contained an invalid application key, so a valid local key was generated. Login passwords remain the values imported from live.

Local PHP is 8.5.9 and MySQL is 8.4.11; Plesk reports PHP 8.3.35 and MariaDB 10.3.39. Code, build artifacts, and snapshot records were verified; the operating system and runtime versions are not identical. Database import and the page, login, session, dashboard, invoice, receipt, and transaction API checks passed on the local runtime.

## Git deployment

Keep private database exports, `.env`, local runtime files, and newly downloaded uploads out of git. `.local/` and untracked `public/uploads/` contents are ignored. Existing tracked upload files remain tracked.

Deploy code through the existing Git/Plesk workflow. Keep the server's production environment separate from this local `.env`. Do not copy local database settings to production or point local development at the live database.


## Google sign-in

The login page now includes Google OAuth for existing NUERS accounts. The local `users.google_id` nullable unique column was added on 15 October 2026. Existing account records and assigned roles are preserved. A pre-migration backup is in `.local/backups/before-google-oauth.sql`; the original 630-row import verification describes the initial snapshot before this schema addition.

Google OAuth credentials are configured in the private local `.env`. The Google Cloud project is `nuers-511622`, with the `NUERS Web` client authorizing localhost and `https://nuers.net/public/auth/google/callback`. A real Google consent and identity callback completed on localhost on 15 October 2026. The account `nuersofficial@gmail.com` is absent from the copied NUERS database, so it correctly returns an account-not-found message. Google login for `siosonmarkdavid@gmail.com` was verified on both localhost and `https://nuers.net`, opening its existing Client Portal. Live credentials and the specific Google ID migration are applied. Live rollback files are outside the website at `/google-oauth-backup-20261015`, with database export `NUERS_before_google_20261015_0658.sql.zip`. The invalid live encryption key was repaired for encrypted OAuth sessions. The server serves its static frontend from `httpdocs/dist`; this directory and the corresponding source files were updated. Redirects include a public cache-busting value so an older cached SPA cannot interrupt Google login. Maintenance used Plesk Run Now without saving a scheduled task. All 15 PHP tests (63 assertions) pass. The consent application remains in External testing mode. Use `deployment/nuers-google.env.example` for the authorized callback URLs and setup commands. The client secret belongs only in the backend `.env`. This integration uses session-validated OAuth redirects, a stable Google account ID, and a short-lived one-use session exchange; bearer tokens are never placed in the callback URL. It does not create new NUERS users or change their roles.
