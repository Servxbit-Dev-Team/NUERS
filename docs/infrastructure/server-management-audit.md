# Server Management — repository audit and implementation plan

Audit date: 20 October 2026. Scope: local source inspection; no remote server discovery or operational changes.

## Existing capabilities

| Evidence | Finding |
|---|---|
| `composer.json:8` | PHP ^8.2; installed Laravel 12.70.0 (composer.lock). Validation runtime: PHP 8.5.9. |
| `package.json:4` | NUERS 1.0.0; React 19, TypeScript 5.9, Vite 7 (build runtime Vite 7.3.7). Existing Lucide icons and Radix dialogs. |
| `src/components/enterprise/enterprise-shell.tsx:39` | Shared System Administration navigation and branded shell. Preserve this structure. |
| `src/App.tsx:220` | Existing Infrastructure Health and Backup & Disaster Recovery pages. |
| `app/Services/ApiAccess.php:14` | Hashed bearer sessions, expiry, device binding and backend role checks. |
| `app/Http/Middleware/ApiBoundary.php:16` | Request size limits, rate limiting and privileged login MFA. |
| `app/Services/MfaDevices.php:45` | Replay-protected authenticator/recovery proof consumption. |
| `app/Services/BirCompliance.php:178` | Serialized hash-chain audit; existing business audit is retained. |
| `app/Services/BackupRecovery.php:103` | Evidence/request workflows explicitly do not run backup, restore or failover. |

## Missing / unverified

No enrolled infrastructure agents, telemetry adapters, provider power adapter, independent controller, bastion, node certificates, verified private IPs, fencing witness, Ceph quorum evidence or production operational credentials were found in the inspected application code. No secret files were inspected. Hosting entitlements and nine-node deployment are not established by the design image. The attached inventory is a planned baseline.

## Dependency-aware change plan

1. Add reversible inventory, bounded telemetry, maintenance, durable action, approval, one-use step-up and hash-chain audit storage. Seed only nine planned labels/specifications; leave observed data empty.
2. Add Super Administrator-only APIs, typed action schemas, preflight evaluation, independent approval, idempotency and fail-closed concurrency control. Reuse existing authentication; isolate infrastructure reauthentication from ordinary login MFA.
3. Add a fixed, operator-configured mTLS controller adapter and command-line sync/dispatch worker. No browser-provided management URLs, arbitrary shell, public agent callback or production demo mode.
4. Match the supplied page composition inside the existing shell. Provide grid/table filters, details, telemetry ranges, services, maintenance, preflight/action dialogs, action history and export. Display explicit unavailable states for missing dependencies.
5. Verify isolated fake-controller workflows, authorization, replay protection, stale telemetry, HA safety, approval separation, ambiguous execution and UI at desktop/mobile widths. Document setup, runbooks and capability gaps before rollout.

The implementation does not install agents or execute live infrastructure operations. Connecting a controller and approving its capabilities is an operator deployment task separate from creating this page.
