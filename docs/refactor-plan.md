# NUERS refactor plan for the 1M transactions/hour launch target

Written 2026-10-27. Source: the architecture review in `server.md`. This is a plan only; nothing here is implemented.

## Goal and status of the numbers

- Target: 1,000,000 transactions per hour from day one (about 278/s sustained; about 2,000/s if BIR sends it in a burst). The figure comes from the internal spec. BIR's actual test shape (sustained or burst, duration, payload mix) is **not confirmed**.
- Every throughput figure below is an estimate, not a measurement. Step 1 exists to replace them.
- No step touches the BIR transport. `BlockedProvider` and the false activation flags in `config/bir_compliance.php` stay as they are.
- No step runs against the live database. Migrations are additive, and backups come first (see `deployment/LOCAL_LIVE_DATABASE_WORKFLOW.md`).

## Order of work

| # | Step | Why this position | Needs sign-off |
|---|------|-------------------|----------------|
| 1 | Load-test harness and baseline | Everything else is sized from it | No |
| 1a | Ledger queries on the acceptance path | **Done 2026-10-27**: 4,060 ms to 128 ms per acceptance at 20,000 receipts | No |
| 1b | Schema-existence queries on the hot path | **Done 2026-10-27** for the acceptance path: 128 ms to 59 ms per acceptance, 108 to 59 queries | No |
| 1c | Per-IP limiter on the intake route | **Done 2026-10-27**: accepted intake no longer spends the 300/minute/IP budget; failed credentials still do | Product decision (made) |
| 1d | Both audit writes deferred to the end of the acceptance transaction, under one lock acquisition (`auditMany`) | **Done 2026-10-27**: lock hold 14.7 ms to 9.6 ms (deferral), then to about 4.7 ms on a quieter machine (single acquisition) | Compliance owner to confirm |
| 2 | Audit write-path redesign | Still the binding ceiling: every sampled lock wait was on `eis_audit_head`; floor with the current design is about 8 ms per acceptance | **Compliance review** |
| 3 | Per-merchant and per-key lock review | Next ceiling after the audit lock | No |
| 4 | Uploads to S3 | Blocks more than one web node | No |
| 5 | Redis for cache, sessions, locks; SQS or Redis for queues | The database is the default for all four today | No |
| 6 | Read replica for reporting | Heavy dashboard queries compete with intake | No |
| 7 | Deploy hygiene | Cheap and mostly independent; can run in parallel | No |
| 8 | Hardening reviews (`TableController`, schema drift) | Security and maintainability | Security review |
| 9 | Rewrite the AWS plan for the launch shape | Sized from step 1 results | Budget |

Steps 7 and 8 can start any time. Steps 2 to 6 should follow the step 1 baseline.

## 1. Load-test harness and baseline

- Run the current code against MySQL 8 (not SQLite) on an isolated stack, never against `nuers.net`.
- Drive `POST /api/integrations/transactions` with Sandbox keys and synthetic merchants. Use many merchants and many keys, plus a single-merchant case, because the locks behave differently.
- Scenarios: sustained 278/s for 60 minutes; burst to 2,000/s; mixed payload sizes; batch route (limited to 10 per minute per route today).
- Record: throughput, p50/p95/p99 latency, lock-wait time, DB CPU and connections, queue depth, error rate.
- Output: a table in `server.md` with the measured ceiling per scenario. This replaces the ~100/s estimate.
- Note the Sandbox and key limits: Live keys cap at 50,000 requests a day and Sandbox keys at 5,000. Test with many keys, or raise the limits in the test environment only.

### Step 1 results (run 2026-10-27, harness in `loadtest/`)

Setup and limits: one Windows desktop (Ryzen 7 5700X, 32 GB) running MySQL 8.4.3, nginx, 12 `php-cgi` workers (PHP 8.5, opcache on) and the load generator together; cache, sessions and queue on the database (the app default); 50 synthetic merchants that all share one TIN; `source_capture` events. These numbers locate bottlenecks. They are **not** a capacity figure and not an AWS sizing.

| Measurement | Result |
|-------------|--------|
| One client IP, 8 concurrent | 2,235 of 2,298 requests rejected with 429 (`NETWORK_RATE_LIMITED`) |
| 4,000 client IPs, 1 / 8 / 32 concurrent | 3.2 / 11.7 / 8.3 accepted per second; p50 314 / 600 / 843 ms. Latency rose with concurrency and with table size, so these reflect query cost, not a lock ceiling |
| Queries per acceptance | 114 to 116, of which about 50 hit `information_schema` (36 column lookups about 51 ms, 13 table lookups about 11 ms) |
| In-process time per acceptance | about 0.65 s with under 1,000 receipts; about 4.06 s with 20,000 receipts of one seller. Almost all of it is database time |
| Slowest query, 20,000 receipts | 3.85 s, the same when re-run alone, so it is query cost and not lock waiting |
| Ledger duplicate check at 1.7k / 5k / 20k receipts | 42 / 48 / 110 ms, against 0.4 ms for a direct indexed lookup |
| Readiness identity count (non-matching TIN) at 1.7k / 5k / 20k receipts | 27 / 32 / 86 ms |
| Commit cost on this disk | 2.7 ms per autocommit insert |
| Data integrity after the 15-request smoke test | inbox, transactions, receipts and audit events all 15; no duplicate event hashes; audit chain intact |

Not measured or not yet explained:
- The audit-head lock ceiling: not visible in this first run because query cost dominated. Measured after 1a and 1c; see "Steps 1a and 1c: done".
- The 504s (and one 500) at 16 to 32 concurrent: later traced to the harness, not the app. `php-cgi` on Windows serves one connection at a time, and the nginx upstream keepalive let idle kept-open connections pin workers. With keepalive off, 64 concurrent clients ran with no errors and nothing was logged by the app.
- Data integrity: re-checked after all the later runs (see "Steps 1a and 1c: done").
- Anything above 20,000 receipts. Extrapolations to 1M rows are arithmetic on a straight line, not measurements.

#### 1a. Ledger queries on the acceptance path

`IssuedDocumentLedger::candidates()` unions every receipt, invoice and unlinked transaction in the system, with correlated subqueries per receipt row. `normalizedCandidates()` wraps that with computed columns (normalised TIN, branch, document number), so MySQL cannot use an index on them. It runs inside the merchant-locked acceptance transaction, in:
- `IntegrationTransactionController::assertNumberAvailable` (line 145);
- `EisDocumentReadiness::inspect` (lines 19 and 63), reached through `prepare()` (called at line 125 of the controller).

Two costs, both measured:
- A full scan of the whole ledger with cheap expressions on every acceptance: 27 ms at 1.7k receipts, 86 ms at 20k, roughly linear.
- A per-seller term in the readiness identity count: 3.85 s with 20,000 receipts of the same TIN, about 0.19 ms per existing document of that seller. A large merchant pays more on every new invoice, inside its own lock.

Fix direction: the duplicate check becomes a direct indexed lookup (a unique index on seller, branch, document family and normalised number, written when the document is stored), and the readiness identity check reads that index instead of rebuilding the ledger. The ledger union stays for reports only, and moves to the read replica (step 6). The unique index also makes the duplicate guarantee enforced by the database.

#### 1b. Schema-existence queries on the hot path

Each acceptance runs about 50 `information_schema` lookups from `Schema::hasTable` and `hasColumn` (166 call sites in `app/`). Fix direction: confirm every environment has the full schema (step 8), then remove the checks; if some must stay, cache the result for the life of the deployment.

#### 1c. Per-IP limiter

`ApiBoundary` allows 300 requests per minute per client IP on every `/api` route, including merchant intake, and keeps the counter in the cache store (the database by default), so each request also costs a cache read and write. 300 per minute is 18,000 per hour per IP. A BIR test or a large merchant sending from a few IPs would be rejected with 429. Needs a product decision: exempt authenticated intake from the IP limit and rely on the per-key limits, or raise the limit for the intake route. Keep a limit on unauthenticated routes, and move the counter to Redis (step 5).

### Steps 1a and 1c: done (2026-10-27)

**What changed**
- `database/migrations/2026_10_27_000001_add_document_number_key.php`: adds a virtual generated column `document_number_key` (`UPPER(TRIM(NULLIF(number,'')))`) with an index to `transaction_receipts` and `business_invoices`. The database derives it, so no writer sets it and no existing row changes. Nothing in the app copies whole receipt or invoice rows, so a generated column cannot break an insert. Down migration drops the index and column.
- `IssuedDocumentLedger::candidates()` and `normalizedCandidates()` take an optional document-number key. When given, each union branch is first narrowed through the index (unlinked transactions by primary key), then the existing normalisation and identity rules run on those few rows. Falls back to the old full ledger if the columns are absent. The column check is made once per process.
- Used by the intake duplicate check (`IntegrationTransactionController::assertNumberAvailable`) and the readiness identity count (`EisDocumentReadiness::inspect`).
- `ApiBoundary`: authenticated merchant intake (`api/integrations/transactions` and sub-paths) no longer spends the shared 300-per-minute-per-IP budget. Only failed credential attempts (401 or 403 responses) count, against a separate `api-ip-auth:` counter with the same 300-per-minute limit, checked before credential lookup as before. All other `/api` routes keep the old limiter unchanged. Per-key limits (`AtomicQuota`) are untouched.
- Tests: new `tests/Feature/IntegrationIntakeScalingTest.php` (5 tests: generated key matches the normalisation; duplicate numbers rejected whatever the case, across receipts and portal invoices; narrowed ledger returns exactly the rows a full scan returns; accepted intake does not spend the IP budget; failed credentials still do and then block with 429). `ApiCustomerReceiptTest` updated to prime the failure counter.
- Test suite: 513 tests; 9 errors, 11 failures, 3 risky. The identical set fails on the unmodified code (all environment limits in the scratch copy: headless Chrome for PDFs, image libraries, public asset folders), so there are no regressions.

**Measured result** (same setup and caveats as step 1; 20,000 receipts in the table)

| Measurement | Before | After |
|-------------|--------|-------|
| One acceptance, in-process | about 4,060 ms | about 128 ms |
| The slow readiness query | 3,849 ms | 2.5 ms |
| Queries per acceptance | 116 | 108 |
| HTTP from one client IP, 8 concurrent | 97% rejected with 429 | 100% accepted (201) |
| Migration on 20,000 existing receipts (MySQL 8.4) | n/a | 0.5 s |

Data integrity after all later runs (13,254 accepted events, including a run that was killed mid-flight): inbox, transactions, receipts and audit events all 13,254; no duplicate event hashes; audit chain intact; no application errors logged.

**New ceilings now visible (all with 12 `php-cgi` workers on one desktop; bottleneck locators, not sizing)**

| Scenario | Accepted per second | Latency |
|----------|---------------------|---------|
| 50 merchants, 8 / 16 / 32 / 64 concurrent | 42 / 53 / 52 / 52 | p50 188 / 285 / 592 / 1,187 ms |
| One merchant, 16 concurrent | 9.6 | p50 1,334 ms, p99 2,610 ms |

- **Global audit lock is the ceiling, about 52/s (about 188,000/hour).** While sampling during a 32-concurrent run, every lock wait seen (208 of 208) was on `eis_audit_head`. Requests were waiting on it in 57% of samples, with an average row-lock wait of 19 ms (max 188 ms). 1 / 52 is about 19 ms, matching. CPU was not saturated: about 3.2 cores of `php-cgi` and 5.3 of `mysqld` out of 16 threads.
- **One merchant tops out near 10/s (about 35,000/hour).** The whole acceptance runs under that merchant's row lock, and a single acceptance takes about 128 ms. If BIR tests with one merchant account, this is the ceiling, well below 278/s.
- Neither number is a production figure. They show which lock binds first and by how much a change moves it.

**Cheap mitigation to consider before the full redesign (not done, needs the compliance owner's nod).** `audit()` is called at line 135, then the webhook enqueue calls run (with their own queries) before the transaction commits, so the audit-head lock is held through all of that. Moving the `audit()` call to the last statement before commit keeps it inside the same transaction (still atomic with acceptance, same chain format, same commit order) but shortens how long the lock is held. It is a smaller change than step 2 and could be measured with the harness first. It does not remove the single global lock.

**Rollout notes**
- Run the migration on a staging copy of the live database first and time it: adding a virtual column and an index is online in MySQL 8 but still reads the table to build the index.
- No worker restart is needed for the new columns: a cached "no" expires after 60 seconds (see 1b), and until then the code falls back to the old, slower, still-correct path.
- Back up before migrating, as the existing deployment workflow requires.
- Behaviour change for compliance owners: the duplicate-number rule is unchanged, but authenticated merchant systems can now exceed 300 requests per minute per IP. They remain bound by per-key limits: 600 per minute and the daily limit (default 50,000 for Live keys, 5,000 for Sandbox). Those key limits are far below 1M/hour (see the next note).

**Per-key limits matter for the 1M/hour test.** `AtomicQuota` caps each key at 600 requests per minute (36,000 per hour) and at its daily limit. 1M per hour needs at least 28 keys at the minute cap, and 24M per day would need about 480 Live keys at the default daily cap. Either the test uses many keys, or limits for the test keys are raised deliberately. This is product policy, so it is not changed here.

**Still open from this pass**
- Equivalence assumptions of the narrowing, to confirm against the live data on staging: blank-numbered rows are skipped by the narrowed lookup (they key on their own id and cannot share a real number); the PHP `trim()` used to build the key also strips tabs and newlines while SQL `TRIM` strips only spaces, as the old code already did.
- A 403 for an inactive business counts as a failed credential attempt, as does any other 401/403 from intake.
- Next biggest cost was step 1b; it is now done (see below).

### Step 1b and 1d: done (2026-10-27)

**1b, schema lookups.** New `App\Support\SchemaMap` memoises `Schema::hasTable()` and `hasColumn()` per process. A table or column that exists stays cached; a "no" is cached for 60 seconds, so running workers pick up a column added by a migration without a restart. It is flushed after every migration run (`AppServiceProvider`) and before every test (`tests/TestCase.php`). Only the acceptance path was converted: `IssuedDocumentLedger`, `MerchantWallet`, `HistoricalBranchEvidence`, `BirCompliance`, `EisReportingPolicy`, `EisDocumentReadiness`, `MerchantWebhooks`. The other ~120 call sites are untouched and can adopt it one at a time. The ledger's own once-per-process column check now uses it too, so the 1a fallback no longer caches a "no" forever (this replaces the "restart PHP workers after deploy" note above).

| Per acceptance, in-process, 20,000+ receipts | Before 1b | After 1b |
|----------------------------------------------|-----------|----------|
| Wall time | about 128 ms | about 59 ms |
| Queries | 108 | 59 |
| Database time | about 99 ms | about 35 ms |
| `information_schema` lookups | about 49 | 0 (warm) |

**1d, audit reorder.** Each acceptance writes two audit entries, not one: `eis.document.prepared`, written inside `EisDocumentReadiness::prepare()` right after the receipt is stored, and `integration.event.accepted`, written by the controller. Either one takes the global `eis_audit_head` lock, and the lock is held until the acceptance commits. The first entry therefore held it through the inbox insert, the second audit write and the webhook enqueue.

- Moving only the controller's call (variant B1) changes nothing: the lock is already held by the earlier entry.
- `prepare()` now takes an optional by-reference argument. When the caller passes a variable it receives the audit entry and writes nothing; every other caller is unchanged. The controller passes it, then writes both entries as its last two statements, in the original order (prepared, then accepted), in the same transaction. The chain format, the set of events and their order are unchanged, and the two entries are still adjacent in the chain. Their `occurred_at` timestamps are a few milliseconds later than before.
- Measured by `loadtest/profile.php` (lock request to the acceptance transaction's commit, median of 40 requests, three interleaved rounds each): A (before) 14.7 ms, B1 14.2 ms, **B2 (both deferred) 9.6 ms**, lower than A in every round. That moves the single-lock ceiling from about 68 to about 105 accepted/s.
- What still sits inside the lock with both deferred: roughly 1.5 ms per audit write (head select, event insert, head update) plus the commit's disk flush and PHP overhead, about 8 ms in all. That is the floor for any design that holds one global row lock through commit. Going lower needs step 2.
- Possible small further saving, not done: write both entries under one lock acquisition instead of two (about 1.5 ms).

**What was not shown.** A concurrent A/B at 32 clients gave 28.1 and 28.5 accepted/s for A and 29.6 and 28.9 for B2, a difference inside the noise. The machine was at 97 to 100% CPU from other programs (a game and an emulator), so both variants were CPU-starved rather than lock-bound; the same code reached about 52/s on a quieter machine earlier the same day. The lock hold time is measured; the end-to-end gain is not demonstrated. Re-run `loadtest/ab.ps1` on an idle machine, or on AWS, before quoting a throughput gain.

**Tests.** `tests/Unit/SchemaMapTest.php` (3 tests: results match the schema, repeat lookups run no queries, a "no" expires and a flush forgets). Added to `IntegrationIntakeScalingTest` (3 tests: both entries are written last, back to back, in order, with a verifying hash chain; an audit failure still rolls back the whole acceptance and the wallet charge; `prepare()` writes its own entry unless asked to defer). Full suite: 519 tests, with the same 20 environment-limited failures as the unmodified code and nothing new.

**For the compliance owner to confirm (1d).** Nothing is lost or reordered, and the entries still commit or roll back with the acceptance. The one visible difference is that `eis.document.prepared` is now recorded after the webhook enqueue instead of before the inbox insert, within the same transaction.

### Step 1d, part two: one lock acquisition (`auditMany`) (2026-10-27)

`BirCompliance::auditMany(array $entries)` appends several entries to the hash chain under one acquisition of the audit-head lock, in the order given: one `SELECT ... FOR UPDATE` on the head, one insert per entry (each linked to the previous), one `UPDATE` of the head to the last hash. `audit()` is unchanged for callers: it now calls `auditMany` with one entry, so single-entry behaviour, the hash input and the order (insert, head update, then the notifications hook) are the same. For a batch, the notifications hook runs only after the head update, so anything it recorded would chain after the batch. `recorded()` ignores both acceptance events and does not write audit entries itself. The acceptance controller now writes `eis.document.prepared` and `integration.event.accepted` in one `auditMany` call as its last statement. The details element of an entry is optional (a first version of the code required it; the new test caught that).

Measured on the same machine, interleaved, after the machine got quieter (about 69% CPU instead of 97 to 100%), so absolute numbers are lower than in the earlier rounds and only the comparisons count:

| Measurement | B2 (two audit calls, last) | C (one `auditMany` call) |
|-------------|----------------------------|--------------------------|
| Audit-head lock hold, median of 40, four rounds | 5.8 ms (range 5.6 to 6.2) | **4.7 ms** (range 4.5 to 4.8), lower in every round |
| Queries per acceptance | 59 | 57 |

| End to end, 50 merchants, 32 clients, two interleaved rounds | A (original placement) | B2 | C |
|--------------------------------------------------------------|------------------------|-----|---|
| Accepted per second | 70.4 | 75.9 | **77.1** |
| p50 latency | about 450 ms | about 418 ms | about 400 ms |

- The gain from A to B2 (+8%) and from A to C (+9.5%) is clear in both rounds. The difference between B2 and C (+1.6%) is inside the run-to-run noise. The hold-time reduction between B2 and C is real (-19%) but is not what limits throughput at this load.
- Final code, standard round: 76.5 and 76.2 accepted/s across many merchants (all 201, no errors), **15 accepted/s for a single merchant** (up from 9.6 and 7.3 in earlier rounds), p50 about 410 ms and 840 ms respectively. Lock waits during the first 32-client run: 743 waits, average 19 ms, max 172 ms; some request was waiting in 47 of 137 samples (34%, against 57 to 93% before this work); every wait was on `eis_audit_head`.
- The single-lock ceiling implied by the hold time is now about 1000 / 4.7, roughly 210 accepted/s. The measured 77/s is below it, so on this setup the 12 PHP workers, CPU and the per-merchant lock also bind. Do not read 210 as a prediction.
- Integrity after every run in this session (36,770 accepted events, including a killed run): inbox, transactions, receipts and accepted-audit events all equal; no duplicate event hashes; audit chain intact.
- Tests: one new test in `IntegrationIntakeScalingTest` (entries chained in order under one head read and one head write, first entry links to the old head, head ends at the last entry, a later single `audit()` continues the chain, empty batch writes nothing); the rollback test now mocks `auditMany`. Full suite: 520 tests; the same 20 environment-limited failures as the unmodified code and nothing new.

**Where this leaves the audit lock.** Inside the lock now: one head select, two event inserts, one head update, and the commit's disk flush, about 4.7 ms on this machine. The remaining cost is dominated by the commit and cannot be cut while the lock is held through commit. The only way below it is step 2 (outbox plus sequencer, or per-merchant chains). The per-merchant ceiling (step 3) is the next limit for a single large merchant.

## 2. Audit write-path redesign (the blocker)

**Today.** `IntegrationTransactionController` opens a DB transaction (line 77), locks the merchant and API key rows (79, 81), then calls `BirCompliance::audit()` (line 135). `audit()` (`app/Services/BirCompliance.php:178-192`) locks the single `eis_audit_head` row, inserts into `eis_audit_events`, updates `last_hash` and calls `NotificationEvents::recorded()`, all while holding that lock until the outer transaction commits. Every accepted transaction in the system therefore serializes on one row.

**Option A (preferred): outbox plus sequencer.**
- Inside the acceptance transaction, insert one row into a new `eis_audit_outbox` table (event, subject, actor, details, a content hash, created time). No shared lock.
- One sequencer worker reads outbox rows in id order, in batches, and appends them to `eis_audit_events` with `previous_hash` and `entry_hash`, updating `eis_audit_head` once per batch.
- `NotificationEvents::recorded()` moves to run after the sequencer commit.
- Chain verification and checkpoints keep working because the chain format is unchanged; only the writer changes.
- Trade-off: the hash chain trails the transaction by the sequencer lag. The outbox row is durable and committed with the transaction, so no event is lost. Compliance must accept "recorded in the outbox at commit, chained within N seconds".

**Option B: per-merchant chains plus periodic global checkpoints.** Removes the global lock entirely but changes the verification model and every audit reader. Larger change; hold in reserve.

**Before building:**
- Get compliance and the accreditation owners to approve the lag model, since the audit log is part of the BIR evidence.
- Read every consumer of `eis_audit_events` and `eis_audit_head` (verification commands, checkpoint export, reports) so none assumes synchronous chaining.
- Add tests: ordering, no gaps or duplicates under concurrent writers, sequencer crash and restart, chain verification after a batch.
- Sequencer must be a single active instance (leader lock) with a standby.

## 3. Per-merchant and per-key lock review

- The acceptance transaction locks `merchants` and `merchant_api_keys` rows; `MerchantWallet.php` locks merchant and wallet rows (lines 32-36, 71).
- A single large merchant sending most of the load will serialize on its own rows even after step 2.
- Use the step 1 single-merchant scenario to see whether this is a real ceiling. If it is: shorten the transaction, move usage counters to Redis with periodic flush, and keep only the wallet ledger write under lock.
- Do not weaken idempotency. Intake must still return the stored result for a repeated key.

### Step 3 findings so far (2026-10-27, analysis; no lock change made)

**The single-merchant ceiling is real.** On the final code, one merchant at 16 concurrent clients managed about 15 accepted/s against about 76/s across 50 merchants. The whole acceptance runs under that merchant's row lock, so a merchant's ceiling is about 1 / (transaction time).

**Measured window.** Profiling one acceptance from the first `merchants ... FOR UPDATE` to the acceptance commit (`PROFILE_LOCK=merchants php loadtest/profile.php ...`): 42.9 ms, made of 33.4 ms in 49 statements and 9.4 ms of application code plus the commit flush. These figures come from a freshly restarted MySQL, so some statements include cold-cache reads (see below); a warm re-run was not obtained before this note was written (the shell tool was failing), so treat 42.9 ms as an upper bound, not the steady state.

**Statements that looked expensive, and what they turned out to be.**
- A wallet-ledger read of 6.3 ms (`merchant_wallet_entries ... ORDER BY sequence DESC LIMIT 1`). Checked with `EXPLAIN ANALYZE`: it uses the `(merchant_id, sequence)` unique index and takes 0.3 ms; the same query took 0.6 to 0.8 ms for warm merchants and 5.6 to 7.9 ms for merchants whose pages were not yet in memory. Cold cache, not a code problem. Do not optimise it.
- The duplicate-number `exists` at 4.4 ms and the `merchant_transactions` insert at 3.6 ms (that table has 16 indexes, `transaction_receipts` has 12). Not yet re-measured warm; they may also be partly cold. Candidates for a real saving only if they stay high when warm.
- `merchants ... FOR UPDATE` is re-issued four times inside the transaction (acceptance, wallet `charge`, wallet `locked` twice). Each costs about 0.3 to 0.5 ms; a small, low-risk saving if the wallet code remembers it already holds the lock.

**Why the lock should not simply be narrowed.** `merchants.id FOR UPDATE` is the platform's per-tenant mutex, taken at about 40 call sites: branches, customers, portal invoices, wallets, document governance, API-key changes, source profiles, team, expenses, business profile, brand and the intake paths themselves. Acceptance depends on it for:
1. Wallet serialisation: `MerchantWallet::locked()` and `charge()` take the merchant row before the wallet row, and the wallet ledger is hash-chained.
2. Idempotency and document-number checks: the number check (`assertNumberAvailable`) and the duplicate-event check read first and insert later. The unique indexes on `integration_inbox` (`namespace_hash`, `event_hash`) are only a second guard; `transaction_receipts` has no unique index on the document number, so the lock is the only thing stopping two concurrent events from recording the same number.
3. Seeing other features' changes: the acceptance re-reads the API key and the merchant status and rejects with "API key policy changed before acceptance". That check is only sound because key edits (`MerchantApiKeyController`), suspension and branch changes take the same lock.
4. Snapshot ordering: under MySQL's default REPEATABLE READ, the first plain read after the lock sees everything committed before the lock was granted. Any reordering that reads before locking would pin an older snapshot and make those checks stale.

Narrowing the lock in the intake path (shared lock, late lock, or per-wallet lock) would remove mutual exclusion with every one of those flows. It needs an agreed design, not a local edit.

**Options, lowest risk first.**
1. Leave the lock alone; accept about 1 / (transaction time) per merchant. With the transaction at roughly 30 to 43 ms, that is about 25 to 35 accepted/s per merchant, and the platform total scales with the number of merchants. Sufficient if the BIR test spreads load over many merchants.
2. Cut time inside the lock without changing lock semantics: remember the already-held merchant lock inside the wallet code, avoid the second `locked()` ledger read, and check the remaining expensive statements warm. Expected gain is modest (a few ms of about 30).
3. Introduce explicit tenant lock domains: an agreed split of what must be serialised per tenant (wallet and numbering) from what only needs a shared lock (most other flows), with the acceptance path taking the exclusive wallet lock late. High value for a single hot merchant (the window could shrink to the wallet charge, inbox insert, audit and commit, roughly 8 to 10 ms), but it changes concurrency behaviour in about 40 places and needs a MySQL concurrency test suite first (duplicate events racing, duplicate numbers racing, wallet balance and ledger chain under concurrent charges, key revocation racing acceptance).
4. Move to per-wallet sub-accounts or pre-allocated number blocks so that one merchant can be served by several workers without a shared row. Largest change; only if a single merchant must exceed what options 2 and 3 give.

**Status of the decision (2026-10-27).** The product owner does not yet know whether the BIR test uses one merchant account or many. Until that is known: option 1 stands, no lock change is made, and the way to keep option 3 cheap is to have the concurrency checks ready. Also still to ask the BIR/accreditation contact: how many taxpayer accounts will the test use, and is the load sustained or in bursts?

**Concurrency baseline (run 2026-10-27 on the current code, real nginx plus php-cgi, throwaway MySQL).** `loadtest/race.mjs` (with `race-setup.php` and `race-verify.php`) races requests against fresh merchants and reads the database back:

| Scenario | Expected | Result |
|----------|----------|--------|
| R1: the same event sent 20 times at once | one 201, nineteen 200 replays marked `duplicate`, one inbox row, one fee | **Found a bug, fixed** (below); then passes |
| R2: 20 different events claiming one document number | one 201, nineteen 409, the number exists once, one fee | Pass |
| R3: one event id with 10 different amounts | one 201, nine 409, one inbox row, one fee | Pass |
| R4: 200 distinct events on one merchant, 40 at a time | all 201; one fee entry each; wallet hash chain valid and gapless; balance = funding - fees; audit chain intact | Pass |
| R5: wallet funded for exactly 10 sales, 30 events at once | exactly 10 accepted, 20 refused with 402, wallet 0 and never negative, refused events leave no records | Pass |

After the fix, two full runs passed every check, the application logged no errors, and the database stayed consistent (inbox, transactions, receipts and accepted-audit events equal, no duplicate event hashes, audit chain intact). So today's per-merchant lock does deliver the guarantees listed above under racing requests; any narrowing of it must keep passing this suite. Not covered yet: an API key revoked or edited while acceptances are in flight, a merchant suspended mid-flight, and portal invoice creation racing an API event with the same number.

**Bug found by the race test: idempotent retries were answered with 302, not 200.** The controller sent a `Location` header on every success. PHP's web SAPIs (CGI, FPM, Apache) rewrite any status other than 201 or 3xx to 302 whenever a `Location` header is present, so in a real deployment every replay (`duplicate=true`, documented as 200) came back as `302 Found` with the JSON body. Clients that follow the redirect would re-request the status URL with a GET, and many drop credentials on redirects. Laravel's test client never goes through that layer, so no existing test could see it. Fixed in `IntegrationTransactionController::store`: 201 keeps `Location`; a replay returns the same URL in `Content-Location` and no `Location`. New test `test_a_replay_is_a_plain_200_without_a_location_header` pins the header choice; the live race run confirms the 200s. Worth telling integrators that replays were 302 before this fix, and checking whether any merchant system already special-cases a 302.

**Decision needed from the owners.** Will the BIR test, and the largest real merchants, send their load through one merchant account or many? If many, option 1 or 2 is enough and step 3 can close. If one account must carry hundreds of transactions per second, option 3 is required and should start with the concurrency test suite.

## 4. Uploads to S3

- Local-disk paths today: `storage/app/private` (encrypted verification documents, avatars, audit checkpoints), `storage/app/verification-uploads` (temporary 1 MiB chunks) and `public/uploads/*`.
- Move to Laravel's S3 filesystem disk. Keep the encryption of verification documents (encrypt before upload). Chunked uploads need a shared store, so use S3 multipart or keep chunks in S3 under a lifecycle rule.
- Audit checkpoints should go to a separate bucket with Object Lock and separate credentials, as the Backup and DR plan requires.
- Migration of existing files: a one-off copy script, run after a backup, with a checksum comparison before cutover.
- Object backup cadence of at most 5 minutes (15-minute RPO) is handled by S3 versioning and cross-region replication to Hong Kong, once availability of those features in `ap-east-1` is confirmed.

## 5. Redis and queues

- Set `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` to Redis (or SQS for queues). Mostly configuration, plus load-testing the lock semantics.
- Scheduler commands that act as workers (`notifications:work`, `merchant-webhooks:work --limit=100 --max-seconds=45`, `eis:prepare-pending`) run once a minute. At 100 webhooks a minute that is about 6,000 deliveries an hour, far below 1M. They need to become supervised long-running workers, scaled by queue depth.
- Rate limiting (`ScopedThrottleRequests`) should use Redis so limits are shared across web nodes.
- Run the scheduler on exactly one node (`onOneServer` with a Redis lock).

## 6. Read replica

- Dashboard and report queries (`DashboardController.php`, 2,458 lines, with heavy UNION and window functions) should read from a replica.
- Add a `read` connection in `config/database.php`, route read-only endpoints to it, and accept replica lag for dashboards. Keep anything that must be current (balances, idempotency lookups) on the writer.
- Longer term, split `DashboardController` into query classes so each can be cached and tested.

## 7. Deploy hygiene

- Delete `public/nuers-db-check.php` and its copy in `deployment/`.
- Remove the real DB host and user from `deployment/nuers-net-mysql.env.example`; rotate the credentials if that host was ever live.
- Add a real `.env.example`.
- Stop committing `dist/` (68 MB) and `public/build` (5.6 MB). Build in CI. Drop the static `dist/` path or document it as unsupported.
- Closure routes (6 of them: `/legal/policies`, `/health`, `/verify-receipt/{n}`, the two `/developer/*` file routes and the business-logo alias) were converted to controllers (`PublicInfoController`, `BusinessLogoController`). Correction: they did **not** block `route:cache` as earlier notes claimed; it was tested on the original code and succeeds. The conversion is tidying only. Run `config:cache`, `route:cache`, `view:cache` and `event:cache` on deploy.
- Guard the seeder: `DatabaseSeeder` creates accounts with password `123456`. It must refuse to run when `APP_ENV=production`.
- CI: separate jobs for `php artisan test`, `tsc` and `node --test tests/js/`, plus `security:deployment-check` against the production configuration.
- Investigate the 34,827 tracked files: break the count down by folder and remove whatever is generated.

### Step 7 status (branch `deploy-hygiene`, 2026-10-27)

**Done**
- Deleted `public/nuers-db-check.php` and `deployment/nuers-db-check.php` (hardcoded key plus a database probe). Removing it from the repo does not remove it from a running server: **delete it from every live host by hand**, and treat the old key (`nuers-db-20260603`) as exposed because it remains in git history.
- `DatabaseSeeder` now throws when `APP_ENV=production`, before touching the database. It creates demo admin, BIR and merchant accounts with password `123456` and deletes dashboard rows. Local and test use is unchanged.
- Added `.env.example`: every setting the app reads, placeholders only, production-safe defaults (`APP_ENV=production`, `APP_DEBUG=false`, notifications off, `SESSION_SECURE_COOKIE=true`), notes on the keys that must never be regenerated, and the Redis and S3 settings to fill in at launch.
- Added a test-only `APP_KEY` to `phpunit.xml`, so the suite runs without a `.env` (an `APP_KEY` already in the environment still wins). Before this, the suite failed with 181 errors on a fresh checkout.
- Scrubbed the live database host, name and user from `deployment/nuers-net-mysql.env.example` and `deployment/NUERS_LIVE_SERVER_ERROR_FIX.md`. They remain in git history: **rotate the database credentials and restrict the database host's firewall** (`194.233.94.62`, user `nuers`).
- Converted the six closure routes to `PublicInfoController` and `BusinessLogoController` (tidying only, see the correction above). `route:cache` succeeds on both the old and the new routes.
- Tests: `tests/Feature/DeployHygieneTest.php` (5 tests: seeder refuses in production; probe script absent; `.env.example` has blank secrets and safe defaults; no application route is a closure; unknown receipt verification is a clean 404). The converted routes were already covered by existing tests. Full suite: 526 tests, the same 20 environment-limited failures as the unmodified code, nothing new.

**Not done, and why**
- **Untracking `vendor/`, `dist/` and `public/build`.** `.gitignore` already lists `vendor/`, `node_modules/` and the build output, yet they are tracked, so the intent is clear. But the current host appears to deploy by pulling the repository (the notes in `deployment/` and `CLAUDE.md` describe `dist/` as committed "for hosting"), so removing them could break the live deploy. Needs a decision on how the live host gets its dependencies (a CI build artifact, or `composer install` and `npm run build` on the server) before the files are removed. Note the tracked `vendor/` is already incomplete and fails on PHP 8.5 (missing `bootstrap85.php`), so it cannot be relied on as is.
- **CI.** A workflow for `php artisan test`, `tsc`, `node --test tests/js/` and `security:deployment-check` was not added: it could not be run here, and the suite has 20 failures that need headless Chrome, image libraries and the public asset folders, so a first CI run would fail until those are provided.
- **Live-host checks.** `deployment/nuers-net-mysql.env.example` sets `CACHE_STORE=file`, `SESSION_DRIVER=file` and `QUEUE_CONNECTION=sync`. If the live `.env` follows it, queued work (webhook delivery, notifications) runs inside the request. Confirm the live values.
- **Tracked-file breakdown (34,827 files).** Not done; mostly `vendor/`, `node_modules`-adjacent and `dist/`. Falls out of the decision above.

## 8. Hardening reviews

- **`TableController`** (`app/Http/Controllers/Api/TableController.php`): the generic `/api/tables/*` endpoint relies on an allowlist plus hand-written per-role and per-column checks (lines 35-84). Review each table and role combination, add tests that assert denied access for every role, and plan to replace the generic endpoint with explicit endpoints for sensitive tables.
- **Schema drift**: 166 `Schema::hasTable` and `hasColumn` calls suggest environments differ. Compare the live schema with migrations, bring every environment to the same state, then remove the checks (they cost queries on hot paths).
- **Controller logic**: 1,010 `DB::` calls in controllers against 321 in services. Move logic into services as each controller is touched for the steps above; do not do a standalone rewrite.

## 9. Launch infrastructure (after step 1)

Rewrite `deployment/aws/stage1-setup.md` or add a launch document. Target shape:
- Cloudflare in front; static assets from S3 behind the same origin.
- Application load balancer and auto-scaled web nodes (initial guess 20-40+ cores for 2,000/s, to be replaced by measured figures).
- Aurora MySQL (writer plus replica) or large RDS, Multi-AZ.
- Redis, SQS, and S3 for uploads and backups.
- Worker fleet for webhooks, notifications and the audit sequencer.
- AWS Hong Kong as the backup and recovery region.
- Regional disclosure to BIR: hosting providers and data location changes are material changes needing prior approval (RMC 110-2026).

## Risks and open items

- The audit redesign changes evidence that BIR may inspect. Do not ship it without written compliance approval.
- The ~100/s ceiling and the 20-40+ core guess are unmeasured.
- BIR's test shape is unconfirmed.
- Hong Kong (`ap-east-1`) is an opt-in region; confirm RDS cross-region backup, S3 replication and Object Lock there.
- Only a local SQLite recovery drill has been done. Run a real MySQL/RDS restore drill.
- Cross-border hosting needs lawyer or compliance-advisor sign-off before real taxpayer data goes in.
- Budget and AWS account ownership are still open.
