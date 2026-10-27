# NUERS on AWS Singapore (ap-southeast-1): Stage 1 setup

Written 2026-10-26. This is a plan and runbook, not a tested deployment. Nothing here has been run. Instance sizes and prices are starting estimates; check them in the AWS console and pricing calculator before buying.

Stage 1 goal: a production-grade single-site system with real backups and a separate database. It is not the accreditation-scale design (see "What Stage 1 does not give you").

## 1. Architecture

```
Browser -> Cloudflare (WAF, TLS) -> EC2 web/app (nginx, PHP-FPM, Redis, supervisor, cron, Node, Chromium)
                                        |-> RDS MySQL 8 (Multi-AZ, private subnets only)
                                        |-> EBS volume (storage/app, public/uploads)
                                        |-> S3 backup buckets (Singapore + a second region)
```

Why uploads stay on local disk (EBS) in Stage 1: the code writes verification documents to `storage/app/private`, chunk uploads to `storage/app/verification-uploads`, and logos to `public/uploads/*`. All of these use local paths. Moving them to S3 is a code change, deferred to Stage 2 when a second web node needs shared storage.

## 2. AWS resources

| Resource | Setting |
|---|---|
| Account | Dedicated AWS account for NUERS production. Root user: MFA, no access keys. Daily-use via IAM Identity Center or IAM users with MFA. Enable CloudTrail (all regions) to an S3 bucket with Object Lock. |
| Region | `ap-southeast-1`. Use the same region for everything except the backup copy. |
| VPC | 10.20.0.0/16, 2 AZs. Public subnets (web) and private subnets (RDS) in each AZ. No NAT needed if the web host is in a public subnet; its security group is what protects it. |
| EC2 | Ubuntu 24.04 LTS, `c6i.xlarge` (4 vCPU / 8 GB) or equivalent, 100 GB gp3 encrypted root, plus a separate 100 GB gp3 encrypted data volume mounted at `/var/nuers-data` (holds `storage/app` and `public/uploads` via symlinks). Elastic IP attached. IMDSv2 required. Use SSM Session Manager for admin access; leave port 22 closed. |
| RDS | MySQL 8.0, `db.m6g.xlarge` (4 vCPU / 16 GB) or equivalent, Multi-AZ, gp3 200 GB with storage autoscaling, encrypted with a customer-managed KMS key, not publicly accessible, automated backups with 14-35 day retention (point-in-time recovery), deletion protection on, Performance Insights on, `time_zone` left at UTC (the app handles `APP_TIMEZONE`). Parameter group: `character_set_server=utf8mb4`, `require_secure_transport=ON`. |
| S3 buckets | `nuers-backup-sg` (Singapore) and `nuers-backup-dr` in AWS Hong Kong `ap-east-1` (decided 2026-10-26: Singapore primary, Hong Kong backup). `ap-east-1` is an opt-in region, so enable it in the account first, and confirm before relying on it that RDS cross-region backup copy, S3 replication and Object Lock are all available there. Block public access, SSE-KMS, versioning, Object Lock in compliance mode (retention 35 days to start), lifecycle to Glacier after 30 days. Cross-region replication from `-sg` to `-dr`. |
| Secrets | AWS Secrets Manager (or SSM Parameter Store SecureString) for the values in section 5. The EC2 instance role gets read access to only those secrets. |
| Monitoring | CloudWatch agent (memory, disk), alarms on CPU, disk, RDS free storage/CPU/connections, status checks. SNS topic to email and phone. External uptime check on `/api/health`. |
| Budget | AWS Budgets alert at your monthly limit. |

### Security groups

- `sg-web`: inbound 443 (and 80 for redirect) only from Cloudflare ranges (https://www.cloudflare.com/ips-v4 and ips-v6; copy them in and set a reminder to re-check them). No SSH. Outbound: all (webhooks go to arbitrary public HTTPS hosts; SMTP 587/465; DNS; NTP).
- `sg-db`: inbound 3306 only from `sg-web`. No other inbound.

Also enable Cloudflare Authenticated Origin Pulls so the origin rejects anything that isn't Cloudflare even if an IP range changes.

## 3. Web server build (Ubuntu 24.04)

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install nginx redis-server supervisor unzip git chrony \
  php8.3-fpm php8.3-cli php8.3-mysql php8.3-curl php8.3-gd php8.3-zip php8.3-mbstring php8.3-xml php8.3-redis php8.3-intl php8.3-bcmath \
  chromium-browser   # on Ubuntu 24.04 this is a snap shim; see note below
# Node 22 (for the PDF renderer at runtime): install from NodeSource or nvm, then:
node -v   # must be 22.x
# Composer: https://getcomposer.org/download/ (verify the installer hash)
```

Notes:
- Ubuntu 24.04's `chromium-browser` is a snap, and snap Chromium can fail under a service user. Safer: install Google Chrome's `.deb` (gives `/usr/bin/google-chrome`, which the app finds automatically) or set `NUERS_PDF_CHROME` to the exact binary. Test PDF generation as the `www-data` user before go-live.
- Time: `chrony` is installed for NTP. The audit chain and compliance duties depend on synchronized time. Confirm with `chronyc tracking`.
- Redis: bind to 127.0.0.1 only, set `requirepass`, keep it off the network.

### Layout and permissions

```bash
sudo mkdir -p /var/www/nuers /var/nuers-data/{app,uploads}
sudo mkfs -t ext4 /dev/nvme1n1 && sudo mount /dev/nvme1n1 /var/nuers-data   # add to /etc/fstab by UUID
# deploy code to /var/www/nuers, then:
sudo ln -s /var/nuers-data/app     /var/www/nuers/storage/app      # only if storage/app is empty first
sudo ln -s /var/nuers-data/uploads /var/www/nuers/public/uploads
sudo chown -R www-data:www-data /var/nuers-data /var/www/nuers/storage /var/www/nuers/bootstrap/cache
```

`storage/framework/cache` must be writable, because some dashboards force the file cache store.

### nginx

Start from `deployment/security/nginx-reference.conf` (rate-limit zones, 4 MB body limit, `/api/auth/*` stricter limits, PHP only through `/index.php`). Adjust:
- `root /var/www/nuers/public;`
- `fastcgi_pass unix:/run/php/php8.3-fpm.sock;`
- TLS: use a Cloudflare Origin CA certificate on the origin, with Cloudflare SSL mode "Full (strict)".
- Real client IP: `config/trusted_proxies.php` already trusts Cloudflare ranges for `X-Forwarded-For`. For nginx rate limiting, add `real_ip_header CF-Connecting-IP;` plus `set_real_ip_from` for each Cloudflare range so `limit_req` counts real clients, not Cloudflare edges.
- `/uploads/` serves from `public/uploads`. Never serve `storage/` or `.env`. Deny dotfiles.
- Block `public/nuers-db-check.php` (delete it, see section 8).

### PHP-FPM

`/etc/php/8.3/fpm/pool.d/www.conf`: `pm = dynamic`, start with `pm.max_children = 24`, tune against memory (each worker is typically 60-120 MB under Laravel). In `php.ini`: `upload_max_filesize` and `post_max_size` just above 4M (the app caps request bodies at 4 MiB and uploads in 1 MiB chunks), `memory_limit = 256M`, `opcache.enable=1`, `opcache.validate_timestamps=0` (reload FPM on deploy), `expose_php=Off`.

### supervisor and cron

Use `deployment/security/supervisor-reference.conf` as the base: two `queue:work database --sleep=1 --tries=3 --timeout=90 --max-time=3600` workers and `schedule:work`. Set `user=www-data`, correct paths, `autorestart=true`. If `schedule:work` is under supervisor you do not also need the cron line; use one or the other, not both (running both duplicates scheduled jobs, though the scheduler uses locks for some). Recommended: cron only.

```
* * * * * www-data cd /var/www/nuers && php artisan schedule:run >> /dev/null 2>&1
```

Scheduled jobs to expect: `eis:prepare-pending` and `notifications:work` and `merchant-webhooks:work` every minute, `eis:reconcile` every 5 minutes, `security:prune-mfa-setups` every 10 minutes, `notifications:reconcile` hourly, `registration:prune-staged-documents` daily.

## 4. Frontend delivery

Serve the SPA and API from the same origin (required by the Google login session handoff). Use the Laravel + Vite path: `resources/views/app.blade.php` loads the built assets in `public/build`, which are committed. The static `dist/` path bakes `VITE_API_BASE_URL` (default `https://nuers.net/public`) into the bundle; do not use it for this setup unless you rebuild with the new base URL. After first deploy, load the site, sign in, and check the browser network tab that API calls go to your own domain's `/api/...`.

## 5. Production `.env` (values come from Secrets Manager; never commit)

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://<your-domain>
APP_KEY=<carry over or generate once; never regenerate afterwards>
APP_TIMEZONE=Asia/Manila

DB_CONNECTION=mysql
DB_HOST=<rds-endpoint>
DB_PORT=3306
DB_DATABASE=nuers
DB_USERNAME=nuers_app          # least privilege on this schema only
DB_PASSWORD=<secret>
# if the app's database config supports TLS options, enable them (verify in config/database.php)

CACHE_STORE=redis
QUEUE_CONNECTION=database      # matches the reference supervisor config
SESSION_DRIVER=database        # or redis; verify the sessions table exists after migrate
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=<secret>

NUERS_AUDIT_CHECKPOINT_KEY=<32+ chars, secret>
NUERS_RELEASE_EVIDENCE_REFERENCE=<10+ chars, e.g. release ticket id>
NUERS_PDF_NODE=/usr/bin/node
NUERS_PDF_CHROME=/usr/bin/google-chrome
NUERS_NOTIFICATION_DISPATCH_ENABLED=true     # only when ready to send real notifications

GOOGLE_CLIENT_ID=...  GOOGLE_CLIENT_SECRET=...
GOOGLE_REDIRECT_URI=https://<your-domain>/...  GOOGLE_FRONTEND_URL=https://<your-domain>
```

Mail (SMTP) is configured in the admin UI and stored encrypted in `platform_mail_settings`; it is not in `.env`. Use a TLS SMTP provider on a public host (SES in Singapore works; verify the domain and request production access first).

Required code/config edits before go-live:
- Add the production domain to `config/cors.php` (currently only localhost and `https://nuers.net` / `www`).
- If you keep `nuers.net`, no CORS change is needed, but confirm DNS plans first.
- Back up `APP_KEY` and `NUERS_AUDIT_CHECKPOINT_KEY` separately from the server (a sealed offline copy plus Secrets Manager). Losing `APP_KEY` makes encrypted data unreadable, including verification documents and SMTP settings.

## 6. First deploy and every deploy

```bash
cd /var/www/nuers
git pull                        # committed build artifacts (public/build, bootstrap/ssr) mean no Node build is needed on the server
composer install --no-dev --optimize-autoloader
php artisan migrate --force     # NEVER run db:seed (it creates accounts with password 123456)
php artisan config:cache && php artisan route:cache && php artisan view:cache
```
Caveat: `routes/api.php` contains closure routes (for example `/health`), so `route:cache` will fail. Skip `route:cache` or convert those closures to controllers first. Run it once in staging to confirm.

```bash
sudo systemctl reload php8.3-fpm
sudo supervisorctl restart all   # queue workers pick up new code
php artisan security:deployment-check   # must pass before real traffic
curl -s https://<your-domain>/api/health
```

If `bootstrap/ssr/document-renderer.mjs` is stale, run `npm ci && npm run build:document` on a build machine, not on the production host, and commit/ship the output.

If migrating existing data from the current host, restore a dump into RDS, then run `php artisan integrations:prepare-verified-connections` (idempotent backfill). Do not point the new site at the old database `194.233.94.62`.

## 7. Backups and restore tests

| Data | Method | Frequency |
|---|---|---|
| Database | RDS automated backups + point-in-time recovery (14-35 days). Plus a nightly `mysqldump --single-transaction` (or RDS snapshot export) copied to `nuers-backup-sg`. | continuous / nightly |
| `storage/app` and `public/uploads` | EBS snapshots of the data volume (Data Lifecycle Manager) for whole-volume recovery, plus an object-level sync (`aws s3 sync` or `restic`) to `nuers-backup-sg` **at most every 5 minutes**, encrypted client-side if you want separation from AWS keys. Replicates to `-dr` region. The Backup/DR plan (`Nuers Docs`, v1.0.0) proposes an RPO of 15 minutes for the whole service and says six-hour object backups cannot meet it, so a nightly or 6-hourly file backup is not enough. | every 5 min (objects), snapshots every 6 h |
| Audit checkpoints | `eis:audit-checkpoint` output lives in `storage/app/private`. Keep a copy in an Object Lock bucket; the point of checkpoints is evidence that the chain wasn't rewritten. | with the backups, at least daily |
| Secrets | `APP_KEY`, `NUERS_AUDIT_CHECKPOINT_KEY`: offline sealed copy | on change |

Restore test (do this before launch, then every quarter, and write down the date and result):
1. Restore the latest RDS snapshot to a temporary instance, and the data volume snapshot to a temporary EC2.
2. Boot the app against them in an isolated network with outbound mail/webhooks disabled.
3. Verify login, a PDF, a verification document opens (proves `APP_KEY`), and the audit chain verifies.
4. Record time taken (your real RTO) and data age (your real RPO).

## 8. Must-do before real data

1. Delete `public/nuers-db-check.php` from the repo and any server (hardcoded key, DB probe).
2. Do not run `db:seed` on production. Check no default accounts exist (`admin@nuers.com`, `bir@nuers.com`, etc.).
3. Close or rotate the credentials of the old database at `194.233.94.62` (user `nuers`); the host is committed in `deployment/nuers-net-mysql.env.example`.
4. Enable MFA for all privileged roles (required by the production gate).
5. `php artisan security:deployment-check` passes.
6. Cloudflare: proxy on, SSL Full (strict), WAF managed rules on, rate-limit rules on login and signup, origin locked as in section 2.
7. Compliance (not a technical step, but gating): disclose AWS Singapore (and the backup region) in the accreditation file, including admin-access arrangements; a later move is a material change needing prior BIR approval. Have a lawyer or compliance adviser confirm that hosting taxpayer data outside the Philippines is acceptable before real taxpayer data goes in. I found no BIR data-residency rule, but I cannot give legal assurance.

## 9. What Stage 1 does not give you

- One web node: a failure or a deploy reload means brief downtime. RDS Multi-AZ covers database failure only.
- Uploads on one disk: single-AZ (protected by snapshots, not by failover).
- No tested capacity at the 1M/hour target. The global audit lock serializes all audited writes, so adding servers alone will not scale writes. Load-test in staging before claiming a number.
- Cloudflare is a hard dependency for the origin lock.

Stage 2 (when accreditation approaches): second web node behind an ALB, uploads moved to S3 with Object Lock (code change), ElastiCache Redis, RDS read replica or Aurora, queue moved to Redis or SQS, a DR copy in a second region.

## 10. Rough cost, to be verified

Order of magnitude only: EC2 + EBS + RDS Multi-AZ + S3/backups + data transfer + CloudWatch should land somewhere around the low hundreds of USD per month. I have not priced this in the AWS calculator; do that before committing.
