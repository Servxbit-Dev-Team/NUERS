# NUERS security remediation and deployment retest

Date: 15 November 2026. Scope: owned NUERS application, isolated local fixtures, and controlled public checks against https://nuers.net.

## Status

**Deployed and verified: `production-20261115-input1` on both production nodes. Both targets are healthy.** The final controlled live campaign passed 59/59 checks, with seven requests blocked at the Cloudflare edge. This is not independent VAPT approval.

## Remediation

The reconstructed frontend received session-bound response/body/stream handling, cross-tab export cleanup, removal of persistent dashboard financial caches, bounded API paths/redirects, safe MFA return navigation, malformed-response handling, login-race protection, and accurate mutation-timeout feedback. Details and before/after regressions are in `NUERS_Frontend_Coverage_Retest_2026-11-15.md`.

The additional attack-input matrix reproduced two backend problems:

1. Bracket-form `eq[id]` reached a JSON-only parser in the privileged own-profile MFA exception and caused HTTP 500. The middleware now accepts JSON or array filters, requires a scalar matching account ID, and keeps MFA enforcement for foreign/nested IDs.
2. Nested equality/in-list values were not consistently rejected. The generic table controller now rejects nested filter values with HTTP 422. No unauthorized data disclosure or SQL execution was demonstrated; this is validation hardening.

Both failing regression cases passed after the changes. The backend patch contains only two runtime PHP files and its five-case regression test. No production schema or customer-data migration was needed.

## Test evidence

| Layer | Result and scope |
|---|---|
| Local backend suite | 810 tests, 10,055 assertions; zero failures, errors or skips. PHP 8.5.10, isolated SQLite and synthetic records. |
| Frontend/session regressions | 37 checks passed: 13 transport/auth, 13 reconstruction/rendering, 11 session-client. |
| Endpoint inventory | 557 registered route/method negative/public probes, plus administrative role and recent-MFA checks. Two shadowed storage routes are recorded as not reaching their intended handlers. This is not every payload/role/resource combination. |
| Final live probes | 59/59 passed after the final deployment; 7 edge-blocked. Public availability remained HTTP 200 after probing. |
| Native Linux input checks | 13 synthetic in-memory checks per PHP 8.4.27 node; production device binding retained. No customer records used. |
| Dependency advisories | npm audit: zero reported vulnerabilities. Composer locked audit: no advisories or abandoned packages reported at execution time. |
| Reproducible frontend build | 237 generated files matched across two builds using the same installed locked dependencies. |
| Browser behavior | Merchant CSV export, admin TOTP, cross-tab sign-out closing an export dialog, and expired-session automatic logout passed on synthetic localhost fixtures. |

## Attack categories exercised

- Authentication bypass: missing/forged credentials, expired/revoked/device-mismatched sessions, stale login/logout races, role header spoofing, administrative aliases and encoded paths.
- Authorization and tenant isolation: foreign records, downloads, reports, table filters, account ownership, integration key scopes, revocation, and privileged MFA boundaries.
- Injection and malformed input: SQL identifier/value probes, nested query shapes, receipt HTML escaping, unsafe SVG exclusion, CSV formula injection, malformed/oversized report streams, and public reflected payload probes.
- SSRF and webhook integrity: private/special network destinations, DNS rebinding controls, credentials/ports/redirect destinations, body/event/timestamp signature binding and replay/expiry rejection in isolated tests.
- Authentication workflow abuse: OTP lockout/replay, TOTP replay, sensitive-operation step-up, API credential lifecycle, and session lifetime limits.
- Exposure and transport: private-file/traversal probes, CORS/preflight, TRACE, cache/security headers, TLS 1.2/1.3 certificate validation, and anonymous session-cookie flags.
- Upload and business-integrity regressions: existing synthetic suite covers validation/scanning and protected financial/provisioning operations; production PDF/scanner/permission smoke checks are deployment gates.

## Test-harness correction

The first native input probe omitted the production-required device binding and correctly received HTTP 401. Laravel's registered exception handler also caused the standalone script to exit zero despite an uncaught assertion. Neither result was accepted as a pass. The probe now creates a synthetic device-bound session, catches failures with exit 1, and deployment requires the explicit successful assertion marker. All 13 checks subsequently passed on each node before switching. Production authentication was not weakened.

## Limits and remaining assessment

This is a bounded engineering security retest, not all known attacks or a guarantee of VAPT certification. Historical recovered domain tests sometimes disable MFA for business-rule fixtures; current dedicated boundary tests explicitly enforce it. Public probes are low-volume and anonymous. Cloudflare-blocked payloads do not prove backend handling; authenticated mutation, tenant, replay and upload cases use isolated fixtures.

Independent authenticated VAPT, exhaustive input/sink and browser/role workflows, offline reconnect/key-rotation fault injection, OS/cloud exploitation, exhaustive TLS cipher/downgrade checks and load/DDoS testing remain outside this campaign. No live brute-force stress or customer financial mutation was performed.

Cloudflare serves HSTS with max-age 15552000 (approximately 180 days), whereas the application origin sets a longer policy. This is an edge-policy difference to review with the domain's subdomain requirements; it is not recorded as an authentication or data-exposure exploit.

## Recovery and operational scope

Deployments use inactive releases and one-node-at-a-time health gates. Preserved previous releases support rollback. Application environment, DML-only database account, root-owned code, restricted runtime archive access and shared storage layout are retained. No new AWS capacity was created; temporary staging instances were verified stopped. Both nodes passed 250 scoped file hashes, root ownership/non-writability, unchanged environment mode 0640, and runtime denial of deployment-archive access. Public JS/CSS hashes match the reviewed build. Both nodes also passed synthetic PDF and scanner/permission checks.

Local logs and test inventory: `.local/security-campaign-2026-11-15/`. Final downloaded cloud evidence, local test inventory, JUnit, dependency audits and SHA-256 manifest are in `security-retest-2026-11-15/summary.json`. The downloaded evidence ZIP hash is `d9037285fa0b267c220fad628e606f3a32a54d02bea53a15d6d285f7ccc4d234`.

## Deployment timestamps and artifacts

- Final rolling deployment completed at 02:07:27 UTC (10:07:27 PHT), 15 November 2026.
- Final public campaign completed at 02:08:57 UTC (10:08:57 PHT).
- Frontend patch: 247 files, SHA-256 `d9e869f0c0ac557ac927f5024d281a9da746500294dac149fc479b9de610a4ed`.
- Backend input patch: 3 files, SHA-256 `12d10b5ac1e2ae18622f6382be489d74d0a7b3da610f1201f632a034adc5c83a`.
- Prior `production-20261114-vapt1` and `production-20261115-frontend1` releases are retained. No rollback was required.
- Synthetic local fixture server on port 8002 stopped; normal localhost server was not stopped.
- Screenshot: `frontend-gap-audit-2026-11-15/security-retest-live.png`; rendered public login: `frontend-gap-audit-2026-11-15/live-login.png`.
