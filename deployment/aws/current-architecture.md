# NUERS production on AWS: current architecture

Observed 2026-11-18 from read-only AWS CLI checks (CloudShell) and a read-only Session Manager shell on `nuers-weba`. This describes what is deployed; it supersedes the Stage 1 plan in `stage1-setup.md`. No secret values, `.env` contents or account credentials are recorded here.

Not verified (needs someone with sudo or live-site access): the `.env` values, `php artisan security:deployment-check` output, Cloudflare settings, and the state of `nuers-webb` beyond what AWS reported.

## 1. Topology

```
Browser -> Cloudflare (DNS, TLS, WAF)
        -> ALB nuers-prod (internet-facing, HTTPS 443 only, ap-southeast-1)
              host rule: nuers.net, www.nuers.net -> forward; default -> fixed response
        -> nuers-weba / nuers-webb (m6a.xlarge, private subnets, nginx :8080 + PHP 8.4-FPM)
              |-> RDS MariaDB 10.11 (Multi-AZ, private)
              |-> EFS nuers-production-shared-files, mounted at /srv/nuers/shared
              |-> S3 (documents, audit logs, recovery)
        NAT gateways (2) provide outbound access from the private subnets
```

Region `ap-southeast-1`. Infrastructure is managed by CloudFormation stacks `nuers-production`, `nuers-staging`, `nuers-shared-storage`, `nuers-backup-controls`, `nuers-observability-runtime`, `nuers-server-power-runtime`. `nuers-vapt-20261114` is a temporary penetration-test environment. `nuers-server-power` and `nuers-observability` are leftover `ROLLBACK_COMPLETE` stacks and can be deleted.

## 2. Components

| Component | Detail |
|---|---|
| Web nodes | `nuers-weba`, `nuers-webb`: m6a.xlarge, no public IP, no key pair (access via SSM Session Manager), IMDSv2 required, encrypted gp3 volumes |
| Web server | nginx on port 8080 (site file `/etc/nginx/sites-enabled/nuers-rehearsal.conf`), PHP 8.4-FPM |
| App path | `/srv/nuers/current` -> `/srv/nuers/releases/<release>` (releases are deployed from a tarball payload). `/srv/nuers` is a separate EBS volume; `/srv/nuers/shared` is the EFS mount |
| Database | RDS `nuers-prod`, MariaDB 10.11.19, db.m6g.xlarge, Multi-AZ, encrypted, not public, deletion protection on, 14-day automated backups, error and slow-query logs exported |
| Load balancer | ALB `nuers-prod`, listener 443 only. Target group `nuers-prod-web` port 8080, health check `/infrastructure-health` (served by nginx itself, so it does not test PHP or the database) |
| Certificate | ACM certificate for `nuers.net` |
| DNS | Cloudflare (no Route 53 hosted zones) |
| Secrets | Secrets Manager holds the RDS-managed secret and `nuers/production/recovery-recipient`. No SSM parameters. Where `APP_KEY` and `NUERS_AUDIT_CHECKPOINT_KEY` are kept is not recorded here |
| Cache, session, queue | `.env.example` and `deployment/security/production-overrides.env.example` use the database for all three, so both nodes share them. No Redis or supervisor on the nodes. Live values not verified |

### Services on a web node

- `nuers-scheduler.timer` runs `nuers-scheduler.service` every minute, **on web A only** ("designated web A"). Scheduled jobs (EIS prepare, webhooks, notifications, pruning) do not run if `nuers-weba` is down.
- `nuers-notifications.service`: notification outbox worker, user `nuers`, waits for the EFS mount.
- `nuers-pdf.service` and `nuers-pdf.socket`: isolated, sandboxed PDF renderer.
- `nuers-infrastructure.timer` and `nuers-observer.timer`: approved power-job reconciler and a 30-second health and observation refresh (from the `nuers-server-power-runtime` and `nuers-observability-runtime` stacks).

## 3. Network and security

- ALB security group: inbound 443 only from Cloudflare ranges. Web security group: 8080 only from the ALB security group. DB security group: 3306 only from the web security group. EFS security group: 2049 only from the web security group. No rule is open to 0.0.0.0/0.
- The VAPT security group only allows traffic from itself.
- Client IP chain: Cloudflare -> ALB -> nginx. `/etc/nginx/nuers-trusted-proxies.conf` trusts the ALB subnets (10.84.16.0/24, 10.84.17.0/24) and the Cloudflare ranges, with `real_ip_header X-Forwarded-For` and `real_ip_recursive on`, so nginx sets the real client IP. `config/trusted_proxies.php` trusts the Cloudflare ranges for Laravel. Keep the Cloudflare lists in `nuers-trusted-proxies.conf`, `config/trusted_proxies.php` and the ALB security group identical, and re-check them against https://www.cloudflare.com/ips periodically.
- nginx passes `HTTPS on` and `X-Forwarded-Proto https` to PHP, returns 444 for unknown hosts, and returns 404 for `vendor`, `config`, `artisan`, `storage/logs` and similar private paths.
- Root account: MFA enabled, no root access keys. No IAM users exist yet; day-to-day work should move to an IAM or Identity Center admin user.
- CloudTrail `nuers-security-management` is multi-region. GuardDuty is not enabled.
- All S3 buckets block public access. Versioning is enabled on every bucket except `network-audit`.

## 4. Backups

- RDS automated backups: 14 days, daily snapshots.
- AWS Backup plan `nuers-production-daily`: daily at 17:00 UTC, 7-day retention, covers RDS and EFS, with a copy to the vault `nuers-production` in **ap-southeast-2 (Sydney)**. Recovery points exist daily from 2026-11-06.
- Not covered by AWS Backup: web node EBS volumes (rebuildable from releases) and S3 (covered by versioning only).
- A restore test has not been recorded. Do one and write down the date, the time taken and the data age.

## 5. Monitoring and cost

- CloudWatch alarms: RDS CPU, memory and storage; web A and B CPU, memory, disk and status; backup errors; observer and power-controller errors; shared-storage budget. Staging alarms show INSUFFICIENT_DATA while the staging host is stopped. Alarm notification targets were not checked.
- Budget `nuers-account-monthly`: $1,300. Last 30 days (before tax): about $290 on the services in the Cost Explorer output, mostly EC2 compute ($130), RDS ($92), NAT gateways and EBS ($52 "EC2 - Other"), load balancing ($7) and VPC ($6).

## 6. Known gaps

1. `php artisan security:deployment-check` result not recorded. Run it as the `nuers` user from `/srv/nuers/current`.
2. Single scheduler node (web A).
3. GuardDuty off; confirm alarm and budget notifications reach a monitored address.
4. Backup retention is 7 days for the Backup plan; consider 30 days for financial records.
5. Stale resources: the `nuers-vapt-20261114` stack (delete after the test report), the two `ROLLBACK_COMPLETE` stacks, 34 old release directories and loose tarballs and diagnostic scripts under `/srv/nuers`.
6. The nginx site file is named `nuers-rehearsal.conf`; rename it when convenient.
7. Differences from `stage1-setup.md`: ALB and two nodes instead of one, MariaDB 10.11 instead of MySQL 8, PHP 8.4, EFS instead of local EBS uploads, Sydney instead of Hong Kong for the backup copy.
8. Rotate shared credentials: the root password that was posted in a chat, and the old database credentials that remain in git history (see `stage1-setup.md` section 8).
