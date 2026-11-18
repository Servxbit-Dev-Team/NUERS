# NUERS 1.0.0 — Storage operations and recovery runbook

Revision: 2026-10-20. Scope: merchant data stored in NUERS. BIR connectivity is deferred and the blocked transport remains enforced. This runbook supplements the technical manual and DR plan; it does not certify production RTO/RPO, high availability or provider residency.

## Operating controls

The existing Plesk minute scheduler invokes Laravel `schedule:run`. `operations:check` runs every minute and records database, cache read/write and locking, private storage capacity, debug/MFA/HTTPS/key configuration, intake commits, delivery backlog, and email/webhook worker heartbeats. Observation timestamps expire after three minutes; missing observations must never be treated as healthy. A heartbeat means a worker ran, not that a recipient received a message. Failed/uncertain outcomes require investigation before retry. Check `/super-admin/security` and Infrastructure readiness for actual observations.

`operations:integrity` runs hourly. It verifies both audit chains and wallet hashes, sequences, balances and orphan entries using a consistent read snapshot. It records discrepancies and deduplicated internal operational alerts; it never repairs or rewrites a ledger. Restrict reports to authorized administrators; no operational observations are available through the generic merchant table API. Neither command contacts BIR or external callback endpoints.

If observations are stale: inspect the Plesk scheduler execution result and PHP version, application logs, shared-cache locks and worker heartbeats. Confirm database/storage access, available disk and queue age. Preserve evidence before remediation. If integrity fails: suspend affected intake through the existing incident/access controls, retain ledger/audit copies, investigate the exact source and reconcile through an approved process. Do not edit hashes to make the check pass.

## Actual infrastructure observations

On 2026-10-20, Plesk listed seven scheduled server backups, including the latest incremental backup at 00:12 and a full backup dated October 14. This is provider backup metadata only: encryption, independent custody, offsite retention and restorability have not been verified. The optional NUERS archive tool described below remains disabled until its dedicated storage and key are provisioned.

The nuers.net origin now has an automatically renewed Let's Encrypt certificate, expiring January 18, 2027. HTTP redirects to HTTPS. Domain-level headers apply HSTS without includeSubDomains/preload, nosniff, frame denial, no-referrer and restricted camera/microphone/geolocation. A baseline CSP prevents framing, object plugins and foreign base URLs. Laravel retains its stricter API response policy. Certificate renewal and independent external uptime alerts still require operational monitoring.

Dataplugs, Contabo and AWS Singapore/Hong Kong remain declared/planned providers or regions where not actually provisioned. No nine-node HA, remote object lock, replica or DR failover is asserted. The domain firewall exposes detection-only Comodo rules; no blocking-mode control is available at this domain scope. A provider/server administrator must review blocking enforcement and false positives without changing unrelated hosted sites. Plesk reports a license grace-period warning; the account owner must resolve licensing with the provider.

## Secure local archive setup (operator-only)

1. Provision a dedicated private directory outside every web/application root, mode 0700, owned by the application backup operator. Reserve sufficient capacity: the tool checks at least three times its input size plus 1 GiB. Default uncompressed input cap is 5 GiB. Plan retention and capacity before enabling daily runs; the tool never automatically purges archives.
2. Generate a separate, cryptographically random 32-byte binary recovery key in a private directory outside both the application and archive directories, mode 0600. Do not print it, commit it, upload it to the site, or put it in the archive. Escrow a protected copy with independent access control. Losing it makes archives unrecoverable.
3. Set `NUERS_BACKUP_DIRECTORY`, `NUERS_BACKUP_KEY_FILE`, and the absolute installed `NUERS_MYSQLDUMP_PATH` in private deployment configuration. Set `NUERS_LOCAL_BACKUP_ENABLED=true` only after a successful manual check. Preserve the existing APP_KEY separately: encrypted database fields require it after recovery. Other infrastructure credentials and environment configuration require a separate reviewed recovery inventory.
4. Run `php artisan operations:backup` as the application operator. Daily scheduling is at 02:30 in the configured application timezone, with overlap prevention. Inspect exit status and `operations_observations`; a file's presence alone does not prove successful capture.
5. Run `php artisan operations:verify-backup ARCHIVE_ID`. The identifier is returned by the backup command. It authenticates the complete encrypted stream, verifies the manifest, extracts into a fresh private temporary directory, and checks every file's size and SHA-256. It never imports into the production database or overwrites an existing directory. On MySQL, successful archive verification is explicitly **not** database/application restore verification.

MySQL capture uses an installed trusted mysqldump client, an InnoDB consistent transaction and a temporary mode-0600 credentials file. Passwords are not command arguments or logs. Remote database connections are refused until a separate TLS client profile is reviewed. SQLite capture/verification is permitted only for isolated local/testing environments. Database snapshots and independently checked object inventories are not claimed to be one atomic application snapshot: reconcile all database object references in the recovery environment. Do not overlap schema changes/deployments with capture.

Archives use libsodium XChaCha20-Poly1305 secretstream with an authenticated final marker. Truncated, modified, wrong-key or trailing-data archives are rejected. A SHA-256 manifest checks recovered files; path traversal, duplicate entries, excessive size and overwrite attempts are rejected. Plaintext staging is private and cleaned in normal completion/failure paths; monitor and securely clean interrupted staging only after confirming no active capture. The database may contain encrypted credentials and personal/business data, so treat the entire archive as sensitive even when encrypted.

## Production recovery acceptance procedure

Provision an isolated recovery network with outbound customer email, webhooks and BIR delivery disabled. Recover the exact application release, dependencies and separately escrowed configuration/key material. Authenticate the archive; import the database into a **new** recovery database. Restore objects to a **new** storage tree, map permissions, and verify object references and encrypted-field readability. Run both audit-chain checks and every wallet reconciliation. Verify counts, totals, idempotency identities, numbering, Live/Sandbox separation and cross-merchant denial tests. Do not replay accepted transactions or send delayed emails automatically.

Record incident/outage time, last committed source timestamp, recovered checkpoint and completion of actual application acceptance checks. Calculate RPO from source versus restored committed data, and RTO from outage to authorized service restoration. Test private recovery-key access and escalation. A reviewer must validate results before any production cutover. Only then redirect traffic, resume safe workers and monitor backlog. Preserve the prior instance and a rollback decision point. A local archive restore alone is not failover, offsite protection or an SLA result.

## Isolated lab evidence

Reproduce with `APP_ENV=testing php tests/Support/storage-recovery-lab.php`. It uses disposable `/private/tmp/nuers-storage-lab-*` SQLite databases, synthetic records and no outbound delivery. It captures and restores two merchants, forty receipts, wallet entries, both audit chains and private objects, compares eight table hashes, and demonstrates that a post-snapshot record is not recoverable from that snapshot. Sanitized results are saved to `output/storage-operations-audit/recovery-lab.json`. The measured archive/SQLite restore duration and checkpoint age are lab figures only; no production RTO or RPO is established.

## Remaining deployment prerequisites

An independently provisioned encrypted offsite target with retention/immutability, recovery key custody, actual production MySQL import/restore and failover exercises, external uptime/TLS renewal alerting, workload capacity testing and independent security assessment remain required. A merchant-controlled HTTPS callback receiver is still needed for end-to-end webhook verification. BIR connectivity is deliberately excluded from this phase.

Technical references: [PHP secretstream initialization](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-init-push.php), [PHP authenticated stream messages](https://www.php.net/manual/en/function.sodium-crypto-secretstream-xchacha20poly1305-push.php), [MySQL backup manual](https://downloads.mysql.com/docs/mysql-backup-excerpt-8.0-en.pdf).
