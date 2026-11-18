# NUERS: remaining 65 endpoint/method coverage review

Date: 15 November 2026 (Asia/Manila).

## Scope and result

The 65 endpoint/method combinations identified in the previous stateful audit now each have explicit successful-flow or fail-closed contract checks for **all seven roles**: super_admin, admin, bir, rdo, merchant, client, consumer. This gives **455 role/endpoint combinations**, including 133 with authorized successful responses. Mutation tests assert resulting records, unchanged denied records, ownership, revisions or idempotence as applicable; a missing-resource response alone does not close these gaps.

**60 endpoints have supported positive flows. Five intentionally have no 2xx workflow:**

| Endpoint | Expected contract |
|---|---|
| POST /api/admin/eis-center/batches/{id}/release | 409 while the legacy verified adapter is unavailable; existing batch remains prepared. Signed wire authentication/inquiry is tested separately. |
| PUT /api/admin/subscription-plans/{id} | 422: retired mutation; use the reviewed policy workflow. |
| POST /api/merchant-accounts | 410: retired account creation; use the registration workflow. |
| POST /api/tables/{table} | 409: generic writes disabled; use dedicated controllers. |
| DELETE /api/tables/{table} | 409: generic deletes disabled; preserve source evidence. |

Roles not authorized to reach these contracts receive 403. Invitation preview is capability-based: valid invitations work regardless of account role; a bearer role cannot substitute for the invitation token. Avatar upload updates only the caller's own profile and is deliberately available to authenticated roles.

Across the full route inventory, all **366 non-HEAD API endpoint/method entries** have behavioral observations; **361 have a successful behavioral flow**. The remaining five are the documented contracts above. This is route coverage, not a claim that every possible input, business state or attack has been tested.

## Findings fixed

1. **Missing explicit business-role enforcement on legacy receipt and transaction reads.** The prior controllers accepted a valid session with a linked business record even after the profile was changed to a non-business role. The local before-test reproduced HTTP 200 for a BIR-role account retaining a business link. Both controllers now require merchant/admin/super_admin before ownership lookup. Existing ownership restrictions remain in force. Receipt verification uses the same guard.
2. **Receipt query input hardening.** The verification endpoint now validates `receipt` as a required string of at most 255 characters before casting or querying. Array-shaped input returns 422 for authorized business roles; forbidden roles are rejected first.

Regression tests use real synthetic linked records for all roles, check that a foreign merchant query cannot widen scope, verify that a foreign receipt cannot be read, and prove that a role downgrade denies access immediately even after the same session populated the dashboard cache.

## Local validation

- Full regression suite: **893 tests, 13,158 assertions, zero failures/errors/skips**.
- Additional focused tenant-isolation regression: **1 test, 49 assertions, passed**.
- New focused flow suite: **51 tests, 1,118 assertions**, passed before the full run; the extra tenant test was added afterward.
- Native harness, local PHP 8.5.10: **8,372 authenticated probes, 52 targeted regressions, 906 explicit administrative denials**, passed.
- Every one of the 455 targeted role/endpoint combinations ran with privileged MFA policy enabled and synthetic device-bound sessions. Public capability routes need not resolve a bearer actor; the scenario role is recorded separately.
- No 5xx responses in the targeted flows.

Valid fixtures cover encrypted document downloads, real PDF preview rendering, support message lifecycle, notification queuing, original expense reconciliation, verified authority and invoice-series registration, public X.509 certificate metadata, secret-preserving webhook updates, signed BIR wire authentication/inquiry, and infrastructure prepare/confirm/idempotency. External AWS/BIR/delivery calls are mocked or blocked; no real resources were purchased or power-cycled and no customer messages were sent.

## Deployment

**Deployed successfully at 21:09:39 PHT on 15 November 2026.** Live release `production-20261115-positive1`, based on `production-20261115-stateful1`, contains only:

- `app/Http/Controllers/Api/MerchantReceiptController.php`
- `app/Http/Controllers/Api/MerchantTransactionController.php`

Baseline source hashes matched on both nodes. Both passed native PHP 8.4.27 isolated in-memory retests: **8,372 probes + 52 targeted regressions + 906 explicit administrative denials per node**. Rolling drain, switch, local HTTP verification and target health gates all passed; both targets are healthy. Environment and frontend manifest were unchanged; modified code is root-owned and not group/world writable. No schema, MFA enrollment, account, dependency or frontend changes were included.

Post-deployment public live checks: **71/71 passed**, zero failures, completed at 21:10:59 PHT. Seven requests were blocked at the edge; those do not establish backend payload coverage. Both nodes also passed 12 direct local HTTP checks after switching. Enrolled authenticators and optional admin 2FA were not changed.

Native broad probes deliberately leave external callbacks and unconfigured delivery unavailable (503). The targeted successful resend flow uses configured synthetic mail settings and asserts durable queuing with no delivery; it does not assert real SMTP delivery.

## Evidence and limitations

- `deployment/reports/NUERS_VAPT_Positive_Flow_Role_Coverage_2026-11-15.csv`: 455 role/endpoint rows with observed statuses and test names.
- `deployment/reports/NUERS_VAPT_Positive_Flow_Coverage_2026-11-15.json`: machine-readable coverage and limits.
- `.local/vapt-positive-2026-11-15/`: before evidence, JUnit, metadata-only request trace, native probes, artifact hashes and rollout evidence.
- Tests: `tests/Feature/Positive*Test.php`, shared `tests/Support/PositiveFlowCase.php`, `tests/Support/SevenRoleSessions.php`, and the new seven-role wire test in `tests/Recovered/Feature/BirWireTransportTest.php`.

This closes the **named 65 coverage gaps**. It does not establish external BIR acceptance, actual provider-side backup completion, resource-exhaustion resistance, every race/interleaving, every frontend journey, or independent VAPT certification. Live public probes are bounded; authenticated mutation attacks use isolated synthetic databases rather than customer accounts. Existing approved optional admin 2FA and enrolled authenticators are preserved.
