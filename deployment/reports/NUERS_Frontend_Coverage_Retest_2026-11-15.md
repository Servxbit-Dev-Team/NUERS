# Reconstructed frontend: coverage-gap review and retest

Date: 15 November 2026. Candidate: `production-20261115-frontend1`; base: `production-20261114-vapt1`.

**Local retest passed; frontend release deployed successfully to both production nodes.** This is a bounded security/regression review of the reconstructed frontend, not an independent VAPT certificate or proof of complete equivalence to the missing original source.

## Findings fixed

| Area | Observed issue / fix | Evidence |
|---|---|---|
| Response isolation | A delayed successful request or body could be consumed after the browser changed accounts. Successful authenticated responses, clones and body readers now check their originating session. The CSV streaming reader checks before and after each read. | Deferred response/body, clone and download regression tests |
| MFA redirect isolation | An old request's delayed MFA challenge could redirect a replacement session. Challenges now apply only to the current requesting token. | Delayed MFA response regression |
| Browser privacy | Dashboard financial payloads remained in sessionStorage. Dashboard data now stays in component memory; legacy dashboard caches are purged on authentication transitions. Previous profile/permissions are cleared while a replacement session is verified. | Cache tests and cross-tab browser logout |
| Export lifecycle | A separately mounted report dialog could outlive logout. It now closes on session changes, checks the session before saving a file, and completes cleanup once even if multiple events arrive. | Real report dialog closed when signing out in another tab; protected CSV download still succeeds |
| Credentialed transport | URL and redirect handling lacked explicit bounds. API paths now reject absolute/network-path/backslash/control forms; credentialed requests enforce no-store and reject redirects. No attacker-controlled production URL path was established in this review. | Invalid URL and override regression tests; defense in depth |
| MFA return navigation | Encoded or normalized MFA return paths could loop. A dedicated validator limits navigation to local non-MFA paths and preserves valid query/hash values. | Local/foreign/encoded/normalized return-path cases |
| Session verification errors | Invalid JSON, missing session fields and malformed non-null session values could invalidate an otherwise valid login. These are now verification errors; explicit null and actual invalid-session responses still log out. | Malformed-response cases plus invalid-session browser check |
| Login completion race | A delayed anonymous login/Google completion/signup could overwrite a session established in another tab. Authentication completion now checks that the initial browser token has not changed. | Three authentication entry points tested with delayed responses |
| Mutation timeout feedback | Timeout copy claimed unchanged records despite an unknown server outcome. Mutations now instruct users to check records before retrying; no automatic replay is introduced. | Timeout and single-request-count test |

Eight initial new regressions failed before remediation. Two additional regression cases subsequently reproduced the anonymous-login race and malformed non-null session problem, and passed after their fixes. Not every matrix row is an independently exploitable vulnerability; several are privacy, resilience or defense-in-depth improvements.

## Validation completed

- **37 frontend/session checks passed:** 13 transport/auth cases, 13 reconstruction/rendering cases and 11 existing session-client scenarios.
- **40 backend boundary tests / 1,874 assertions passed** in isolated SQLite. Includes all **557 registered route/method negative or public probes**, admin-role rejection and privileged MFA boundaries. Two routes remain explicitly recorded as handler-shadowed, not falsely counted as handler execution.
- TypeScript solution check and production Vite/document-renderer build passed. A second build compared **237 generated outputs with zero hash differences** using the same locked installed dependencies.
- Receipt HTML tests verify hostile seller/buyer/item/notes text is escaped and untrusted SVG logo data is excluded. CSV tests verify formula neutralization, quoted streaming data and rejection of malformed/oversized reports.
- Synthetic browser tests on port 8002: merchant dashboard and CSV download, admin login/TOTP, report dialog opening, cross-tab sign-out closes the dialog and removes private content, final-build login/TOTP and automatic redirect on server-expired session.
- The synthetic reporting index was initialized locally after the report correctly displayed an unavailable-index error. No customer database records, real emails, payments, invoices or permissions were modified for these checks.
- One stale-chunk load error occurred while the local build directory was being replaced during testing. Reloading after the build completed resolved it. The build retains a >500 kB chunk advisory; this is a performance warning, not a passed performance audit.

The endpoint checks exercise authentication boundaries and selected synthetic payloads; they are not exhaustive payload/role/resource combinations. The earlier 805-test Linux run is historical evidence from November 14 and is not represented as rerun in this frontend-only review.

## Remaining coverage limits

| Coverage area | Current evidence / remaining work |
|---|---|
| Reconstructed source provenance | New maintainable source/build hashes recorded; original missing source is still unavailable. No claim of historical 1:1 recovery. |
| Wider business workflows | Prior reconstruction checks and this targeted browser retest cover selected admin/merchant flows. Every screen, import variant, browser, role and lifecycle has not been exercised end to end. |
| Injection surface | Targeted receipt escaping and CSV defenses tested; a comprehensive authenticated DOM-XSS campaign across every input/sink remains external VAPT work. |
| API-key connector and encrypted offline queue | Existing distinct API-key/queue behavior is outside the new bearer-session race suite. Full offline/reconnect, key rotation and multi-tab fault injection remain separate acceptance coverage. |
| Infrastructure/cloud/OS | This patch does not repeat the prior Linux multi-node/infrastructure audit. Candidate native smoke and two-node rolling health/hash verification are deployment gates. |
| Independent assessment | Formal authenticated VAPT and infrastructure tester sign-off remain outstanding. No measured statement of 100% frontend coverage or guaranteed VAPT pass. |

## Scope and recovery

The frontend patch packages only the scoped frontend source/helper/tests and compiled assets. A separately validated two-file backend input-validation overlay subsequently produced `production-20261115-input1`; see `NUERS_PostDeployment_Security_Retest_2026-11-15.md`. Existing backend, vendor dependencies, database, production environment and IAM permissions stay at the verified base. The inactive release is checked before one-node-at-a-time switching, with the old release retained for rollback. No additional EC2 capacity is provisioned. Short-lived operator access to the single reviewed build object does not remove the runtime migration-archive deny policy.

Evidence: `NUERS_Frontend_Gap_Provenance_2026-11-15.json`, local logs under `.local/frontend-gap-audit-2026-11-15/`, and `frontend-gap-audit-2026-11-15/session-invalid.png`.
