# BIR EIS connection and activation runbook

NUERS 1.0.0 · Implementation review: 28 October 2026

This release implements the public BIR EIS wire protocol and an administrative connection workspace. It is ready for issued-credential configuration and controlled interoperability testing. It is **not a BIR certification result**. Outbound requests and optional callbacks ship disabled. Merchant storage and ERP ingestion continue independently.

This document supersedes the implementation-gap findings in the 28 October PTI preparation audit for authentication, mapping, signing, submission and inquiry. Earlier audit reports remain dated evidence. Actual BIR certification and authorization remain outstanding until independently evidenced.

## Operator locations

- Super Admin → API Integrations → **BIR EIS Setup** (`/super-admin/api-platform?view=bir`).
- BIR Transmission Center → **BIR setup & submission** opens the same workspace. Local preparation batches remain distinct from official-format submission batches.
- PTI / PTT Management → PTI Applications prepares filing packages; the issued-authority register is at `/super-admin/pti-ptt?view=register`.
- ESP Accreditation (`/accreditation`) maintains solution/version evidence and controls.

No electronic PTI-application submission API was found in the supplied EIS invoice guide. The existing PTI workspace therefore prepares and tracks filing, while this new workspace handles the separate invoice transmission protocol.

## Contract baseline

Primary sources inspected on 28 October 2026:

- [Official BIR EIS API guide](https://eis-cert.bir.gov.ph/#/apiGuide), guide 2.3.
- [BIR's updated guide and JSON-format download](https://eis-cert.bir.gov.ph/#/downloads/1331), posted 15 October 2026. The downloaded workbook is titled JSON format v2.1 with a 26 September 2026 change-request date; its CAS sheet identifies proposed v2.2 changes.
- [RMC 98-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2098-2026%20redacted.pdf), for separate accreditation, taxpayer authorization and certification workflows.

The source excerpts and workbook checksums are retained privately in `output/bir-integration-20261028/sources/`. Public examples are reference material; their sample credentials are never loaded into NUERS.

| Operation | Certification | Production configuration |
| --- | --- | --- |
| Authentication | POST `https://eis-cert.bir.gov.ph/api/authentication` | POST `https://eis-api.bir.gov.ph/v2/authentication` |
| Invoice submission | POST `/api/invoices` on the certification host | POST `/v2/invoices` on the production host |
| Result inquiry | GET `/api/invoice_result/{submitId}` | GET `/v2/invoice_result/{submitId}` |

The production `/v2` prefix follows the published production example and must be confirmed against the issued contract before activation. Both origins are pinned by environment. An arbitrary URL, redirect or host substitution cannot receive credentials. Prefix changes require a reviewed deployment.

### Items requiring BIR interoperability confirmation

| Published inconsistency | Implemented behavior |
| --- | --- |
| July CAS includes debit note type `02`; September proposed CAS changes remove it | Explicit connection dialect. September mode rejects CAS debit notes; July mode permits them with original accepted-reference evidence. |
| POS `VatSales` versus `VATSales` | Explicit July/September dialect selects the exact field casing. |
| Result-list spelling varies between `processedDocuments`, `processDocuments`, `proccessdDocuments` | Accept exactly one known field. Conflicting lists and incomplete UID sets fail verification. |
| Callback worked example is an object; a table describes encryption | Callback stays disabled until certified. The implemented response follows the object example. Polling works independently. |
| Old callback examples use `S001` while result definitions use `SUC001` | Only `SUC001` establishes acceptance. Unknown codes remain uncertain for review. |

Do not enable a dialect based only on its date. Record the BIR-issued contract/version confirmation in the company connection and verify actual certification responses.

## Company connection setup

1. Add separate Certification and Production profiles for each merchant, registered five-digit branch and CAS/POS source system. Select the verified seller registration and applicable format.
2. Record the BIR-issued accreditation ID and application ID, source-system CAS acknowledgment/PTU number, and POS MIN/MSN where applicable. PTI, PTT and CAS/POS registration serve distinct purposes.
3. Store the BIR user ID, password, application key, authentication public key, invoice-signing private key, signing-key ID and actual key-expiry date. Use the secured dashboard only, never chat, source control or frontend environment variables.
4. The dashboard encrypts the bundle using Laravel's application encryption and displays only configured status and a public-key fingerprint. It never returns the bundle. Backups require both protected database contents and separately controlled application-key recovery material.
5. Record contract and testing/authority evidence. Enable the individual connection when appropriate. The server deployment switch remains independently enforced.
6. After authorized credentials arrive, the server operator may set `BIR_EIS_OUTBOUND_ENABLED=true`, clear cached configuration and confirm the worker/scheduler. This switch alone cannot grant production authority or release a batch.
7. Use **Test authentication**. It authenticates only and sends no invoice. A stored credential or successful login is not BIR certification.

All privileged routes require a Super Admin session and the existing enforced MFA policy. Merchant, citizen, admin and BIR monitoring roles cannot read or change wire credentials. These endpoints are not merchant ERP endpoints.

## Protocol implementation

- Header signature: Base64 of raw HMAC-SHA256 over Manila `yyyyMMddHHmmss` + uppercase HTTP method + exact path. `Authorization: Bearer ...`. Application key for authentication; session key for invoice submission and inquiry.
- Authentication payload: JSON user ID/password/random 32-character auth key, RSA PKCS#1 v1.5 single-block encryption with the BIR public key, then Base64. Oversized plaintext is rejected; it is not split into invented RSA chunks.
- Authentication response: AES-256-CBC using the auth key and its first 16 bytes as IV, following the published interoperability protocol. Padding and identity are checked. Sessions are encrypted at rest and reused until shortly before expiry; expiry is interpreted in Manila and stored in UTC.
- Invoice signature: compact JWS, `alg=RS256`, issued signing-key `kid`, Base64URL encoding, RSA-SHA256 signature.
- Batch: 1–100 signed invoices, comma-joined JWS strings, AES-256-CBC encrypted with the session key. Submit ID and all EIS unique IDs are persisted before transmission.
- TLS certificate verification is required. Redirects and automatic HTTP retries are disabled. Connections have bounded timeouts and response-size limits.
- Request audits contain operation, HTTP/result code, response hash and encrypted non-authentication response. Authentication ciphertext, bearer tokens and credential bodies are not retained in attempt logs.

The CBC IV convention is specific to this BIR protocol; it is not NUERS's general-purpose data-encryption design.

## Document preparation and lineage

Merchant source records → locally reconciled `NUERS-normalized-document-5` → immutable encrypted outbox → CAS/POS mapper → immutable encrypted BIR batch → signed/encrypted request → acknowledgment → authenticated per-invoice inquiry → production reporting projection.

- Company and branch are enforced server-side. A request cannot include another merchant's invoice.
- Original issue date, seller identity, customer identity, line amounts and source hashes remain traceable. The application does not rewrite an issued merchant invoice to satisfy BIR precision.
- CAS mixed VAT, zero-rated and exempt/non-VAT sales produce separate EIS identities per tax class, preserving the original company invoice number. The 100-invoice limit applies after splitting.
- CAS requires buyer TIN/branch/name. An unregistered buyer must be explicitly attested before the zero-TIN convention is used. Malformed TINs are rejected.
- Manual line discounts and recorded statutory discounts remain separate. Senior citizen, PWD, national athlete/coach and solo-parent calculations use the existing reviewed source calculation; mapping does not invent eligibility or recalculate legal entitlement.
- VAT-exclusive unit prices and quantities must reproduce the immutable source at the published precision. Unrepresentable prices/quantities, missing line items and unsupported document types are blocked for source review.
- Credit/debit/correction references must point to accepted original documents for the same merchant/environment, with a reviewed correction code. Latest-format unsupported CAS debit notes are blocked.
- Withholding allocation uses integer centavos by tax class and income/VAT/percentage type, exactly reconciling with the source total. It cannot add unrecorded tax amounts.
- Captured ERP originals require explicit reporting-responsibility and duplicate-reporting review. Reviewed coverage must include the original document date.
- Certification preparation uses synthetic fixtures. Live merchant documents cannot be prepared into certification batches.

Preparation is idempotent by request ID and request fingerprint. A source/tax class can belong to only one non-cancelled reporting batch. Never-sent batches can be cancelled while preserving their payload and audit history. Potentially submitted batches cannot be cancelled or blindly replayed.

## Production authority and release

Use **Link issued PTI / PTT & certification** on a Production connection after actual BIR documents are available. Select maintained, current permit records for the exact merchant, branch and Production environment. Record the taxpayer EIS certificate number/expiry, written authorization date, certification-result evidence and decision reason. Select the verified NUERS solution accreditation matching its approved version/configuration.

Saving this evidence does not issue a government permit. Production release separately requires the deployment switch, enabled connection, current key, configured credential bundle, confirmed contract, active merchant/branch, reviewed coverage, source-system authorization, current PTI/PTT, taxpayer certification and solution accreditation controls. These checks are repeated immediately before sending. Source snapshots must still match their preparation hashes.

Review the exact BIR JSON, mapped invoice count, environment and Submit ID. Select the exact destination and release the batch. `bir:wire-work --limit=10` is scheduled each minute with overlap protection. Existing local-preparation workers do not send batches owned by the new wire adapter.

## Result handling, recovery and reconciliation

| State | Meaning / operator action |
| --- | --- |
| Prepared | Local payload only. Review, cancel/rebuild or explicitly release. |
| Queued / sending | Durable leased work. Submit intent is saved before the invoice POST. |
| Processing | Submission receipt obtained or final result not yet available. Not acceptance. |
| Accepted | Every expected invoice in the batch has `SUC001` from verified inquiry. |
| Partial | Mixed final results. Source documents with any failed tax-class component are not wholly accepted. |
| Rejected | Verified failures, including duplicate result `SYN003`; duplicate is not treated as success. |
| Uncertain | Timeout, malformed response or unknown outcome. Inquire with the same Submit ID. |
| Reconciliation | Polling budget reached, unavailable result or BIR E15/E16. Review and request another inquiry; do not replay POST. |

The inquiry verifier checks accreditation/user/submit/ack identities, processing code, exact expected UID set, unique UID count and success/failure totals. It rejects missing/extra UIDs, ambiguous fields and unknown result codes. Production projections preserve encrypted response evidence and hashes; Certification never increments production acceptance.

Authentication failure before any invoice POST leaves an unsent, reviewable batch. Once a POST might have occurred, only inquiry is retried. Exponential inquiry backoff is capped at one hour, with a 24-attempt review boundary. A manual inquiry keeps the same Submit ID and history.

**Pause outbound access** stops authentication, sends and inquiries for that profile. **Resume** preserves the connection identity and outstanding Submit IDs. Credential replacement requires unsent batches to be cancelled and active leases to finish. Already-submitted batches can move to replacement credentials only under the same BIR user/application identity and remain inquiry-only. A revoked permit or expired signing key blocks new sends but does not prevent safe inquiry of already-submitted work.

Monitor the batch state, attempt history, key expiry and authority checklist. Preserve uncertain submissions during incidents. Do not delete batch identities, clear idempotency claims or mark records accepted manually to resolve an error.

## Optional BIR callback

PUT `/api/bir-eis/receive_invoice_result/{profile_uuid}` is distinct from merchant ERP webhooks. It is disabled by default through `BIR_EIS_CALLBACK_ENABLED=false`.

When separately certified/enabled, it verifies the application-key HMAC, method/path, accreditation/application identity, timestamp window, batch scope, exact document results and body-size limit. Duplicate callbacks reuse the recorded acknowledgment. The published HMAC does not authenticate the body, so a callback can only trigger an authenticated BIR GET inquiry; it can never directly publish invoice acceptance. Do not enable it until BIR confirms the conflicting callback-response examples and the callback certification scenarios pass.

## Activation acceptance record

Retain the issued endpoint/format specification, environment-specific credentials, authentication result, prescribed BIR certification outcomes, representative CAS/POS and tax-category results, duplicate/timeout inquiry outcomes, taxpayer authorities, configuration/version approval, clock/TLS checks and operational ownership. Test the required portal scenarios, including additional callback cases only if callbacks will be used. Record actual evidence rather than lab test counts as certification.

Local PHPUnit tests use synthetic identities, in-memory databases and HTTP fakes with stray requests prohibited. They validate implementation and isolation; they do not prove acceptance by BIR. This release makes no real BIR submission, creates no live test sale, and does not change merchant wallet fees.
