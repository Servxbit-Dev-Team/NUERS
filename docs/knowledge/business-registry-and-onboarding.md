# Business registry, onboarding and RDO mapping — 15 October 2026

## Implemented pages

- `/super-admin/merchants`: real merchant registry, search, business/status/EIS/date/region/city/RDO/industry/VAT/account filters, pagination, profile, edit and review modals, complete filtered CSV export, template and actor-scoped CSV preview/atomic commit.
- `/super-admin/taxpayer-onboarding`: shared application records, evaluation/BIR-validation/EIS-setup/onboarded/rejected stages, five-item evidence checklist, reviewer reason, process guide and requirements cards. Platform approval is separate from BIR authority. Evidence references and private verification files are persisted. Authorized administrators can preview files, compare retained versions, request changes and independently verify documents before the separate account activation decision.
- `/activate-business`: expiring single-use invitation, encrypted server payload and hashed lookup token, strong confirmed password, explicit current policy acceptance. Invitations use URL fragments; raw invitation tokens are not query parameters. No automatic email is sent, account email is not falsely marked verified, and an existing login is never overwritten.
- `/super-admin/rdo-mappings`: complete reference office directory and city/barangay rule editor, PSGC hierarchy validation, evidence/source, enable/disable and optimistic revisions. Changes are audited and affect future lookups, not taxpayer transfers.

Registry writes require admin/super-admin; BIR is read-only here. TIN checks are local format/duplicate checks, not official BIR verification. Suspension/inactivation revokes linked merchant sessions and unused invitations. Financial document snapshots are preserved. The old generic merchant mutation path is disabled; the legacy business-account update path now delegates to the strict owner and revision-checked Business Profile handler.

## RDO coverage and limits

The catalogue contains **124 reference offices**, with **105 named city/municipality rules and five barangay rules** for 1,642 PSGC cities/municipalities. This is not complete nationwide jurisdiction coverage. `scripts/build-rdo-named-localities.py` matches specifically named localities and records provenance; it does not infer province-wide coverage or nearest offices. Special zones and split cities require specific evidence. Unmapped or conflicting addresses require confirmation against the taxpayer's registration.

Caloocan City `1380100000`, including Barangay 179 `1380100179`, selects **RDO 027 — Caloocan City**. RDO 045 is SMART, reflecting the cited reorganization. A legacy local unsplit code 021 remains in existing records but is excluded from the current selection catalogue. It is not silently deleted or reassigned.

Sources:

- [BIR Citizens Charter regional/RDO directory, 2024](https://bir-cdn.bir.gov.ph/BIR/pdf/citizens-charter-rr-rdo-2024-v3.pdf)
- [BIR Tax Calendar, 2024](https://bir-cdn.bir.gov.ph/local/pdf/2024-bir-tax-calendar.pdf)
- [RAO 2-2024, SMART jurisdiction](https://bir-cdn.bir.gov.ph/BIR/pdf/RAO%20No.%202-2024.pdf)
- [RAO 6-2025](https://bir-cdn.bir.gov.ph/BIR/pdf/RAO%20No.%206-2025.pdf)

These are dated references, not proof of an exhaustive current jurisdiction dataset. User confirmed no additional complete jurisdiction file is available. Directory office addresses alone do not establish all cities/barangays under an RDO.

## Verification

`BusinessRegistryTest` has nine tests for role restrictions, retired legacy paths, TIN uniqueness, revisions, checklist decisions, session revocation, CSV actor scoping and atomicity, invitation replay/expiry, PSGC mapping precedence and named locality consistency. Browser checks covered the actual Caloocan/Barangay 179 selection, real registry and onboarding rows, profile/create modals and RDO counts. Screenshots are in `.local/registered-businesses-dashboard.jpg`, `.local/taxpayer-onboarding-dashboard.jpg`, and `.local/caloocan-rdo-auto.jpg`.


## Administrative document review — 18 October 2026

Submitting the complete Business Profile checklist for review atomically queues a NUERS confirmation to the linked owner's sign-in email and an operations notice to authorized admin/super-admin accounts. The editable business contact email is not used as the recipient. Emails link to Merchant Business Profile or Registered Businesses without attaching private evidence or including TINs. A persisted submission revision deduplicates repeated clicks and recovery attempts; a changed, resubmitted profile receives its own notice. Missing or invalid evidence never queues a submission notice. Workflow/operations email preferences remain respected, and administrator role is rechecked before dispatch. Delivery uses the encrypted notification outbox and existing SMTP worker; SMTP acceptance does not establish inbox delivery or account activation.

Authorized admins can manually notify the merchant from Registered Businesses → Review / Manage Status → Merchant review email, or Business Details → Documents. Save the document/account decision first. Changes requested requires a current Needs changes document on a pending account; Documents verified requires all five current files to have independent verified decisions; Account approved additionally requires an active, onboarded account, all five onboarding checks and an evidence reference. Emailing never changes these decisions.

The admin previews the exact recipient, subject, saved correction notes and optional additional message, then explicitly confirms Queue merchant email. Recipients are limited to the authoritative linked sign-in address. Previews expire after ten minutes and bind the administrator, bearer session, business, recipient and decision fingerprint. Send requests are idempotent, merchant-row-locked and atomically persist encrypted message evidence, outbox record and audit reference. Manual delivery history shows actor/time, exact message and SMTP status. Deliberate resends require a reason, a five-minute cooldown and at most five manual messages per business per hour; pending jobs are deduplicated and uncertain SMTP outcomes cannot be replayed from this panel. Delivery rechecks the saved decision, recipient, merchant workflow preference and administrator role. Changed decisions cancel stale queued emails.

GET `/api/admin/businesses/{merchant}/review-notifications`, POST its `/preview` subroute and POST the base route are admin/super-admin only, private/no-store, and use the existing device-bound authentication and MFA verified for the current login session for writes. BIR read-only roles cannot access this email panel. `business_review_notifications` retains encrypted manual message snapshots and request/outbox references; plaintext audit events include only IDs, notification kind and resend flag. No private files are attached, and messages distinguish NUERS approval from BIR authority.

Registered Businesses → Business Details → Documents and Review / Manage Status share the verification checklist. The profile now distinguishes uploaded verification categories from transaction-document counts. Each uploaded file opens a private review workspace with image and PDF rendering, PDF page navigation, zoom, rotation, fit-to-view, original download, file size/type/upload details, a SHA-256 reference, and paginated version history. Retained earlier versions are read only. Rendering uses bundled Mozilla PDF.js, worker, CMaps and standard fonts; documents are not sent to a third-party viewer. PDF pages are rendered to canvas without interactive document scripts or link navigation. Object URLs are released on file change or close. Mobile layout and focus/cancellation are supported.

GET `/api/admin/verification-documents/{id}/preview` checks the authenticated admin/super-admin role, decrypts and validates the whole original, audits the view, and returns an expiring encrypted review proof in `X-Verification-Preview`. The proof binds the actor, current bearer-session hash, document ID, original SHA-256 and a 30-minute expiry. GET `/api/admin/businesses/{merchant}/verification-document-history?kind=...&page=...` returns only that business/category's metadata and review notes, in pages of 20. It never returns private storage paths, encrypted payloads or credentials. Both APIs use private/no-store responses.

Document decisions require that exact preview proof, the expected original hash, explicit confirmation, a 10–1000 character reason/reference, and readable/identity-match/complete check flags. Verification requires all checks. The controller locks the merchant, rejects self-review and stale/superseded versions, preserves prior decisions, and audits actor, before/after status, original hash, check results and evidence reference. Identical retry submissions return the saved decision without another audit event or revision increment. The existing fresh-MFA policy still applies to sensitive administrative writes.

Evidence references and account/document decision reasons provide contextual suggestions plus editable manual text. Suggestions do not complete checks, establish evidence, approve a document or activate an account. Reviewers must choose only statements supported by the actual evidence. Request-changes suggestions include category-specific requirements, readable scans, complete pages, matching registered details and document validity. Account reasons distinguish pending evaluation, activation, suspension and rejection.

An explicit re-upload after Needs changes creates a new Pending/Awaiting review version even when the file bytes are identical. The previous original and encrypted decision remain immutable; the new version has no reviewer, review timestamp or inherited reason. Same-content pending or verified uploads continue to deduplicate, and retrying a completed chunk upload does not create another version. The merchant sees whether a new version was saved or the exact file was already pending/verified. Superseded document proofs cannot authorize a review of the new version.

A failed private-file integrity check is audited and may authorize a Needs changes decision with a replacement request; it can never authorize verification. Unreadable or password-protected PDF previews show an actionable error. Notes for required changes appear on the merchant's existing document checklist. No preview, individual document verification, or checklist completion activates an account or establishes BIR approval. All five latest documents and the existing independent onboarding checks remain required where document-based activation applies.

Verification: synthetic image and two-page PDF fixtures, previous-version read-only behavior, rendered-page navigation/zoom/rotation, exact-file confirmation, saved review notes, and a 390px responsive layout. Backend tests cover role isolation, cross-business history, missing/modified/expired/session-mismatched proofs, stale originals, review check enforcement, idempotent retries, corrupted originals, and audit-chain integrity.


## Merchant status and NUERS ESP processing (18 October 2026)

Registered Businesses displays NUERS account standing only. Merchant EIS connection badges, filter, connection summary/chart and detail tab are removed, including the EIS field in registry CSV exports. The NUERS Processing panel links to platform ESP accreditation and BIR transmission operations. Onboarding uses the label NUERS Processing Setup while preserving existing stage identifiers and records.

This presentation change does not assert a new accreditation or connection outcome, modify taxpayer authorizations, enable transport, approve an account or remove legal-readiness checks. Existing internal registry API integration fields remain available for compatibility; no merchant EIS status is rendered in this workspace.


## Merchant profile correction approval — 19 October 2026

Only Contact Number, Website and Business Description are directly editable by the linked merchant owner. Business name, TIN, owner/representative, trade name, business email, registration/address/tax details and profile-image changes are proposals until an independent admin/super-admin approves them. Merchant User Directory and its export use the registered business owner/representative name; the sign-in email and credentials are separate and are never transferred by a profile correction.

Business Profile includes a change-request form and paginated status/history. Registered Businesses and Taxpayer Onboarding include a pending/all request queue with before/proposed values, evidence confirmation and mandatory decision reason. Requests are encrypted in the existing business_registry_requests table under profile_change, limited to one pending request per merchant, and deduplicated by request ID. Withdrawal and rejection preserve the current record. Approval locks the registry and merchant, rejects changed baseline values or duplicate TINs, applies only the proposed fields and records immutable audit evidence. Contact-only edits do not make an unrelated owner-name request stale. No account reactivation, ownership reassignment, login-email change, credential change, wallet modification or historical invoice rewrite is implied.

The generic profile endpoint and citizen profile editor cannot be used by merchant accounts to bypass the approval workflow. Existing business-profile detail values are preserved when the three direct fields are saved. Profile pictures now save immediately through the owner-only, MFA-protected image endpoint. A new profile upload supersedes an old image-only request, with audit history retained. Registered identity changes still require independent approval. Invoice Settings has an independent E-Receipt Logo; changing either image never changes the other. Existing receipt logos are copied once into the separate field during migration to preserve appearance.


## Merchant portfolio overview — 20 October 2026

Registered Businesses now shows available/reserved wallet balances, Live transaction count and amount, actual posted NUERS charges, credited deposits, withdrawals, pending top-ups, active unexpired API-key counts by environment, separate Sandbox event counts, and the last received Live transaction time in PHT. Clicking a wallet balance opens the existing protected wallet ledger; Business Details includes the same financial overview. The portfolio cards cover all merchants matching the registry filters, independently of pagination. Dates in the search filter select merchant registration dates, not transaction dates; financial figures are all-time.

Source definitions: `merchant_wallets` holds current balances; `merchant_wallet_entries` supplies actual fee debits, credited deposits and withdrawals. Pending funding requests never increase available funds or deposits. `merchant_transactions` supplies recorded Live transaction counts and amounts for consistency with the admin dashboard; this includes all recorded statuses and is explicitly not labelled net sales. `integration_sandbox_events` is shown separately and never contributes to Live amounts or charges. Key counts expose no credentials and exclude revoked, disabled and expired keys. A funded/low/empty indicator uses each wallet's saved low-balance threshold.

Financial fields are returned only to admin/super-admin. BIR read-only registry users retain their existing non-financial view. Merchant/client accounts cannot access registry list, detail or export endpoints. All existing session/device/MFA and wallet-write authorization remain enforced. Reading the register never creates a wallet or modifies a balance.

Registry filters, stage/application filters, counts and pagination execute in SQL. Integration presentation no longer performs an additional query for every listed merchant. Per-page financial queries group only the selected merchant IDs. Small portfolio aggregates are cached by query scope and the committed dashboard revision; changed application records invalidate the cached totals. Existing one-second change checks refresh active views without replacing unfinished edits. CSV uses the same filters and adds explicit PHP-unit financial columns for authorized administrators; exports stream in batches of 200, with a 10,000-merchant limit per download.

Verification includes `RegistryMerchantOverviewTest`: exact posted-centavo totals, duplicate fee replay, historical price changes, pending/Sandbox exclusion, empty/unfunded states, pagination independent summaries, stage filters, bounded query count, export units and role isolation. Existing registry and wallet regression suites also pass. No schema migration is needed.

### Merchant row actions

Registered Businesses and Taxpayer Onboarding use one labelled **View details** button and a **More actions** menu for each merchant. The menu identifies the merchant and groups wallet, edit, account review, account access and TIN lookup actions. Wallet access is shown only to administrators permitted to view financial data; editing, review and account access retain existing write-permission checks. Choosing a menu action opens its existing workflow; it does not commit a change.

Actions remain visible during horizontal scrolling on desktop. Smaller screens use the table's horizontal scroll with 44-pixel action targets. Keyboard navigation, focus return, Escape dismissal and viewport-contained menus are supported. Background registry refresh pauses while a row menu or business wallet is open and resumes after closing. This presentation change does not alter accounts, permissions, wallet balances or audit history.

## Business Details transaction activity (2026-10-21)

Authorized administrators can open **Registered Businesses → View details → Transactions**, the **View business transactions** button beneath the financial overview, or **More actions → View transactions**. The workspace identifies the selected business and TIN and locks all records, totals, details, filter options and exports to that merchant on the server. A conflicting merchant query is rejected. Merchant, client, RDO and BIR-only roles cannot access these registry financial endpoints; normal privileged MFA remains enforced.

The default Live view uses the canonical document register: one row per document identity, with linked payment evidence shown separately. This differs intentionally from the Profile overview's raw transaction-event count. Filters include original/received dates, status, document type, customer, sale category, manual/statutory discounts, tax bases, amount range, payment method, channel, source, branch, region, RDO, identity conflicts and BIR evidence. Summary cards, document tabs, paging and reports use the same applied filters. Unsaved filters do not change an export. Refreshes preserve the active page and filters.

Details include document identifiers, business/customer information, financial and tax breakdowns, manual and statutory discounts, payment evidence, saved line items, document source records and transmission history. Missing source fields are explicitly labelled; no value is inferred. Line-item fields are allowlisted to exclude private integration payload fields.

The separate Sandbox tab shows only this business's saved API tests and cannot affect Live totals or wallet balances. Both environments offer branded NUERS PDF and CSV reports. Live supports exporting all matching records or selected rows intersected with the current merchant/filter scope. Detailed PDFs are limited to 500 records and report their snapshot and filters; larger datasets use streamed CSV or narrower filters. PDF jobs and downloads remain private to the requesting administrator. Pagination stays beneath the table.

Read-only endpoints: `/api/business-registry/{merchant}/transactions`, `/transactions/{record}`, `/transactions/export`, `/sandbox-records`, `/sandbox-records/{record}` and `/sandbox-records/export`. No database migration, transaction mutation, wallet change or BIR send is part of this feature.
