# PTI/PTT, API integration workspace and device sessions — 15 October 2026

## PTI/PTT register

`/super-admin/pti-ptt` uses the supplied dashboard composition and shared fixed-width sidebar/typography. The saved ESP deck defines **PTI as Permit to Issue** and **PTT as Permit to Transmit**; the image's terminal terminology is not used as a legal definition. Devices are tracked in a separate tab.

Implemented: six metrics, PTI/PTT/device/connection-observation/renewal/audit tabs, search, taxpayer, RDO, environment, status, validity and date filters, pagination, detail/review modals, registration, device registration, manual connection observations, CSV template, UTF-8 preview and actor-scoped atomic/idempotent import, filtered CSV and selected-record JSON export. Evidence and reason are required; edits check revisions. Identity, permit reference and environment cannot be reassigned. Revoked authorities cannot be reactivated. Permit dates come from recorded authority; no blanket five-year PTI/PTT term is invented. Thirty-day expiry is an operational reminder, not an asserted statutory renewal deadline.

Active/expired/expiring states are derived from evidence-review status and dates. Devices require a current parent PTT. Manual observations cannot set Connected. Matching maintained permit records constrain existing production integration references when expired, suspended, revoked, for another tenant or environment. New records do not automatically set legacy issuance/certification flags or authorize BIR transport.

## API integration workspace

`/super-admin/api-platform` replaces the old admin portal screen with seven tabs, real stored connection settings, provider/environment/tenant separation, credential replacement and revocation, public X.509 fingerprint/expiry recording, endpoint configuration, log search/environment/method/status/date filters, pagination, complete filtered log CSV, webhook configuration, diagnostics and implementation guidance. Existing EIS integration metadata is imported as **draft**, with provenance; no secret, trust or connectivity is inferred.

Secrets are encrypted using the application key and omitted from reads, logs and exports. Changing destination or client ID clears the stored provider credential; changing the destination disables configured endpoints. Endpoint paths must be relative, destination URLs HTTPS without embedded credentials/query tokens, and certificate uploads reject private keys. Public certificate metadata is not issuer trust verification. BIR access tokens are not fabricated or generated locally. Webhook signing secrets are encrypted, but actual signed delivery/retries are **not implemented**. Local diagnostics perform no network request. Request logs retain certification labels and redact common credential fields/query parameters.

Admin/super-admin writes and administrative/BIR reads are enforced server-side. These tables are configuration/evidence stores, not a certified BIR adapter, key-management service, signed webhook delivery engine or full API client policy gateway. Merchant API client scope/IP enforcement and official BIR interfaces remain separate work.

## Session behavior

`DeviceSession` stores a hash of a browser installation UUID. Successful logins on the same identified browser device retain the existing valid bearer token; another device rotates the one active token. A browser profile/incognito context or cleared browser storage counts as a different device. Device ID alone grants no access; login still requires valid credentials. Legacy clients without the identifier retain conservative token rotation. Both password login and the Google exchange use the same issuer. Explicit logout only revokes the presented token, so a stale logout cannot revoke a replacement device's session.

The client checks validity at startup, every 15 seconds, on focus, reconnect and tab visibility changes. An authenticated 401 clears only the token used by that request and redirects to Login; a delayed stale response cannot clear a newer login. Tabs synchronize token changes via browser storage events. Failed login, HTTP 403, network errors and server errors do not delete a valid stored session. Initial inability to reach the server shows a retry state rather than silently dropping the token. Invalid sessions clear profile state; routes wait for verified permissions. No idle logout timeout was added.

Browser token storage remains localStorage; this change is not an MFA implementation or a migration to HttpOnly sessions. Session signing and encrypted token persistence improvements belong in the security backlog.

## Verification

Seven `TaxpayerWorkspaceTest` tests cover permit revisions/idempotence/expiry, current PTT/device restrictions, observation integrity, private atomic import, credential encryption and revocation, URL validation, endpoint/webhook persistence and redacted exports. Three `DeviceSessionTest` tests cover same-device preservation, new-device invalidation, stale logout, failed login, validation and private response caching. `node scripts/test-session-client.mjs` checks eight client scenarios, including offline and stale-response races. Browser checks cover real configuration/logs/diagnostics and 390px pages/modal sizing with no document overflow. Production builds pass; the existing large-bundle warning remains.
