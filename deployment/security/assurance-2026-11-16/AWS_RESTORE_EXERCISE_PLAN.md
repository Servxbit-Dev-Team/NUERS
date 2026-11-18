# NUERS AWS production-backup restore exercise — prepared, not executed

The synthetic MariaDB restoration passed on 2026-11-16. This plan covers the separate AWS-provider recovery gap. No production snapshot has been copied to staging under this plan.

## Exact source and destination

Account 289829301267; region ap-southeast-1. Latest completed source backups observed:

- RDS: `arn:aws:rds:ap-southeast-1:289829301267:snapshot:awsbackup:job-7922d798-d04c-b476-0593-8b09a10cc622`, captured 2026-11-15 17:00 UTC, MariaDB 10.11.19, 250 GB allocated storage.
- EFS: `arn:aws:backup:ap-southeast-1:289829301267:recovery-point:965f54b0-495a-4745-9c90-57563a208084`, captured 2026-11-15 17:00 UTC; source filesystem `fs-0209a2a523b43a8d2`.

Restore into NEW, encrypted, explicitly disposable `nuers-restore-drill-20261116-*` resources in the same AWS account and region. Do not restore over `nuers-prod` or the current EFS filesystem. Use the existing isolated database subnet group `nuers-production-dbsubnets-zi4slk7oevrx`; disable public access and Multi-AZ for this temporary drill. Do not connect the existing synthetic staging environment to production data.

## Access and acceptance gate

Initially no application inbound access or EFS mounts. Before any verifier gets access to restored customer records, review and approve the exact verifier IAM/network permissions. The existing synthetic staging role must retain its prohibition on production-data access. Do not expose database credentials, rotate production passwords, copy APP_KEY into staging, send restored notifications, run schedulers, or call BIR/payment/webhook providers.

A reviewer needs to validate the precise restore metadata, supported temporary RDS instance class, existing backup-role restore permissions and KMS grants before executing. Do not broaden those policies implicitly. Verify aggregate table counts, ledger/invoice totals, foreign keys, object references and private-object hashes from an isolated verifier. Record only aggregates and hashes. Recovery jobs reaching COMPLETED alone do not prove application recovery.

## Cost and cleanup gate

Use an incremental planning allowance of US$20 (approximately ₱1,400 at the previously agreed conservative ₱70/USD), within the existing US$1,300 account alert budget. Recheck actual + forecast account costs before provisioning. Set an operator deadline of four hours and a concrete cleanup mechanism before creating resources. This allowance is not a quoted AWS price and the alert budget is not a hard spending cap.

Cleanup is limited to newly created, explicitly disposable restored copies and verifier resources, after recording evidence. Keep original production databases, filesystems, source recovery points, KMS keys and policies intact. Explicit confirmation is required for any new sensitive-data access and final irreversible deletion of restored copies.

## Current status

Not run. Provider restore duration, production-scale RPO/RTO, restored customer-data consistency, KMS recovery and application recovery from AWS RDS/EFS remain unverified. Do not mark this gap closed from the synthetic restoration or successful scheduled backup jobs.
