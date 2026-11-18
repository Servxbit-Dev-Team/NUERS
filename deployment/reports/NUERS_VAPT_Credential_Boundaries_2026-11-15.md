# VAPT continuation — 15 November 2026

Outcome: no new confirmed vulnerability in this bounded pass. This is engineering retest evidence, not independent VAPT certification or exhaustive attack coverage.

## Results

- Full isolated local PHP suite: **834 tests, 11,556 assertions, zero failures/errors**, including native PDF checks (3m 42s).
- Seven new credential-boundary regression tests: **154 assertions**.
- Authenticated endpoint matrix: **8,505 probes**, seven roles, 558 route/method entries expanded to 617 concrete route/method paths; zero unexpected findings. Each role has 1,215 probes.
- 753 explicit administrative role-denial checks in the separate isolation matrix.
- Fresh bounded serial public live checks at `https://nuers.net`: **59/59 passed**, 19:20:02–19:20:49 PHT; seven requests blocked at Cloudflare. Availability passed after probing.
- Explicit public header checks: self-only scripts, framing denial, object blocking, nosniff, HSTS, no-referrer, and no exposed PHP version passed on four baseline/health responses.

## Newly exercised cases

Visitor chat: full migrated schema, production privileged-MFA enforcement and device-bound synthetic sessions; all seven roles; cross-conversation read/write/read-marker denial, token versus administrator-session separation, sender impersonation fields, idempotent retry versus changed payload, expired/deleted capability denial, invalid-device and unverified-MFA denial.

Merchant API: human sessions rejected as machine keys; conflicting credentials rejected; machine keys denied human security/key-management/admin endpoints; revoked, expired and read-only keys cannot create financial records. Positive wallet reads confirm that valid keys reach the integration handler. Existing positive capture, replay, source-profile/tenant and financial-isolation tests also passed.

BIR callbacks: valid HMAC with wrong identity, acknowledgment, batch, invoice UID, count, duplicate document, conflicting aliases and unknown result code rejected; stale/future timestamp, wrong method signature, application/accreditation headers and query strings rejected. Exact callback replay retains the acknowledgment without adding another row. Callback notifications cannot overwrite a final state or an active worker lease. All BIR transport is mocked; no real submission was made.

No runtime security source patch or database migration was needed for these checks. Existing account MFA enrollment was not changed. New artifacts are regression tests and evidence; the separate homepage portrait issue raised during this run was separately deployed as the frontend-only `production-20261115-homeportrait1` release. A further 59/59 bounded public probes passed after that deployment (seven Cloudflare edge blocks); see `NUERS_Public_Homepage_Portrait_2026-11-15.md`.

## Explicit limits

The endpoint matrix uses baseline/malformed requests and many missing-resource IDs; it does not prove every valid business-state transition. Its 14 unavailable records are deliberately disabled verification-email delivery. Fourteen GET/HEAD storage records are shadowed by the SPA route and do not exercise the storage handler. Machine credentials require the targeted tests above rather than ordinary role sessions.

Public requests blocked at Cloudflare establish edge denial only. No destructive production attack, load/brute-force test, real customer mutation, live authenticated tenant attack, new Linux/multi-node run, physical-device test, exhaustive DOM-sink review or independent infrastructure penetration test was performed in this continuation. Earlier staging evidence remains separate. Remaining coverage gaps therefore must not be described as fully closed.

The test selection follows [OWASP authorization testing](https://wstg.owasp.org/latest/4-Web_Application_Security_Testing/05-Authorization/02-Bypassing_Authorization_Schema/) and [IDOR testing](https://wstg.owasp.org/latest/4-Web_Application_Security_Testing/05-Authorization/04-Insecure_Direct_Object_References/).

Evidence directory: `.local/vapt-continuation-2026-11-15/` (full JUnit/log, copied endpoint and role matrices, public probe metadata, explicit header checks, test-source hashes). Public response bodies and cookies were not saved.
