# Authenticated NUERS security coverage expansion

Date: 15 November 2026. Base: `production-20261115-input1`. Candidate: `production-20261115-auth1`.

**Deployed and verified: `production-20261115-auth1` on both production nodes. Both targets are healthy. Local, strict MariaDB, native Linux and controlled public gates passed.**

## Coverage added

The new route inventory runs with valid device-bound sessions and fresh MFA for all seven platform roles: super administrator, administrator, BIR, RDO, merchant, client and consumer. It covers 557 registered route/method entries, expanded to 616 concrete route/method paths for generic tables, dashboard portals, tax-intelligence scopes and invoice/receipt variants. Each API case receives a baseline and malformed-input request; web routes receive baseline requests. Total: 8,491 local probes.

This inventory checks authenticated dispatch, unexpected server errors, and explicit nonprivileged denials for the administrative namespace. A successful response alone is not treated as proof of correct authorization. Separate tests assert administrative route-family denial, super-admin-only infrastructure access after fresh MFA, dashboard allowlists, foreign financial-record filtering through eq/in/or, denied foreign receipt/download/portability access, merchant delegation and revocation, RDO office isolation, individual malformed fields, and source-buyer owner scope/link exclusion.

Two storage route/method entries are still shadowed by the SPA catch-all (14 role-specific records). These are recorded, not counted as handler execution. Fourteen resend-verification cases fail closed with 503 because test mail delivery is disabled. Integration-key, visitor-capability and signed BIR-callback endpoints require their own credential class; a human session does not establish their successful handler coverage. Existing integration-key, webhook, OTP/TOTP, tenant, upload and replay regressions remain in the complete suite.

## Findings and changes

1. **Malformed query shapes caused HTTP 500.** Citizen approvals, live monitoring, transactions overview, business invoices, merchant expenses, receipts and transactions now reject array-valued scalar filters using bounded string validation. No injection or unauthorized disclosure was demonstrated by these failures.
2. **Malformed generic profile update bodies reached array operations before validation.** Scalar/list payloads and nested identity fields now return HTTP 422 before authorization-sensitive identity comparisons. Legitimate single-object updates remain supported.
3. **Delegated own-profile exception assumed JSON text.** Merchant team authorization now handles JSON or bracket-form filters and requires a scalar matching the current account. Foreign/nested identifiers do not gain the self-profile exception; revoked team access remains denied.
4. **Indexed customer source-buyer lookup used an obsolete SQL alias.** A stable derived-table alias fixes the HTTP 500 while retaining merchant scope and exclusion of already linked documents. Positive and negative cases run in indexed and non-indexed modes.
5. **Regional aggregate portability.** A derived normalized-region query avoids ambiguous SQLite grouping and strict MariaDB GROUP BY incompatibility. This was a query correctness/compatibility finding, not a demonstrated privilege escalation.

The SQLite inventory supplies a test-only DATE_FORMAT bridge for legacy MySQL report SQL. MariaDB runs the real SQL under strict mode with a disposable database and DML-only runtime grants. These are separate results; SQLite compatibility support is not counted as MariaDB proof.

## Operational safeguards

Authenticated mutations use synthetic local databases or native Linux in-memory SQLite only. Outbound HTTP is blocked, queues/mail are faked or disabled, and infrastructure execution is disabled in the test process. Test credentials and assertions never use customer accounts. Native checks retain production device binding and MFA enforcement. No schema migration, customer data update, IAM expansion, or additional AWS compute instance is included in this patch.

The deployment artifact contains 10 changed runtime PHP files and two new test files. It is based on hashes captured before this task, not the repository's broader pre-existing dirty diff. Preparation refuses mismatched live source hashes. Production switching requires passed local/full/MariaDB gates and explicit native assertion markers from both inactive candidates. The old release is retained for rollback.

## Remaining limits

Every registered route/method has a recorded probe, but this is not exhaustive authorization proof for every role/resource/state/payload combination. Missing-resource and invalid-input requests can stop before deeper business handlers. Shadowed routes, disabled email transport, API-key/capability-specific successful paths, all browser DOM sinks, offline/reconnect and key-rotation fault injection, large-scale concurrency, infrastructure exploitation, exhaustive cryptographic/cipher analysis and independent authenticated VAPT remain separate coverage. No destructive load, customer mutation or live brute-force campaign is included.

A concurrent test run exposed shared fake-storage deletion in the test harness; the new matrix now uses process-specific temporary disk roots. The isolated 100 MB upload/download/retry/review test subsequently passed 219 assertions. No production storage behavior was changed for this test-harness fix.

## Passed validation gates

| Gate | Result |
| --- | --- |
| Complete local PHPUnit suite | 819 tests, 11,207 assertions; zero errors, failures or skips |
| Strict MariaDB authenticated suites | 9 tests, 1,145 assertions; zero errors, failures or skips |
| Local authenticated route matrix | 8,491 probes per database engine; no unexpected server-error findings |
| Linux candidate, each of two nodes | 8,358 API probes, seven roles, 906 explicit admin-access denials; native PHP 8.4.27 |
| Large-document isolated retest | 100 MB upload/download/retry/review, 219 assertions passed |

The native Linux probe records 14 signed-BIR-callback and 14 resend-verification responses as unavailable (503); those transports are not exercised successfully by the human-session inventory. No live email or BIR submission was sent. Both native runs use in-memory SQLite and are distinct from the strict MariaDB SQL validation.

Artifact SHA-256: `edca95f1e73b2529071de3b3a8bca71f645b67635e75a461f05290f003297d41` (51,339 bytes). Ten runtime files and two tests; no frontend rebuild or database migration. Source hashes matched the frozen local artifact and both live baselines before preparation.

Evidence is retained in `deployment/reports/authenticated-audit-2026-11-15/` and the working `.local/authenticated-audit-2026-11-15/` directory. Downloaded cloud evidence has been verified against its SHA-256 index. Full local XML logs, both route matrices, role-denial results, artifact hashes and production reports are included.


## Production verification and recovery

- Rolling deployment completed at **02:44:45 UTC / 10:44:45 PHT, 15 November 2026**. Each node was drained, switched, tested and restored to healthy before the next node changed. No rollback was required; `production-20261115-input1` remains available.
- Both active nodes repeated the native authenticated matrix after switching: 8,358 probes and 906 explicit administrative-access denials each. Synthetic PDF, scanner and permission smoke checks also passed.
- **261 cumulative scoped file hashes** matched on each node. Reviewed code remains root-owned and not group/world writable; `.env` is unchanged and mode 0640. Runtime access to deployment archives remains denied.
- Public home, login and API health endpoints returned 200. Public JS/CSS hashes match the already-reviewed frontend build.
- Controlled public campaign finished at **02:45:49 UTC / 10:45:49 PHT**: **59/59 checks passed**, seven at the Cloudflare edge. Edge rejection is not evidence of backend payload handling. See the prior [security category report](NUERS_PostDeployment_Security_Retest_2026-11-15.md) for the unchanged broader regression scope and the current `cloud/security-probes.json` for this release's actual results.
- Both temporary staging instances remain stopped. No AWS resource was provisioned. The isolated local MariaDB test server was shut down after its disposable test databases/users were cleaned up; normal localhost was not stopped.
- No customer data, schema, IAM grant or application environment change occurred.

Downloaded cloud ZIP SHA-256: `4c956eaaabedb47d60504370843c41b55279b42622097a0d9900c8ca94ab9d1a`.

Evidence summary: [summary.json](authenticated-audit-2026-11-15/summary.json). Cloud results: [final verification](authenticated-audit-2026-11-15/cloud/final-verification.json), [rolling deployment](authenticated-audit-2026-11-15/cloud/production-rollout.json), [public probes](authenticated-audit-2026-11-15/cloud/security-probes.json). The evidence index records file hashes; earlier failed development runs remain in the working directory for traceability and are not represented as passed gates.

![Verified live deployment and bounded security retest](authenticated-audit-2026-11-15/live-security-verification.png)
