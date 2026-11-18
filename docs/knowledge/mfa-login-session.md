# MFA and role-based session policy

## Self-service 2FA disable — 15 November 2026 (local, not deployed)

An enrolled account owner, including admin, super_admin, BIR and RDO, can turn off 2FA from Account Security. The new `POST /api/auth/mfa/disable` requires the current device-bound session, current password, unused authenticator or recovery code, and explicit acknowledgement. The user row is locked and the presented token is rechecked before consuming proof. The endpoint is rate limited to five attempts per minute and accepts no target-account override.

Disabling erases all active/pending authenticator secrets and recovery hashes, revokes authenticator rows, removes pending enrollment, revokes API/browser/remember sessions, writes an audit event and queues an essential security notification. The user signs in again. No existing account is automatically disabled by deployment or migration.

The nullable, non-mass-assignable `users.mfa_disabled_at` records this explicit choice. Privileged accounts without a prior verified opt-out still require initial enrollment. When explicitly opted out, ordinary portal login/actions no longer demand MFA. Role, tenant, device binding, 30-minute privileged idle expiry and eight-hour absolute expiry remain enforced. MFA metadata is separate from session timeout enforcement. Infrastructure operations with their own MFA proof requirements remain protected and require re-enrollment before use.

Re-enrollment confirms a newly generated secret before clearing the opt-out. Old devices/recovery codes remain unusable; primary-device materialization safely replaces a revoked legacy row. Login and five-minute privileged sensitive-action verification resume. The existing final-device Remove control still preserves at least one authenticator; the separate Turn off 2FA action disables the entire account's factor configuration.

This changes the prior mandatory-MFA security policy for accounts that explicitly opt out. Prior VAPT evidence is not proof that optional admin MFA satisfies an external assessment policy. Production activation remains pending review/approval; local tests use synthetic accounts and do not change any real user's enrollment.

The shared admin/merchant device manager and disable dialog support small phones and landscape layouts. Inputs use 16px text, confirmation actions have 44px touch targets, short dialogs scroll internally, and safe-area insets constrain dialog width/height. Layout geometry does not animate during orientation changes. Where a browser exposes viewport segments, the dialog stays in the first segment instead of crossing a hinge. Browser verification covers 320–1920 CSS pixel widths; physical iOS keyboards and foldable hinges still require device testing. See `deployment/reports/NUERS_MFA_Disable_Responsive_Retest_2026-11-15.md` for evidence and deployment status.

## Previous baseline — VAPT remediation, 14 November 2026

This supersedes the 18 October once-per-login policy for privileged roles only. It has been implemented and tested locally; production deployment is pending.

Admin, super_admin, BIR and RDO sessions expire after 30 minutes without foreground activity, with an eight-hour absolute limit. Background polling cannot extend the session. Configuration may shorten these bounds, but cannot increase them. Merchant and client session settings retain their existing limits. New device sign-in, logout, revocation and expiry invalidate the old session.

Privileged mutations and exports require MFA verified within five minutes. Initial login verification remains required. The generic boundary recognizes exports/downloads/PDFs/statements/portability by route/action and export format. MFA workflows and infrastructure step-up have their own verification. Role, tenant, owner, branch and transaction validation still apply after MFA.

A stale sensitive request returns HTTP 403 with `code=MFA_REQUIRED` and `reason=RECENT_MFA_REQUIRED`, and records a challenge for that token. The existing deployed MFA screen then receives `verified=false`, preventing an automatic redirect loop. Successful current authenticator or unused recovery verification restores assurance. The original request is not replayed; the user must retry it. Infrastructure step-up still requires password plus authenticator (no recovery code).

`SessionPolicy::limits` exposes effective idle/absolute limits and role-specific `mfa_challenge`. For privileged users, MFA status `fresh` describes verification within five minutes; ordinary users retain current-login freshness. `step_up_minutes` is five for privileged users and null for others. Ordinary MFA-enabled users still verify once per login. `DeviceSession::issue` clears previous MFA assurance and pending challenges. Replay counters and concurrent token checks remain enforced. Session issuance rechecks the locked password hash so an authentication result from before a password reset cannot create a new token. Logout revokes only the presented token, so an in-flight old logout cannot revoke a replacement login.

The additive migration adds `users.api_last_activity_at` and `users.api_mfa_challenged_at`. Migrate using a separate deployment identity before rolling out the code. Existing privileged sessions without activity metadata use their login timestamp and may require signing in again. Runtime application credentials must not have schema privileges.

Regression evidence and remaining limitations are recorded in `deployment/reports/NUERS_VAPT_Remediation_Retest_2026-11-14.md`. No live authenticator secrets, recovery codes or authentication state were changed by this local remediation.

## Registered-email Google sign-in and multiple authenticators (2026-10-19)

Google sign-in authenticates an existing NUERS account; it never creates one. The current Google email must be verified and match exactly one registered `users.email` after case normalization. The stable Google subject must also match the existing link. A linked subject with a renamed, unregistered email is denied. A new link requires a Google-managed Gmail or verified Workspace address. OAuth state validation, role checks, the five-minute single-use handoff, and required MFA still apply. Email, linked subject and password are rechecked when the handoff is exchanged. Pending registration data without a `users` account does not grant access.

Merchant → Account Security → Two-Factor Authentication → Add Device supports up to five independently named TOTP authenticators. Add a device using the current password and a fresh code from an active authenticator (or an unused recovery code). Scan the new device's QR or enter its private setup key, then confirm a code from that new device. Setup expires after ten minutes and is tied to the current login and password. Any active device can complete MFA at the next login. Multiple authenticators do not change the single-browser session policy.

Each device has an encrypted secret and independent replay counter. Setup secrets are returned only when starting enrollment, never in device lists or audit records. Cancelled and removed secrets are erased immediately; `security:prune-mfa-setups` erases expired pending secrets every ten minutes. Recovery codes remain account-level and single-use. Removing a device requires password plus current factor proof; the final active device cannot be removed. Removing the primary device promotes another active device. Device additions/removals generate security audit entries and queue security notifications through the existing mail worker.

The additive `account_mfa_devices` migration does not rewrite existing user credentials. Existing primary authenticators are represented as “Primary authenticator” and materialized lazily under the user lock. The original `users.mfa_secret` and counter remain a compatibility mirror of the active primary. Login verification, enrollment and device changes recheck the token under the user lock to prevent a replaced login from changing the new session's MFA assurance.

References: [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect) and [OWASP Multifactor Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html).
