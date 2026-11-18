# Optional 2FA production deployment — 15 November 2026

Status: deployed and verified on https://nuers.net. This report supersedes the pending-production status in `NUERS_MFA_Disable_Responsive_Retest_2026-11-15.md`; that document remains the frozen pre-deployment test record.

## Release and authorization

- User explicitly approved deployment including optional administrator 2FA, with existing enrollments preserved.
- Previous release: `production-20261115-auth1`.
- Active release: `production-20261115-mfa1` on both AWS Singapore application nodes.
- Rolling rollout completed at 18:46:36 PHT. Final verification started at 18:48:55 PHT and completed successfully.
- Artifact SHA-256: `fd11ff4c5658fc1d2869160f6145d065cbb5db3dec09f7757f38e8dcb07c61a1`.
- Both load balancer targets are healthy. The new interface was activated after both backends passed their checks.

## Existing accounts preserved

Read-only enrollment comparisons before migration and after rollout checked all **3 existing accounts**, all previously enrolled. **0 existing enrollments changed; 0 accounts opted out.** The authenticated merchant page also visibly retained Enabled status and its three authenticator devices alongside the new Turn off 2FA control. No real account was disabled for testing.

The additive `users.mfa_disabled_at` column is nullable and defaults to NULL. The migration does not update existing user enrollment data. It ran through the separate database operator identity over a verified TLS connection; runtime database privileges remained DML-only. The first metadata check encountered MariaDB's string `NULL` default representation after successful DDL; the validator was corrected and the idempotent migration verification passed. Original diagnostic logs were retained.

Account owners, including administrators, can explicitly turn off their own 2FA using their current password, an unused authenticator or recovery code, and confirmation. Successful opt-out revokes authenticators, pending setups, recovery codes and login sessions, writes an audit event and queues an essential security notice. Role/tenant checks, device binding, rate limiting, privileged session limits and independent infrastructure MFA requirements remain enforced.

## Retest results

| Verification | Result |
| --- | --- |
| Local full regression | 827 tests / 11,402 assertions passed |
| Strict local MySQL | 8 tests / 191 assertions passed |
| Linux candidate and switched-release MFA probes | 229 assertions per node passed, covering all 7 roles |
| Authenticated endpoint matrix | 8,372 probes per node passed, including 906 explicit admin denials |
| Native scanner, PDF, runtime and permission smoke | Passed on both nodes |
| Deployed artifact integrity | All 255 files matched on each node |
| Served frontend integrity | 6 relevant assets matched per node; public entry JS/CSS matched |
| Public home, login and API health | HTTP 200 |
| Anonymous MFA-disable and device endpoints | HTTP 401 |
| Anonymous account-security endpoint, per node | HTTP 401 |
| Environment-file exposure, per node | HTTP 404 |
| Existing production MFA enrollment | 3 preserved, 0 changed, 0 opted out |

Authenticated attack probes used isolated synthetic in-memory data on the deployed code; they did not mutate production customer accounts or send real test notifications. A public asset request initially received 403 with the Python client's default header. Adding the same explicit authorized-verification User-Agent used by the other probes made the asset check pass. No production protection was relaxed.

Both nodes retain root-owned application files, unchanged environment configuration, DML-only runtime database access and denied runtime access to deployment archives. No new AWS resources or IAM grants were created. The previous release remains available for rollback; the nullable column can remain in place without erasing opt-out history.

## Responsive scope and evidence

The deployed build includes the locally verified responsive security pages and confirmation dialog: 22 page checks and 22 dialog checks across 11 viewport sizes from 320 to 1920 CSS pixels, including landscape and dual-screen-sized layouts. No horizontal overflow was measured; landscape actions remained reachable by scrolling. Physical iOS Safari, on-screen keyboards and a physical hinge were not tested.

Local evidence:

- `.local/mfa-disable-2026-11-15/final-verification.json` — production integrity, HTTP, target health and enrollment results.
- `.local/mfa-disable-2026-11-15/live-optional-2fa.png` — authenticated live UI with Enabled status and the optional control.
- `.local/mfa-disable-2026-11-15/responsive-results.json` — local viewport results.

Operator-side detailed reports remain in `~/nuers-mfa-20261115/` in the existing AWS CloudShell session, including rollout, migration, native probe and final verification records. Credentials and private enrollment comparison hashes were not copied into this report or the deployment artifact.

This is a verified release-specific regression result, not independent VAPT certification or a claim that every possible attack has been tested.
