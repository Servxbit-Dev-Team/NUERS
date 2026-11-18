# Self-service 2FA and responsive security settings — 15 November 2026

Status: implemented locally; production activation is pending. No real account's authenticator configuration was disabled. Live remains on the previously deployed release until a separately approved rollout.

## Behavior

Account owners, including administrators, can use Account Security → Turn off 2FA. The operation requires the current password, an unused authenticator/recovery code, and an explicit acknowledgement. It revokes all of that owner's authenticator secrets, pending setups, recovery codes and login sessions; records an audit event; queues an essential security notice; and returns the browser to sign-in. It cannot target another account.

Explicit opt-out is stored in the nullable `users.mfa_disabled_at` column. It is hidden and cannot be mass assigned. The migration does not opt anyone out. Previously unenrolled privileged accounts still require enrollment. Re-enrollment confirms a new secret before removing the opt-out. Privileged idle/absolute session limits, tenant/role gates, device binding, rate limits and independent infrastructure MFA checks remain enforced.

Optional admin MFA changes the security policy. These tests do not establish that an external VAPT assessor will accept that policy.

## Responsive verification

Admin and merchant security pages and the shared confirmation dialog were checked at these CSS viewport sizes:

| Phone | Landscape / dual-screen-sized | Tablet / desktop |
| --- | --- | --- |
| 320 × 568 | 568 × 320 | 768 × 1024 |
| 375 × 667 | 540 × 720 | 1024 × 768 |
| 390 × 844 | 720 × 540 | 1280 × 800 |
| 430 × 932 | | 1920 × 1080 |

All 22 page measurements had document width equal to viewport width. All 22 dialog measurements stayed within viewport bounds without internal horizontal overflow. Text inputs rendered at 16px with 44px height; confirmation buttons measured 44px high. At 568 × 320, real scrolling exposed both footer actions inside the visible dialog on both pages.

Changes include narrow-screen card/grid reflow, stacked actions, safe-area-aware bounds, dynamic viewport height, internal vertical scrolling, and no geometry transition during rotation. The dialog uses the first viewport segment when the browser reports a physical fold/hinge. These are progressive CSS enhancements, not a claim of physical-device testing. Real iOS Safari, the on-screen keyboard, notches and physical dual-screen hinges were not available in this test environment.

Evidence (local, synthetic accounts only):

- `.local/mfa-disable-2026-11-15/responsive-results.json`
- `.local/mfa-disable-2026-11-15/admin-phone.png`
- `.local/mfa-disable-2026-11-15/merchant-phone.png`
- `.local/mfa-disable-2026-11-15/admin-landscape.png`
- `.local/mfa-disable-2026-11-15/merchant-landscape.png`

References: [MDN safe-area environment variables](https://developer.mozilla.org/en-US/docs/Web/CSS/Reference/Values/env) and [viewport segments](https://developer.mozilla.org/en-US/docs/Web/API/Viewport_segments_API/Using).

## Regression evidence

- TypeScript check and production frontend/document build: passed.
- New MFA-disable tests plus existing device/account-security tests: 20 tests, 318 assertions passed.
- Strict local MySQL with separate migration identity and DML-only runtime user: 8 tests, 191 assertions passed; disposable database/user removed afterward.
- Frontend security transport: 13 cases passed; session client: 11 scenarios passed; reconstruction/rendering: 13 cases passed.
- Responsive browser verification: 44 measurements passed, plus landscape scrolling checks.
- Native local PDF regression: 8 tests, 71 assertions passed.
- Full native-host regression: **827 tests, 11,402 assertions passed**, no failures or risky tests. Evidence: `.local/mfa-disable-2026-11-15/full-host.xml` and `full-host.log`.

The initial restricted execution runs produced native renderer crashes/timeouts and dependent PDF/mail failures. The same PDF suite passed with native subprocess access. Chromium's own sandbox, renderer isolation, timeout and network restrictions were not relaxed, and PDF application code was not changed. Restricted-run failure logs are retained rather than overwritten. The shared document renderer bundle hash remains `f37c5b8a3e3d20aadbd373607bc7280a44275a41f91eac4593aef42601378336`.

## Rollout boundary

The local schema has the additive nullable column. Runtime database permissions remain unchanged. Production must apply this migration using the deployment identity before switching application code; runtime users must not gain DDL access. Preserve the current release for rollback and check both nodes after rollout. No `.env`, database snapshot, browser fixture, credentials or diagnostic files belong in the deployment artifact.

Production activation requires action-time confirmation when performed through the AWS browser console because it makes admin MFA optional after a verified owner opt-out. Deployment itself does not disable any user's 2FA.
