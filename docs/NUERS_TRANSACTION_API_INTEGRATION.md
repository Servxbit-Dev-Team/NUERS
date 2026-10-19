# NUERS Transaction API Integration Guide

Download the maintained [PDF guide](/guides/NUERS-API-Connection-Guide.pdf).

NUERS ERP / POS / ECOMMERCE CONNECTION GUIDE
Version: NUERS-ingestion-1 · 18 October 2026

PURPOSE
Save finalized transactions from your existing system in your own NUERS account. This is NUERS intake, not the BIR wire schema, accreditation, or BIR acknowledgment.

1. PREPARE YOUR ACCOUNT
Complete Business Profile review. Approval activates the account and automatically prepares its owned-system connection. Existing active merchants receive the same setup when generating a key. No separate admin source approval, source-profile request or Branches / Terminals setup is needed for new owned ERP/POS copies. Generate a named Live or Sandbox key, save the one-time secret, and copy the returned connection configuration. The API key itself is the secret; no public/private key pair or token exchange is required. Find source_profile_id, source_system and branch_code in Merchant → API Integration → Source Profiles; the first active connection is selected automatically. Choose record_mode: source_capture for a document already issued in your system. Its original date must be on or after the automatic connection start, while the verified seller identity, VAT registration and active location still match. Historical dates or a different effective seller registration require an independently reviewed historical profile. Do not change an original timestamp to pass this check. Multiple owned systems using this stable business connection must namespace source_event_id and external_reference by system; keep identifiers unchanged during key rotation. Capture stores a copy, preserves the original number/date and does not allocate a NUERS series or issue a second original. For new nuers_issuance, choose a stable source_system identifier and omit source_profile_id. nuers_issuance (the legacy default when record_mode is omitted) creates a new NUERS original and requires the real NUERS issuance authorities plus the next authorized series. Never renumber an ERP original to pass issuance checks.

2. CREATE A SCOPED KEY
Merchant → API Integration → Generate New API Key → enter an API key name and select Live or Sandbox. No merchant Branches / Terminals setup is required. NUERS derives the registered establishment from the approved business TIN and retains branch scoping internally. Live keys use 50,000 requests/day; Sandbox keys use 5,000. Both default to transactions:write plus transactions:read and a 90-day expiry. Environment is immutable; create a different key to switch. Configure IP restrictions and narrower scopes through Edit Key Policy after creation. Use Testing Tools for synthetic validation. The registered owner must complete MFA for the current login session. One key per system, branch and environment; keys expire after 90 days. Only the hash is stored. Copy the key once into your server secret manager. Limit source IPs to your backend egress IPv4/IPv6 addresses where appropriate. Never expose secrets in browser/mobile code, source JSON, logs or emails. Use transactions:write to submit and transactions:read for status and wallet; the manual retry tool requires both. Revoked keys cannot be reactivated.

3. DOCUMENT TYPES
vat_invoice — VAT Invoice
non_vat_invoice — Non-VAT Invoice
vat_exempt_invoice — VAT-Exempt Sales Invoice
zero_rated_invoice — Zero-Rated Sales Invoice
mixed_sales_invoice — Mixed Sales Invoice
payment_receipt — Payment Receipt (Supplementary)
sales_invoice — Sales Invoice (Auto-classify)
official_receipt — Official Receipt (Payment Alias)
Seller VAT registration is reviewed account/source-profile evidence, never an API-key tax toggle. VAT sellers may mix vatable, vat_exempt and zero_rated lines; they cannot use non_vat. Non-VAT sellers may use non_vat (sales subject to percentage tax classification, without computing a percentage-tax liability here) and vat_exempt, with separate sspt_sales and vat_exempt_sales buckets; they cannot charge VAT or zero-rate as VAT sellers. An entirely exempt non-VAT sale is still non_vat_invoice. Mixed documents use mixed_sales_invoice. Classify each line from its actual tax facts and applicable effective legal basis; product name or buyer VAT status alone is insufficient. Preserve tax_basis_reference or per-line references for exemption/zero-rating review. Tax types: vatable, non_vat, vat_exempt, zero_rated. Mixed sales use classified lines and mixed_sales_invoice. sales_invoice auto-classifies from lines. official_receipt is an alias of supplementary payment_receipt, not another primary sale. Do not submit a second sale when collecting an already invoiced amount. Credit/debit adjustments use the reviewed linked-document correction workflow; arbitrary adjustment imports are not supported here.

4. REQUEST CONTRACT
POST https://nuers.net/api/integrations/transactions
Header: X-NUERS-API-Key: <server-held-key>
Content-Type: application/json
Required fields: schema_version, source_system, source_event_id, external_reference, event_type, occurred_at, branch_code, receipt_number, document_type, payment_method, gross_amount (or amount), tax_type, complete items. For source_capture also supply record_mode and the source_profile_id/source_system from your automatic connection or applicable reviewed historical profile. Optional customer_address, withholding_tax and withholding_basis_reference are preserved. Withholding reduces net_payable, not gross_amount, VAT base or the wallet fee threshold. Do not invent a withholding rate; submit the source amount and applicable CWT basis. Other charges must be classified line items. Unknown fields and conflicting totals are rejected.
source_system accepts letters, numbers, underscore, dot and hyphen. Each source_event_id identifies one immutable source event. occurred_at is the ORIGINAL event timestamp with timezone, not the retry time; no more than five minutes in the future. invoice.finalized pairs with primary invoices; payment.recorded pairs with payment receipts. Branch must exactly match the key's five digits. Payment methods: cash, card, bank_transfer, e_wallet, credit, other. One to 100 lines, each with description, quantity and unit_price; item tax_type defaults to the header. Ordinary taxable lines use 12% VAT only. Prices and gross amount are PHP/PESOS with at most two money decimals; wallet fields are INTEGER CENTAVOS. Do not copy portal invoice centavos into this endpoint. The server reconciles line totals and rejects inconsistent amounts. gross_amount is the final total including any VAT; vat_inclusive describes whether item prices include VAT.

5. OFFLINE SOURCE OUTBOX (REQUIRED IN YOUR CONNECTOR)
Commit a durable outbox entry in the same local database transaction that finalizes the ERP/POS document. Save the complete ORIGINAL JSON, stable IDs, original time and source number. A worker sends it after commit. If the internet goes down or the response is lost, keep this exact entry pending. Never generate a different reference, timestamp or number for a retry. Use sequential document-number order per branch/series. Keep independent source backups.

6. ACKNOWLEDGE ONLY VERIFIED ACCEPTANCE
201 creates one durable NUERS resource. 200 with duplicate=true returns the original IDs without another wallet fee. Check matching source_event_id and external_reference, status=durably_recorded, nonempty id and receipt_id. Save those NUERS IDs and status_url back to the source atomically before marking the local outbox accepted. Unknown or malformed success responses stay pending.
GET https://nuers.net/api/integrations/transactions/{id} uses transactions:read and the same merchant/branch. Keys can be rotated without changing source identity. The tenant is derived from the key, never trusted from JSON. Same identity plus changed content returns 409 and cannot overwrite an issued original. Receipt-number and source-event identities have independent server guards.

7. BATCH OR MANUAL RETRY
POST https://nuers.net/api/integrations/transactions/batch with {"events":[...]} containing 1–50 original events for the SAME branch, in legal source-number order. Batch financial writes and wallet deductions commit together or none. Already accepted duplicates remain free.
The portal's Manual Retry tab can import an array or {"events":[...]} JSON export from your system (max 50 events / 2 MB), encrypt it in this browser's queue with a 16+ character recovery passphrase, and submit manually. Enter a matching branch key in memory only. Each key transmits only its branch. Use the same passphrase after reload. Browser storage can be cleared and is not a substitute for your source outbox or backups. The portal cannot retrieve records still confined to an offline ERP automatically. Wallet → intake mode is a preference your connector must honor; realtime attempts after local durable save, batch waits for an operator. This cannot authorize issuing invoices offline.

8. WALLET / ERRORS
GET https://nuers.net/api/integrations/transactions/wallet returns available_centavos, intake_mode, current fee, threshold and rate revision. Current default policy: PHP3 per finalized primary Live sale with a PHP0 minimum (every amount is chargeable). A positive minimum exempts sales strictly below it; Super Admin can change the minimum qualifying amount and fee for future acceptances. Read current pricing; never hardcode either value. Accepted retries and payment copies do not add another sale fee.
402 INSUFFICIENT_WALLET_BALANCE: no financial record, debit, inbox or series advance commits; retain locally, fund and resend the original. 401/403: correct authentication/scope/IP/account/branch or permissions. 409: inspect identity, series, authority or environment conflict; stop and review. 422: fix the source mapping before first acceptance; do not mutate accepted originals. 429: follow Retry-After when present and use bounded exponential backoff with jitter. Timeout/5xx: outcome unknown, resend unchanged to resolve it. Stop on the first unaccepted document in a source series. Insufficient funds and outages do not extend legal deadlines.

9. WEBHOOKS AND TESTING
Merchant → API Integration → Webhooks now supports owner-managed HTTPS receivers separately for Live and Sandbox. Save a URL you control, store the one-time signing secret, request signed ownership verification, then enable delivery. No admin source approval is needed for the automatic owned-system connection. Verification leaves callbacks paused until you enable them. Delivery logs show worker health, individual attempts and bounded manual retries. Use polling as a fallback. Webhooks never prove BIR acceptance; saved legacy URLs are not activated. See /guides/NUERS-Webhook-Connection-Guide.pdf for the receiver, exact signature and replay rules. Synthetic Testing Tools validates the current ingestion fields and per-line calculation without creating financial records or contacting BIR. Never submit production customer data to the public synthetic preview. Sandbox keys use the same intake/status/wallet URLs but persist only encrypted test records in a separate store. Responses include environment=sandbox and test_only=true, no Live debit, no legal numbering and no BIR queue. The Sandbox wallet has available_centavos=null and shows only a simulated fee. Sandbox tests use the merchant’s current VAT registration; they do not approve source profiles or issuance authority. Status IDs cannot cross environments. Merchant Invoices & Receipts and admin Transactions provide separate Live/Sandbox views. Manual Retry uses separate encrypted device queues and rejects a key from the other environment. Keep environment in your own outbox namespace and verify response.environment before acknowledging; a Sandbox acknowledgment must never clear a Live outbox event. The public preview requires an explicit synthetic seller_vat_registered boolean; no registration is inferred from header tax type.

10. VERIFY YOUR INTEGRATION
Test disconnected source queuing, lost-response retry, matching duplicates, changed-payload conflicts, branch/tenant denial, expired/revoked keys, IP policy, insufficient funds, sequence ordering and atomic batch rollback in an authorized isolated staging environment. Confirm every accepted source in Invoices & Receipts and reconcile original amounts, branch and source IDs. Real source integration and authorized capacity testing remain the integrator's deployment responsibility.

SYNTHETIC EXAMPLE — replace source values ONCE in your real connector; never send this sample as a live sale:
{
  "schema_version": "NUERS-ingestion-1",
  "record_mode": "source_capture",
  "source_profile_id": "00000000-0000-4000-8000-000000000001",
  "tax_basis_reference": "SAMPLE applicable exemption or zero-rating basis; replace with reviewed evidence",
  "source_system": "sample-erp",
  "source_event_id": "SAMPLE-invoice-0001",
  "external_reference": "SAMPLE-INV-0001",
  "event_type": "invoice.finalized",
  "occurred_at": "2026-10-18T05:00:21.621Z",
  "branch_code": "00000",
  "receipt_number": "INV-00000001",
  "document_type": "vat_invoice",
  "payment_method": "cash",
  "gross_amount": "112.00",
  "tax_type": "vatable",
  "vat_inclusive": true,
  "customer_name": "SAMPLE Customer",
  "items": [
    {
      "description": "SAMPLE item",
      "quantity": 1,
      "unit_price": "112.00",
      "tax_type": "vatable"
    }
  ]
}


## Verified-account automatic setup (18 October 2026)

Account approval prepares an `account_connection` profile atomically with the review. It snapshots the registered seller name, TIN, address and VAT registration, derives the exact registered location, and assigns a stable `owned-…` system identifier. It grants storage of owned-system copies only. The profile is visible in both workspaces; Live and Sandbox credentials and transaction stores remain isolated. The seller remains responsible for lawful original issuance.

`POST /api/merchant/api-keys` still accepts just a name and environment. It now also returns `connection` with schema_version, record_mode, source_profile_id, source_system and branch_code. The one-time `key.plain_key` is the secret credential; API key IDs and source profile IDs are non-secret identifiers. The server stores only the key hash. No secret is generated or emailed automatically at approval time. The verified owner generates and receives it through the authenticated portal.

An automatic profile starts at its actual creation time. It has no credential-style expiry, but every new Live capture rechecks active account/location and the exact seller snapshot. Changed identity requires account review; revoked profiles cannot be recreated by key generation or the idempotent backfill command. Earlier originals remain eligible only through the separate reviewed historical-profile flow. Previously accepted identical retries preserve their original records and never charge again.

Deployment command: `php artisan integrations:prepare-verified-connections`. This prepares connection metadata for already-active linked accounts, skips incomplete/ambiguous identities and preserves explicitly revoked profiles. It never generates a credential or financial transaction.


11. MERCHANT WEBHOOK DELIVERY
Owner human session endpoints (not API-key endpoints): GET/POST /api/merchant/webhooks; PATCH/DELETE /api/merchant/webhooks/{endpoint}; POST /{endpoint}/test (kind verify or test), POST /{endpoint}/rotate; GET /api/merchant/webhooks/deliveries and /deliveries/{id}; POST /deliveries/{id}/retry. Lists/detail/retry require environment=live|sandbox. Mutations require active Merchant Admin + login MFA; revision protects concurrent changes. Save requires request_id UUID, name, HTTPS URL, environment, events and ownership_confirmed=true. Saving is idempotent by request_id; URL/environment are immutable. Retain request_id until save is acknowledged. Five non-revoked endpoints per environment; event options: transaction.accepted, source.received, invoice.issued, payment.recorded (API-origin events).

Only newly configured, verified, explicitly enabled merchant endpoints send metadata. Verification uses webhook.endpoint_challenge and requires signature validation followed by a JSON nuers_challenge echo of data.challenge. It expires after ten minutes and is not retried automatically. A successful challenge leaves delivery paused. Enable, then send a synthetic webhook.test event; it creates no sale or wallet debit. No endpoint is automatically created because NUERS cannot guess an ERP callback URL. No admin approval is required for owned-system source connection generation; webhook ownership verification is performed by the receiver itself.

POST callback headers: Content-Type application/json, X-NUERS-Event (event UUID), X-NUERS-Timestamp (Unix seconds), X-NUERS-Signature (v1=hex HMAC), X-NUERS-Environment, X-NUERS-Key-Version. Signature: HMAC-SHA256(secret, timestamp + '.' + event_id + '.' + EXACT raw body bytes). Reject timestamps outside +/-300 seconds, compare signatures in constant time, then validate body id/environment/merchant_id against your local configuration. Reject duplicate security headers. Signatures do not cover separate environment/version headers: compare them with the signed body and local secret configuration. Store and deduplicate (environment,event_id) with a unique database constraint before returning 2xx. Conflicting content under an existing ID must fail. Receiver source core: docs/examples/nuers-webhook-receiver.php; adapt its SQLite durable inbox to your own ERP database/worker. Do not re-book sales from transaction.accepted plus source.received; they describe the same accepted resource. Business processing must also be idempotent by subject_id and semantic action. Never trust delivery order.

Immutable envelope: id, type, environment, merchant_id, subject_id, occurred_at, data. Data contains accepted NUERS IDs, record mode and status only. It excludes financial line items, buyer data and credentials. Poll the authenticated status endpoint using data.id, with the same environment/business/location key. Do not concatenate arbitrary callback strings into outbound URLs; construct a same-origin URL from a validated UUID. A callback is a synchronization hint and cannot clear an unrelated source outbox item or establish BIR acceptance.

Worker uses encrypted storage, three-minute leases, rechecked public DNS pinned to TLS, HTTPS/443 only, certificate validation, no proxy fallback or redirects, eight-second HTTP timeout and 16 KiB response cap. Global budget 600 sends/minute; endpoint 60/minute plus daily limits. Transient errors retry with exponential backoff/jitter, up to 12 attempts within 24 hours. HTTP redirects and 400/401/403/404/405/410/413/422 end automatic retries. Other failures retry subject to budget. Responses and arbitrary errors are not retained. At most three manual retry cycles per event; same event ID/body, current endpoint signing version, receiver must deduplicate. Subscription change, pause, account suspension, secret rotation or revocation cancels pending incompatible deliveries. Review logs and manually retry eligible events after restoration. Rotation pauses and requires receiver verification again. Already in-flight requests can arrive after pause/revocation. Old immutable source profiles and accepted records remain preserved.

Operations: merchant-webhooks:work --limit=100 --max-seconds=45 runs every minute under the domain user. Monitor the dispatcher heartbeat (<3 minutes), dead letters, queued age and sender/receiver clocks. Stop all new merchant dispatch with NUERS_MERCHANT_WEBHOOK_DELIVERY_ENABLED=false and refresh config. This flag does not enable the legacy webhook queue or BIR transport. Configure an independent egress firewall appropriate to the host; application DNS/address checks do not replace network segmentation. Final ERP HTTPS acceptance testing remains pending the merchant-controlled receiver URL.

12. AUTOMATIC SOURCE REPLACEMENT
Account verification is the prerequisite. There is no separate admin source approval when the verified owner generates an API key. From an unchanged verified seller snapshot, key creation reuses the active default connection. If that automatic profile was revoked, an explicit new-key request creates a new active profile ID while retaining the revoked row and its decisions. Existing keys/profile references are never silently reactivated; revoked API keys stay blocked. Background backfill and page views do not create replacements. Update your connector mapping once, and retain old accepted outbox JSON unchanged for reconciliation. Pending/suspended/inactive accounts, inactive locations, changed registered identity, historical originals and issuance/reporting authority keep their existing gates.

## Statutory sale categories (18 October 2026)

The API, Create Invoice, document views and filtered exports share these explicit categories: `regular`, `senior_citizen`, `pwd`, `national_athlete_coach`, `solo_parent`. Seller VAT registration remains the verified business/source snapshot, not an API-key toggle.

For a qualifying sale, send `sales_benefit` containing `category`, `scope`, `beneficiary_name`, `id_number`, `evidence_reference`, and `eligibility_confirmed: true`. Mark each qualifying line `benefit_eligible: true`; split shared or partly eligible quantities from ordinary purchases. Send original prices and original tax types before relief. Use `sales_invoice` for automatic document classification and supply the final discounted `gross_amount`, including any remaining VAT. The source original and complete retry payload must remain unchanged.

| Category | Supported calculation | Eligibility boundary |
|---|---|---|
| Senior citizen / PWD | 20% off eligible VAT-exclusive price; applicable VAT exemption | Covered goods/services for the beneficiary's exclusive use, with verified evidence |
| National athlete / coach | 20% off eligible VAT-exclusive price; ordinary VAT remains on discounted base | Qualifying national athlete/coach with valid PSC evidence and covered purchase |
| Solo parent | 10% off eligible VAT-exclusive price; applicable VAT exemption | Specified child necessities; child six or younger, annual income below PHP250,000, valid SPIC and booklet |

Solo-parent payloads additionally require `child_birth_date`, `id_valid_until`, `booklet_reference`, and `income_below_250000: true`. Dates are evaluated at the original transaction date in Philippine time. The server rejects an expired supplied ID, a child who has reached seven, missing eligibility evidence, eligible lines without a benefit, and unsupported scopes. Eligible lines cannot also claim a promotional `discount_pct` or a zero-rated export classification. Non-VAT sellers never remove VAT they did not charge.

Covered scope enums:

- Senior/PWD: `restaurant`, `hotel`, `recreation`, `admission`, `medicine`, `medical_dental`, `domestic_transport`, `funeral`.
- National athlete/coach: `restaurant`, `hotel`, `recreation`, `admission`, `medicine`, `sports_equipment`, `domestic_transport`.
- Solo parent: `baby_milk`, `baby_food`, `micronutrient_supplements`, `diapers`, `medicine`, `vaccines`, `medical_supplements`.

These are bounded supported benefit programs. The separate 5% basic necessities/prime commodities and utility programs are not included. A scope selection is not government proof of entitlement. The seller must verify actual coverage, original ID/prescription/booklet evidence, exclusive share and required signatures, and retain the supporting record privately.

For one eligible PHP1,120 VAT-inclusive item: senior/PWD total PHP800 (PHP200 discount, PHP120 VAT adjustment); national athlete/coach total PHP896 (PHP200 discount, PHP96 remaining VAT, PHP24 VAT adjustment); solo parent total PHP900 (PHP100 discount, PHP120 VAT adjustment). A non-VAT PHP1,120 item totals PHP896 for a 20% benefit, or PHP1,008 for solo parent, with no VAT adjustment. Inclusive input is reconciled with `subtotal - discount - VAT adjustment`; exclusive input instead adds the remaining computed VAT.

Responses and records expose `sale_category`, `statutory_discount_amount`, and `vat_relief_amount` in PHP. The last field is displayed as **VAT Adjustment**, including the reduction of VAT following the athlete/coach discount; it is zero for exclusive input prices. These amounts are not an additional discount. The configured wallet fee threshold uses final primary-sale gross including remaining VAT and before withholding. Matching retries are not charged again; supplementary payment copies do not create another primary-sale charge.

Apply **Sale Category** in merchant reports, merchant Invoices & Receipts, admin Transactions, or Electronic Invoices & Receipts, then export. CSV and report XLSX carry classification and benefit amounts; report PDF includes the same values. Bulk reports omit beneficiary ID/child details. Authorized original-document views and invoice PDFs display the beneficiary and evidence reference. Sandbox metadata stays separate; its detailed private payload remains encrypted. Public synthetic previews require TEST-/DEMO-/SAMPLE- beneficiary details.

API Integration now offers a sale-category example selector. The detailed PDF developer manual includes complete requests, calculations, evidence requirements, filters, export semantics and primary references.

Sources: [PWD RR 5-2017 via NCDA](https://ncda.gov.ph/disability-laws/implementing-rules-and-regulations-irr/revenue-regulations-no-5-2017-rules-and-regulations-implementing-republic-act-no-10754/), [Senior RR 7-2010](https://elibrary.judiciary.gov.ph/thebookshelf/showdocs/10/55830), [NAAC RR 13-2020 discussion by Senate STSRO](https://legacy.senate.gov.ph/publications/STSRO/Taxbits%20June%20-%20July%202020%20%2818pages%29%200723%20Final%20with%20DG%20edits.pdf), [Solo parent RR 1-2023](https://bir-cdn.bir.gov.ph/local/pdf/RR%20No.%201-2023.pdf), [DSWD explanation](https://www.dswd.gov.ph/solo-parents-entitled-to-discounts-vat-exemption-other-perks-dswd-exec/), [invoice particulars RR 7-2024](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%207-%202024.pdf).

## Manual discounts (PHP amount or percentage)

Use `manual_discount` at the document level **or** on individual `items[]`. Send original unit prices before discount. The new object requires `type` (`amount` or `percentage`), `value` (non-negative, at most two decimals), and a non-blank `reason` (1–500 characters). A percentage must not exceed 100; an amount must not exceed the original line/document price. Fixed line amounts apply to the whole quantity extension, not each unit.

```json
"manual_discount": {
  "type": "amount",
  "value": "112.00",
  "reason": "Loyalty discount"
}
```

For one VAT-inclusive item with quantity 1 and original `unit_price: "1120.00"`, send `gross_amount: "1008.00"`. The result is manual discount PHP112.00, VATable base PHP900.00, VAT PHP108.00, final gross PHP1008.00. `type: "percentage", value: "10.00"` gives the same result. Put the same object inside one item to discount only that line. Existing `items[].discount_pct` remains supported.

Amounts follow the declared `vat_inclusive` price basis: inclusive discounts include VAT; exclusive discounts reduce the pre-VAT base. A document discount is allocated proportionally across original line extensions in integer centavos; remainder centavos go to the largest fractional shares, with original line order breaking ties. VAT is recalculated on each discounted line using its own tax classification. For a precise ERP-specific allocation, send per-line amount discounts instead. Ordinary discounts do not change VAT registration or make a product VAT-exempt.

Use one method per line. Do not combine a document discount with line discounts or `sales_benefit`; do not combine a line manual discount with a non-zero `discount_pct` or a statutory-eligible line. A basket may contain statutory-eligible items and separate non-eligible items with manual discounts. No discount is applied twice. This field records discounts already reflected in the original invoice at sale time; later conditional rebates require the appropriate correction workflow.

The response includes `calculation.manual_discount_amount`, `discount_amount` (manual plus statutory), `statutory_discount_amount`, and final tax totals, all in PHP. Stored lines preserve `discount_reason` and either `discount_pct` or `fixed_discount_amount` (PHP); the latter is a calculated response field, not a request input. Manual discounts appear in merchant records/reports, admin transactions/documents, PDF copies and filtered exports. Report totals already reflect the discount: do not subtract it again. Sandbox records remain isolated and never debit the Live wallet.

Supply the final discounted `gross_amount`, including VAT and before withholding. Mismatched totals, unsupported fields and invalid/stacked discounts return 422. The configured fee threshold uses that final gross. Once accepted, retries must keep the same discount and reason as well as all other original fields; changed content returns 409 and an identical retry has no second wallet charge. An already accepted source with omitted discount details is not silently rewritten; use the reviewed correction process.


## NUERS status and automatic customer receipts (19 October 2026)

Every ERP, POS or e-commerce sales row must show NUERS Status, failure reason, environment, NUERS reference and last attempt. Show Success only after a valid 201 or 200 duplicate acknowledgement with matching source_event_id, external_reference and environment, non-empty id/transaction_id/receipt_id, status=durably_recorded and nuers_status=success. Persist these identifiers atomically with the local outbox acknowledgement.

Show Error plus error.reason and applicable errors field messages for a rejected request. Keep the original sale locally. 402 requires wallet funding; 401/403 require credential or permission correction; 409 requires reconciliation; 422 requires mapping correction. For timeouts, invalid JSON, malformed 2xx or error.outcome=unknown, show Pending confirmation and retry the identical original event. Honor Retry-After and bounded backoff for 429/5xx. Never render server messages as HTML or expose credentials to the customer.

Optional customer_email: one valid checkout email address, maximum 254 characters. Omit or send null when absent. Map the checkout email explicitly; NUERS does not scan arbitrary payload fields. Invalid addresses return 422 before acceptance. Never use a customer address as an API credential.

A newly accepted Live event containing customer_email queues one customer PDF email in the same database transaction. Exact retries return the same record and do not queue another email or fee. Failed/rolled-back events queue no email. Sandbox always returns suppressed_sandbox and never emails customers. Existing transactions are not emailed retroactively.

Email attachments use the same receipt renderer and E-Receipt Logo saved under Merchant Invoice Settings → Logo and Branding. This logo is independent from the Business Profile Picture; both uploads save immediately without administrator approval. Original seller identity, lines, taxes, discounts and totals remain the recorded snapshot. Save branding before the email worker renders the PDF; changes affect subsequent renders and cannot recall an already sent attachment.

Poll GET status_url with the same environment and transactions:read scope for customer_receipt.status and reason. States: not_requested, suppressed_sandbox, queued, preparing, sending, smtp_accepted, failed, uncertain, expired, cancelled. smtp_accepted means the SMTP server accepted the message, not confirmed inbox delivery. Email failures never change a successfully recorded sale to Error. Do not retry the sale to resend mail; use the merchant document Send Copy action after investigating an uncertain delivery.

Merchant transaction, batch and wallet responses do not expose bir_acceptance, bir_connection, bir_status or internal preparation blockers. Display NUERS status in the connector. NUERS Success confirms platform recording; it does not assert government acceptance.

```json
{
  "environment": "live",
  "duplicate": false,
  "nuers_status": "success",
  "status": "durably_recorded",
  "message": "Transaction successfully recorded by NUERS.",
  "error": null,
  "id": "EXAMPLE-INBOX-ID",
  "transaction_id": "EXAMPLE-TRANSACTION-ID",
  "receipt_id": "EXAMPLE-RECEIPT-ID",
  "source_event_id": "erpA:invoice:0001",
  "external_reference": "erpA:INV-0001",
  "status_url": "https://nuers.net/api/integrations/transactions/EXAMPLE-INBOX-ID",
  "customer_receipt": {
    "delivery_id": "EXAMPLE-DELIVERY-ID",
    "status": "queued",
    "reason": "Customer receipt is queued for email processing."
  }
}
```

```json
{
  "nuers_status": "error",
  "code": "VALIDATION_FAILED",
  "message": "The customer email field must be a valid email address.",
  "error": {
    "code": "VALIDATION_FAILED",
    "reason": "The customer email field must be a valid email address.",
    "retryable": false,
    "outcome": "rejected"
  },
  "errors": {
    "customer_email": [
      "The customer email field must be a valid email address."
    ]
  }
}
```

Client adapter: `/guides/NUERS-Transaction-Status.mjs`. Shared documentation contract: `resources/data/merchant-api-receipts.json`.
