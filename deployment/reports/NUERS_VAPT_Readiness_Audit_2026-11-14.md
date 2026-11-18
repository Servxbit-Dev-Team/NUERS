# NUERS VAPT readiness audit

> Subsequent remediation: the scoped fixes were deployed and verified on both production nodes later on 14 November. See `NUERS_VAPT_Remediation_Retest_2026-11-14.md` and `NUERS_Production_Rollout_2026-11-14.json`. The assessment below records the original pre-remediation state.

**Assessment date:** 14 November 2026, Asia/Manila  
**Decision:** **Not ready for an unqualified VAPT pass/sign-off. Remediate and retest.**  
**Type:** Internal, evidence-based readiness assessment: source review, dependency analysis, isolated security tests, public HTTP checks and read-only AWS configuration inspection. This is not an independent penetration-test certificate or a claim that every endpoint has been exploited/tested.

The examined system has substantial security controls. No critical exploit or confirmed cross-tenant data disclosure was demonstrated in this assessment. However, there are two affected dependencies, a confirmed production/rehearsal database privilege overlap, an overlong privileged session policy, and material verification gaps. A package advisory's upstream severity is distinguished below from demonstrated application exploitability.

## 1. Scope and provenance

- Target public service: `https://nuers.net`.
- AWS Singapore production web nodes: `i-0694f0738afd178ce` and `i-0fe793f3af7b0c49b`.
- Release: `/srv/nuers/releases/production-20261106-analytics-grouping2` via `/srv/nuers/current`.
- Local assessment copy: `/Users/marksioson/Desktop/NUERS`, synchronized from that AWS release earlier on the assessment date. Previous Plesk deployment is not the target.
- Deployed entry assets: `main-DCofh3HT.js` and `main-mwrEibA1.css`.
- Local production database snapshot: 14 November 2026, approximately 15:13–15:15 PHT. Production configuration was independently queried later during the audit.
- Security tests used a fresh **SQLite in-memory database with synthetic identities**, fake mail, and unexpected HTTP calls blocked. No production customer records were changed. A separate read-only check of the local production snapshot checked historical seed accounts and returned counts only.
- No deployment, dependency update, production session-policy change, credential rotation, or database permission change was made. Audit artifacts and tests were written locally.

**Source limitation:** The deployed release contains only a partial editable frontend source tree. Exact production bundles were available, but retained local frontend source is not fully attributable to the current live build. This prevents a complete source-to-build and DOM-XSS assurance claim. The PHP backend is the synchronized deployed copy. Production PHP is 8.4.27; local tests ran under PHP 8.5.10. SQLite tests do not establish MariaDB concurrency behavior.

## 2. Findings and priorities

| ID | Finding | Classification | Priority |
|---|---|---|---|
| VAPT-01 | phpseclib 4.0.2 has an outstanding encrypted-key CPU-exhaustion advisory | Upstream **High**; affected package confirmed; exploitable NUERS input path not demonstrated | P1: patch before sign-off |
| VAPT-02 | Laravel 12.70.0 has an outstanding concurrency task-output advisory | Upstream **Low**; affected package confirmed; required application usage not found | P2: patch before sign-off |
| VAPT-03 | Production runtime database account also has write access to rehearsal database | **Medium**, confirmed configuration / least-privilege issue | P1 |
| VAPT-04 | Privileged sessions allow 24-hour idle and seven-day absolute lifetime; old MFA assurance remains accepted | **Medium**, confirmed policy/design concern; session theft not demonstrated | P1: agree and implement privileged policy |
| GAP-01 | Available regression suite errors; complete release-matched source and security coverage are missing | Assurance gap, not a proven exploit | P1 |

These are internal triage ratings, not fabricated CVSS scores. Acceptance depends on the VAPT provider's agreed scope and risk policy. A “zero high findings” gate may flag VAPT-01 based on software composition alone even when application reachability is not demonstrated.

### VAPT-01 — Affected phpseclib dependency

**Evidence:** `composer.lock:2910`, installed Composer metadata and `.local/vapt-2026-11-14/composer-audit.json` show `phpseclib/phpseclib 4.0.2`. `laravel/socialite v5.31.0` requires the 4.x package. Upstream advisory **GHSA-w3p9-g4p2-h3rr** affects 4.0.0–4.0.2 and identifies **4.0.3** as the patched 4.x version.

**Impact and conditions:** Attacker-controlled password-protected cryptographic input can request excessive PBKDF iterations and exhaust a PHP worker's CPU. This requires an exposed path that loads such input with phpseclib. No such direct application call was found in `app/` or `routes/`. The reviewed integration certificate upload uses OpenSSL X.509 parsing and rejects private keys. Socialite's phpseclib use was found in its Facebook provider; this is not proof that NUERS exposes vulnerable encrypted-key parsing. No denial-of-service payload was executed.

**Action:** Update phpseclib to at least 4.0.3 on its current major line, refresh the lock file and runtime vendor build, and test OAuth and cryptographic integrations. Do not blindly upgrade major versions. Confirm the deployed instances match the remediated lock/build and rerun Composer audit.

**Source:** [phpseclib publisher advisory](https://github.com/phpseclib/phpseclib/security/advisories/GHSA-w3p9-g4p2-h3rr).

### VAPT-02 — Affected Laravel dependency

**Evidence:** `composer.lock:1139`, installed metadata and Composer audit show `laravel/framework v12.70.0`. Advisory **GHSA-63hm-g96r-2m2q / CVE-2026-128083** is reported by Composer as affecting 12.x versions below **12.71.0**.

**Impact and conditions:** The advisory concerns forged task output when an application uses process concurrency and prints attacker-controlled data in the necessary format. No `Concurrency::run()` usage was found in application routes/services/controllers. This is an affected dependency finding, not demonstrated remote code execution in NUERS.

**Action:** Upgrade the current 12.x line to at least 12.71.0, test sessions, queues, mail and API workflows, and deploy/recheck both web nodes.

**Source:** [Laravel publisher advisory](https://github.com/laravel/framework/security/advisories/GHSA-63hm-g96r-2m2q); the 12.x threshold is recorded in the saved Composer advisory output.

### VAPT-03 — Production/rehearsal database privileges overlap

**Evidence:** Read-only `SHOW GRANTS FOR CURRENT_USER` through the bootstrapped live application on **both** web nodes returned `SELECT, INSERT, UPDATE, DELETE` for the runtime account on both:

- `nuers_production_20261106.*`
- `nuers_rehearsal_20261106.*`

No global data privileges or DDL privileges were shown, which is positive. Authentication clauses were omitted from evidence. SSM evidence command: `e83da623-85dc-478d-a390-c5548ee34972`.

**Impact:** Compromise or misuse of the production runtime database identity can affect rehearsal data as well as production. If the same identity is also available in a lower-trust rehearsal deployment, that deployment could reach production; that reverse-use condition was **not** established in this audit. The confirmed issue is unnecessary cross-environment authorization.

**Action:** Use separate runtime identities with environment-specific grants. Identify any legitimate job dependency before revoking the rehearsal grant from production. Keep migrations under a separate short-lived deployment identity. Test production startup, queues, reports and backups after separation.

**Retest:** Verify the production runtime account has only documented production permissions; confirm a harmless read against rehearsal is denied without altering either database. Review rehearsal credentials independently for production access.

### VAPT-04 — Long privileged sessions without generally fresh MFA

**Evidence:** Both live nodes report:

- `security.token_minutes=1440` — 24-hour idle window.
- `security.session_absolute_minutes=10080` — seven-day absolute lifetime.
- `security.enforce_privileged_mfa=true`.

`config/security.php:17` and `:18` apply global session windows. `app/Services/DeviceSession.php:14` extends the idle window for accepted foreground activity up to the absolute cap. `app/Services/MfaSession.php:10` accepts MFA completed after login with no separate freshness deadline. `IntegrationWorkspaceController::actor()` uses administrative role/session authorization; general integration credential changes do not request recent MFA. Some specialized operations may have additional protections; this finding does not say every sensitive action lacks them.

**Controlled reproduction:** The synthetic test `test_old_privileged_mfa_assurance_is_currently_accepted` created a super-admin login/MFA timestamp six days old and an unexpired session. A privileged registry request returned 200. This test intentionally confirms the present policy; its passing result is not evidence that the policy meets a stronger administrator security baseline.

**Impact:** A copied session token plus device identifier, or an unattended authenticated administrator browser, can retain authority for a long interval. Device binding here is a client-held identifier, not hardware-bound proof. The deployed bundle stores both the bearer token and browser identifier in localStorage; JavaScript execution in the origin could read both. No XSS or session theft was demonstrated, and localStorage use alone is not reported as a proven account takeover.

**Action:** Separate privileged and merchant session policy. A reasonable starting point for administrator review is 15–30 minutes inactivity, 8–12 hours absolute duration, and recent MFA for credential replacement, privilege changes, bulk export and infrastructure operations. These are proposed risk-based values, not a claim that OWASP mandates those exact numbers. Preserve the requested merchant login convenience through a separately reviewed policy. The current configuration clamps idle lifetime to at least 60 minutes, so a 15–30-minute administrator policy requires a code change, not only an environment setting.

**Retest:** Exercise idle expiry, absolute expiry, recent-MFA expiry, background polling, logout, password reset, role changes and second-device login on multiple nodes. Confirm stale sessions cannot perform protected changes even if they keep sending activity headers.

### GAP-01 — Release-matched testing and assurance evidence incomplete

**Evidence:** The available regression suite ran **11 tests / 64 assertions**, with **one error**: `tests/Feature/StorageOperationsTest.php:86` references missing `Database\\Factories\\UserFactory`. The available snapshot has only two feature test files. A deployment can legitimately omit test factories; the issue is that this copy cannot reproduce a complete green verification run. The complete editable frontend build source is also missing from the deployed release.

**Action:** Obtain the release-matched repository/commit, factories, tests, lock files and CI build provenance. Run the complete suite with supported production PHP and MariaDB, then add durable security regression tests. The local audit harness is useful evidence but is not a substitute for complete project coverage or independent authenticated DAST.

## 3. Executed checks and results

### Synthetic security harness

`.local/vapt-2026-11-14/SecurityBoundaryTest.php`: **13 tests, 229 assertions, zero failures/errors** in the final run. Includes:

1. All **156 registered protected GET API route templates** returned 401 to anonymous requests. Route parameters were replaced with synthetic UUIDs. Public web documentation routes were correctly excluded; public endpoints and non-GET routes were not classified as protected GETs.
2. Device mismatch, idle expiry, absolute expiry and revoked tokens rejected.
3. Client profile isolation and attempted super-admin role escalation rejected.
4. Merchant registry isolation and cross-tenant filtering verified; generic registry writes blocked.
5. Privileged MFA gate enforced; narrowly scoped own-profile retrieval still works before MFA.
6. Malicious query identifiers rejected; users table cannot be accessed through the generic table API.
7. SSRF destinations resolving to loopback, metadata, private, CGNAT and IPv6 special-use addresses rejected, including mixed public/private DNS answers. DNS was mocked; no private address was contacted.
8. Outbound webhook signature verification binds event, timestamp and body; stale/modified messages rejected. This does not establish full inbound idempotency or concurrency correctness.
9. Spreadsheet formula prefixes escaped in the shared CSV helper.
10. Registration OTP locks after five incorrect attempts and rejects reuse after successful verification.
11. Integration API read scope, expiry, revocation and cross-merchant retrieval boundaries enforced with synthetic keys.
12. Six-day-old privileged MFA accepted, confirming VAPT-04.

A preliminary test classified public `/api/docs` as a protected API route because of its path. Inspection showed it was a public web documentation route; the harness was corrected to require API middleware membership. This was a test classification issue, not an authorization bypass.

### Dependency and public HTTP checks

- Composer: two affected packages described above.
- npm lock audit: **zero reported vulnerabilities** at audit time. This does not prove the deployed frontend bundle's complete dependency provenance or absence of unknown vulnerabilities.
- HTTP root redirected to HTTPS (301).
- HTTPS homepage and login returned 200; API health returned 200 with no-store caching.
- Anonymous live `/api/tables/profiles` returned 401.
- `/.env`, `/.git/config`, `/storage/logs/laravel.log` returned 403. Bodies were discarded. These checks establish protection for these URLs, not every alternate encoding or origin path.
- Live responses included CSP, frame denial, nosniff, no-referrer and HSTS (`max-age=15552000`).
- Login response session cookie was Secure, HttpOnly, SameSite=Lax. The separate CSRF cookie was Secure/SameSite=Lax and intentionally JavaScript-readable; this is not a missing-HttpOnly authentication-cookie finding.
- Historical Supabase demo-password seed files remain in the repository. A read-only lookup in the local production snapshot found **zero matching historical seed accounts**, so no current default-password login finding was established. Keep obsolete seed material out of deployment/provisioning paths and review the full repository history separately.

### Verified live AWS controls

| Control | Observed state |
|---|---|
| Runtime mode | Production; debug false on both nodes |
| Privileged MFA | Enabled |
| Session/cache state | Database-backed on both nodes |
| Upload scanner | Enabled; ClamAV 1.5.4 with same-day signatures on both nodes |
| Environment file | 0640, root:nuers |
| EC2 instance metadata | IMDSv2 tokens required, hop limit 1 |
| Web ingress | Port 8080 from ALB security group only |
| RDS ingress | Port 3306 from web security group only |
| RDS public access | Disabled |
| RDS storage encryption | Enabled |
| RDS backup retention | 14 days |
| ALB ingress | HTTPS 443 from Cloudflare-labeled address ranges |
| ALB TLS policy | ELBSecurityPolicy-TLS13-1-2-2021-06 |
| Runtime database grants | DML only; cross-environment overlap remains VAPT-03 |

The initial query used a nonexistent `security.upload_scan_enabled` key and returned null. The correct `security.upload_scan.enabled` key was subsequently queried and verified **true**; there is no scanner-disabled production finding.

## 4. Review coverage and remaining limits

| Area | Evidence obtained | Remaining work before full sign-off |
|---|---|---|
| Authentication, password reset, sessions, MFA | Source review; synthetic expiry/revocation/MFA checks; live configuration | Multi-node races, recovery abuse, all second-device and reset combinations |
| Registration and OTP | Source review; attempt lockout and replay tests | Concurrent resend/verify/register races; attachment abuse under production-equivalent limits |
| Authorization and tenant isolation | Global middleware, generic tables, verified TIN checks, merchant ownership, API scopes; 156 anonymous GET route checks | Full authenticated read/write/export/download matrix for owner/team/admin/BIR/RDO/client roles |
| API integration | Scope/expiry/revocation/tenant tests; source checks for idempotency and branch binding | MariaDB concurrent replay, financial double-spend, batch failure, all sandbox/live transitions |
| SQL/command injection | Query validation tests and focused raw-query/process review | Payload coverage across every input/filter/import and command execution boundary |
| File upload/download | Scanner, image re-encoding, encrypted private-stream integrity and PDF sandbox source reviewed; scanner live status | End-to-end adversarial files, malformed documents, traversal, download authorization and resource exhaustion on an isolated staging stack |
| XSS/CSRF/browser | CSP/cookies, bearer boundary and OAuth flow review; exact compiled bundle checks | Complete release source, stored/reflected/DOM XSS testing, all OAuth/linking/CSRF flows |
| Secrets/crypto | Hashed tokens; encrypted secrets/docs; scoped certificate upload; narrow tracked-file checks | Full Git-history secret scan, cryptographic key custody/rotation, backup/export secret review |
| Audit/operations | Existing tests include integrity/tamper and archive behavior; source controls reviewed | External log retention, alert delivery, audit anchoring, recovery drill with measured RPO/RTO |
| AWS infrastructure | EC2 metadata, selected security groups, RDS encryption/backups/grants, ALB TLS configuration | IAM policies, S3/EFS policies, WAF effectiveness, OS/package scan, origins and full TLS/cipher negotiation |

The application security checks were broad but sampled. They do not establish that every function is safe. No denial-of-service, password spraying, production tampering, live financial event creation, external message sending or destructive test was performed. Active attacks should use a release-equivalent isolated staging stack and synthetic records.

## 5. Recommended remediation and acceptance gates

1. **Before VAPT submission:** patch the two dependencies; separate production/rehearsal grants; approve and implement the privileged session/re-authentication policy. Record any explicitly accepted residual risks.
2. **Restore reproducibility:** retrieve the exact full release source and CI evidence; fix the missing factory in the test source distribution; run the full suite on production-equivalent PHP/MariaDB. Preserve deployed asset hashes and SBOM evidence.
3. **Complete authenticated testing:** cover every role and sensitive read/write/export route, file workflow, OAuth/MFA recovery path, API branch/sandbox boundary and financial/idempotency race condition. Use at least two independent merchants and two users per relevant role.
4. **Complete cloud/perimeter assessment:** validate IAM and storage boundaries, all supported TLS versions/ciphers, WAF/edge/origin behavior, patch exposure and recovery/alert evidence.
5. **Retest and sign off:** independently retest findings against the exact candidate build. Require no unresolved exploitable critical/high findings, documented treatment of medium findings, clean agreed dependency gates, green release tests and a signed scope/evidence report. Agree these criteria with the actual assessor; VAPT does not have one universal pass percentage.

Use [OWASP ASVS](https://asvs.owasp.org/) to agree the verification baseline (Level 2 is a reasonable starting point for this financial/tax-record platform, with stronger controls for privileged operations) and the [OWASP Web Security Testing Guide](https://wstg.owasp.org/stable/) to structure hands-on testing. This report does not claim ASVS certification or a full control-by-control assessment.

## 6. Evidence files

Evidence is local and git-ignored under `.local/vapt-2026-11-14/`:

- `composer-audit.json`, `npm-audit.json`: dependency results.
- `phpunit.log`, `phpunit.xml`: available regression suite output.
- `SecurityBoundaryTest.php`, `security-boundary.log`, `security-boundary.xml`: reproducible synthetic audit tests.
- `anonymous-route-coverage.json`: 156 checked protected GET route templates.
- `public-http-checks.json`: selected live HTTP results with cookie values omitted.
- `aws-security-observations.json`: manually transcribed, nonsecret AWS observations and SSM command references.
- `legacy-seed-check.json`: historical seed-account check counts only.

Synthetic harness reproduction from the project root:

```sh
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: DB_URL= \
MAIL_MAILER=array QUEUE_CONNECTION=database CACHE_STORE=array SESSION_DRIVER=array \
vendor/bin/phpunit .local/vapt-2026-11-14/SecurityBoundaryTest.php
```

This harness explicitly resets only its in-memory SQLite database. Never replace its database settings with production credentials. It contains one intentional characterization test for the long-MFA policy; revise that test when VAPT-04 is remediated.
