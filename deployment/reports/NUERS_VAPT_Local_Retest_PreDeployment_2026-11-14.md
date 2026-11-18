# NUERS local VAPT remediation and retest

**Date:** 14 November 2026, Asia/Manila  
**Candidate:** local changes against AWS release `production-20261106-analytics-grouping2`  
**Decision:** Confirmed dependency and session findings are remediated locally and regression tests pass. Database isolation is enforced locally; the production grant change and the newly identified S3 migration-archive access restriction are prepared/tested but not applied. The authorized reconstructed frontend now builds and passes local regression checks; original source identity is not recovered. Complete VAPT sign-off remains open.

This follows `NUERS_VAPT_Readiness_Audit_2026-11-14.md`. No live application deployment, production permission change or live customer-record mutation was performed. AWS source discovery was read-only. Localhost intentionally now differs from live because it contains the security candidate.

## Finding disposition

| Finding | Local result | Production / remaining acceptance |
|---|---|---|
| VAPT-01: affected phpseclib | Updated 4.0.2 → 4.0.3; Composer constraint rejects older 4.x; installed version and lock agree | Deploy locked candidate and re-audit both nodes |
| VAPT-02: affected Laravel | Updated 12.70.0 → 12.71.0; framework floor raised | Deploy locked candidate and re-audit both nodes |
| VAPT-03: cross-environment DB grants | Dedicated local runtime identity has SELECT/INSERT/UPDATE/DELETE only on the local snapshot; harmless cross-database read denied; no global data, DDL or GRANT OPTION | Production/rehearsal separation still pending. Reviewed SQL and prerequisites in `deployment/security/production-runtime-grants.sql` |
| VAPT-04: excessive privileged session/MFA lifetime | Admin/super_admin/BIR/RDO: 30-minute idle, eight-hour absolute, five-minute MFA freshness for mutations and exports. Server caps cannot be increased by environment settings | Roll out additive migration and code on both nodes; complete production-equivalent multi-node testing |
| VAPT-05: session replacement/reset races (found during remediation) | Password reset invalidates an earlier authentication result before token issuance; an in-flight old logout cannot revoke a replacement device session. Both interleavings failed before the fix and pass afterward | Deploy scoped changes; retain multi-node staging acceptance |
| VAPT-06: runtime role can read/write migration archives | Targeted deny policy prepared; AWS simulation denies migration archive Get/Put/Delete while preserving ordinary document Get/Put | Pending reviewed production IAM application. Existing versioning/public-access/TLS controls reduce exposure but do not remove runtime access |
| GAP-01: incomplete tests/source provenance | Restored factory and 78 historical regression classes; reconstructed missing frontend from retained source and live bundles. Typecheck/build pass; repeat build matches 233 files. Final suite: 805 tests / 10,022 assertions | Original developer source was not recovered. A new source baseline and hashed archives are established, with UI/business equivalence limits. Independent authenticated VAPT and Linux multi-node staging remain open |

Only two installed Composer package versions changed from the synchronized base: Laravel and phpseclib. No major-version upgrade was performed. Current Composer audit reports **zero advisories**; npm lockfile audit reports **zero vulnerabilities**. Those point-in-time results do not prove application-level security or validate unproven frontend source provenance.

## Implemented session behavior

`SessionPolicy` selects privileged limits independently from merchant/client limits. Only accepted foreground activity renews idle expiry; background polling does not. Expired sessions cannot be revived by activity. Absolute expiry, new-device replacement and logout remain enforced. New login clears prior MFA assurance and pending challenge metadata.

A privileged mutation or export with stale MFA returns 403 `MFA_REQUIRED` / `RECENT_MFA_REQUIRED`. The token-specific persisted challenge makes the existing MFA status report `verified=false`, preventing the deployed screen from immediately redirecting back in a loop. Verification consumes a current authenticator or unused recovery code; replay is rejected. Infrastructure step-up retains its stronger password plus authenticator requirement. Rejected actions are not automatically replayed.

The additive migration introduces `api_last_activity_at` and `api_mfa_challenged_at`. It was applied only to the local database. Legacy privileged sessions without activity timestamps use their login time and may need a new sign-in. Merchant/client session limits were not shortened. Role-specific policy metadata and the knowledge-base policy were updated.

## Retest evidence

| Check | Result |
|---|---|
| Complete currently available PHPUnit suite, isolated SQLite | **805 tests / 10,022 assertions; zero failures/errors** (PHP 8.4.26; includes adapted historical regression coverage) |
| Earlier security suite, disposable local MySQL 9.5 database | **21 tests / 1,640 assertions; zero failures/errors** |
| Final combined boundary, endpoint and session suite, MariaDB 10.11.19 | **23 tests / 1,648 assertions; zero failures/errors** after frontend reconstruction |
| Earlier stable session group, MariaDB 10.11.19 | **8 tests / 56 assertions; zero failures/errors**, including both new race regressions |
| Concurrent wallet operations, disposable MariaDB 10.11.19 | **2 tests / 19 assertions**: competing withdrawals cannot overdraw; four replayed credits create one ledger entry |
| Concurrent registration OTP, disposable MariaDB 10.11.19 | **2 tests / 39 assertions**: four correct requests issue one proof; seven incorrect requests stop at five attempts and reject a later correct code |
| Prepared database grant change, disposable MariaDB | Exact reviewed REVOKE tested: each synthetic identity can write its own database and is denied the other; fixture databases/users removed |
| Session race reproductions | Before: **2 failures / 6 assertions**. After: session group **8 tests / 56 assertions** on SQLite; final MariaDB session rerun also **8 tests / 56 assertions** |
| Registered routes | **371 route definitions / 557 method-template probes** (327 unique URI templates) |
| Anonymous bearer-protected method probes | **480 / 480 returned 401** |
| Remaining route categories | 46 public, 12 separately authenticated, 19 web method probes; no unexpected 5xx |
| Administrative namespace | Merchant, client and consumer roles denied across registered admin methods |
| Privileged sensitive boundary | Stale MFA rejected before handlers across registered protected mutations and recognized export methods |
| Original deployed browser assets + local backend (earlier run) | Initial login/TOTP succeeds; stale-MFA CSV export redirects to MFA; challenge remains visible; verification returns to invoices; expired session redirects to `/login?session=invalid` |
| Normal localhost service | `/api/health` returned HTTP 200 after local changes (liveness check, not database coverage) |
| Local DB isolation | Allowed local database read; separate synthetic database read denied |
| Reconstructed JS/CSS and SSR | Candidate installed locally; 233 generated hashes match the reproducible build. Original live assets retained only as comparison evidence |
| Frontend automated checks | Typecheck/build pass; 9 regression tests and 11 session-client scenarios pass |
| Reconstructed browser workflows | Synthetic admin/merchant login and TOTP, invalid-session logout, infrastructure telemetry/capacity, VAT-basis changes, policy eligibility, role dependencies and 390 px responsive layout verified |

The MySQL test database and test account were created solely for synthetic tests and removed afterward. Migration setup used a separate local administrator identity because trigger creation required elevated migration privileges; the application runtime identity was not broadened. Browser checks used a separate synthetic SQLite database and localhost port 8002. No actual merchant/admin identity, OTP, private financial record or outbound mail was used. No test queue worker was started.

**Coverage limits:** “All endpoints” means every registered route/method received a documented boundary or public negative probe, not that every handler branch and payload was penetration-tested. These checks include HEAD, GET, POST, PUT, PATCH and DELETE as registered. Two storage GET/HEAD handlers are shadowed by the SPA catch-all and are explicitly flagged in the CSV; their 200 SPA response is not evidence of private-file access. Empty public mutations were rejected, except intentionally idempotent logout. Authentication, tenant separation, OTP replay/lockout, integration key scopes, query identifiers, SSRF destination checks, webhook signature binding and CSV escaping have additional targeted tests. The recovered suite expands valid business flows, upload rejection, document integrity, integration, report, backup and infrastructure regression coverage. Every role/resource combination, adversarial scanner/resource-exhaustion behavior, all financial/concurrency interleavings, actual OAuth delivery, full DOM-XSS and cloud IAM/storage/OS coverage are not exhaustively established.

The expanded suite ran on **PHP 8.4.26**, and separate database tests used **MariaDB 10.11.19**, matching the production database version. Production PHP is **8.4.27** on Linux; the local host is macOS, so exact runtime/OS and multi-node equivalence remain open. Earlier checks used PHP 8.5.10/MySQL 9.5. The isolated MariaDB used a private Unix socket with TCP networking and scheduled events disabled. Its test-only `innodb_flush_log_at_trx_commit=2` setting improves disposable-test speed; crash durability was not tested and no production setting changed.

## Source recovery result

The user confirmed Git is outdated and the authoritative files are on live. Read-only inspection of the active AWS release, neighboring deployment releases, common source/archive locations, CloudShell deployment archives and the recovery bucket's migration prefix did not locate a complete matching frontend project. The CloudShell archives contain zero to four TS/TSX source files each and no `src/main.tsx`. GitHub HEAD `02bbfb5967dd680a598e0794d84dba440a58091b` is dated 30 October and differs from the 6 November release. It was cloned separately for inspection, not substituted for live.

Additional read-only discovery inspected both web nodes, 12 CloudShell patch tarballs, three setup/verification zip archives, and two full runtime archives in S3. The full runtime archives contain **zero TS/TSX source files and zero test files**. No CodeBuild projects or CodePipeline pipelines were listed. The stopped staging instance was inspected through a temporary encrypted snapshot clone mounted read-only (`ro,nosuid,nodev,noexec,norecovery`). It remained stopped and its original disk was not modified. The disk contains three deployed releases, **zero unpacked TS/TSX source files and no Git repository**. Its two deployment archives (13,601 and 12,058 members) each contain **zero TS/TSX source files, zero source maps and zero tests**. None of the three releases contains the current live entry asset. The clone was unmounted and both temporary AWS resources deleted; verification showed zero temporary volumes/snapshots and the original staging disk still attached to the stopped instance. Live and localhost health both returned HTTP 200 afterward. See `NUERS_Source_Recovery_2026-11-14.json` for precise evidence and scope.

The initial retained source failed typechecking with missing infrastructure modules and outdated contracts. After the user confirmed there was no further source backup and explicitly authorized reconstruction, the missing components and feature differences were reconstructed from local source, compiled live assets and backend contracts. The source now typechecks, the browser bundle and document renderer build, and two builds match across 233 generated files. All 56 original source manifest entries remain present. The new candidate is installed only locally; live remains unchanged. MFA guidance now reflects privileged step-up. This is a reconstructed source baseline, not original-source recovery or proof of complete 1:1 equivalence. See `NUERS_Frontend_Reconstruction_2026-11-14.md` and `NUERS_Frontend_Reconstruction_Provenance_2026-11-14.json` for exact inventories, archives, comparisons, browser evidence and limitations.

## Regression recovery and test validity

Recovered 78 test classes from the separate 30 October Git clone, preserving original hashes in `tests/Recovered/provenance.json`. The adaptation notes are in `tests/Recovered/README.md`. Fixtures now supply current session timestamps, document requirements, configured quota buckets and eligible synthetic merchant industries. Privileged tests expect fresh MFA; production eligibility/authentication controls were not relaxed to make historical tests pass. Domain fixtures retain their historical MFA-disabled default; the separate endpoint/session security tests explicitly exercise the enabled boundary. Real local PDF tests use installed Chrome, while mail and HTTP transports are controlled. A no-op default seeder avoids historical demonstration account creation.

The additional concurrent registration tests exercise real HTTP-kernel requests in separate processes against synthetic MariaDB records: four correct OTP requests yield one proof; seven wrong requests consume exactly five attempts, and a later correct code is rejected. Both tests passed (39 assertions), with no external mail or HTTP calls.

The first MariaDB run completed 21 tests with two MFA-status failures because `MfaSession` had been loaded before its `fresh()` method was added during concurrent development. The error log records the undefined-method error. This intermediate run is retained as failed evidence; it is not counted as a clean release run. The earlier stable session-only rerun passed **8 tests / 56 assertions**. After frontend reconstruction, the full combined MariaDB security suite was rerun and passed **23 tests / 1,648 assertions**, superseding the split intermediate acceptance runs. Temporary test databases and users were independently counted afterward: **zero remaining**. The dedicated test server was shut down after verification.

## Review and deployment artifacts

- `NUERS_Endpoint_Coverage_2026-11-14.csv`: every method/path probe, category, action, result and shadowing flag.
- `NUERS_Remediation_Manifest_2026-11-14.json`: scoped backend candidate/base hashes and retained baseline assets; paired with the separately inventoried reconstructed frontend.
- `NUERS_Frontend_Reconstruction_Provenance_2026-11-14.json`: 532 source/configuration/test/public-asset files, 233 generated files and verified archive hashes. The original source was not recovered.
- `deployment/security/VAPT_ROLLOUT_2026-11-14.md`: staging, migration, identity isolation, two-node verification and recovery plan.
- `deployment/security/privileged-session.env.example`: nonsecret policy values only.
- `docs/knowledge/mfa-login-session.md`: updated role-based session/MFA policy.
- Durable tests: `tests/Feature/SecurityBoundaryTest.php`, `PrivilegedSessionTest.php`, `EndpointSecurityTest.php`, `tests/Recovered/`, `tests/WalletConcurrencyTest.php`, and guarded helpers under `tests/Support/`.
- `tests/Support/run-isolated-mariadb.php [security|sessions|wallet|registration]`: reproducible local-only synthetic harness; refuses an unexpected database version/datadir, creates a unique DML-only runtime identity and removes the temporary database/identity afterward. Requires the deliberately provisioned isolated local MariaDB socket, never the customer snapshot.
- `NUERS_Runtime_Archive_Permissions_2026-11-14.json`: observed runtime S3 permissions and before/candidate policy-simulation results; no live IAM change.
- `NUERS_Source_Recovery_2026-11-14.json`: source-search locations and remaining limitations.
- Private local evidence: `.local/vapt-remediation-2026-11-14/` contains Composer/npm audit JSON, PHPUnit logs/JUnit XML, route inventory, isolation results and source comparison. Private environment backups and fixture credentials in that directory must not be committed or published.

Run the expanded isolated suite from the project root with `/opt/homebrew/opt/php@8.4/bin/php vendor/bin/phpunit`; the security tests explicitly use in-memory SQLite unless the guarded disposable MySQL fixture is supplied. Never point migration-reset tests at production or the copied customer database.

**Next gate:** reproduce and accept the reconstructed source baseline on production-equivalent Linux multi-node staging, complete broader UI/business acceptance, apply and verify the reviewed production changes through the deployment gate, and obtain independent authenticated VAPT coverage. Original developer source remains unavailable; the source-search gate is superseded by the user's authorized reconstruction and its documented equivalence limits. No VAPT pass/certification is asserted by this report.
