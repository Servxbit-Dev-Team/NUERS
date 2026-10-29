# NUERS merchant ingestion version 1

The maintained end-to-end guide is [NUERS API Connection Guide](../../output/pdf/NUERS-API-Connection-Guide.pdf), served at `/guides/NUERS-API-Connection-Guide.pdf`. The machine contract is `resources/data/developer-openapi.json`; the page's examples and readable guide come from `src/lib/integrations/merchant-guide.ts`.

## Modes and compatibility

`schema_version: NUERS-ingestion-1` supports an additive `record_mode` field:

- `source_capture`: copy an already-issued ERP/POS original. Supply `source_profile_id` from the automatically generated owned-system connection for a verified merchant. Historical originals outside its scope require an independently reviewed profile for the merchant, source system, registered location, seller snapshot, effective VAT registration and original issue period. Preserve original number/date. No NUERS series allocation, PTI claim or second issuance. Copies are blocked from BIR reporting until source coverage and duplicate-reporting reconciliation are separately reviewed.
- `nuers_issuance`: create a new NUERS original. Requires current verified solution/version/configuration accreditation, taxpayer/branch/environment-specific maintained issuance authority, written client authorization and the next authorized series number.
- Omitted mode preserves legacy NUERS issuance semantics. Accepted historical request hashes and IDs are not rewritten. Identical accepted events replay before tax/profile re-evaluation; a revoked/expired key or inactive business still cannot submit through a write endpoint.

Source profiles are administered in Admin → API Integrations → Merchant Source Profiles. Reviews require an activated business and active registered branch. The profile stores original issuer name/TIN/address, effective registration, original system authority and independent review references. Date intervals cannot overlap for the same system/branch. Profiles are immutable, with audited revocation; wrong evidence requires revocation and replacement. Historical profile reviews remain independent. Verified-owner API key creation prepares the default owned-system profile automatically without another admin approval; it does not approve historical or government issuance authority.

## Security boundaries

The production endpoints require a live API key, derived tenant ownership, exact five-digit branch, current expiry/status, required scope and exact-IP policy. Sandbox keys use the same URLs but only a separate encrypted test store; responses identify sandbox/test_only. Live financial records, wallet, numbering, reports and BIR queues are never changed. Foreign environment status IDs return 404. The Sandbox wallet is virtual with no real balance. Public previews are synthetic and require `seller_vat_registered` explicitly; this never changes the merchant registration. API money uses PHP pesos; portal form amounts and wallet balances have their separately documented centavo units.

New keys require the registered owner's MFA verified for the current login session and an active merchant. The merchant form requires only a name. The server assigns the registered establishment from the business TIN (or an existing unambiguous active main record for a base-only TIN), materializes its internal location context if missing, and preserves existing inactive/reviewed records. No merchant Branches / Terminals page or physical terminal setup is required. Legacy explicit branch requests remain limited to existing active owned branches. Name-only keys default to live, both supported scopes, 50,000 requests/day and 90 days. Missing/ambiguous business identity requires registration correction; no BIR code is guessed. Supported scopes are `transactions:write` and `transactions:read`. Existing credentials are not automatically broadened, replaced or exposed. Key creation/update/revocation serialize on the business row and commit together with the mandatory audit entry. Acceptance locks the same business and rechecks current key status, expiry, environment, branch, IP-policy snapshot and write scope before proceeding. Conflicting header/Bearer credentials and non-JSON financial writes are rejected.

Only `X-Forwarded-For` client-IP forwarding from the explicitly listed Cloudflare networks in `config/trusted_proxies.php` is trusted. Forwarded host/protocol are not trusted. Infrastructure owners must maintain edge ranges and origin restrictions. No firewall or proxy trust expansion is part of this release.

## Tax and financial integrity

VAT sellers may submit `vatable`, `vat_exempt` and `zero_rated` lines. Non-VAT sellers may submit `non_vat` and `vat_exempt`; they cannot charge ordinary VAT or claim VAT zero-rating. VAT sellers cannot submit `non_vat` lines. An exempt Non-VAT-only sale resolves to `non_vat_invoice`; multiple treatments resolve to `mixed_sales_invoice`. Original-source profiles provide historical registration; current account registration governs new issuance. Never select tax treatment from product name or buyer VAT status alone.

Integer-centavo arithmetic reconciles ordinary 12% VAT, inclusive/exclusive prices, discounts and line totals. New API records preserve separate `sspt_sales` and `vat_exempt_sales`. SSPT is a sales classification, not a computation of percentage-tax liability. The shared portal's historical aggregate representation remains supported when reading old records; reports avoid subtracting new SSPT twice.

Buyer name, TIN and `customer_address`, document/item `tax_basis_reference`, and applicable `withholding_tax` plus `withholding_basis_reference` are retained. Withholding reduces `net_payable`, not the document gross, output VAT, taxable base or wallet threshold. Gross is preserved in the receipt's legacy `total_due` field; the presentation derives net payable separately. Extra charges must be classified lines. Unknown fields, excessive precision, unsupported tax rates and contradictory quantity/price/amount aliases return 422.

## Durable acceptance and wallet

Identity includes merchant, branch, source system, family and external reference; source-event identity is independently unique. Requests are canonicalized only across supported fields. Changed content under an accepted identity returns 409. Seller/branch/family/document-number identity is checked against both portal invoices and API receipts. Never create another source ID or change an original number/date to escape a conflict.

Single acceptance serializes on the tenant. Wallet fee, financial records, receipt snapshot, source inbox, encrypted readiness model, mandatory audit and configured signed callback intent commit together. Capture uses the same wallet fee policy as a new eligible primary sale. Accepted retries and supplementary payment copies are free of another primary sale fee. `402 INSUFFICIENT_WALLET_BALANCE` commits none of these new records or sequence changes. Atomic batches contain 1–50 complete events for one branch, with aggregate funding admission and all-or-nothing writes.

201 identifies a newly durable NUERS record; 200 `duplicate=true` returns its original identifiers. Neither means BIR acceptance. GET status requires read scope and the same live business/branch. Preserve original source JSON in a local transactional outbox; check matching source event/reference, nonempty IDs and `durably_recorded` before local acknowledgment. Unknown outcomes retry unchanged with bounded backoff. Funding failures remain local until replenishment. Browser manual retry is encrypted device storage, not a substitute for source backups.

BIR production transport remains disabled; merchant callbacks use owner-configured, verified and enabled HTTPS receivers with signed delivery and bounded retries. See the maintained webhook guide for the receiver contract. Readiness model version 4 preserves buyer address, CWT, SSPT, source mode and profile identity. No official BIR wire schema, transport verification or certification is claimed.


## Live and Sandbox isolation (2026-10-18)

Select a name and environment when creating a key. Both environments require an active business, valid unexpired key, exact registered location, scope, quotas and IP rules. Environment is immutable and derived exclusively from the credential. Do not add an environment field to transaction JSON; unknown fields are rejected.

Sandbox source_capture requires a UUID-shaped source_profile_id for contract mapping, but does not authorize or validate the real profile: Sandbox is not approval evidence. Tax calculation uses the merchant's current VAT classification. nuers_issuance omits source_profile_id. Identical retries return the saved test response; changed identity content and reused test document numbers return 409. Batch test writes commit atomically. Sandbox never touches production receipts, transactions, wallet entries, invoice series, BIR outbox or sales reports. Sandbox JSON detail downloads are test artifacts, not invoices.

Live and Sandbox status IDs cannot be retrieved using the other environment's key. Environment namespaces must also be maintained in external connector outboxes. Rotate keys only within the same environment/location when retrying an accepted event. Manual Retry separates encrypted Live and Sandbox queues and verifies the key environment before transmission.

Admin Wallets & Transaction Pricing controls minimum_transaction_centavos and fee_centavos under optimistic revision checks, privileged MFA and mandatory change reason/audit. The current default is PHP0 minimum and PHP3 fee. A minimum_transaction_centavos of 0 charges every new finalized Live primary sale, including amounts below PHP500. A positive minimum exempts amounts strictly below it. Super Admin can change either setting prospectively. Rules apply prospectively to newly accepted primary sales; gross amount includes VAT and precedes withholding. Payment copies and exact accepted replays do not add a primary-sale fee. Insufficient funds returns 402 and transaction_saved=false; the transaction, receipt, inbox, wallet debit and series change all roll back. Batch funding covers every new eligible sale or none commits. Sandbox returns simulated_fee_centavos only.

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


## Invoice identity and printed contents — 29 October 2026

`receipt_number` is the seller’s original document serial, 1–50 characters, preserving its prefix and leading zeros. Control characters and surrounding whitespace are rejected. It maps to EIS `CompInvoiceId`; it is not the separately generated 24-character `EisUniqueId`. Existing issued identities are never renumbered. NUERS issuance reserves an evidence-backed series under transaction/merchant/series locks, rejects reused numbers (including voided records), and rolls back the counter when issuance rolls back. Draft references are not legal invoice numbers.

Supply `customer_type` (`business`, `retail`, `walk_in`). Business buyers require registered name, address and TIN; B2C address/TIN remain optional. Send `invoice_reference` for the original invoice behind a payment or additional invoice. Optional `items[].unit` prints the recorded unit of measure.

`document_registration` records source evidence with `system_type` (`CAS`, `POS`, `ELECTRONIC`) and the applicable `pti_number`, `ptu_number`, `ac_number`, `authority_issued_at`, `series_start`, `series_end`, `machine_identification_number`, `machine_serial_number`, `software_license_number`, `non_vat_basis` (`percentage_tax` or `exempt`). Date uses YYYY-MM-DD. Native issuance requires authentic series registration details; source copies can retain missing evidence without inventing it. A submitted value is not a verified authority. This snapshot cannot be changed by replaying an accepted event.

The shared preview, customer-email PDF and downloaded copy display recorded seller name/address, VAT or Non-VAT Reg TIN with five-digit branch, prominent invoice number, transaction date, buyer details, quantity/unit/price/description, totals and separate tax classes. Applicable CWT details, statutory-benefit evidence, original-invoice reference and registration details remain visible. Copies use REPRINT. Supplementary documents display the input-tax warning and do not create another primary sale. Missing historical details are labelled not recorded; the original financial data stays immutable. Existing receipt branding/layout is retained.

References: [RR 7-2024](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%207-%202024.pdf), [RMC 98-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2098-2026%20redacted.pdf), [RMC 112-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%20112-2026_redacted.pdf), [BIR EIS certification API guide](https://eis-cert.bir.gov.ph/#/apiGuide). Actual authority and BIR acceptance require external evidence.
