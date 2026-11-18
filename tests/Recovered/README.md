# Recovered domain regression coverage

These 78 test classes were recovered from GitHub commit `02bbfb5967dd680a598e0794d84dba440a58091b` (30 October 2026), then adapted to the current local security candidate. They do not establish the source provenance of the 6 November frontend build. Original file hashes are in `provenance.json`; existing current tests were not overwritten.

`RecoveredDomainTestCase` retains the historical domain-fixture default of disabling the generic MFA boundary. Individual MFA/security tests explicitly enable it. The current `tests/Feature/EndpointSecurityTest.php`, `PrivilegedSessionTest.php` and `SecurityBoundaryTest.php` test authentication and recent-MFA enforcement separately with the boundary enabled. Unexpected Laravel HTTP requests are blocked; delivery tests mock their transports.

Adaptations made during recovery:

- Restore the test-only infrastructure controller and no-op default database seeder.
- Use the complete current schema and hashed, device-bound token fixtures instead of legacy plaintext-session assumptions.
- Supply authentication timestamps; calendar/report tests moving the clock to October also move their synthetic login timestamps. Session-expiry tests retain their explicit old timestamps.
- Expect privileged re-verification and expiry under the new 30-minute / eight-hour / five-minute policy. Merchant current-login assurance remains unchanged.
- Fill rate buckets using current configured limits and the correct public/authenticated bucket names.
- Account for the current six-document review requirements and their historical replacement rows.
- Give statutory-sale fixtures a supported registered industry; mixed batches use item scopes allowed by the same industry. Production eligibility checks are not bypassed.
- Use the installed Chrome executable for real local PDF tests on macOS. Linux CI should install a reviewed sandbox-capable Chromium/Chrome and configure `NUERS_PDF_CHROME`. No substitute PDF template is accepted.

Run `vendor/bin/phpunit` for the current suite plus these tests. PHPUnit's 512 MB limit applies only to the test process. The explicitly invoked `tests/WalletConcurrencyTest.php` requires the separate guarded, disposable local MariaDB harness; SQLite is not used to claim concurrent row-lock correctness.

## Linux timezone fixture correction (14 November 2026)

`BackupRecoveryTest` explicitly selects Manila time and asserts a `+08:00` timestamp. Updating `app.timezone` after Laravel bootstrap did not change PHP’s default timezone, so the fixture failed on the live-equivalent UTC host. The test now sets PHP’s timezone for its fixture and restores the previous timezone in teardown. The timestamp assertion and production defaults are unchanged.
