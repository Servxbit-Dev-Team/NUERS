# NUERS 1.0.0 — Storage operations re-audit

Completed 20 October 2026. The authorized scope is merchant storage and non-BIR hardening. BIR transmission remains deferred and blocked. This is an implementation and first-party verification record, not a security certification or a production RTO/RPO claim.

## Deployed result

Release `2026-10-20.storage-operations` was deployed to nuers.net at **09:13:12 PHT**. Thirty release files passed checksum verification. Existing merchant profiles, API keys, documents, invoices/receipts, transactions, wallet entries/top-ups, security configuration and the environment file passed preservation-hash checks. Old hashed assets and private rollback backups are retained. Only the forward-only `operations_observations` table was added.

An initial live verification found that Live intake has no environment column because Sandbox uses a separate table. The installer restored previous files. The health query was corrected to count the two physical stores separately, a non-empty fixture regression test was added, and the corrected release passed. No business data was rewritten to satisfy verification.

The origin certificate was installed with the owner's approval of the Let's Encrypt terms. It covers nuers.net only, expires January 18, 2027 and is marked for automatic renewal in Plesk. Direct origin HTTPS was verified with certificate and hostname checks enabled. No wildcard, www or mail certificate was added. Public responses now include HSTS, nosniff, frame denial, no-referrer, restricted device permissions and a baseline CSP.

## Live evidence

- Database query, shared cache read/write and locking, private storage read/write and capacity: passed. Approximately 68.36 GiB free at deployment.
- Privileged MFA enforced; debug disabled; HTTPS application URL and encryption-key length checks passed.
- Email and webhook worker heartbeats fresh; no due backlog or failed/uncertain outcomes reported in the observed 24-hour window. This is not proof of end-to-end merchant callback delivery.
- Both audit chains valid; wallet mismatches: zero.
- Twelve Live intake records and zero Sandbox records observed; no incomplete intake older than five minutes.
- Read-only follow-up at 09:15:25 PHT confirmed a newer **scheduled** runtime observation at 09:15:04, not just the deployment verifier's one-time observation.
- Anonymous sensitive endpoints returned 401. Environment, application log, dependency manifest and private documentation paths returned 403. The private installer is outside the web root; its public-looking URL returns the generic SPA shell and exposes no PHP source. Deployed JS/CSS hashes match local release files.

In the admin Operational Readiness page, core storage health, audit/wallet integrity and recovery evidence are separate. Stale observations cannot retain a healthy label. The page refreshes every minute; age also expires locally while it remains open. Alerts are internal and deduplicated. The checks never send BIR transactions or repair financial records.

## Verification

Final isolated suite: **549 tests, 5,994 assertions, all passed**. Seven desktop/mobile UI checks passed, with external requests blocked and synthetic login only. Both production builds completed. The large application bundle warning remains a performance optimization opportunity, not a measured capacity result.

Recovery tests reject wrong keys, modified/truncated archives, trailing bytes, unsafe paths and existing restore targets. An actual isolated encrypted SQLite/object recovery restored two synthetic merchants, forty receipts, both audit chains, wallet records and private objects. Eight table hashes and two object hashes matched. The observed local restore interval was 0.016 seconds and checkpoint age 0.072 seconds; these tiny synthetic-fixture measurements are **not production RTO or RPO**. A deliberately created post-snapshot record was correctly absent after recovery.

## Remaining dependencies

The owner confirmed no separate backup/DR destination exists. The encrypted local archive tooling and runbook are deployed but remain opt-in and disabled until a private directory, separate random recovery key, independent key custody and retention/capacity controls are provisioned. Plesk showed seven daily backup records; that metadata alone does not prove encryption, offsite custody or restore success.

Production MySQL restore/reference reconciliation, independently stored immutable backups, failover, external uptime/TLS-renewal alerts, capacity testing and an independent security retest remain unverified. A merchant-controlled HTTPS callback receiver is still awaited. Plesk exposes only detection-mode Comodo firewall controls for this domain and displays a license grace-period warning; provider/server administration must resolve these without changing unrelated hosted sites. Planned Dataplugs/Contabo/AWS regional HA is not presented as deployed infrastructure.

## Evidence and runbook

- `output/storage-operations-audit/live-deployment-result.json`
- `output/storage-operations-audit/live-http-checks.json`
- `output/storage-operations-audit/scheduler-verification.txt`
- `output/storage-operations-audit/verified-suite.xml`
- `output/storage-operations-audit/ui-results.json`
- `output/storage-operations-audit/recovery-lab.json`
- `docs/infrastructure/storage-operations-and-recovery.md`

Package SHA-256: `345ac1a465a108091bf2c07f13a9a57bb14ac04b59d75718e6c2860d011216a3`.
