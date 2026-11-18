# iPhone Duo portrait verification — 15 November 2026

Status: deployed to https://nuers.net and verified. Both production targets were healthy when rollout completed at 19:06:18 PHT.

## Device reference and scope

The test dimensions use [WebKit’s official Safari 27.1 reference](https://webkit.org/blog/18486/), rather than hardware-pixel dimensions. Outer portrait is 382 × 580 / 618 CSS pixels; inner portrait is 669 × 867 / 902; each split-view window is 385 × 571 / 609. Additional checks covered 594 × 368, 951 × 585, 320 × 568, 390 × 844, 430 × 932 and 1280 × 800.

These are viewport simulations in the available browser, not physical-device or Safari-engine certification. The device-posture and viewport-segment features are optional developer previews in Safari 27.1; ordinary layout does not depend on enabling them. Existing dialog segment/safe-area enhancements remain unchanged.

## Finding and fix

The shared header search form retained its intrinsic minimum width and could push the account control outside a narrow viewport. Merchant Help hiding also depended on dashboard CSS being loaded, so direct navigation to account security behaved differently.

The shared stylesheet now lets search shrink, reserves space for header actions, hides the duplicate Help shortcut on narrow screens, uses 44px touch targets through 900px, and uses 16px search text. The account menu has an explicit accessible label. The sidebar and account actions remain available; help is available from the sidebar.

Only `src/components/enterprise/enterprise.css`, `src/components/enterprise/enterprise-shell.tsx`, and rebuilt frontend assets are in this release. No PHP behavior, database migration, authentication policy or enrollment state is changed.

## Local results

- Production typecheck and frontend/document build passed.
- 13 existing frontend reconstruction/rendering tests passed.
- 12 page/header viewport measurements passed: no document overflow and all header controls in bounds.
- 12 confirmation-dialog viewport measurements passed: no horizontal overflow and dialog bounds inside the viewport.
- At outer portrait 382 × 580, actual scrolling exposed both 44px confirmation actions. The account menu opened successfully after cancelling; no disable request was submitted.
- Direct reload on the security route verified the fix without dashboard CSS load-order dependence.

Evidence: `.local/portrait-2026-11-15/local-results.json`, `build.log`, `render.log`, and the preserved baseline archive in the same directory.

## Release

Candidate: `production-20261115-portrait1`; previous release: `production-20261115-mfa1`.
Artifact SHA-256: `a580fbaba9304ce0f0c3297632d789bc023906338fb6bf44402dd45d6daa4a7d`.
239 files, including 2 source files and 237 frontend build files. Candidate preparation verifies source baseline hashes, the complete artifact, root ownership, and unchanged environment configuration. New content-addressed assets are staged on both old releases before the rolling switch to avoid mixed-node asset failures. The previous release is retained for rollback. No new AWS resources or IAM permissions are introduced.

## Production verification

Both nodes matched all 239 release files, retained root-owned non-writable application files and unchanged environment configuration, and passed home/login/API health (200), unauthenticated MFA disable/devices (401), and served entry asset hash checks. The source baseline matched before preparation. No account enrollment, database data or schema was modified by this release.

After deployment, a freshly loaded authenticated live security page passed all 12 distinct viewport/header checks and all 12 dialog checks. The live account menu opened at 382 × 580, and the live 2FA dialog was reviewed and cancelled without submitting. The existing account remained enrolled. This release uses the same MFA implementation verified in the preceding MFA production deployment.

Evidence: `.local/portrait-2026-11-15/rollout.json`, `live-results.json`, `live-dialogs.json`, `live-duo-outer-portrait.png` and `live-duo-inner-dialog.png`. The synthetic local test server was stopped after verification.
