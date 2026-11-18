# Linux and two-node VAPT remediation acceptance — 2026-11-14

The reconstructed candidate passed the isolated Linux acceptance suite. This is engineering verification, not an independent penetration-test certification. The exact production deployment outcome is recorded separately in the rollout evidence.

## Verified scope

- Ubuntu 24.04, PHP 8.4.27, MariaDB 10.11.19; two private Singapore t3.large instances with encrypted 30 GB disks, no public inbound access and no production customer database.
- Full UTC suite: **805 tests / 10,022 assertions**, zero failures/errors/skips. A separate Manila-time full run also passed. The BackupRecovery fixture now explicitly sets/restores its PHP timezone; production remains UTC.
- MariaDB security: **23 tests / 1,648 assertions**. Wallet concurrency: **2 / 19**. Registration OTP concurrency: **2 / 39**.
- **28** cross-node HTTP/TLS checks: shared sessions, device binding, login replacement, stale logout, TOTP replay, MFA step-up, idle/absolute expiry, tenant boundaries, concurrent login and shared throttling.
- Four cross-host wallet assertions: overdraft rejected, valid ledger chain, identical idempotent responses, and exactly one credit posting. All transactions were synthetic.
- Mandatory antivirus accepted clean content, rejected harmless EICAR, failed closed when its socket was unavailable, and had fresh signatures on both nodes. Container attempts to obtain an IMDS token failed.
- Both Linux builds and a clean locked-dependency Mac build matched across **233 generated files**; a fresh Mac repeat also matched. Tailwind now scans the explicit source directory instead of accidentally scanning generated files and logs.
- Native PDF generation used sandboxed Chrome in a restricted systemd service. The receipt reference asset was added to the candidate packaging inventory. No sandbox protection was disabled.
- Locked Composer audit: zero advisories or abandoned packages. Runtime packages are Laravel 12.71.0 and phpseclib 4.0.3.

## Endpoint coverage and limits

The UTC run recorded **557 route/method probes**: 480 bearer-protected, 46 public, 12 separately authenticated and 19 web. Negative access, empty mutation, nonprivileged admin access and recent-MFA gates were tested. Two routes were recorded as shadowed by another handler; those probes do not establish direct handler coverage. The CSV and JSON coverage artifacts identify those cases. This is not a claim that every business-input combination or infrastructure attack surface has been exhaustively tested.

The original frontend source was not recovered. The user-authorized reconstruction is the new maintainable baseline. Reproducible output and regression tests do not prove historical 1:1 UI/business equivalence. Independent authenticated VAPT and infrastructure testing remain necessary for an external pass decision.

## Preserved evidence and resources

`NUERS_VAPT_Retest_Evidence_2026-11-14.json` contains counts, XML hashes and evidence paths. Downloaded bundle SHA-256: `fb818ee63d8753ad752e3fe52a3de52e39de4aaef21d0606a4f2396eea6ff04b`. Intermediate failed runs were retained, including packaging and fixture failures, alongside the final passing runs.

Both temporary instances were verified **stopped** after evidence collection. Their encrypted disks remain retained for reproducibility; storage charges continue. No instance or disk was terminated/deleted. The staging role is restricted to its test artifact prefixes; production data access was not granted.

## Budget constraint

The user specified **₱100,000/month**. NUERS production configuration already reports that budget ceiling. Current known AWS fixed baseline is approximately **₱83,514/month**, using 730 hours, an explicitly assumed ₱65/USD and a 12% tax allowance. Variable requests, transfer, logging, snapshots and other listed exclusions can increase the bill. This is neither an exchange-rate quote nor a guaranteed bill ceiling. No additional permanent compute capacity was added and no production service was stopped to enforce the budget. An AWS billing hard cap or notification subscription was not configured.
