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
| 5 | Background workers (Redis is optional) | **Done 2026-10-27 (see step 5)**: the app uses no Laravel queue; fixed the every-minute full-ledger scan (4.3 s to 0.65 s) and made the webhook claim parallel-safe (84 to 733 claims/s, original crashed with 8+ workers) | No |
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

### Step 4 status: code ready, not switched on (branch `s3-uploads`, 2026-11-03)

**Why it matters.** Several web servers cannot share one server's disk. With the old code, a document uploaded through server A returned 409 "unavailable" when the reviewer's request landed on server B; a merchant logo uploaded on A was a 404 on B; the chunked upload (parts arrive as separate requests) failed whenever two parts hit different servers.

**What wrote to local disk (full inventory).**

| Data | Old location | Shared store needed? |
|---|---|---|
| Verification documents (encrypted) | `storage/app/private/merchant-verification/` | Yes |
| Branch evidence (encrypted) | `.../branch-evidence/` | Yes |
| Upload chunks (encrypted, temporary) | `.../verification-uploads/` | Yes (parts arrive on different requests) |
| Admin avatars | `.../admin-avatars/` | Yes |
| Client avatars, business and invoice logos | `public/uploads/*` (served by the web server) | Yes, plus a way to serve them |
| PDF rendering and report export scratch folders | `storage/app/private/pdf-*`, `report-*` | No: created and deleted inside one request |
| Sessions, cache | database tables (not files) | Already shared |
| Audit checkpoints | `eis:audit-checkpoint` writes signed files to `storage/app/private/audit-checkpoints` (a manual command, not scheduled; correction to an earlier version of this table) | Yes, and they must go to a separate Object-Lock store (not changed in step 4) |

**What changed (defaults unchanged, so deploying this alters nothing until you set the two env values).**
- `App\Support\UploadDisk` plus `filesystems.private_disk` / `filesystems.image_disk`, set by `NUERS_PRIVATE_DISK` (default `local`) and `NUERS_IMAGE_DISK` (default `uploads_public`, the old `public/uploads` folder, same files and URLs). Disks `s3_private` and `s3_images` are defined for the object store; credentials come from the instance role when no key is set.
- `PrivateDocumentStream` no longer opens a local path or seeks inside the stored file: it frames the encrypted document in a temporary stream and writes the finished object in one call (an object store never shows a half-written document); the legacy-format read re-opens the stream instead of rewinding it. The encryption format is unchanged, so existing documents read as before.
- Chunk upload, document cleanup, branch evidence and the pruning command use the private disk. Admin avatars are written and read through it. `SafeImage::storeOn()` re-encodes to PNG and writes to any disk; client avatars and logos use the image disk.
- `UploadedImageController` replaces `BusinessLogoController` and serves `business-logos`, `invoice-logos`, `client-avatars` (UUID `.png` names only) at `/public/uploads/{folder}/{file}` and `/uploads/{folder}/{file}` with a one-year immutable cache header, so Cloudflare or CloudFront can cache them. With the default local disk the web server still serves existing files itself.
- `php artisan uploads:copy {private|images}`: copies existing files to the new disk, verifies each by hashing the copy, skips files already present and identical, and exits non-zero on any mismatch. It never deletes or changes the source and has `--dry-run`. Upload chunks are not copied (temporary, safe to abandon).
- `league/flysystem-aws-s3-v3` added to `composer.json` and `composer.lock` (also moves guzzle, psr7, promises, symfony/process, polyfill-mbstring by patch versions, a side effect of resolving it).
- `.env.example` documents the new settings.

**Tested.** 9 new tests (`tests/Feature/ObjectStoreUploadsTest.php`) run the document, legacy-document, tamper, image-serving and copy-command paths against a test adapter that has no local directory and no seeking (`tests/Support/MemoryAdapter.php`), plus a guard test that fails if `Storage::disk('local')`, `public_path('uploads')` or `$disk->path()` come back into application code. The existing document, branch and logo tests pass unchanged against the default disks.

**Not tested, because there is no AWS account here.** Real S3: bucket policy, IAM permissions, latency of one PUT per 1 MiB part, behaviour of `deleteDirectory` on a large prefix, and `ap-east-1`. Rehearse in staging before the cutover.

**Cutover runbook (do not run until the AWS account and buckets exist).**
1. Back up the database and `storage/app/private` and `public/uploads`.
2. Create two private buckets (Block Public Access on, versioning on, default encryption on). Suggested lifecycle rule: expire `verification-uploads/` after 2 days as a safety net. Give the web and worker role read, write and delete on those buckets only.
3. Deploy this branch and run `composer install` (the tracked `vendor/` does not contain the S3 driver; see the open vendor question).
4. Set `NUERS_PRIVATE_DISK=s3_private`, `NUERS_IMAGE_DISK=s3_images`, the two bucket names and the region, but do not reload PHP yet.
5. `php artisan uploads:copy private --dry-run`, then without `--dry-run`, then the same for `images`. Expect exit code 0 and a "failed" count of 0.
6. Reload PHP, then run both copy commands again (idempotent) to pick up files written during the switch.
7. Open a known document as an admin and a known logo as a merchant. Keep the local files for at least a week.

**Not done.**
- Audit checkpoints and the separate Object-Lock bucket. Correction: an earlier version of this note said nothing writes checkpoints. `eis:audit-checkpoint` does (in `routes/console.php`): it signs the chain head and saves it to local `storage/app/private/audit-checkpoints`, and it is not scheduled. It was not moved in step 4, and it must not simply follow `NUERS_PRIVATE_DISK`: the point of a checkpoint is that production credentials cannot rewrite it. It needs its own disk (own bucket, Object Lock, separate write-only credentials) and a schedule. The `UploadDisk` guard test does not catch it because it writes with `file_put_contents`. Proposed as a small follow-up change; the Object Lock bucket itself is infrastructure.
- Cross-region replication to Hong Kong and the 15-minute object RPO: infrastructure, depends on `ap-east-1` features.
- The renderer bundle `bootstrap/ssr/document-renderer.mjs` and the Chrome binary for PDFs must exist on every server; that is build and image work, not storage.
- Serving through the application costs one PHP request per uncached image; Cloudflare caching in front is assumed (the cache header allows it).
- A pre-existing advisory in `symfony/mime` (CVE-2026-45070) showed in `composer audit`; fixed separately on branch `dependency-patches` (7.4.19 to 7.4.22).

## 5. Background workers (and, much less urgently, Redis)

**Original plan, as first written (kept for the record):** set `CACHE_STORE`, `SESSION_DRIVER` and `QUEUE_CONNECTION` to Redis or SQS; make the scheduled workers long-running; share the rate limiter across nodes; run the scheduler on one node.

### Step 5 findings and changes (branch `scale-workers`, 2026-10-27)

**Corrections to the original plan.**
- **The application uses no Laravel queue.** There are no jobs, no `dispatch()` and no `ShouldQueue`. Its "queues" are database outbox tables (`merchant_webhook_deliveries`, `webhook_deliveries`, `eis_delivery_jobs`, notifications) handled by scheduled artisan commands. So `QUEUE_CONNECTION=redis` or SQS would change nothing, and the two `queue:work` workers in `deployment/security/supervisor-reference.conf` have nothing to process. The real work is making those outbox workers scale.
- **Redis is not the intake bottleneck.** An accepted transaction touches the cache table once (a 0.64 ms read of the failure counter, out of 26.6 ms of database time), so Redis would save under 2% there. The cache writes I measured earlier (`insert ignore`, `delete ... expiration`, about 4 queries per call) belong to the portal routes' per-IP limiter, not to merchant intake. Redis remains a sensible choice for portal traffic and for taking those writes off the primary database, but it is not needed for the 1M/hour intake goal, and nothing here depends on it. `.env.example` documents the settings. For a Redis client in production, install the `php-redis` extension; the repo does not require `predis`.

**1. `eis:prepare-pending` no longer scans the whole ledger every minute.** It rebuilt the full document union and anti-joined the outbox on every run: 4.3 s per run (3.2 s of scanning, 1.1 s artisan boot) at 37,000 documents, growing linearly with every document ever issued, so after a few days at launch volume it would run continuously on the primary. Acceptance and invoice issuance already prepare the outbox record in their own transaction, so a document only lacks one after a failure or for historical rows. Now:
- the every-minute run is a direct anti-join over documents created in the last 72 hours (`--since-hours`), using the outbox's existing unique index and a new `created_at` index on `transaction_receipts` and `business_invoices` (migration `2026_10_27_000002_index_document_created_at.php`, 0.9 s on 37,000 rows including boot);
- a daily `--all` sweep at 03:30 covers everything older;
- eligible documents are unchanged (every receipt; every non-draft invoice), and a test proves the full sweep selects exactly the documents the old ledger-based scan selected.
- Measured: about 0.65 s per run, which is the artisan boot, so the scan itself is now negligible and no longer grows with the table.

**2. The merchant webhook claim could not run in parallel, and cost grew with the backlog.** `MerchantWebhooks::runOne` claimed work with one query over three states ordered by time. MySQL cannot satisfy that order from the `(state, available_at)` index, so it sorts, and a sort reads and locks every due row before choosing one. Consequences, measured with `loadtest/claimrace.php` on MySQL 8.4 (2,000 deliveries; each worker completes a delivery after claiming it):

| | 1 worker | 8 workers | 16 workers |
|---|---|---|---|
| Original claim | 84 claims/s | 95/s, **7 of 8 workers crashed** | 94/s, **15 of 16 crashed** |
| New claim | 110 claims/s | **538/s**, no crashes | **733/s**, no crashes |

The crashes were `Deadlock found when trying to get lock` on the claim's own `UPDATE` (gap locks in the state index under REPEATABLE READ). It never showed in production because the scheduler runs one worker at a time (`withoutOverlapping`). Changes: the claim is its own method (`MerchantWebhooks::claim()`); it probes each state separately in index order (so it reads and locks only the row it returns) and leases the oldest of the at most three candidates; it uses `FOR UPDATE SKIP LOCKED` and READ COMMITTED for the claim transaction. A new long-running mode, `merchant-webhooks:work --watch --max-runtime=3600 --idle-sleep=1`, lets a process manager run several workers; `deployment/security/supervisor-reference.conf` has a `nuers-merchant-webhooks` program (4 workers, `autostart=false` until egress is approved). The per-minute scheduled run stays as a fallback and does not conflict. Every correctness check passed for every size: each delivery leased exactly once, each with its own token, none lost, nothing left due.

Throughput note: these are claim-rate figures against a local database. Actual delivery rate is bounded by receiver latency (about 1 per receiver round trip per worker), so size the worker count from the backlog: at 278 deliveries/s and 300 ms per delivery, about 85 concurrent workers would be needed if every sale triggered a callback.

**Tests.** `MerchantWebhookClaimTest` (3: oldest-first across states, expired leases reclaimed and live leases left alone, nothing due returns null, watch mode stops at `--max-runtime`), `ScheduledJobsTest` (5: window, `--all`, `--since-hours`, `--limit`, equivalence with the old selection, no-op). The existing webhook tests, which run real deliveries through the new claim, pass. Concurrency is verified by `loadtest/claimrace.php`, which needs MySQL.

**Not done.**
- `SignedWebhook`, `EisDelivery` and `PlatformNotifications` claim with the same single sorted query and carry the same cost-with-backlog behaviour. `webhooks:work` and `eis:work` are not scheduled, and EIS delivery is blocked by design, so they matter only when enabled; the notification worker is one process. Apply the same pattern before running them in parallel.
- `merchant_api_request_logs` and the key's `last_used_at` are written in a third transaction after every accepted request that locks the merchant row again (about 5 ms under the merchant lock). Folded into step 3 (the per-merchant ceiling).
- `AtomicQuota` costs two row-locked upserts and a commit per request before the main transaction (about 4 to 5 ms). Also step 3.
- Running the scheduler on exactly one node (`onOneServer`) needs a shared cache lock: with the database cache that works already; note it when adding a second app node.

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
### Step 8a: `TableController` review (branch `table-endpoint-review`, 2026-11-03)

Method: read the controller, then probed it with a throwaway test per attack (admin, super_admin, client, merchant, BIR sessions on an in-memory schema). The frontend uses it only through `src/lib/supabase.ts`; the `admin/users`, `admin/client-accounts`, `admin/merchants`, `admin/notifications` pages are the writers.

**Confirmed in the old code (each reproduced before fixing):**

| # | Finding | Severity |
|---|---|---|
| 1 | A plain `admin` could set their own profile role to `super_admin` (HTTP 200). | High: privilege escalation |
| 2 | An `admin` could change or demote any `super_admin` profile. | High |
| 3 | `PATCH /api/tables/profiles` with no filter changed every profile in one call (7 of 7 rows, including all super admins). Same shape for `client_accounts`. | High: one request, platform-wide |
| 4 | An admin could change `profiles.email` (or `id`) without touching `users.email`, leaving the two identities out of step. | Medium |
| 5 | Merchants could read, filter and sort by internal columns on their own registry row: `notes`, `compliance_score`, `last_audit_date`, `next_audit_date`. Customers could read `external_payload`, `source_api_key_id`, `source_request_hash` on transactions matched to their TIN. | Medium: internal data exposure |
| 6 | Malformed `values` (a string, or a non-object element) returned HTTP 500. | Low |
| 7 | The `account.table.updated` audit entry recorded field names and a count, not which records changed or the new role. | Medium: weak evidence |
| 8 | `store()`, `destroyProfiles()` and `destroyClientAccounts()` were unreachable (DELETE is refused earlier, POST was never used by the UI) but kept destructive code, including deleting users, one removed guard away from live. | Low: latent |

Checked and found sound: unauthenticated access (all tables, all verbs: 401); cross-tenant reads (merchant A cannot see merchant B; a client sees only their own account and profile); client and merchant role escalation (refused); financial tables (read-only, refused with 409); the filter and sort allowlist (columns come from the table schema, so no SQL injection through column names); the `or` filter (wrapped, so it cannot escape the tenant scope).

**Changes made:**
- POST is refused (409) for every table: nothing in the product creates records through this endpoint. Removed the dead create and delete code paths.
- Only a `super_admin` can grant `super_admin`, or change a profile that currently is one.
- Admin changes to `profiles` and `client_accounts` must name their records (`eq`, `in` or `or`); an unfiltered change is a 422. Non-admin changes stay scoped to the caller's own row, so the portal's "save my profile" call is unaffected.
- `id` and `email` on a profile cannot be changed through this endpoint. Sending the current value is still accepted (the admin Users screen does).
- `values` must be an object (422 otherwise).
- Merchants no longer receive, or can filter/sort by, the four internal registry columns. Merchants and customers no longer receive the three transaction-source columns. Admin, super admin and BIR are unchanged. No frontend page outside `pages/admin` reads these columns through this endpoint.
- The audit entry now carries `target_ids` (first 50) and `role_to`.
- 9 tests in `tests/Feature/TableEndpointSecurityTest.php` (145 assertions): every table and verb unauthenticated; POST and DELETE for every role and table; super-admin protection; unfiltered bulk changes; email and id; malformed values; internal columns and tenant isolation for merchants; client isolation and field limits; financial records.

**Not changed, needs a decision or a separate piece of work:**
- `admin/users` sends `tin` on every save, which the endpoint has always refused with 403 (TIN changes belong to the verified identity workflow), so saving a user from that screen fails today. Either stop sending `tin` from the UI or add an explicit admin endpoint.
- `admin/merchants` still calls `update` and `delete` on `merchants`, which the endpoint refuses with 409 ("use the audited registry workflow"). Those buttons need to be pointed at the registry workflow or removed. Not a security issue; a functional gap.
- `ApiBoundary` and `MerchantTeamAccess` call `json_decode` on `request->query('eq')`; a bracketed array parameter (`eq[id]=1`) would be a type error for a privileged session when MFA enforcement is on. Not exploitable; worth guarding.
- Longer term the plan stands: explicit endpoints for sensitive tables and retire the generic one. This step makes the generic one safe to leave in place until then.
- Whether the live `profiles` table already contains a role value or `profiles.email` that differs from `users.email`: check the live data before assuming the two are in step.

- **Schema drift**: see "Step 8b" below.
### Step 8b: schema drift (branch `schema-drift`, 2026-11-03)

**The question.** The code asks "does this table or column exist?" 183 times in 38 files (166 with a fixed name, 17 with a computed one). Each is a place written to survive a database that is behind the migrations. If the live database has run every migration, the checks are dead weight; if it has not, they are what keeps those pages working. Nobody could tell which from the code.

**What the code says (measured, not guessed).** I built the full schema by running all 74 migrations on a clean database and checked every fixed-name guard against it:
- 165 of 166 guards find exactly what they look for. So against a database that has run every migration, they never fire.
- 1 guard looks for a table no migration creates: `transaction_receipt_items` (in `TenantPortability` and `LiveMonitoringController`). Nothing in the repository defines it. It can only be true on a database that has that table from an older schema or a hand import. It is the one place where "live may differ" is already visible in code.
- 17 guards use a computed name (lists of tables looped over) and cannot be checked statically.
- The reverse check (tables a migration does not create) finds none on a migrated database.

**What I built.**
- `php artisan schema:drift` (read-only; safe on the live database or a copy of it): lists migrations not yet run, migrations recorded as run with no file in this checkout, the schema checks whose target is missing here, and tables in the database that no migration creates. Exit code 1 if anything is found; `--json` for a machine-readable report. It uses `SchemaGuardScanner`, which reads the source for the checks.
- `SchemaDriftTest` (5 tests) pins the finding above: a new check that refers to something no migration creates fails the test, as does a table made outside migrations.
- All 165 raw `Schema::hasTable/hasColumn` calls in controllers, services, the User model and `routes/console.php` now go through `SchemaMap`, the cached lookup the intake path already uses (positives remembered, negatives re-checked after 60 s, flushed after migrations and in tests). Previously every one of them was a separate catalogue query on every request (about 1.5 ms each on MySQL); the dashboard, transaction and receipt controllers had 11 to 22 each. Behaviour is the same: a missing table still takes the fallback path. A test fails if a raw call is added again.

**What I did not do, deliberately.** I did not delete the checks. Removing them is only safe once the live database is known to be fully migrated, and I cannot see the live database. The local workflow notes show it was behind by up to 12 migrations at one point (15 Oct 2026), so that is not a theoretical risk.

**To close this out (needs access to a copy of the live database).**
1. Take the usual backup and restore it to a staging database (or point at a read-only replica).
2. Run `php artisan schema:drift` against it. Expect either "No drift" apart from `transaction_receipt_items`, or a list.
3. If migrations are pending, rehearse and apply them on staging first (the migrations added by this effort, `document_number_key` and the `created_at` indexes from steps 1 and 5, will show as pending until applied).
4. Once staging and live both report no drift, remove the checks in a follow-up change, file by file, starting with the files that have the most (`MerchantTransactionController`, `CitizenApprovalController`, `MerchantReceiptController`, `MerchantApiKeyController`, `DashboardController`). Decide what to do about `transaction_receipt_items`: if live has no such table, delete both guards; if it does, write the migration that adopts it.

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
