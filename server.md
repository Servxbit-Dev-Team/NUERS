# server.md

Running notes on hosting the NUERS project. Updated after every hosting discussion.

## Current live setup (from `deployment/` docs, not yet confirmed with owner)

- Domain `nuers.net`, managed in **Plesk** (Apache). The repo root `.htaccess` serves the committed `dist/` static SPA and the static build calls the API at `https://nuers.net/public/api/...` (`VITE_API_BASE_URL`, default in `vite.static.config.ts`). Laravel's `public/` is exposed as `/public/`.
- Database: remote **MariaDB/MySQL on `194.233.94.62:3306`**, DB `NUERS`, user `nuers` (`deployment/nuers-net-mysql.env.example`). The app server and DB server are separate machines.
- Live `.env` example uses `CACHE_STORE=file`, `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`. That conflicts with the production checklist below.
- Local dev: `http://localhost:8001`, MySQL 9.5 on 127.0.0.1:3307 via `.local/start.sh` (the `.local/` folder is not in this checkout).
- `deployment/security/` holds reference configs for a VPS-style target: `nginx-reference.conf`, `supervisor-reference.conf`, `production-overrides.env.example`. They assume nginx, PHP-FPM, supervisor, `/srv/nuers`, and Cloudflare in front (`config/trusted_proxies.php` trusts only Cloudflare ranges).

## What the app needs to run (derived from reading the code)

**Runtime**
- PHP `^8.2` (dev machine runs 8.5), extensions: pdo_mysql, curl, gd, zip (ZipArchive), openssl, mbstring, intl-friendly. Composer.
- MySQL 8+ or MariaDB 10.2+ (uses window functions `COUNT() OVER`, `ROW_NUMBER() OVER`, JSON columns, `lockForUpdate`, InnoDB). Tests use in-memory SQLite.
- Node + headless Chromium **on the app server** for PDF generation. `DocumentPdf` runs `node bootstrap/ssr/document-renderer.mjs` and `chromium --headless=new`. Set `NUERS_PDF_NODE` and `NUERS_PDF_CHROME` (defaults: `node`, `/usr/bin/chromium`, `/usr/bin/chromium-browser`, `/usr/bin/google-chrome`). Without them, PDF download, customer-copy emails and sales-report PDF return 503.
- Outbound HTTPS 443 for webhooks to merchants, outbound SMTP (TLS required) and PSGC lookups (`psgc.gitlab.io`, with built-in fallbacks).

**Background work (required, otherwise features silently stall)**
- Scheduler: `php artisan schedule:run` every minute (or `schedule:work`). Registered jobs: `eis:prepare-pending` (1 min), `eis:reconcile` (5 min), `notifications:work` (1 min), `notifications:reconcile` (hourly), `merchant-webhooks:work` (1 min, 45 s max), `registration:prune-staged-documents` (daily), `security:prune-mfa-setups` (10 min).
- Queue worker is referenced by the supervisor reference config (`queue:work database`), but the code mostly uses its own DB-backed outboxes processed by the scheduler.
- Email only sends when `NUERS_NOTIFICATION_DISPATCH_ENABLED=true` **and** SMTP is configured and enabled in the admin UI (SMTP settings live encrypted in the DB table `platform_mail_settings`, not in `.env`).
- Merchant webhook delivery: `NUERS_MERCHANT_WEBHOOK_DELIVERY_ENABLED` (default true). Platform BIR webhooks are hard-disabled in code.

**Storage that must persist and be backed up**
- `storage/app/private` (encrypted verification documents, admin avatars, audit checkpoints) and `storage/app/verification-uploads` (temporary chunks).
- `public/uploads/*` (business logos, invoice logos, client avatars; gitignored; also aliased at `/public/uploads/business-logos/...` in `routes/web.php`).
- Database. It contains hash-chained audit and wallet ledgers (integrity checks fail if rows are altered or partially restored).
- **`APP_KEY` must never be regenerated or lost.** Customer profiles, documents, SMTP password, webhook secrets, MFA secrets and HMAC search indexes are encrypted or keyed with it. `APP_PREVIOUS_KEYS` exists for rotation.

**Cache, session, queue**
- `ApiBoundary` rate-limits per IP (300/min) and several endpoints use `cache` rate limiters and cache locks. `security:deployment-check` requires `CACHE_STORE` of `redis` or `database` and `QUEUE_CONNECTION` of `database`, `redis` or `sqs`. A `file` cache breaks cross-process limits.
- Some dashboards force `Cache::store('file')`, so `storage/framework/cache` must be writable.
- Sessions are only used for the Google OAuth handshake (default driver `database`). Normal login uses bearer tokens in `users.api_token_hash` and a per-browser `X-NUERS-Device` header.

**Frontend / API wiring**
- Two ways to serve the SPA:
  1. Laravel + Vite: `resources/views/app.blade.php` loads `src/main.tsx`; build with `npm run build` into `public/build` (committed).
  2. Static: `npm run build:static` writes `dist/` (committed) with `VITE_API_BASE_URL` baked in.
- CORS (`config/cors.php`) allows only `localhost`, `127.0.0.1`, `:5173`, `https://nuers.net`, `https://www.nuers.net`. A new domain needs a code change.
- Google login: `GOOGLE_CLIENT_ID/SECRET`, `GOOGLE_REDIRECT_URI`, `GOOGLE_FRONTEND_URL` must match the host. The final step calls `/auth/google/session` with a same-origin session cookie, so SPA and API must share an origin (or at least site) for Google sign-in.
- `APP_URL` must be `https://` and correct: emailed links are built from it.
- Timezone: `APP_TIMEZONE` (example env uses `Asia/Manila`; the config default is UTC). Timestamps are stored in the app timezone.

**Production gate command**
- `php artisan security:deployment-check` must pass: debug off, https APP_URL, cache redis/database, queue database/redis/sqs, `NUERS_AUDIT_CHECKPOINT_KEY` (32+ chars), `NUERS_RELEASE_EVIDENCE_REFERENCE` (10+ chars), migrations applied.

## Hosting expectations that come from the project's own docs and tests

- **Design targets (not proven):** 1,000,000 transactions/hour (about 278 TPS average) and bursts to 2,000 TPS; p95 durable acceptance under 2 s. The internal spec proposes 2 web nodes, 2 DB nodes plus an independent quorum/fencing witness, 3 object-storage nodes, a durable queue, monitoring, immutable offsite backups and a DR site (64-core/128 GB class each). The audits state none of this has been measured or deployed.
- **Known scaling limits in the code:** one global audit-chain lock (`eis_audit_head`) serializes every audited write; per-merchant row locks serialize that merchant's intake; `IssuedDocumentLedger` is a large UNION with window functions; several reports load rows into PHP memory; uploads write to `public_path('uploads')` (local disk), so more than one web node needs shared storage. Daily dashboards can lag at scale.
- **Regulatory hosting duties (RMC 110-2026, per the supplied slides):** disclose every hosting/cloud provider, DR and administrative-access arrangement, and update the disclosure before changing provider; keep time-synchronized immutable audit logs accessible to the BIR; documented architecture, availability/RTO/RPO targets; manual-invoicing fallback during outages; critical incidents reported to the RDO in writing within 24 h; prior BIR approval for material changes, including a change of hosting or cloud environment or data location. Data residency interpretation is still an open item in the docs. So **where the app is hosted is itself a compliance matter**, not just a technical one.
- **What is NOT yet needed from hosting:** no live BIR connection exists, so there is no static egress IP, VPN or mTLS requirement today. That will change once an official adapter exists.
- **Tests:** `phpunit.xml` uses in-memory SQLite, sync queue and array cache; JS tests run with `node --test`. The `DocumentCopyMail` and `StatutorySales` PDF tests, and `tests/js/document-renderer.test.mjs`, need `bootstrap/ssr/document-renderer.mjs` built and a Chromium binary, and the renderer test writes a temp file into `public/uploads/invoice-logos/`. Do not point tests at a real database: the docs warn some tests call migration `up()` directly.
- **Upload sizes:** verification files up to 100 MB are uploaded in 1 MiB chunks (each request must be under the 4 MiB `max_request_bytes`); PHP and proxy limits only need to allow a few MB per request, but the `storage` disk must hold up to 100 MB per document plus 2-hour temporary chunk copies.
- **Maintenance commands named in docs:** `php artisan integrations:prepare-verified-connections` (idempotent backfill after deploy), `php artisan security:deployment-check`, `php artisan eis:audit-checkpoint`, `php artisan migrate --force` (migrations are forward-only; many `down()` methods deliberately throw).
- **Tool versions in use by the owner:** PHP 8.5.x, Node 22, MySQL 8.4/9.5 locally; Laravel 12.70; the live remote DB is described as MariaDB (Plesk export).

## Risks noticed while reading the repo (for the hosting plan)

1. `public/nuers-db-check.php` (and a copy in `deployment/`) is committed in the web root, with a hardcoded secret key and a DB-connection probe. Remove it from any live host.
2. `database/seeders/DatabaseSeeder.php` creates `admin@nuers.com`, `bir@nuers.com`, `customer@nuers.com` and others with password `123456`, plus the merchant `m.sioson@servxbit.com`. **Never run `db:seed` on a live database.**
3. `deployment/nuers-net-mysql.env.example` exposes the DB server IP and user. Rotate/restrict DB access (firewall to the app server only).
4. Built artifacts are committed (`dist/`, `public/build/`, `bootstrap/ssr/document-renderer.mjs`), plus large `tmp/`, `output/`, `docs/` binaries (about 400 MB in git). A deploy by `git pull` is possible but heavy.
5. Official BIR/EIS transport is intentionally blocked in code; hosting cannot enable it. Backups/DR evidence is recorded in the app but **not executed** by it, so backups need to be run by the host.
6. A dedicated `.env.example` is missing from the repo.

## Provider shortlist and questionnaire

- Questionnaire and verified contact routes: `hosting-provider-questionnaire.md`.
- Findings (2026-10-26): Zenlayer Manila (MNL1) lists networking only, no VMs, so it is not a host for the app. STT GDC Makati sells colocation, not cloud VMs. CloudLake advertises VMs and DR but its page shows a likely placeholder phone number and a mismatched footer email, so verify it exists and is legitimate. Alibaba Cloud has a Manila region per press coverage; product availability still to be confirmed with sales. VITRO sales contact not found.

## Discussion log

- 2026-10-26: File created. Repo read for architecture and hosting needs (backend, schema, config, deployment docs). No hosting decisions made yet.
- 2026-10-26 (later): Added what the tests and docs imply for hosting (capacity targets, compliance duties on hosting providers, scaling limits, PDF/test requirements). Still no decisions.

## Decisions

- Owner's stated constraints (2026-10-26): target is **accreditation scale**; data must live **in the Philippines only**; budget: asked me to propose; current Plesk plan type and ownership of the DB server at 194.233.94.62 are **unknown** (owner to check).
- Owner is now considering **Singapore** to broaden provider choice (data location was "Philippines only" earlier). Not yet decided; caveat given: the hosting location must be disclosed in the ESP accreditation file and a later change is a material change needing prior BIR approval, so pick the final production location before applying. Singapore is fine for dev/staging/pilot.
- **Owner now leaning to Singapore** (2026-10-26, reversing the Philippines-only decision) because Philippine providers seem less reliable to them. Compliance caveat stands: declare the hosting location in the ESP file, avoid a later move (material change needing BIR approval), and have a lawyer/compliance advisor confirm cross-border hosting before putting real taxpayer data there. The Philippine questionnaire (`hosting-provider-questionnaire.md`) is parked, reusable for any provider.
- Hosting provider/topology: **not decided** (recommendation given in chat, awaiting owner).

## Research notes (web, 2026-10-26; verify before relying)

- No confirmed AWS or Azure full region in the Philippines. AWS has only announced plans (July 2026) and has a Manila Local Zone attached to Singapore. Google Cloud, Alibaba Cloud and Huawei Cloud are reported to list Manila regions (Google's claim came from a single directory, uncorroborated). Telco data centers (PLDT VITRO, Globe, Converge) are the usual local option.
- **Correction:** the earlier claim of a Google Cloud Manila region is unconfirmed. `asia-southeast3` is Bangkok per the one source found. Treat Google as having no Philippine region until Google's own location page says otherwise.
- Philippine options found: Alibaba Cloud Manila region (opened about 2021-22, reported 200+ customers; product availability in that region not verified); Zenlayer (Pasig, MNL1), Equinix (Manila), VITRO/ePLDT (Makati, Parañaque), DataHOUSE (colo/managed), CloudLake (in-country cloud, claims data stays in PH), and small VPS hosts (Herza Cloud at STT Makati, SurferCloud, LightNode). Managed DB / object storage / load balancer availability at the local providers is **not verified**; ask each.
- No source found stating a BIR data-residency rule for ESPs; the accreditation deck only requires disclosure of hosting/cloud providers and prior approval for changes. Philippines-only is therefore the owner's choice, which is also the safest reading.
- A KPMG note says BIR clarified on 14 Sep 2026 that it has not accredited any ESP and suspended certain ESP-related engagements. The ESP framework may still change.

## Open questions

- Which Philippine provider/data center, and does it offer the managed pieces (DB, object storage, load balancer, DDoS) or only VMs?
- Stay on Plesk/nuers.net or move to a VPS/cloud (the nginx and supervisor references suggest a VPS)?
- Is Cloudflare actually in front of the live site now?
- Where does the MariaDB at 194.233.94.62 live, and who manages its backups?
- Does the host allow Chromium + Node for PDFs, a cron every minute, and outbound SMTP/HTTPS?
- Expected traffic, uptime target, and budget.

## Decision: AWS Singapore (2026-10-26)

- Provider chosen: AWS, region `ap-southeast-1`, after the user judged Philippine hosting unreliable. Philippines-only is dropped; the Philippine questionnaire is parked.
- Stage 1 runbook written: `deployment/aws/stage1-setup.md` (EC2 + RDS MySQL 8 Multi-AZ + EBS for uploads + S3 backups in two regions + Cloudflare in front). Untested plan; sizes and cost are estimates.
- Findings while writing it: `routes/api.php` has closure routes, so `route:cache` will likely fail; uploads are local-disk only (S3 needs a code change, Stage 2); `config/cors.php` needs the new domain if it isn't `nuers.net`; snap Chromium on Ubuntu 24.04 may not work under `www-data`, so use the Chrome .deb or set `NUERS_PDF_CHROME`.
- Compliance: disclose AWS Singapore and backup region in the accreditation file; a later move needs BIR approval; have an adviser confirm cross-border hosting before real taxpayer data goes in.
- Still open: domain (keep nuers.net?), current Plesk/DB ownership, whether Cloudflare is already in front, monthly budget limit, uptime target, AWS account owner.

## Capacity question: can Stage 1 handle 1M invoices/hour? (2026-10-26)

Answer: no, and it is unmeasured. Target is ~278/s average with bursts to 2,000/s.

- The global audit-head lock serializes every audited write, so adding web nodes will not raise write throughput. If a locked write takes 10-20 ms (my guess, not measured), the ceiling is ~50-100/s, or ~180k-360k/hour. Reaching 278/s needs under ~3.5 ms per locked write; 2,000/s is impossible with one global lock.
- Stage 1 has one ~4 vCPU web node and one ~4 vCPU MySQL primary; the dashboards' heavy UNION/window queries compete with writes.
- `merchant-webhooks:work --limit=100` runs once a minute, about 6,000 deliveries/hour. If invoices trigger webhooks, the backlog grows without bound at this volume.
- `QUEUE_CONNECTION=database` puts queue traffic on the same MySQL. One web node means no failover.
- Needed for the target (Stage 3): redesign the audit chain (per-merchant or sharded chains plus periodic global checkpoints; needs a compliance check against BIR audit expectations), Redis/SQS queues, scalable webhook delivery, several web nodes behind a load balancer with shared storage, a larger primary or Aurora with replicas, reporting queries moved off the primary, and a staging load test against the 2 s p95 target.
- Stage 1 fits the pilot and early customers. Next cheap step: a load test on a staging copy to find where the lock saturates (plan not yet written).

## Nuers Docs folder read (2026-10-26)

Read in full via text extraction: Technical Specifications & System Manual, Backup Procedure & DR Plan, Exit/Migration/Data Portability Plan, Merchant API Connection Guide (ed. 1.3), System Reports & Sample Printouts, Principal & Supplementary Documents (specimens), plus the topology image (`846156082_...jpg`). Not examined: PDF binaries or embedded images beyond their text labels.

**The docs already name the planned hosting:** Dataplugs (Hong Kong), Contabo (Singapore) and AWS Singapore `ap-southeast-1` / Hong Kong `ap-east-1`, all marked "planned, not deployed; roles unassigned". Our AWS Singapore choice fits. They propose AWS HK as the recovery region, so the backup region in `deployment/aws/stage1-setup.md` now says `ap-east-1` (opt-in region) instead of Tokyo.

**Topology image = the Stage 3 target:** Cloudflare WAF, 2 load balancers (4C/16GB, active/standby), 2 web (16C/64GB), DB primary + replica, durable queue + BIR transmission workers, 3 storage nodes.

**Hosting-relevant facts from the docs**
- Proposed targets (not measured): RPO 15 min, RTO 4 h, MTPD 8 h; core API 99.9% monthly (43 min 12 s/month), p95 interactive 2 s, document PDF p95 within 2 min for 95%, dispatch freshness at most 15 min. A single web node with deploy reloads cannot promise 99.9%.
- 15-minute RPO needs DB log continuity and object versions at the same boundary. 6-hourly object backups cannot meet it, so the Stage 1 doc now says object sync every 5 minutes at most.
- The only recovery test was a local SQLite lab (500 synthetic events, 0.33 s component restore). It says nothing about MySQL, network, or production-scale restores. A real MySQL/RDS restore test is still owed.
- Backup copy must be independently administered, with deletion credentials separate from production admin; Object Lock proposed; audit checkpoints kept offsite; `APP_KEY` and the checkpoint key held separately with two custodians; keep old keys as long as old backups must stay readable.
- Network baseline: no public DB listener, origin locked to the edge, named admin accounts with MFA (no shared Plesk/root), TLS 1.3 preferred, verify TLS on DB connections, limited egress (mail, merchant callbacks). Before multiple web nodes: verify shared storage, distributed locks, scheduler exclusion and worker leases.
- Public API base is `https://nuers.net/api/integrations/transactions` (merchant guide). So `/api` must be served at the domain root, not under `/public`, and the static build default (`https://nuers.net/public`) is wrong for the new host. Keep `nuers.net` as the domain.
- Merchants can restrict keys to exact source IPs; keep a stable Elastic IP for outbound traffic and tell merchants before changing it. Per-key daily limits: 50,000 Live, 5,000 Sandbox; batch route 10 requests/minute; webhook retries 12 attempts in 24 h, 8 s timeout. These per-key caps are a separate throughput ceiling from the audit-lock one.
- Uploads: 100 MB per verification file in 1 MiB chunks; align nginx and PHP limits. Malware scanning is not implemented (docs call it a gap).
- Customer receipt emails and Google OAuth are subprocessors the docs want in the data-residency register; mail provider and sender-domain setup is still undecided.
- Regulatory: NPC breach notification window 72 hours (docs cite it; legal review required). Provider disclosure and any later host move need BIR handling per the accreditation deck.
- Docs say the audit hash chain is only tamper-evident, not immutable, until checkpoints go to independent custody. Plan for `eis:audit-checkpoint` output to land in an Object Lock bucket in a separate account.

**Still open:** which provider is primary vs backup/DR given the docs list four candidates; mail provider; named on-call owners; MySQL restore drill; who signs the data-location register.

## Decision: AWS Singapore primary, AWS Hong Kong backup (2026-10-26)

- Primary: AWS `ap-southeast-1` (Singapore): web, database, uploads. Backup/recovery: AWS `ap-east-1` (Hong Kong). Dataplugs and Contabo, named as candidates in the manuals, are not used for now.
- Both regions are outside the Philippines, so the cross-border review applies to the primary and the backup copy. The manuals' data-residency register needs both listed, with account IDs, KMS key regions and who can access them.
- Hong Kong is an opt-in region: enable it in the account before provisioning. Still to confirm before relying on it: RDS cross-region backup copy, S3 cross-region replication and Object Lock in `ap-east-1`.
- Keep the backup in a separate AWS account (or at least separate credentials) from production, with deletion rights held separately, as the Backup/DR plan requires. Put the audit checkpoint copies in the Hong Kong Object Lock bucket.
- Updated `deployment/aws/stage1-setup.md` to match. The manuals still say providers are "planned", so update their provider tables after the account is set up.
- The BIR accreditation file must disclose both regions. A later change of host or location is a material change needing prior BIR approval.

## Requirement change: 1M transactions/hour from day one (2026-10-26)

User: BIR will stress-test the server, so the launch setup must handle 1M transactions/hour (about 278/s sustained, bursts to 2,000/s from the internal spec). The Stage 1 single-site plan no longer satisfies this; it stays only as a pilot/staging shape. Source of the 1M/hour figure is the internal spec, not a BIR document I have read; confirm what BIR will actually test (sustained vs burst, duration, payload mix).

Code finding that infrastructure cannot fix: `IntegrationTransactionController` opens a transaction (line 77), takes per-merchant and per-key row locks, then calls `BirCompliance::audit()` (line 135) inside it. `audit()` takes `lockForUpdate` on the single `eis_audit_head` row (id 1), and also runs `NotificationEvents::recorded()` inside that lock (`app/Services/BirCompliance.php:178-192`). The global lock is therefore held until the outer acceptance transaction commits, so every accepted invoice in the system serializes on one row. At 10 ms hold time that is ~100/s (~360k/hour); at 278/s the hold must be under ~3.6 ms and at 2,000/s under 0.5 ms, which commit latency on a Multi-AZ database alone exceeds. Also each merchant is serialized by its own `merchants` row lock.

Proposed fix (not implemented, needs compliance review): write an audit-outbox row inside the acceptance transaction (no shared lock), and have one sequencer worker append to the hash chain in batches (hundreds of events per transaction). The chain stays totally ordered and verifiable; audit entries appear seconds after commit instead of in-transaction. Alternative: per-merchant chains plus periodic global checkpoints.

## Architecture review of the repo (2026-10-26)

Scope: structural read-through plus measurements below. No load test, no security audit, frontend pages not read. All hosting-relevant conclusions are structural, not measured.

**Measured**
- `DashboardController.php` 2,458 lines; next largest controllers 816, 746, 710, 703 lines. Frontend: `client/dashboard.tsx` 2,408 lines, `admin/merchants.tsx` 1,992, `dashboard-layout.tsx` 1,552.
- `DB::` calls: 1,010 in controllers vs 321 in services. Only 7 Eloquent models. 280 `Route::` lines in `routes/api.php`, 5 closure routes (blocks `route:cache`).
- 166 `Schema::hasTable/hasColumn` calls in `app/`. 46 PHP feature tests, 7 JS tests.
- `dist/` is 68 MB and committed; `public/build` 5.6 MB committed.

**Sound**
- Integer centavos; idempotent intake returning the stored result; commit before side effects; Live/Sandbox derived from the API key; BIR transport blocked by default behind off flags; hash-chained audit and wallet ledgers; hashed API keys; device-bound sessions with MFA; DNS-pinned webhooks.

**Concerns, in order**
1. Global serialization: single `eis_audit_head` row lock plus per-merchant and per-key row locks (see the 1M/hour entry). Design-level blocker for throughput.
2. Uploads on local disk (`storage/app/private`, `public/uploads`): blocks more than one web node until moved to S3.
3. Database used as queue, cache and sessions by default: competes with invoice writes at high volume; needs Redis or SQS.
4. Business logic in controllers and raw `DB::` queries, few models, one 2,458-line controller: hard to change safely; the audit redesign will touch many places.
5. Generic table API (`/api/tables/*`, `TableController.php`): table allowlist plus hand-written per-role and per-column checks (lines 35-84); each new table or role is a chance to miss a check. Largest security surface to review.
6. 166 defensive schema checks: code is written to survive several schema states and adds queries and branches on the hot path; suggests schema drift between environments.
7. Heavy UNION/window dashboard queries on the primary database: need a read replica.
8. Deploy hygiene: committed build artifacts, committed `public/nuers-db-check.php` with a hardcoded key, seeder password `123456`, no `.env.example`, closure routes.

**Verdict:** fine for pilot-scale traffic with hardening. Not ready for 1M/hour from day one. No rewrite needed; the required work is the audit write-path redesign (about 5 files), S3 uploads (a few services and controllers), Redis/SQS for queue and cache (mostly configuration), a read replica for reporting, and a load test.

## Question: frontend and backend in one repo (2026-10-26)

Answer: acceptable, and this repo benefits from it. Keep it, with the fixes below.
- Pros here: Laravel serves the SPA shell on the same origin as `/api`, which Google login (session-cookie handoff), cookies and CORS need. The PDF renderer reuses the same React document components server-side (`scripts/document-renderer.tsx`), which a split repo would make awkward. One repo means one release, one version label (1.0.0) and one place for the docs and tests.
- Problems to fix:
  - Committed build output (`dist/`, `public/build/`, `bootstrap/ssr/`) bloats git and can drift from source. Build in CI and ship artifacts instead.
  - Two delivery paths (Laravel+Vite and the static `dist/` with a baked-in API base URL, default `https://nuers.net/public`) cause confusion. Pick the Laravel+Vite path.
  - One deploy unit means a frontend change redeploys the API, and PHP workers end up serving static files. At target load, serve static assets from the CDN or S3 (Cloudflare routing keeps the same origin for the SPA and `/api`) and keep PHP only for `/api`.
  - Keep a clear folder boundary and separate CI jobs (PHP tests; frontend typecheck and JS tests) so one side's failure is obvious.
- Do not split into two repos now: it would add cross-repo release coordination and break the shared renderer without fixing the scaling problems above.

## Refactor plan written (2026-10-27)

`docs/refactor-plan.md` now holds the ordered plan: (1) load-test harness and baseline, (2) audit outbox plus sequencer (needs compliance sign-off), (3) per-merchant/key lock review, (4) uploads to S3, (5) Redis and queues, (6) read replica, (7) deploy hygiene, (8) `TableController` and schema-drift reviews, (9) rewrite the AWS plan for the launch shape. Nothing in it is implemented. All throughput figures remain estimates until step 1 is run.

Also from the last background check: `vendor` is 77 MB, `node_modules` 234 MB, and git tracks 34,827 files (breakdown by folder not done yet).

## Load-test baseline run (2026-10-27)

Harness built in `loadtest/` (README inside). Ran the Live intake path against a throwaway MySQL 8.4 on port 3390, from a scratchpad copy of the repo. Nothing touched `nuers.net` or Laragon's MySQL on 3306. Details and the full results table are in `docs/refactor-plan.md` under "Step 1 results".

Headline: no capacity number yet, but the first wall is not the audit lock.
- Each Live acceptance runs 114 to 116 queries and takes about 0.65 s with under 1,000 receipts and about 4 s with 20,000 receipts of one seller. Almost all of it is database time, mainly `IssuedDocumentLedger` queries that rebuild a union of every document in the system and cannot use indexes. At that cost a single worker manages well under 10 accepted per second against a 278/s target.
- About 50 of those queries are `Schema::hasTable`/`hasColumn` lookups on `information_schema` (about 60 ms).
- `ApiBoundary` caps every `/api` route, including merchant intake, at 300 requests per minute per client IP. With one source IP, 97% of requests were rejected with 429. A BIR test from few IPs would hit this.
- The audit-head lock ceiling is still unmeasured; it only shows once the query cost is fixed.
- Unexplained: a few 504s (and one 500) at 16 to 32 concurrent; `verify.php` was run only after the 15-request smoke test.
- Caveats: one Windows desktop running everything, shared TIN across test merchants, cache and queue on the database. These are bottleneck locators, not AWS sizing.

New facts about the repo found along the way:
- The tracked `vendor/` is incomplete: `symfony/polyfill-intl-grapheme/bootstrap85.php` is missing, and the lock file needs PHP 8.4.1 or newer although `composer.json` says `^8.2`. The app fails to boot from the committed `vendor/` on PHP 8.5 (and on 8.3). A fresh `composer install` fixes it. Another reason to stop committing `vendor/` and build in CI.
- Laragon's PHP is 8.3.30, which is too old for the lock file. Use PHP 8.5 from `C:\php`.

Next: step 1a in the refactor plan (replace the ledger duplicate and identity checks with an indexed lookup), then re-run the harness to see the audit-lock ceiling.

## Steps 1a and 1c done; the audit lock is now the measured ceiling (2026-10-27)

Code changed in the real repo (uncommitted): `IssuedDocumentLedger`, `IntegrationTransactionController`, `EisDocumentReadiness`, `ApiBoundary`, a new migration (`2026_10_27_000001_add_document_number_key.php`), a new test file and one updated test. Full detail, results and rollout notes: `docs/refactor-plan.md` under "Steps 1a and 1c: done".

- 1a: a virtual generated column plus index lets the duplicate-number and readiness-identity checks read only the rows that share a number. One acceptance at 20,000 receipts fell from about 4,060 ms to about 128 ms. The migration took 0.5 s on 20,000 rows.
- 1c: authenticated merchant intake no longer spends the 300-per-minute-per-IP budget; only failed credentials (401/403) count. One client IP went from 97% rejected to 100% accepted.
- Tests: 513 run; the 20 failures are identical to the unmodified code and are limits of the scratch environment (PDF/Chrome, images, asset folders). No regressions.
- New measured ceilings (one desktop, 12 workers, not production sizing): about 52 accepted/s across many merchants, all lock waits on the global `eis_audit_head` row (average wait 19 ms); about 10/s for a single merchant, because the whole acceptance runs under that merchant's row lock.
- Per-key limits will matter for the BIR test: each API key is capped at 600 requests/minute and its daily limit (default 50,000 Live). 1M/hour needs many keys or deliberately raised limits for the test keys. Product policy, not changed.
- Earlier 504s were a harness artifact (php-cgi on Windows plus nginx upstream keepalive), not an app bug. Fixed in `loadtest/serve.ps1`.
- Integrity after all runs: 13,254 accepted events, counts consistent, no duplicate hashes, audit chain intact.

Nothing deployed. Before this reaches `nuers.net`: run the migration on a staging copy of the live database and time it, back up first, and restart PHP workers after deploy.

Next, in order: step 1b (schema-existence queries, about 63 ms per acceptance), then decide on the cheap audit-lock mitigation (move `audit()` to just before commit; same transaction, shorter hold) and re-measure, then the full audit redesign (step 2) with compliance sign-off.

## Step 1b and the audit reorder done (2026-10-27)

Full detail in `docs/refactor-plan.md` under "Step 1b and 1d: done". Uncommitted in the working tree, nothing deployed.

- 1b: a per-process cache for `hasTable`/`hasColumn` (`App\Support\SchemaMap`), used on the acceptance path only. One acceptance went from 128 ms and 108 queries to 59 ms and 59 queries at 20,000+ receipts. A cached "no" expires after 60 seconds, so no worker restart is needed after a migration.
- Audit reorder: each acceptance writes two audit entries (`eis.document.prepared` from readiness `prepare()`, then `integration.event.accepted`). Moving only the controller's call did nothing (14.7 to 14.2 ms) because the earlier entry already held the global lock. Deferring both to the end of the transaction, same order, same transaction, cut the lock hold from 14.7 to 9.6 ms (median, three interleaved rounds). Single-lock ceiling about 68 to about 105 accepted/s. The floor with one global lock held through commit is about 8 ms.
- Not demonstrated: an end-to-end throughput gain. The workstation was at 97 to 100% CPU from other programs (a game and an emulator), so both variants measured about 28 to 30 accepted/s at 32 clients. Re-measure on an idle machine or on AWS before quoting a gain.
- Tests: 519 run; the same 20 environment-limited failures as the unmodified code, nothing new.
- To confirm with the compliance owner: no entry is lost, reordered or detached from the acceptance transaction; `eis.document.prepared` is recorded a few milliseconds later than before, after the webhook enqueue.

Next, in order: the audit outbox plus sequencer redesign (step 2, needs compliance sign-off) is the only way below the ~8 ms floor. Before that, a cheap extra: write both audit entries under a single lock acquisition (about 1.5 ms). Per-merchant ceiling (about 10/s for one merchant) is untouched and is step 3.

## Single audit-lock acquisition done (2026-10-27)

Full detail in `docs/refactor-plan.md` under "Step 1d, part two". Uncommitted in the working tree, nothing deployed.

- `BirCompliance::auditMany()` writes several audit entries under one acquisition of the global audit-head lock; `audit()` now delegates to it with one entry. The acceptance path writes its two entries (`eis.document.prepared`, `integration.event.accepted`) in one call as the last statement before commit.
- Measured (interleaved, quieter machine): lock hold 5.8 ms to 4.7 ms (-19%, lower in every round). End to end at 32 clients: 70.4 accepted/s originally, 75.9 with both audits deferred, 77.1 with the single acquisition. The last step's throughput gain is inside the noise; the earlier deferral's is not.
- Final code: about 76 accepted/s across many merchants, 15 accepted/s for a single merchant (was about 9.6), waits on the audit head in 34% of samples (was 57 to 93%).
- Integrity after all runs: 36,770 accepted events consistent, no duplicate hashes, audit chain intact.
- Tests: 520 run; the same 20 environment-limited failures as the unmodified code, nothing new. A new test caught a real bug in the first version (entry without details), fixed.
- Earlier "the machine is the limit" caveat still applies: the first rounds ran with the workstation at 97 to 100% CPU from other programs, which cut throughput roughly in half. Compare variants only when interleaved.

What remains inside the lock is mostly the commit's disk flush (about 4.7 ms in all). Below that needs the outbox plus sequencer redesign (step 2, compliance sign-off). The next ceiling for a single large merchant is the per-merchant lock (step 3).

## Step 3 started: per-merchant lock analysis (2026-10-27)

Detail in `docs/refactor-plan.md` under "Step 3 findings so far". No lock or application code changed in this step; only the profiler (`loadtest/profile.php`, new `PROFILE_LOCK` option and a db/php timeline) and the docs.

- A single merchant is capped near 15 accepted/s (about 76/s across 50 merchants), because the whole acceptance runs under that merchant's row lock. Window measured at 42.9 ms from a freshly restarted MySQL (33 ms in 49 statements, 9 ms application and commit); an upper bound, since the buffer pool was cold and a warm re-run was not obtained.
- A suspected 6.3 ms wallet-ledger read was a cold-cache artefact (0.3 ms by `EXPLAIN ANALYZE`, 0.6 to 0.8 ms warm). Not a problem.
- The merchant row lock is the platform's per-tenant mutex, used at about 40 call sites (wallet, branches, invoices, keys, governance, team, ...). Acceptance relies on it for wallet-chain ordering, duplicate-number and duplicate-event checks (no unique index on the document number), and for seeing key/status changes. Narrowing it only in the intake path would silently drop mutual exclusion with those flows, so it was deliberately not done.
- Options (documented in the plan): leave it (about 25 to 35 accepted/s per merchant), trim time inside it (modest gain), define explicit tenant lock domains (needs a MySQL concurrency test suite first), or per-wallet sub-accounts / number blocks.
- Question for the owners: does the BIR test (and the largest real merchants) use one merchant account or many? That decides whether step 3 can close at option 1 or 2.

Housekeeping: the throwaway MySQL on 127.0.0.1:3390 (root, empty password, local only) is still running; stop it when finished.

## Race test run; a real API bug found and fixed (2026-10-27)

The product owner does not know whether the BIR test uses one merchant account or many, so the lock stays untouched and the concurrency checks were run first. Detail and the results table are in `docs/refactor-plan.md` under "Concurrency baseline".

- `loadtest/race.mjs` (+ `race-setup.php`, `race-verify.php`) races requests against fresh merchants and reads the database back. On the current code, with the real per-merchant lock, all of these hold under racing requests: duplicate document numbers (one 201, rest 409), changed content on one event id (one 201, rest 409), 200 distinct events on one merchant (exact, valid, gapless wallet chain; balance = funding minus fees), and a wallet funded for exactly 10 sales receiving 30 at once (exactly 10 accepted, 20 refused 402, never negative). No application errors logged; database consistent afterwards.
- **Bug found: idempotent retries came back as 302, not the documented 200.** The intake response always carried a `Location` header; PHP's web SAPIs rewrite any status except 201 or 3xx to 302 when `Location` is present, so every replay was a redirect in a real deployment. Laravel's test client cannot see this, which is why no test caught it. Fixed: 201 keeps `Location`; replays send `Content-Location`. Regression test added. After the fix two full race runs passed. Integrators who built against live behaviour may have worked around a 302; tell them.
- Not yet covered by the race suite: API key revoked/edited mid-flight, merchant suspended mid-flight, portal invoice creation racing an API event with the same number.
- Full suite after the `Location` fix: 521 tests; the same 20 environment-limited failures as the unmodified code, nothing new. The throwaway MySQL on 3390 and the test servers are stopped (verified); the Laragon MySQL on 3306 was never touched.
- Still open: ask the BIR/accreditation contact how many taxpayer accounts the load test uses, and sustained vs burst.
