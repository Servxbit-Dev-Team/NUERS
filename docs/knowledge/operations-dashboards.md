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

The report is based on the imported/local ledger, not a live BIR pull. Gateway connection is unverified; production transport remains disabled. Report aggregation currently materializes the filtered ledger for reconciliation/payload comparison and group calculations; use SQL aggregates and a scheduled reconciliation worker before large production volumes. Certification data cannot establish real BIR acceptance. Administrative review is distinct from financial correction documents. CSV is labelled explicitly and can be opened in Excel; no native XLSX or BIR submission is implied.

Sidebar follow-up: main links and child links use a single 14px/20px, medium-weight rule across enterprise pages and legacy sidebar components. Group headings use 12px/16px. Page-specific invoice/operations styles can no longer shrink sidebar labels. Superseded by the 15 October sidebar correction below.

Verification: the original PowerPoint copy has the same SHA-256 as the supplied file. The production build passes; the full application suite passed 62 tests, and the operations tests additionally cover end-date-only filtering, reversed ranges, complete exports, CSV formula escaping, stale-review conflicts, tampered payloads and certification isolation. Browser checks confirmed 14px/20px sidebar links on Dashboard, Invoices, Transactions, Sales and BIR Transmission; a 390px mobile viewport has no document overflow. The downloaded sales CSV contains all 13 current successful records, totaling ₱750,400.00 gross and ₱80,400.00 VAT, matching the UI. These values are a dated local snapshot, not public production statistics.

Sidebar correction after user screenshot review: removed the invoice/operations sidebar overrides instead of only overriding font size. All enterprise pages now share 320px expanded width, the same logo/header/nav spacing, 14px links and 36px menu rows. Labels and child links use nowrap; there is no word breaking or ellipsis. The intentional collapsed mode remains 72px; mobile uses the same drawer up to the viewport width. Browser measurements across Invoices, Transactions, Sales, BIR Transmission, Reconciliation and Live Monitor returned width 320px, font 14px, row height 36px, and zero wrapped or overflowing menu labels.
