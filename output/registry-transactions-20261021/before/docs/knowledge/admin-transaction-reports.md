# Admin transaction register and exports

Super Admin → Transactions provides a canonical Live document register and a separate Sandbox test register. This is an operational report; document acceptance and BIR acknowledgment are distinct.

## Finding records

The Live register supports keyword, merchant, original document date or NUERS received date (PHT), document group, exact document type, NUERS status, sale category, recorded tax bases, manual/statutory/no-discount, customer name/TIN, representative payment method, channel, record source, branch, region, RDO, BIR evidence, identity conflicts, minimum/maximum amount, and date/amount sorting. Today/7-day/30-day shortcuts update the draft dates. Click Apply filters. Chips identify applied criteria; Reset all clears every filter including the sale category and document group. Tabs share one document-group state with the query.

Summary cards, pagination and exports use the same applied filters. Recorded amounts include all selected document statuses/types and must not be interpreted as reconciled revenue. Linked payment sums are scoped to the matching documents and are separate from invoice sales. Tax basis filters mean a positive recorded base; “Non-VAT document type” uses the document classification, not the merchant's current registration or an inferred line classification. Sales Reports remains the reconciled tax report.

Columns can show customer, tax, payment, location and source context. Details provides original IDs, financial breakdown and processing evidence. Unavailable information is labeled rather than invented. Export includes all report fields regardless of visible columns. Selecting rows selects only the current page; filter/page changes clear selection.

## Export report

Choose all matching records or selected rows. Selected IDs are intersected with the applied filter scope on the server. CSV streams all matching rows across pages in the selected sort order, with spreadsheet formula escaping. A PDF contains the NUERS official logo, navy/blue/cyan/gold/red branding, Philippine peso amounts, generation time/actor/version, report reference, applied filters, summary totals, merchant/category breakdowns, detailed record register and SHA-256 content snapshot. It has page numbers and private/no-store response headers.

PDF is bounded at 500 matching records to keep detailed rendering predictable. Larger scopes return an explicit error before rendering; narrow the filters, select records, or use CSV. No partial PDF is silently returned. Five export requests per minute per authorized account and one concurrent PDF per account are permitted. The PDF renderer uses a temporary private directory, embedded local assets, escaped HTML, a restrictive CSP and blocked network resolution; temporary data is removed after rendering. Downloads create immutable export audit events and do not change source documents, balances or invoice status.

Sandbox exports are independently scoped to test records, prominently labeled, and never include Live financial activity. Its date filters use received PHT boundaries. Admin Sandbox offers keyword/source/business, date, sale category, document type and amount filters. Sandbox test totals are excluded from Live reports and no fee is charged by exporting.

## API access and validation

`GET /api/transaction-workspace` and `/api/transaction-workspace/export?format=pdf|csv` retain existing admin/super_admin/bir role checks; merchant credentials cannot read this register. `GET /api/admin/sandbox-records/export?format=pdf|csv` requires admin/super_admin. Existing authentication, session/device checks and MFA remain in force. PDF requests return HTTP 202 with an `export_id`; poll `GET /api/transaction-workspace/exports/{export_id}` until it returns the PDF. Status and download are restricted to the requesting authorized account. No public download link or new credential is introduced.

Live filter names: `search`, `merchant`, `from`, `to`, `date_basis=document|received`, `type`, `document`, `status`, `sale_category`, `vat`, `discount`, `customer`, `payment`, `channel`, `source`, `branch`, `region`, `rdo`, `bir`, `identity`, `min`, `max`, `sort`. Export optionally accepts up to 100 unique `ids[]`. Page size stays bounded at 100. Dropdown catalogs are bounded; keyword search can find businesses outside the initial merchant catalog. Date/amount bounds and enum values are validated before querying.

Read-only reporting changes require no schema migration. Source records, wallet pricing and BIR processing controls are unchanged.

## Private PDF preparation

PDF exports prepare through the existing scheduled report worker (`reports:export`). The dialog shows preparation and automatically downloads the file when ready, usually within two minutes. Report inputs and generated PDFs are encrypted in private storage, restricted to the requesting authorized administrator, and expire after 20 minutes. Every status/download request rechecks account access. The Chromium sandbox remains enabled. CSV remains a streamed download.
