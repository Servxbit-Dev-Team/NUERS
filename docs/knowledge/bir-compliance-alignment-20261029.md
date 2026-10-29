# BIR technical alignment — 29 October 2026

Release: `2026-10-29.compliance-operations.1`. Software identity remains NUERS 1.0.0.

## Implemented controls

| Area | Implementation | Verification / operating boundary |
|---|---|---|
| Shared PTI | One taxpayer may record the same PTI for additional branches of the same approved system, with branch coverage/notification evidence. Authorization lookup selects the precise taxpayer, branch and environment. | Different taxpayers/systems, duplicate branch entries and unsupported PTT sharing are rejected. A stored permit is administrative evidence, not BIR verification. |
| Invoice corrections | A decrease uses a credit memo; an increase uses a new invoice referencing the original. The native form rejects new debit memos. Original invoice number is carried onto the correction. | Originals are not rewritten. Existing historical documents remain readable. Official issuance retains its authority and sequence checks. |
| Buyer withholding | Structured buyer/payor classification, ATC, rate, tax base, amount and supporting reference are validated separately from gross sales and VAT. | Existing legacy intake remains compatible. Supplying structured evidence requires complete, mathematically consistent fields. |
| Later CWT adjustments | Tenant-bound CWT credit-memo workflow snapshots the original, records buyer confirmation, rejects stale drafts and reserves a registered credit-memo number at issuance. | Sales and VAT adjustments remain zero. The original and wallet are unchanged. Later memos use the last issued CWT value. |
| Outage recovery | Record outage/restoration evidence, authorized manual invoices and the three-calendar-day recovery deadline. Link one matching replacement after restoration. | Buyer, branch, gross amount, original manual number and tenant must match. API linking is atomic with acceptance and wallet charging. Sandbox cannot consume Live recovery records. |
| Service fee invoicing | Super Admin prepares statements from unbilled posted wallet fees; unique claims prevent duplicate billing. A configured separate registered NUERS issuer can issue an invoice through existing authority and numbering controls. | A statement is not a tax invoice. Issuance is blocked until the issuer and its evidence are configured. No second wallet debit. The recipient can download only its scoped billing document. |
| ESP intake policy | `NUERS_SOURCE_CAPTURE_ACCREDITATION_ENFORCED` provides a fail-closed gate for new source-capture intake. Accepted retries remain idempotent. | **Disabled by the operator's explicit decision to preserve current intake.** This is continuity configuration, not a declaration of regulatory authorization. |
| Tenant access and evidence | Owner access derives the business from the authenticated account; admin roles can select businesses. Staff without this capability are denied. Payloads are encrypted, hashed and audited; updates require current revisions. | Cross-tenant record, original-document, action and export access are denied. Export counts are bounded with explicit errors, never silent truncation. |
| Integration and documentation | Existing credential-ready BIR wire adapter retained. Website contract, OpenAPI and merchant guide edition 1.4 document the additions. | No BIR credentials, outbound calls, callbacks or production acknowledgments are invented or enabled. |

## User navigation

- Super Admin → Compliance Operations: all permitted businesses, outage/manual recovery, CWT memos, service-fee statements and PDF/CSV exports.
- Merchant → Compliance Operations: own records and documents only; merchant users cannot issue platform fee invoices.
- PTI / PTT Management: approved system reference and branch coverage evidence.
- Merchant → Create Invoice: original invoice reference for corrections, buyer CWT evidence and optional manual recovery linkage.
- ESP Accreditation: implemented transport and pending external prerequisites are displayed separately.

## External prerequisites still outstanding

Actual BIR accreditation/approval, taxpayer authorities and registered series must be supported by authentic evidence. BIR-issued credentials, supervised certification and production transmission are separate unfinished operational steps. Independent VAPT and any required ISO/SOC evidence, confirmed hosting/data residency, provisioned independent backup storage and production disaster-recovery evidence cannot be satisfied by local software changes or synthetic tests. NUERS service billing additionally requires the actual registered billing entity, authority and invoice series to be configured.

Do not describe this release as BIR certified or 100% regulatory compliant. A software regression result establishes tested behavior, not regulatory approval.

## References reviewed

- [RMC 98-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%2098-2026%20redacted.pdf): e-invoicing operational clarifications, corrections and recovery.
- [RMC 110-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%20110-2026_redacted.pdf) and [Annex A](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%20110-2026%20ANNEX%20A.pdf): ESP technical and accreditation requirements.
- [RMC 112-2026](https://bir-cdn.bir.gov.ph/BIR/pdf/RMC%20No.%20112-2026_redacted.pdf): electronic-invoice withholding treatment.
- [BIR EIS certification API guide](https://eis-cert.bir.gov.ph/#/apiGuide): integration protocol reference; not a public PTI filing API.

Deployment evidence and exact regression results are retained under `output/compliance-alignment-20261029/`. The deployment uses a private backup, source checksums, targeted migration and before/after preservation hashes. Existing data and environment configuration are preserved.
