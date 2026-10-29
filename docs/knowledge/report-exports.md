# NUERS report exports — 1.0.0

Updated: 21 October 2026. This guide describes the implemented report download controls, not government submission formats.

## Exporting a report

Apply the page filters, choose **Export PDF / CSV**, and review the scope in the dialog. PDF is a searchable A4 report with the official NUERS logo, navy/blue branding, cyan/yellow/red accents, report identifier, version, PHT generation time, applied filters, exported-row and source-field counts, all source fields, and page numbering. Explicit PHP columns receive recorded totals; running balances, prices, averages and rates are not added together. Blank values are labeled “Not recorded.” Long fields continue on subsequent pages.

The PDF uses the same authorized CSV response as its page. There is no independent query or new permission bypass. Merchant exports remain restricted to their business and granted branch/terminal scopes. Selection-based and current-page reports state that scope; they do not claim to contain all records.

PDF is limited to 500 source rows and 8 MiB of CSV input. Oversized sources produce a clear error, never a truncated report. Narrow the period/business/filter or use CSV. Existing transaction and merchant sales report downloads retain their specialized server-rendered PDFs and queued CSV jobs. Existing endpoint-specific CSV limits still apply.

CSV preserves field values, quotes commas/newlines, and protects spreadsheet formula cells. Negative numeric values may have a leading protective apostrophe in CSV; PDF PHP fields display the numeric amount. PHP is the currency code; labels retain recorded source time zones, while report generation time is Asia/Manila.

## Covered export entry points

| Area | Scope and formats |
| --- | --- |
| Admin transaction workspace | Existing filtered PDF/CSV and queued CSV; individual records use PDF/CSV |
| Electronic invoices and receipts | Authorized filtered PDF/CSV; selected visible documents are explicitly labeled |
| Merchant invoice/receipt records | Filtered, current-page or selected PDF/CSV; original document copies retain merchant branding |
| Merchant invoice record details | PDF/CSV with monetary centavos normalized to PHP |
| Merchant sales reports | Existing complete filtered PDF/CSV and export history |
| Live transaction monitoring | Filtered stream records, PDF/CSV |
| BIR Transmission Center | Filtered document/transmission register, PDF/CSV; does not transmit to BIR |
| Sales summary / reconciliation | PDF/CSV with sale category, manual/statutory discounts and VAT adjustment |
| Registered businesses / onboarding | Filtered registry PDF/CSV |
| Permit and branch registries | Filtered or selected PDF/CSV; import templates remain CSV |
| Merchant customers | Filtered customer PDF/CSV within authorized business |
| Wallet statements | Merchant or selected-business filtered statement PDF/CSV |
| Merchant user directory | Complete authorized business directory PDF/CSV, with recorded export purpose |
| Immutable audit journal | Search-filtered PDF/CSV including integrity hash fields; export event is outside its frozen result |
| API request logs | Applied-filter PDF/CSV |
| Server inventory | Complete authorized inventory PDF/CSV, including unconfigured entries |
| Backup/DR evidence | PDF/CSV readiness snapshot; up to 100 records per section, rollups from latest 2,000 evidence records |
| Configuration / accreditation | PDF/CSV of the current authorized snapshot; existing secret redaction preserved |
| Verification checklist | PDF/CSV of current business document requirements and statuses |
| Sandbox transaction records | Existing admin PDF/CSV plus selected test-record PDF/CSV |
| Legacy receipt, VAT and merchant directories | PDF/CSV of the explicitly stated loaded-view scope |

## Format exceptions

Original invoices/receipts, attached verification evidence, and published manuals keep their document formats. OpenAPI schemas, Postman collections, API request/response examples, encrypted queue recovery archives, data-portability archives and MFA recovery codes retain machine-readable or recovery formats required for their purpose. They are not financial report exports. No archive, key or government transmission is automatically sent by choosing Export.

## Validation

The release is checked with TypeScript and both production builds, CSV parser boundary tests, rendered PDF samples (empty data, long fields, negative amounts, accented names and a 500-row stress sample), backend export/security regression tests and browser download checks. PDF values are not a tax return or proof of BIR acceptance.
