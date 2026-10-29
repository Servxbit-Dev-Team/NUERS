# MFA once per login

The 18 October 2026 policy requires authenticator or one-time recovery verification once for each authenticated login. Browsing, profile saves, document review, manual review email, wallet administration, team access changes and API credential management share the same current-login assurance. There is no 15-minute re-challenge timer.

`MfaSession::verified` checks an enrolled authenticator and a verification timestamp from the current login, no later than server time. `DeviceSession::issue` resets MFA assurance on each new password/Google sign-in. Logout, password reset, device replacement, revocation and normal session expiry continue to invalidate access. Existing session/device bounds and rate limits are unchanged. No timestamp is refreshed just by browsing or saving.

`/api/auth/mfa/status` returns verified=true only for this login. An already verified session opening an old MFA link returns to the sanitized internal return path without showing another code form. Recovery codes displayed immediately after enrollment remain visible until explicitly acknowledged. The legacy fresh response is an alias of current-login verified, and step_up_minutes is null; policy.mfa_challenge is login_session.

Role, tenant, owner, branch, account-activation and transaction-specific validation still apply. Password confirmation remains required where already implemented. This policy increases the time during which an intercepted valid device-bound session can authorize changes without another code; protect signed-in devices and sign out when finished.

Validation: 387 isolated PHPUnit tests / 4338 assertions; production frontend and static builds; synthetic browser initial-login MFA, profile save beyond the old timer, and already-verified MFA return. No live user MFA secrets, recovery codes or authentication state are modified by deployment.

## Registered-email Google sign-in and multiple authenticators (2026-10-19)

Google sign-in authenticates an existing NUERS account; it never creates one. The current Google email must be verified and match exactly one registered `users.email` after case normalization. The stable Google subject must also match the existing link. A linked subject with a renamed, unregistered email is denied. A new link requires a Google-managed Gmail or verified Workspace address. OAuth state validation, role checks, the five-minute single-use handoff, and required MFA still apply. Email, linked subject and password are rechecked when the handoff is exchanged. Pending registration data without a `users` account does not grant access.

Merchant → Account Security → Two-Factor Authentication → Add Device supports up to five independently named TOTP authenticators. Add a device using the current password and a fresh code from an active authenticator (or an unused recovery code). Scan the new device's QR or enter its private setup key, then confirm a code from that new device. Setup expires after ten minutes and is tied to the current login and password. Any active device can complete MFA at the next login. Multiple authenticators do not change the single-browser session policy.

Each device has an encrypted secret and independent replay counter. Setup secrets are returned only when starting enrollment, never in device lists or audit records. Cancelled and removed secrets are erased immediately; `security:prune-mfa-setups` erases expired pending secrets every ten minutes. Recovery codes remain account-level and single-use. Removing a device requires password plus current factor proof; the final active device cannot be removed. Removing the primary device promotes another active device. Device additions/removals generate security audit entries and queue security notifications through the existing mail worker.

The additive `account_mfa_devices` migration does not rewrite existing user credentials. Existing primary authenticators are represented as “Primary authenticator” and materialized lazily under the user lock. The original `users.mfa_secret` and counter remain a compatibility mirror of the active primary. Login verification, enrollment and device changes recheck the token under the user lock to prevent a replaced login from changing the new session's MFA assurance.

References: [Google OpenID Connect](https://developers.google.com/identity/openid-connect/openid-connect) and [OWASP Multifactor Authentication Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Multifactor_Authentication_Cheat_Sheet.html).
