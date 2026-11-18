# BIR Transmission Center — NUERS 1.0.0

Updated 20 October 2026. Route: `/super-admin/eis-transmissions`.

## Operational scope

The center combines the canonical Live document ledger, immutable document outbox, durable delivery jobs, company preparation batches and separate historical transmission records. Preparation is available before the official BIR connection is configured. A locally prepared document is not evidence of submission or BIR acceptance.

Actual outbound delivery remains blocked by the current provider adapter. Production release additionally requires verified company/branch authority, reviewed reporting coverage, unchanged source snapshots and complete local validation. External issuer copies remain subject to reporting-coverage review and duplicate-reporting reconciliation. No guessed BIR endpoint, wire schema, credential or acknowledgment is used.

## Company workflow

1. Select a company and apply filters. The amount and document totals follow the applied filters.
2. Select documents on the current page, or choose the filtered company batch. Each batch contains at most 100 documents. This is a NUERS operational limit, not a statement of a BIR protocol limit.
3. Review the frozen document list, amounts and per-document validation blockers. Add an audit note and create the preparation batch within ten minutes. The preview belongs to the administrator who created it.
4. The scheduled `eis:prepare-batches --limit=10` worker runs every minute. It locks and rechecks each source, preserves an immutable outbox revision and records prepared, needs-review or failed results. Larger batches complete over successive worker runs. Restarted workers resume pending items and repair interrupted batch completion.
5. Open Company batches to inspect every result. Repeated creation with the same preview is idempotent; repeated preparation does not duplicate the same outbox revision. Changed sources require a new review.
6. Release to BIR queue becomes available only when the verified adapter and company gates allow it. Release rechecks all documents server-side and is atomic for the batch. It does not reset accepted, in-flight, uncertain or failed requests for blind resending.

The delivery adapter and official contract integration are a separate deployment milestone. Before enabling them, certify authenticated send/inquiry responses, configure and supervise `eis:work`, verify retry and inquiry behavior, and run approved end-to-end certification tests. This release performs local preparation only and does not send a production test sale.

## Filters and evidence

- Searchable company selector; document/TIN/transaction/provider-reference search.
- Original document date or NUERS received date; inclusive start/end dates using PHT boundaries.
- Preparation/delivery state, document type, sale category, source, branch, RDO, region and amount range.
- Calculated deadline (overdue, due today, upcoming) and stable date/amount ordering. Deadline calculation follows the configured calendar-day setting; coverage and applicability still require review.
- Pagination with selection confined to the displayed page; CSV export uses the same document filters, caps operational exports at 10,000 records and neutralizes spreadsheet formula cells.
- Company batch history applies the selected company. Historical transmissions apply company, search, creation-date range, document type, environment and history status. Production and certification histories are displayed separately.
- Document detail shows source validation, reporting coverage, calculated deadline, immutable SHA-256, outbox reference and delivery/inquiry attempt metadata. Encrypted payloads and credentials are not exposed by these endpoints.

## Status meanings

| Status | Meaning |
| --- | --- |
| Not prepared | No current internal outbox revision exists. |
| Prepared · connection pending | Local preparation is recorded; authenticated BIR acceptance has not been recorded. |
| Needs source review | A source, tax, identity, cancellation or coverage problem requires review. |
| Queued / sending / retry | Durable delivery job state; not acceptance. |
| Inquiry required | Outcome is uncertain; reconcile before another send. |
| BIR accepted | Delivery worker recorded an authenticated acceptance result. |
| BIR rejected / reconciliation required | Inspect provider evidence or exhausted delivery attempts. |
| Acknowledgment recorded (history) | Historical transmission status; inspect its environment and response evidence. Certification results never count as production acceptance. |

## Access and integrity

All endpoints require the API authentication boundary. Admin and super-admin roles may prepare/release; the BIR role has read-only access. Merchant, citizen and RDO roles cannot access this administrative workspace. Privileged MFA remains enforced by the application boundary. Batch items are bound to one company, source and immutable snapshot, and cross-company selections are rejected. Business records, API keys, wallet balances and transaction fees are not modified by preparation.

The deployment adds only `eis_preparation_batches` and `eis_preparation_batch_items`. Historical transactions and source documents remain intact. Recovery uses the release file backup; batch audit records should be retained through forward migrations.

## Official references and remaining dependency

The official BIR EIS certification portal describes middleware certification and Permit to Transmit: <https://eis-cert.bir.gov.ph/>. RMO 24-2023 describes structured invoice data and EIS identification: <https://bir-cdn.bir.gov.ph/local/pdf/RMO%20No.%2024-2023.pdf>. RR 11-2025 addresses structured electronic invoice data: <https://bir-cdn.bir.gov.ph/BIR/pdf/RR%20No.%2011-2025.pdf>.

These public materials do not establish that this NUERS deployment has a certified live transport. Obtain the applicable official interface specification, credential/certificate provisioning, taxpayer authority and certification evidence before activating production submission.
