# Transactions page: BIR EIS alignment audit

16 October 2026, 21:20 PHT. Localhost implementation and local database only.

**Partially aligned as an operational transaction ledger. It is not a verified BIR EIS submission system or a complete issued-invoice/SLS ledger.** The separate transaction and BIR status columns are appropriate. Production transmission remains deliberately blocked, and several record identity, coverage, deadline and calculation gaps prevent acceptance.

## Verified current state

A guarded, read-only MySQL transaction returned these aggregates. No production financial or identity rows were changed.

| Check | Result |
|---|---|
| Transaction rows | 13 |
| Successful transaction rows | 13 |
| Successful transaction amount | PHP 750,400.00 |
| BIR status for those rows | All 13 Not Submitted |
| EIS transmission records, across all environments/documents | 1; this does not establish submission of these 13 transactions |
| Implemented production transport | False |
| Recorded solution accreditation | 0 records |
| Verified Annex A / accreditation stages | 0 / 0 |
| Orphan transaction receipt links | 0 |
| Current registry versus receipt seller identity mismatches | 0 |
| Duplicate receipt identity groups | 0 |
| Non-draft business invoices without a merchant + transaction-reference match | 3 |

The last three integrity failure scenarios below were reproduced with dummy fixtures; a reproduction does not assert that the current 13 rows already contain those defects.

## Findings

### T01 - Production BIR delivery and schema acceptance are unimplemented

**Production blocker.** `config/bir_compliance.php:9` fixes `transport_implemented` to false. `BirCompliance::gate()` blocks production transmission. `EisController::acknowledge()` only permits explicitly synthetic certification acknowledgements; its generated CERT references are not BIR acceptance.

The local payload validator checks a few generic fields and provides warnings for absent signature/encryption hashes. It is not evidence of conformance to the authorized, versioned EIS API contract. A SHA-256 digest alone is not a verified signing/encryption exchange. No successful BIR production delivery, authentic response verification or official inquiry was established in this audit.

**Required:** Implement against the authorized BIR interface and certification package, including field/code mapping, credentials and key custody, signing/encryption as prescribed, delivery/inquiry, response authentication, replay/duplicate controls and end-to-end evidence. Record current taxpayer authorities and solution accreditation before activating the production gate. Do not change the flag merely to make the UI appear connected.

**Evidence:** [configuration](/Users/marksioson/Desktop/NUERS/config/bir_compliance.php:9), [production gate](/Users/marksioson/Desktop/NUERS/app/Services/BirCompliance.php:126), [certification acknowledgements](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:408), [local validator](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:554).

### T02 - Issued documents outside merchant_transactions are absent

**High for complete invoice/EIS reporting.** The workspace starts exclusively from `merchant_transactions`. It never reads `business_invoices`, even though Invoice Center supports that issuance source. A dummy issued business invoice appeared in Invoice Center (1 document) while Transactions returned 0. The local aggregate also found three non-draft business invoices without a matching transaction reference for the same merchant.

The current page is consequently useful for transaction operations but cannot be treated as the full invoice population or the sole submission source. Payment events and issued documents need an explicit, traceable relationship.

**Required:** Define one canonical issued-document population across both invoice paths, retain each source identity, distinguish sale/issuance from payment events, and reconcile completeness before submission or SLS generation.

**Acceptance:** An authorized invoice created through either path appears exactly once in the invoice reporting population and can be traced to its payment events and submission history.

**Evidence:** [ledger source](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:26); isolated probe T02.

### T03 - Historical seller identity follows the mutable business registry

**High.** The query prefers the current merchant business name and TIN over the receipt's original seller snapshot. In an isolated fixture, changing the merchant record changed the historical transaction's returned business name and TIN while the original receipt snapshot stayed intact. Display and export can therefore disagree with the issued record and its submission payload.

**Required:** Use the immutable issued-document seller snapshot as historical identity. Show current registry details separately when needed, together with the applicable revision. Preserve original TIN, branch code, legal name and issuance authority evidence.

**Acceptance:** An authorized registry update cannot change the historical invoice's displayed/exported seller identity or its canonical submission hash.

**Evidence:** [seller identity selection](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:37); isolated probe T03. No current identity mismatch was found in the local aggregate.

### T04 - Deadline fallback uses record creation time and lacks applicability context

**High for deadline monitoring.** When no transmission `due_at` exists, the page computes its target from `merchant_transactions.created_at`. It does not select the linked document's issue timestamp. A dummy document issued 1 October and imported/created as a transaction on 15 October returned an 18 October target; the supplied circular's transaction-date rule would instead yield 4 October for that example, assuming that issuance date is the authoritative transaction date.

The `overdue` calculation also does not consult taxpayer coverage, a documented transmission start obligation, or applicable outage/advisory treatment. An operational target must not be presented as a legal penalty determination.

**Required:** Preserve the authoritative transaction/issue date independently from import/create time. Derive deadlines from the applicable taxpayer obligation and retain original deadline, restoration target, outage evidence and applicable advisory separately. Expose those fields in operational review without erasing the original date.

**Acceptance:** Imported/backdated records keep their correct business date and deadline; test, exempt/not-yet-covered and approved-outage cases have explicit applicability/evidence rather than an undifferentiated overdue label.

**Evidence:** [date fields and fallback](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:110); isolated probe T04.

### T05 - Latest certification attempt can hide production submission evidence

**Medium.** The latest-transmission join orders all environments together. A fixture with a production submission initially displayed Processing. Adding a newer certification record changed the page to Test and removed the row from the Processing filter, even though the production submission still existed.

This is a read-model gap for mixed or migrated histories. The public certification submission route requires synthetic data; this probe inserted dummy history directly and does not show that route accepting real taxpayer transactions.

**Required:** Derive production submission state separately from certification activity. Preserve an attempt timeline per environment, and distinguish latest attempt from authoritative acceptance.

**Acceptance:** Later sandbox/certification history cannot mask an existing production submission or acceptance.

**Evidence:** [latest transmission selection](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:29); isolated probe T05.

### T06 - Successful transaction total is not a unique-invoice sales/SLS total

**Medium reporting limitation.** Two dummy successful transaction rows referencing the same seller/invoice identity, each PHP 112, produced PHP 224. The page sums transaction rows, without document identity reconciliation. A transaction total may legitimately include multiple payment events; that value must not be reused as deduplicated invoice sales or SLS without an approved aggregation model.

**Required:** Keep payment/transaction totals explicitly separate from unique issued-invoice totals. Surface duplicate/conflicting identities and apply traceable credit/debit/void treatment in sales reporting.

**Acceptance:** Approved invoice totals count each in-scope document once; repeated imports, split payments and conflicting duplicates do not silently inflate SLS.

**Evidence:** [summary aggregation](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:122); isolated probe T06. No duplicate receipt identity groups were found in the current local aggregate.

### T07 - Legacy invoice calculator accepts inconsistent submitted totals

**High, upstream financial integrity.** The existing merchant invoice calculator trusts submitted totals and line totals. An isolated pure-method call with quantity 2 and unit price 10,000 centavos accepted a submitted total of 1 centavo (PHP 0.01), rather than recomputing PHP 200. No invoice was created. The newer Invoice Center draft calculator does not protect the separate legacy path.

**Required:** Share one server-side calculation and validation service across issuance paths. Recompute line amounts, applicable tax classifications, discounts, VAT, rounding and totals; reject inconsistent inputs before issuance or EIS payload construction.

**Acceptance:** Tampered financial totals cannot be saved through any issuance path, and document, ledger, export and EIS payload values reconcile.

**Evidence:** [legacy totals](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:449); isolated probe T07.

## Controls that already align

- The transaction metric is labelled payment/ledger status, with a separate BIR status column. A successful payment is not automatically labelled BIR acknowledged.
- Not Submitted and Gateway Not Verified are consistent with the local records and disabled transport. These are important truthful states to preserve.
- The workspace API requires an administrative/BIR role; date, amount, branch, region, RDO, document and submission-status filtering are server-side. CSV export covers all matching pages and records an application audit event.
- Certification acknowledgements are identified as test and are excluded from production acceptance. Manual production acknowledgement is blocked.
- Raw transmission/acknowledgement references, attempt fields and original payload hashes exist for further traceability. The application has a deadline helper and bounded retry records, but those are not proof of actual BIR delivery.

## Regulatory interpretation and source limits

The supplied RMC 110-2026 copy, particularly pp.4,8-9, establishes the project baseline for structured records, separate issuance/transmission obligations, preservation, non-duplication, status visibility and transmission/recovery targets. It is used as supplied source material; its public issuance and the exact authorized EIS API contract were not independently established here.

Official [RR 11-2025 digest](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%2011-2025%20Digest.pdf) describes structured, electronically extractable invoice data and readiness for electronic reporting. A screen, printed receipt, QR code or CSV alone does not demonstrate complete EIS conformance.

Official [RR 26-2025](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2026-2025.pdf) separates the electronic-invoice issuance transition from electronic sales reporting requirements and their applicable issuances. Do not infer that every NUERS account has the same live transmission obligation merely because it can register. The [official EIS portal](https://eis.bir.gov.ph/) also publishes availability advisories; applicability and outage evidence need review for the relevant taxpayer and period.

Official [RR 7-2024](https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%207-%202024.pdf), section 6, establishes the invoice as the primary sales document, with official/collection/payment receipts as supplementary payment evidence. Preserve legacy terminology where necessary, but distinguish invoice issuance from payment collection in the canonical data model. Actual payload field names, document codes, signing and encryption requirements must come from the authorized interface/version rather than a third-party EIS website.

## Verification and remediation order

Five existing OperationsWorkspace tests passed (58 assertions). Six private diagnostic cases reproduced T02-T07 (31 assertions). These successful reproductions demonstrate open findings; they do not certify compliance. The MySQL audit was read-only and used aggregate results only. No application fixes, live-server checks or BIR submissions were performed.

First correct canonical document coverage, immutable snapshots, authoritative dates and calculations. Then implement/verify the authorized transport and per-environment submission history. Complete acceptance with duplicate/split-payment/adjustment cases, date/import/outage cases, taxpayer authorities, genuine BIR responses and controlled end-to-end certification. The broader security and accreditation findings in the [earlier full-system audit](document-alignment-audit-2026-10-16.md) remain separate prerequisites; they were not retested by this focused review.

Private evidence: `.local/transaction-eis-audit-20261016/runtime-status.json`, `probe-results.jsonl`, `probe-tests.log`, `existing-tests.log`, and `TransactionEisAuditTest.php`.
