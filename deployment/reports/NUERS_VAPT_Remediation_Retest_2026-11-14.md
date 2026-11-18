# NUERS VAPT remediation and production retest

**14 November 2026.** The reviewed security candidate was tested on isolated Linux nodes and deployed to both production web hosts as **`production-20261114-vapt1`**. Both load-balancer targets are healthy. Confirmed findings VAPT-01 through VAPT-06 are remediated within the assessed scope. An independent authenticated VAPT and full infrastructure assessment are still required for an external pass/sign-off.

## Finding disposition

| Finding | Implemented and verified result |
|---|---|
| VAPT-01: phpseclib advisory | Locked runtime updated from 4.0.2 to **4.0.3** on both hosts; zero Composer advisories in the reviewed build. |
| VAPT-02: Laravel advisory | Updated from 12.70.0 to **12.71.0** on both hosts. |
| VAPT-03: cross-environment database grants | Removed the production runtime account’s four rehearsal DML privileges. Remaining grants are production-only SELECT/INSERT/UPDATE/DELETE, global USAGE, no GRANT OPTION or per-table grants. Live harmless reads confirm production allowed and rehearsal denied on both nodes. No new database identity or customer record was created. |
| VAPT-04: privileged session/MFA lifetime | Enforced **30-minute idle / eight-hour absolute / five-minute recent MFA** for privileged actions. Added two nullable session metadata columns through a separate deployment identity. Production timezone remains UTC. |
| VAPT-05: session replacement/reset races | Scoped fixes deployed; regression and simultaneous cross-node login/logout/MFA tests pass. Merchant/client convenience policy remains separate. |
| VAPT-06: runtime access to migration archives | Added a narrow explicit deny for the production bucket’s `migration/*` objects. Actual runtime HEAD requests are denied on both hosts; the separate operator retains artifact access. IAM simulation preserves ordinary document Get/Put and denies archive Get/Put. Initial live verification was retried after IAM propagation. |
| GAP-01: source and testing provenance | Established the user-authorized reconstructed source baseline, repeatable Linux/Mac build and expanded regression suite. Original source was not recovered; complete historical 1:1 behavior is not proven. Independent VAPT and broader coverage remain assurance limits. |

## Acceptance evidence

- **805 tests / 10,022 assertions**, zero failures/errors/skips, on Linux/PHP 8.4.27 with production UTC timezone. Separate Manila-time run also passed.
- MariaDB 10.11.19 security **23 / 1,648**; concurrent wallet **2 / 19**; concurrent registration OTP **2 / 39**.
- **28 cross-node checks** for sessions, device binding, replay, step-up, expiry, tenant isolation and shared throttling; **four cross-host wallet integrity checks**.
- **557 registered route/method negative or public probes**, including 480 bearer-protected probes returning 401. Admin namespace role checks and sensitive-operation MFA gates pass. Two shadowed web handlers are flagged; their SPA responses are not direct handler coverage.
- **233 generated files** match across two Linux builds and a fresh locked Mac build; a clean repeat also matches. Tailwind’s explicit source scan removed generated-file contamination.
- Antivirus enforcement, harmless EICAR rejection, fresh signatures, scanner fail-closed behavior, isolated container metadata access and native sandboxed PDF rendering verified.
- Production smoke checks on both hosts: health/home/login/API health, anonymous protected denial, served JS/CSS hashes, dependency versions, session settings, storage permissions, scanner signatures and synthetic invoice PDF generation.

The release artifact SHA-256 is **`72fc77ae33ffd0dbc94b0069dc89db22a0fc181a15f082349a4ea56057bc362f`** (111,334,363 bytes). It contains the 16 scoped backend runtime changes, 533 reconstructed source/static/config files, 233 generated files and fresh locked production dependencies. All 36,294 inventoried regular files were verified on each prepared release. The unrelated dirty workspace was not deployed wholesale. No local `.env`, customer snapshot or staging credentials were copied into production.

## Rollout and recovery

The preserved prior release is `production-20261106-analytics-grouping2`. RDS retains its existing 14-day point-in-time recovery configuration. Only an instant additive schema migration and its migration tracking row were applied; customer document/financial rows were not rewritten.

The first rolling attempt encountered an orchestration error because the second web host has no notification-worker unit. The guard initiated rollback; its same service assumption required a follow-up peer health check and re-registration. Both old releases were verified healthy before retry. The corrected orchestration restarts the worker only where active and prevents draining a serving target while its peer is unhealthy. The retry completed with both new targets healthy. Failure and recovery evidence were retained.

The PDF worker’s production source was recovered read-only and archived in `deployment/pdf-runtime`; its sandbox was not weakened or replaced. Native production PDF probes used synthetic content and left no issued document or test account.

Both temporary Linux test instances are **stopped**. Their encrypted disks remain retained; storage charges continue. Audit port-forward listeners were closed. No production capacity increase was made.

## Budget and remaining limits

The user’s limit is **₱100,000/month**, and the existing NUERS infrastructure configuration reports that ceiling. Known AWS fixed baseline is approximately **₱83,514/month** using 730 hours, an assumed ₱65/USD and a 12% tax allowance. Variable usage and exchange-rate changes can consume the remaining headroom. This is not a guaranteed bill ceiling; no AWS billing hard cap or notification subscription was configured. See `NUERS_AWS_Budget_Check_2026-11-14.json` for assumptions and exclusions.

These results make the scoped remediation ready for independent assessment; they are not a penetration-test certificate. Every payload/role/resource combination, external OAuth/mail delivery, denial-of-service load, DOM-XSS possibility and cloud/OS attack path has not been exhaustively tested. Production customer logins and financial operations were not used as destructive tests.

Detailed results: `NUERS_VAPT_Retest_Evidence_2026-11-14.json`, `NUERS_Linux_Multinode_Acceptance_2026-11-14.md`, `NUERS_Endpoint_Coverage_2026-11-14.csv`, and `NUERS_Production_Rollout_2026-11-14.json`. The earlier local-only report is preserved as `NUERS_VAPT_Local_Retest_PreDeployment_2026-11-14.md`; the initial audit remains historical evidence.
