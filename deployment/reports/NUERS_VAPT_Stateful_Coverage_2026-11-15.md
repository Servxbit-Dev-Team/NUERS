# NUERS stateful VAPT continuation — 2026-11-15

## Scope and decision

Owned NUERS local source and the existing two-node production deployment. Authenticated mutations run only against isolated synthetic databases. No production user, MFA enrollment, invoice, wallet, or customer record is changed by this test cycle.

Local gate passed: **842 tests, 12,040 assertions, zero failures/errors/skips**. This is internal regression evidence, not an independent penetration-test certification or proof that every attack/business state is covered.

## Confirmed findings and fixes

| Finding | Evidence before fix | Change | Retest |
|---|---|---|---|
| Optional contact email caused valid personal-profile update to return 500 | `profile-before.log`: undefined `email` key on name-only update | Resolve optional contact email once; preserve existing contact when omitted; keep login identity unchanged | All seven roles, own/foreign IDs, role/TIN/security-field injection, duplicate contact, null/explicit contact, notification outbox |
| Public geographic query arrays caused 500; city code was interpolated into lookup path without format validation | `location-before.log`: array-to-string conversion; source inspection of outbound path | Validate query strings and lengths before casting; allow only 9/10-digit PSGC or bounded LOCAL codes; reject path/query fragments before outbound lookup | Invalid arrays, nested/path/oversized codes return 422; valid mocked PSGC, local fallback, country, RDO, public catalog/OpenAPI flows pass |

These are input-handling/availability findings. No account takeover, SQL injection, or arbitrary-host SSRF was demonstrated by these cases.

## Stateful authorization and integrity coverage

New tests exercise real synthetic resources with device-bound sessions and privileged MFA enforcement enabled:

- Customer read/update/create, server-bound merchant ownership, forged tenant/user selectors, idempotent retries, changed retry conflict, revision conflict, and foreign-document linking. Foreign records remain unchanged.
- API key listing/edit/revocation: own-resource success and foreign-resource denial for all seven roles; revoked keys remain revoked. No real keys are used.
- Receipt copy/PDF/email targets: cross-tenant and disallowed-role requests denied; no document deliveries created.
- Expense review: valid reconciled supplier invoice, independent admin/super-admin approval, self-approval denial, rejected buyer-TIN/VAT/status changes, recorded reviewer identity, unchanged expense on rejection.
- Generic PUT alias: privileged identity/TIN/security-field changes and direct issued-financial-record/registry edits denied; permitted personal edits succeed.
- Public geography/developer resources: valid requests with anonymous and every role's headers; no live third-party delivery.

Test sources: `tests/Feature/PersonalProfileBoundaryTest.php`, `StatefulResourceBoundaryTest.php`, and `PublicLocationBoundaryTest.php`.

## Measured endpoint coverage

An opt-in recorder in `tests/TestCase.php` writes test name, route template, method, status, resolved role, credential kind, MFA setting, and device-binding presence. It does not record payloads, credentials, resource IDs, or query strings. It is gated to the testing environment and is not deployed.

- **558 route/method inventory entries**, including web/HEAD entries.
- **8,505 all-role inventory probes**, covering admin, super_admin, BIR, RDO, merchant, client, consumer; no unexpected server errors. These probes predominantly use missing-resource and malformed input cases.
- **14,900 total recorded local HTTP requests** across the complete suite.
- **366/366 non-HEAD API route/method combinations** observed in tests outside the broad endpoint/input matrices.
- **301/366** have at least one successful behavioral HTTP response; **65** have no successful behavioral response in this run. A 2xx is not itself proof of complete authorization or business-state coverage.
- Legacy tests may disable privileged MFA. The CSV separately counts successful requests with MFA enforcement and device binding, so legacy behavior is not represented as equivalent assurance.

Detailed evidence: `.local/vapt-stateful-2026-11-15/endpoint-coverage.csv`, `coverage-summary.json`, `role-endpoint-matrix.json`, `request-coverage.jsonl`, `full-after.xml`, and `full-after.log`.

## Outstanding positive-flow gaps

The list below identifies endpoints without a successful behavioral response, not confirmed vulnerabilities. Some intentionally reject generic mutation or depend on disabled/external services. Each needs either a supported positive-flow fixture or a documented fail-closed contract. Matrix-only 200 responses are not counted as successful business-flow tests.

| Method | Route |
|---|---|
| GET | `api/admin/analytics` |
| GET | `api/admin/analytics/export` |
| GET | `api/admin/bir-wire/batches/{id}` |
| POST | `api/admin/bir-wire/batches/{id}/inquire` |
| PUT | `api/admin/bir-wire/profiles/{id}` |
| POST | `api/admin/bir-wire/profiles/{id}/authenticate` |
| GET | `api/admin/bir-wire/profiles/{id}/authority` |
| GET | `api/admin/bir-wire/profiles/{id}/candidates` |
| GET | `api/admin/eis-center/batches` |
| POST | `api/admin/eis-center/batches/{id}/release` |
| GET | `api/admin/infrastructure/actions` |
| GET | `api/admin/infrastructure/backups` |
| POST | `api/admin/infrastructure/backups/confirm` |
| POST | `api/admin/infrastructure/backups/prepare` |
| GET | `api/admin/infrastructure/events` |
| GET | `api/admin/infrastructure/expansions` |
| POST | `api/admin/infrastructure/expansions` |
| GET | `api/admin/infrastructure/monitoring` |
| POST | `api/admin/infrastructure/power/confirm` |
| POST | `api/admin/infrastructure/power/prepare` |
| GET | `api/admin/infrastructure/servers/{id}` |
| GET | `api/admin/integration-source-profiles` |
| GET | `api/admin/sandbox-records/{id}` |
| GET | `api/admin/subscription-plans` |
| PUT | `api/admin/subscription-plans/{id}` |
| DELETE | `api/admin/support/{id}` |
| DELETE | `api/admin/support/{id}/permanent` |
| POST | `api/admin/support/{id}/read` |
| POST | `api/admin/support/{id}/restore` |
| GET | `api/admin/verification-documents/{id}` |
| GET | `api/admin/wallets` |
| GET | `api/admin/wallets/{merchant}/audit` |
| POST | `api/auth/resend-verification` |
| POST | `api/bir-eis/validate` |
| PUT | `api/business-accounts/current` |
| POST | `api/business-accounts/current/logo` |
| GET | `api/business-registry/template` |
| GET | `api/citizen-approvals` |
| POST | `api/client/profile/avatar` |
| GET | `api/compliance-operations/businesses` |
| GET | `api/compliance-operations/documents` |
| POST | `api/esp-compliance/applications/{id}/certificate` |
| POST | `api/integration-workspace/connections/{id}/certificate` |
| PUT | `api/integration-workspace/webhooks/{id}` |
| POST | `api/invoice-series` |
| POST | `api/merchant-accounts` |
| GET | `api/merchant/api-branches` |
| GET | `api/merchant/expenses` |
| POST | `api/merchant/expenses` |
| POST | `api/merchant/invoice-settings/preview/pdf` |
| GET | `api/merchant/pos-devices` |
| GET | `api/merchant/receipts` |
| GET | `api/merchant/receipts/verify` |
| POST | `api/merchant/team/roles` |
| GET | `api/merchant/transactions` |
| GET | `api/operations/readiness` |
| GET | `api/pti-applications/{id}` |
| GET | `api/rdos` |
| POST | `api/rdos` |
| POST | `api/tables/{table}` |
| DELETE | `api/tables/{table}` |
| GET | `api/taxpayer-authorizations/{id}` |
| PUT | `api/taxpayer-devices/{id}` |
| POST | `api/team-invitations/preview` |
| GET | `api/transactions/overview` |

## Linux/deployment gate

Deployed successfully at **2026-11-15 20:25:36 PHT**, both nodes healthy. Release: `production-20261115-stateful1`; baseline: `production-20261115-homeportrait1`. Only the two controller files are included. Frontend manifest, production environment, authentication policy, and database schema are unchanged.

The first standalone Linux harness attempt stopped before traffic switched because it used a PHPUnit-only assertion while production excludes development dependencies. The harness was corrected to inspect recorded mock requests directly. The unused candidate was retained under a separate audit name; this was a harness failure, not a production application failure.

## Verified rollout and public retest

- Both existing Linux nodes passed **8,372 authenticated probes**, **906 explicit administrative-role denials**, and **15 targeted profile/location regressions per node**, using PHP 8.4.27 and an isolated in-memory database before traffic switched.
- Synthetic Linux gates did not load the production environment file/configuration or use production database credentials; mail/queue were faked and unexpected HTTP calls blocked. BIR result and resend-verification paths returned intentional unavailable states and are explicitly recorded; live external delivery is not claimed.
- Rolling drain/switch/health checks passed on both nodes. Baseline source hashes matched; changed files are root-owned and not group/world-writable. The environment file and frontend build manifest matched the prior release. MFA endpoints still return 401 without authentication.
- Public live retest: **68/68 passed**, **7 edge blocks**, from **20:26:08–20:27:07 PHT**. Includes private-file/path checks, anonymous administrative access, forged auth context, URL normalization, pre-auth injection boundaries, reflected payloads, return URLs, CORS/preflight, TRACE, invalid login inputs, six new malformed location queries, and three valid geographic requests. Edge blocks are not credited as backend payload coverage.
- Evidence: `.local/vapt-stateful-2026-11-15/rollout.json`, `public-probes.json`, `deployment-proof.png`, `stateful-manifest.json`, and `tested-source-hashes.json`.
- No infrastructure was provisioned, no permissions expanded, and no existing account's 2FA enrollment was disabled.

## Limits

No destructive load/denial-of-service test, real customer mutation, live payment/refund, actual email delivery, BIR submission/acceptance, or unrestricted brute-force test is claimed. Edge-blocked public probes do not prove the backend payload was exercised. Third-party behavior and the outstanding successful-flow gaps remain explicit work items. Independent VAPT remains necessary before calling the system certified or fully cleared.
