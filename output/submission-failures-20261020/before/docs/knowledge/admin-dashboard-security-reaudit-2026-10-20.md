# NUERS 1.0.0 — Admin dashboard and API isolation re-audit

Release: `2026-10-20.admin-security-audit`. Assessment date: 20 October 2026 (PHT).

The application had material gaps between its documented access rules and several legacy screens/endpoints. The reproduced defects below are corrected and regression-tested. This is an application audit and remediation record, not an independent penetration-test certificate or an assertion that every production dependency is ready.

## Reproduced findings and corrections

| ID | Finding | Correction and evidence |
| --- | --- | --- |
| A01 — high | Receipt-number lookup disclosed merchant name, TIN and amount without authentication. A repeated document number could resolve to another business. | Requires a valid account session and server-derived business or independently verified buyer scope. Unauthorized receipt numbers return 404; anonymous callers return 401. Duplicate numbers are resolved within scope, with explicit ambiguity handling. A registry match is not labeled a verified signature or BIR acceptance. |
| A02 — high | An authenticated account could use TIN lookup as a cross-merchant business directory. | Non-administrative queries are restricted to the caller's owned or authorized merchant context; a TIN never establishes ownership. Missing and out-of-scope records have the same generic response. |
| A03 — high | An ordinary admin could assign elevated platform roles or change privileged profiles through generic profile updates. | Only Super Administrators can change privileged profiles. Self-role changes, login identity replacement, bulk profile changes and generic profile creation are rejected. Merchant names/organization remain in the audited registry workflow. Valid non-merchant name edits synchronize the login directory. Existing session revocation on role change remains enforced. |
| A04 — medium | Administrative namespace authorization depended on individual controllers and path spelling. | The decoded `/api/admin/*` namespace rejects merchant/citizen sessions before dispatch. Controllers retain their narrower role checks. Encoded alias coverage is included in regression tests. |
| A05 — functional | A new invitee could not inspect/activate a business invitation without first logging in. | Exact invitation endpoints reach their existing expiring, hashed, one-use token validators. This does not bypass registration review or grant access from an email/TIN match. |
| A06 — operational integrity | Several legacy screens displayed sample metrics, fake SDK versions and simulated success messages for downloads, email, certificate operations and approvals. | Legacy admin integrations, performance, taxpayer, certificate, notification, compliance and analytics entries now use maintained database-backed workspaces. SDK/connection links redirect to the maintained developer documentation. Reports download real authenticated CSV/JSON responses. Unsupported AI advice and report scheduling are not represented as functioning services. |
| A07 — functional | User Directory searched only its first 100 loaded profiles, potentially hiding existing accounts. | Validated server-side search, stable ordering, scoped counts and bounded pagination now support the full directory. Pagination remains below the results. Search and pagination do not expand a merchant's authorization scope. |
| A08 — evidence accuracy | Readiness screens did not clearly expose all relevant deployment identity/authentication observations. | The readiness workspace now identifies NUERS 1.0.0, current MFA enforcement, account-isolation controls and external evidence requirements. Configured controls, observed health, pending evidence and blocked BIR transport are distinguished. |

## Documentation-to-dashboard mapping

| Documentation subject | Current administrative surface | Evidence boundary |
| --- | --- | --- |
| Merchant API connection and webhook guide | API Integrations; Merchant Source Profiles; request logs | Machine keys are tenant/branch/environment scoped. Owned-system setup and source metadata remain automatic for verified merchants. Documented ingestion payloads, statuses, fees and signed callback contracts are unchanged by this release. |
| Principal and supplementary documents | Electronic Invoices & Receipts; document copies; transaction register | Authorized copies and exports use the retained document record. Knowing a URL, receipt number or ID is not an access grant. Public receipt-number disclosure is intentionally retired. |
| Generated reports and statutory sales | Reports & Exports; Summary List of Sales; Transactions; Reconciliation | Filters are forwarded to the existing reporting services. Registry and complete audit-journal exports explicitly do not use transaction-date/category filters. BIR filings and acknowledgments require separate authentic evidence. |
| Technical manual — access and data lineage | Users & Permissions; Registered Businesses; Immutable Audit Logs; readiness | Tenant ownership derives from verified database relationships. Login identity, merchant identity, roles and regulatory authority use distinct controls. |
| Technical manual — hosting/data locations | Server Management; Operational Readiness | Dataplugs, Contabo and AWS Singapore/Hong Kong are declared/planned architecture choices where not actually provisioned. Sample node values are not telemetry or proof of regional replication. |
| Backup and disaster recovery plan | Backup & Disaster Recovery; Server Management; readiness | Lab restore results are not production RTO/RPO. Production backup restore, failover and provider recovery require actual recorded drills. |
| Exit/migration/data portability plan | Data Governance & Portability; scoped document/registry exports | Tenant-scoped retrieval preserves access boundaries. A portability export is not account deletion, an external restore guarantee or permission to remove audit evidence. |
| Incident response and service-level framework | Security & Incidents; Operational Readiness; audit and delivery queues | Queue/configuration observations do not establish continuous monitoring, provider SLA attainment or an operational security response team. |

## Authorization and negative-test coverage

The route inventory contains 296 API route entries (300 non-HEAD method entries). Inventory is not equivalent to exhaustive security proof. Automated tests cover anonymous static protected routes, merchant rejection across administrative methods, and representative object-level checks across documents, keys, wallets, exports, invitations, source profiles, webhooks, customers and delegated staff.

Additional tests reproduce and prevent foreign receipt lookup; foreign TIN lookup; foreign document detail, PDF, email and portability access; generic filter widening; privilege escalation; directory pagination/search leakage; foreign machine-key event reads; forged merchant selection; and revoked machine-key use. Existing tests cover current-session MFA, device binding, session expiry, team/branch/terminal restrictions, key scopes, IP restrictions, Live/Sandbox separation, webhook destination/signature controls, wallet debit atomicity and duplicate-safe ingestion.

A merchant key for business A cannot read an event belonging to business B. Query parameters and payload fields cannot change the key's tenant. Rejected cross-tenant operations leave financial, document-email and transaction records unchanged. Administrative oversight is intentionally separate from merchant access. Verified buyer receipt access requires the existing independent taxpayer-identity workflow.

## Verification results

- Full PHP suite, final round: **541 tests / 5,950 assertions passed**; isolated SQLite and mocked external delivery. The real PDF-render tests use local headless Chrome with synthetic documents.
- Browser checks: **10 passed**, no JavaScript page errors or failed API responses; synthetic localhost accounts, all external requests blocked or routed to localhost. Six real authenticated report downloads, date validation, mobile layout, full-directory paging/search, protected identity inputs, evidence display, legacy redirects and anonymous receipt-lookup denial were checked.
- TypeScript and both production build targets passed. Vite still reports an oversized bundle warning; this is a performance optimization item, not a passed load test.
- Public dependency-advisory checks reported **zero known production npm vulnerabilities and zero Composer advisories/abandoned packages** at audit time. This does not prove absence of vulnerabilities.
- Syntax and whitespace checks passed for the changed backend sources.
- Re-audit evidence is retained in `output/admin-security-audit/`, including the API inventory, JUnit results, dependency checks, browser screenshots and release manifest. No production credentials or merchant records are included in the release package.

## Deployment and rollback controls

The release contains application source, built assets and this internal record. There is no schema migration, financial backfill, merchant reset, key rotation or access expansion. The private installer checks the target domain, SHA-256 manifest, source baseline and PHP syntax, creates a recoverable file backup, promotes entry manifests last, clears application caches and runs a fresh verifier. Existing hashed assets remain available. Verification checks preservation fingerprints for accounts, keys, documents, balances, entries, transactions and `.env`; a failed install restores the previous files.

The separate deployment result records whether promotion and live health checks actually succeeded. Passing local tests alone is not evidence of a completed live deployment. Authenticated browser checks here used synthetic local accounts; a logged-out live browser is not reported as authenticated production verification.

## Remaining production evidence and limits

1. Independent penetration testing and remediation retest, including a broader authenticated role/tenant assessment and deployment/network attack surface.
2. Real production restore/failover drill with measured RTO/RPO, approved hosting roles/locations and retained evidence. Existing lab results remain labeled as lab results.
3. Provider WAF/DDoS/network controls, controller/node enrollment, trusted telemetry, operational alerts and workload capacity/load testing.
4. Live worker liveness and an end-to-end callback to a merchant-controlled HTTPS receiver. The user has not yet provided a receiver; automated callback tests are isolated.
5. Official BIR interface/credentials, authentic response verification and applicable authority evidence. A successful NUERS ingestion is not a BIR acknowledgment.

Do not mark these items complete based on dashboard colors, source code, mock telemetry or synthetic test output. The corrected application can be deployed while these external dependencies remain explicitly unverified; it cannot honestly be certified “100% production ready” by this audit alone.
