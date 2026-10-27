# Load-test harness

Measures the Live merchant intake path (`POST /api/integrations/transactions`) against a throwaway MySQL. Nothing here talks to `nuers.net` or any real database. Every script that touches data refuses to run unless `NUERS_LOADTEST=1`, `DB_HOST` is local and the database name contains `load`.

## Files

| File | Purpose |
|------|---------|
| `seed.php` | Creates synthetic merchants, funded wallets, Live API keys and source profiles; writes the keys to a JSON file |
| `serve.ps1` | Starts nginx plus N `php-cgi` workers (Windows has no php-fpm) on port 8090, or stops them |
| `run.mjs` | Node load driver: closed loop (find the ceiling) or open loop at a target rate; many merchants or one; optional spread of client IPs |
| `verify.php` | After a run: inbox, transaction, receipt and audit counts agree, no duplicate event hashes, audit hash chain intact |
| `profile.php` | Runs the acceptance path in-process: queries per request, DB time, slowest query shapes, `EXPLAIN ANALYZE` of the slowest |
| `dupcheck.php` | Times the ledger duplicate checks at the current table size against a direct indexed lookup |
| `grow.php` | Clones receipt rows to reach a target table size, to time scaling |
| `ab.ps1` | One compact measurement round (starts the server, runs fixed scenarios, prints a table, stops it) for before/after comparisons |
| `race.mjs`, `race-setup.php`, `race-verify.php` | Concurrency checks for the Live intake path: identical event x20 at once, 20 events claiming one document number, one event id with 10 different amounts, 200 distinct events on one merchant (wallet ledger exactness), and a wallet funded for exactly 10 sales receiving 30 at once. Each creates a fresh merchant, races the requests, then reads the database back and prints PASS/FAIL per invariant. Run it before and after any change to the per-merchant lock |
| `lockwatch.php` | Samples InnoDB lock waits during a run and tallies which table and index requests wait on |

## How the 2026-10-27 baseline was run

1. Start a separate MySQL 8.4 on port 3390 with its own data directory. Laragon's MySQL on 3306 was left alone.
2. Work from a copy of the repo outside the working tree. The tracked `vendor/` is incomplete (it lacks `symfony/polyfill-intl-grapheme/bootstrap85.php`, needs PHP 8.4.1 or newer, and breaks on PHP 8.5), so run `composer install --no-dev --no-scripts` in the copy.
3. There is no `.env`. Pass the settings as environment variables: `APP_KEY`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3390`, `DB_DATABASE=nuers_load`, `DB_USERNAME=root`, `DB_PASSWORD=`, `NUERS_LOADTEST=1`. Cache, session and queue stay on the database, which is the app default.
4. `php artisan migrate --force`, then `php loadtest/seed.php 50 1000000 keys.json`.
5. To vary client IPs, set `config/trusted_proxies.php` in the **copy only** to `['127.0.0.1']`, so the driver's `X-Forwarded-For` is honoured. Do not do this in the real repo.
6. `.\loadtest\serve.ps1 -Action start -AppDir <copy> -RunDir <scratch> -Workers 12`, then for example `node loadtest/run.mjs --keys keys.json --scenario many --ips 4000 --concurrency 16 --duration 60 --warmup 5`.
7. `php loadtest/verify.php`, then `serve.ps1 -Action stop`.

## Reading the results

- Run `lockwatch.php` (in a second shell, a few seconds after the load starts) to see which lock binds. On 2026-10-27 every sampled wait was on `eis_audit_head`.
- `run.mjs` reports `avg_in_flight`. If it is far below `--concurrency`, the requests are not actually reaching the server (check the harness before trusting the numbers).
- `serve.ps1` leaves nginx upstream keepalive off on purpose. `php-cgi` serves one connection at a time, and a kept-open idle connection pinned workers and caused 504s at higher concurrency.
- The application needs `APP_KEY` supplied from outside (there is no `.env`), both for the server and for PHPUnit.

## When the machine is not idle

The load numbers depend on the whole machine. On 2026-10-27 a game and an emulator held CPU at 97 to 100%, and the same code that managed about 52 accepted/s earlier managed about 28 to 30. Check `Get-CimInstance Win32_Processor` load before trusting a throughput run, and compare variants only when they are interleaved (A, B, A, B).

For lock changes, `profile.php` is more robust than a concurrent run: it reports `audit_head_lock_hold_ms` (from the `eis_audit_head ... for update` query to the acceptance transaction's commit) and prints a timeline of everything inside the lock. Hold time sets the single-lock ceiling (1000 / hold in ms = accepted per second). Take the median of several interleaved rounds.

## What the harness does and does not exercise

- It sends `source_capture` events. That path skips `assertCanIssue` (which needs real accreditation records) and invoice-series reservation, so it is a little lighter than `nuers_issuance`. It still takes the merchant lock, API-key lock, wallet charge, readiness preparation, global audit-head lock and webhook enqueue.
- All 50 seeded merchants share one TIN. Real merchants will not, which changes the per-seller query cost (see `docs/refactor-plan.md`).
- Load generator, nginx, PHP and MySQL share one Windows desktop. Numbers are for finding bottlenecks and comparing before/after, not for sizing AWS.
- `grow.php` clones receipts only, with no linked transactions, so per-row query cost is understated.
