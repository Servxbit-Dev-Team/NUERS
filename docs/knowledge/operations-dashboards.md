# Operations dashboards — 15 October 2026

Design references: user-provided Transactions, BIR Transmission, Reconciliation, and Summary List of Sales dashboard PNGs. Layouts reuse the existing skyline/Philippine flag artwork, actual NUERS branding, official BIR seal, and Lucide icons; no generated replacement for the official seal was required.

## Implemented local routes

- `/super-admin/transactions`: transaction metrics, document tabs, date/status/business/TIN/payment/branch/region/RDO/BIR/amount filters, pagination, details, all-filtered CSV export, and selected JSON export. Amount metric includes successful records only. Operational status and BIR evidence remain separate.
- `/super-admin/eis-transmissions`: queue metrics, volume/status charts, honest gateway status, environment/date/document/status/search filters, pagination, details, CSV export, and existing validated queue/retry tools. Production accreditation and taxpayer gates remain in force. Test records are labelled.
- `/super-admin/reconciliation`: source-derived exception review, charts, review status filters, exports, audit link, revision-checked administrative review with mandatory evidence/reason. Review state is persisted in `reconciliation_reviews`; source ledger and BIR evidence are not overwritten. A match requires production acknowledgment evidence plus readable, hash-verified linked payload comparison. No matching BIR record is claimed merely from payment success. Missing acknowledgment is `For Review`, not a fabricated BIR rejection.
- `/super-admin/tax-filings`: Summary List of Sales layout with successful records only, daily/monthly/business/type/payment/branch views, sales/VAT/VATable totals, charts, top businesses, filters and full-filtered CSV. This management report is not a submitted BIR filing or a claim of prescribed-format filing validation. Other existing tax-filing routes remain available.

New report endpoints require administrative roles; reconciliation writes require admin/super_admin. Stale reviews return 409; an append-only compliance journal records review updates. CSV cells escape spreadsheet formula prefixes. Receipt/invoice records continue to open the existing authentic document preview rather than a fabricated PDF.

## Typography

Shared tokens: body 14px, controls/table text 13px, metadata/helper labels at least 12px, hierarchy retained for titles and headings. Small fixed CSS font declarations were standardized across the app. Registration and operations inputs use 16px on narrow screens for usability. The official `.or-*` document/PDF typography retains its print-specific scale.

## Practical limits

The report is based on the imported/local ledger, not a live BIR pull. Gateway connection is unverified; production transport remains disabled. Reconciliation and sales use indexed SQL paths described below; selected rows are hydrated after pagination. Certification data cannot establish real BIR acceptance. Administrative review is distinct from financial correction documents. CSV is labelled explicitly and can be opened in Excel; no native XLSX or BIR submission is implied.

Sidebar follow-up: main links and child links use a single 14px/20px, medium-weight rule across enterprise pages and legacy sidebar components. Group headings use 12px/16px. Page-specific invoice/operations styles can no longer shrink sidebar labels. Superseded by the 15 October sidebar correction below.

Verification: the original PowerPoint copy has the same SHA-256 as the supplied file. The production build passes; the full application suite passed 62 tests, and the operations tests additionally cover end-date-only filtering, reversed ranges, complete exports, CSV formula escaping, stale-review conflicts, tampered payloads and certification isolation. Browser checks confirmed 14px/20px sidebar links on Dashboard, Invoices, Transactions, Sales and BIR Transmission; a 390px mobile viewport has no document overflow. The downloaded sales CSV contains all 13 current successful records, totaling ₱750,400.00 gross and ₱80,400.00 VAT, matching the UI. These values are a dated local snapshot, not public production statistics.

Sidebar correction after user screenshot review: removed the invoice/operations sidebar overrides instead of only overriding font size. All enterprise pages now share 320px expanded width, the same logo/header/nav spacing, 14px links and 36px menu rows. Labels and child links use nowrap; there is no word breaking or ellipsis. The intentional collapsed mode remains 72px; mobile uses the same drawer up to the viewport width. Browser measurements across Invoices, Transactions, Sales, BIR Transmission, Reconciliation and Live Monitor returned width 320px, font 14px, row height 36px, and zero wrapped or overflowing menu labels.


## Sales report performance — 21 October 2026

Summary List of Sales uses validated reporting projections, SQL totals and server-side pagination (maximum 100 documents and 50 summary groups per page). Source financial and linked payload checks run when affected documents change, not once per historical invoice on each page request. Changes to original invoices also invalidate linked adjustments. Duplicate identities, invalid source totals, missing dates, supplementary records and invalid linked payloads remain excluded; credit notes reduce sales.

The additive migration `2026_10_21_000001_index_operations_sales_evidence` adds eligibility and covering indexes. Twelve queue-only triggers invalidate affected evidence. Existing financial records, wallet balances, API keys and authentication policies are not edited. After migration, run `reports:refresh --max-seconds=180` until the dirty queue and missing eligibility are empty. If the database role cannot create triggers, a database administrator installs the reviewed trigger definitions with the existing application definer; no global privilege/security relaxation is required.

Totals and text-search matches use revision-scoped caches. Writes invalidate them before subsequent reads. Deep pages can read from the nearer end of the ordered index; only requested rows are hydrated. Selective text searches resolve at most 5,001 IDs before reusing matches; broad contains searches still require a scan. Narrow dates/merchant filters remain useful for large or complex searches. CSV exports stream in 500-row chunks. Branded PDF exports retain the 500-record bound; narrow filters for PDF, use CSV for larger results.

UI reads time out after 20 seconds, cancel superseded filter requests and provide Retry. Failed initial loads show unavailable values instead of false zero totals. Existing realtime revision checks avoid overlapping requests. The million-row benchmark uses isolated synthetic MySQL data; its measurements do not establish a production concurrency SLA or BIR acceptance.


## Page loading and large registers — 28 October 2026

Routes load their page bundles on demand; authentication, MFA and role checks continue to wrap those pages. A page loading boundary keeps navigation available and provides a reload action on a failed bundle download. Existing hashed assets must be retained during deployment for already-open clients.

Transaction, reconciliation and EIS registers use bounded ID selection before hydrating detailed rows. Merchant documents, payment registers and sales reports use stable ordering and read near-end pages from the nearer end of the index. Large middle-page offsets and broad substring searches can still cost more than selective date/merchant filters. Aggregate caches include the reporting revision and, where operational state is involved, the application revision. Wallet balances and financial source rows remain authoritative.

Default merchant record counters read narrow covering indexes when there is no delivery evidence or restricted team scope. Detailed filters and scoped team access retain the full authorized query. Broad MySQL sales summaries that match at least 100,000 rows and half of the reporting table use a sequential clustered scan to avoid millions of random row lookups; selective queries retain indexed seeks. This plan decision is made only on a summary cache miss and never changes tenant predicates or financial eligibility.

The migration `2026_10_28_000001_index_reconciliation_evidence` adds derived reconciliation evidence, a draft flag, and covering/order indexes. Existing invalidation triggers queue changed source records. Run `reports:refresh --max-seconds=180` until the backlog and missing evidence are zero. Source payload validation and integrity checks occur on changed documents; a reconciliation match still requires verified production evidence. No transaction is transmitted by these read optimizations.

Large audit pages no longer verify every journal entry in the foreground. `reports:audit-integrity` performs the complete bounded-memory chain check every five minutes through the existing scheduler. The UI reports its checked time and pending state when the journal advances or the result expires; a failed check stays failed until a later full verification succeeds. Small journals are checked synchronously.

Performance evidence is recorded in `output/all-pages-performance-20261028/`. Synthetic million-row timings are single-user read measurements, not a concurrency SLA. Production verification must identify the deployed release separately from local tests.
