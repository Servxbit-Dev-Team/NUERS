# NUERS financial concurrency VAPT follow-up

Date: 15 November 2026 (Asia/Manila).

## Scope

This review tests invoice creation/publication and numbering, wallet fee debits and withdrawals, manual funding requests and independent approvals, payment-record ingestion, and suspension/key-revocation boundaries. It extends the previous seven-role and 65-endpoint coverage report; it does not replace that inventory.

Real process races use separate PHP processes and database connections against a network-disabled, disposable MariaDB 10.11.19 instance with InnoDB REPEATABLE READ. Workers synchronize at a filesystem barrier. Suspension and API-key revocation tests additionally pause a request after its initial merchant SELECT, commit the competing change on another connection, then resume the request. No authorization, wallet or invoice-numbering services are mocked. HTTP delivery is blocked, mail and queues are faked, and all records and identities are synthetic.

## Findings and fixes

### FC-01: committed suspension could be bypassed by an in-flight mutation

The preflight access check could read an active business and continue after a concurrent suspension had committed. The draft-invoice and top-up paths returned 201; wallet settings returned 200. The request retained the stale preflight status.

Invoice creation now checks the current merchant status immediately after acquiring its merchant row lock. Wallet settings and funding requests likewise check the current status under the same lock used to serialize their writes. Suspended and inactive businesses receive 403 before writing. Existing read/retrieval policies remain intact. Locks are held through commit or rollback, so a status change and a mutation have a consistent ordering.

The process tests reproduced all three paths before the fix. Separate default-suite regressions cover all three business-capable roles (merchant, admin, super_admin), both suspended/inactive states, and all three affected endpoints: 18 cases, all failing before and passing after the fix. Assertions check no document/top-up was created and the wallet row and ledger were unchanged.

### FC-02: concurrent valid funding approvals saw an obsolete wallet snapshot

Four distinct, independently reviewed funding requests for one merchant produced one success and three false settlement-conflict responses under REPEATABLE READ. The initial consistent top-up read occurred inside the transaction before waiting for the merchant lock. Later reads therefore could see an obsolete wallet snapshot despite acquiring the current merchant lock.

The controller now resolves only the merchant lock target before entering the transaction. Its first transactional read acquires the merchant lock, then it re-reads and locks the top-up and verifies its merchant association. Independent-review requirements, immutable decisions, global payment/settlement uniqueness and ledger integrity checks remain enforced. All four distinct approvals now commit once each, with four ledger credits and the exact expected balance. Duplicate approvals and shared settlement references are still rejected.

No duplicate financial credit was observed in the pre-fix tests; FC-02 was a legitimate concurrent-operation failure, not evidence of a successful double-credit exploit.

## Concurrency scenarios

| Scenario | Required result |
|---|---|
| Four identical invoice submissions | One invoice, one number and one fee; three idempotent replays |
| Four distinct invoice submissions | Four unique increasing numbers and four fees |
| Same invoice request ID, different content | One success and one 409; original preserved |
| Two invoices competing for the last fee balance | One success, one 402; no overdraft or consumed second number |
| Concurrent publication of one draft | One publication/fee; competing request 409 |
| Invoice fee versus wallet withdrawal | Exactly one affordable operation commits; ledger balance agrees |
| Four identical top-up requests, then four approvals | One pending request and one credit; further approvals 409 |
| Same settlement reference across two merchants | Exactly one credit globally; other request 409 |
| Four distinct top-up approvals for one wallet | Four credits, exact balance and valid ledger chain |
| Competing wallet settings with the same revision | One save, one 409; revision advances once |
| Two invoices competing for the last registered number | One invoice and fee; failed operation rolls back |
| Four replays of a payment-record event | One inbox, transaction and receipt; no invoice fee |
| Different payment events sharing a receipt number | One record, one 409; no duplicate receipt |
| API key revoked after preflight | 403; no payment record |
| Suspension during draft invoice / top-up / settings | Three scenarios, each 403 with no mutation |

All 17 scenarios passed in three independent fresh-database runs: **51 scenario executions, 669 assertions**, with zero failures/errors/skips. The original test run that rebuilt the entire schema per case was stopped to improve setup speed; it is not counted as a completed run. The completed baseline runs and their failures are retained separately.

The additional default-suite suspension regressions passed: **18 tests, 126 assertions**. The native harness also passed locally: **8,372 authenticated probes across seven roles, 52 prior targeted regressions, 18 financial suspension regressions, and 906 explicit administrative denials**.

## Deployment status

The full local regression gate passed: **912 tests, 13,333 assertions, zero failures/errors/skips**. All three MariaDB runs passed (17 tests and 223 assertions each). The deployment artifact contains only `BusinessInvoiceController.php` and `MerchantWalletController.php`, with baseline/source SHA-256 guards. **Deployed successfully at 21:44:03 PHT on 15 November 2026.** Live release `production-20261115-races1` is based on `production-20261115-positive1`. The earlier expired AWS session was restored before upload; the SHA-256-verified artifact then passed both nodes' baseline checks.

Both nodes passed isolated native PHP 8.4.27 validation before traffic switched: **8,372 authenticated probes, 52 prior targeted regressions, 18 financial suspension regressions and 906 explicit administrative denials per node**. External callbacks and unconfigured delivery deliberately remained unavailable in the synthetic broad matrix; these checks do not establish real provider acceptance or delivery.

Rolling drain, switch, 16 direct HTTP checks per node and load-balancer health gates all passed. Both targets are healthy. Changed code is root-owned and not group/world writable; environment and frontend manifest were unchanged. No schema migrations, real customer financial mutations, account changes, MFA enrollment changes or new infrastructure were included. Optional admin 2FA behavior and existing enrolled authenticators were preserved.

Post-deployment bounded public checks: **79/79 passed, zero failures**, completed at **21:45:40 PHT**. This includes eight financial anonymous/forged-context checks, all returning 401. Seven other probes were blocked at the edge; those do not establish backend payload coverage. Final health returned 200. Deployment proof is saved at `.local/vapt-races-2026-11-15/deployment-proof.png`.

## Reproduction and evidence

- `tests/Concurrency/FinancialConcurrencyTest.php`: real process races; intentionally outside the default SQLite suite.
- `tests/Support/financial-concurrency-worker.php`: guarded HTTP-kernel worker and controlled race barrier.
- `tests/Feature/FinancialMutationSuspensionTest.php`: default-suite stale-preflight regressions.
- `scripts/run-financial-concurrency.py`: creates a random least-privilege test account/schema and removes both after execution; requires the explicitly isolated local MariaDB socket already running with networking disabled. Run from the repository with an evidence label, for example `python3 scripts/run-financial-concurrency.py retest`.
- `.local/vapt-races-2026-11-15/`: source baselines, pre-fix failures, JUnit reports, synchronized worker status metadata, cleanup evidence, local/native gates and deployment artifacts.

Every disposable schema/user was removed and the isolated MariaDB process stopped after the three passing runs. No real customer records, balance adjustments, financial transfers or message deliveries were used.

## Limits

This is bounded concurrency testing (up to four simultaneous workers plus controlled competing updates), not a throughput or denial-of-service certification. It does not prove every possible interleaving, distributed database failure, production-scale contention or external payment-provider settlement. The payment path tested is NUERS payment-record ingestion; wallet funding is the existing independently verified manual settlement workflow. Native Linux checks use synthetic in-memory databases; the real multi-process InnoDB races ran locally. Existing enrolled authenticators and optional admin 2FA are outside this patch and must be preserved. Independent VAPT sign-off remains separate from these engineering tests.
