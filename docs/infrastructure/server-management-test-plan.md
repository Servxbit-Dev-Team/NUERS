# Server Management — validation record and acceptance gates

Validation date: 20 October 2026. All execution outcomes below use isolated fixtures or a fake controller. They are not evidence of production host access, live failover, backup restoration, mTLS certificate deployment or provider entitlement.

## Automated results

Command:

```sh
php vendor/bin/phpunit tests/Feature/InfrastructureGatewayTest.php tests/Feature/ServerManagementTest.php tests/Feature/BackupRecoveryTest.php tests/Feature/ApiProductionSecurityTest.php
```

**PASS — 53 tests, 404 assertions.** Module-specific coverage is 24 tests (19 workflow + 5 transport); existing backup and API security regression tests are included in the total.

| Scenario | Result | Evidence |
|---|---|---|
| Anonymous and non-super-admin access denied, including mutations/export | PASS | `ServerManagementTest` |
| Nine planned entries, no fake live metrics/offline claims | PASS | Inventory assertions and desktop capture |
| Step-up password, replayed OTP, one-use/expired proof | PASS | MFA and expired-proof tests |
| Pinned fingerprint/environment/tier/group, pin revocation | PASS | Identity and telemetry tests |
| Stale telemetry, UTC storage and PHT display | PASS | Freshness assertions, rendered page |
| Same/older telemetry replay cannot renew freshness | PASS | Sample/freshness test |
| Invalid action, shell text, URL/SSRF target and unknown service fields | PASS | Strict-schema tests |
| Request idempotency and changed-payload conflict | PASS | Dispatch-once test |
| Unhealthy WEB/LB peer blocks disruption | PASS | HA peer tests |
| Missing DB witness / unsafe Ceph quorum | PASS | Tier-policy tests |
| Self-approval denied; distinct critical reviewer required | PASS | Independent review test |
| Production restore requires two distinct independent reviewers | PASS | Restore approval test |
| Revoked session or newly stale telemetry blocks dispatch | PASS | Dispatch recheck tests |
| Ambiguous controller response retains HA lock, no re-execution | PASS | Reconciliation test |
| Failed postcheck never reports success or releases group | PASS | Postcheck test |
| Arbitrary raw result/exception text not persisted | PASS | Structured output test |
| Maintenance optimistic revision | PASS | Conflict test |
| Audit chain verifies; tampered event detected | PASS | Audit/export tests |
| Fixed HTTPS origin, HMAC canonical request, nonce | PASS (HTTP fake) | `InfrastructureGatewayTest` |
| Redirect, wrong nonce, oversized response rejected | PASS (HTTP fake) | Transport tests |
| Operational worker disabled in default config | PASS | CLI returned disabled/unavailable without network dispatch |
| TypeScript / production assets | PASS | `tsc -b`, `npm run build` |

## Browser verification

`tests/Support/server-management-ui.cjs` ran against loopback port 8098 with a separate SQLite test database and synthetic administrator. It completed **8 interaction groups** with **zero browser page errors**:

1. Nine planned records and unknown metrics.
2. Grid/table switching and server search.
3. Reboot preflight blocked without a configured controller.
4. All nine section tabs.
5. Actual local maintenance-window save with password + authenticator.
6. Authenticated inventory CSV export.
7. 390-pixel responsive layout without horizontal document/content overflow.
8. Mobile enrollment dialog.

Screenshots in `output/server-management/`:

- `desktop-unconfigured.png`: actual local unconfigured state.
- `preflight-blocked.png`: safety requirements and blocked request.
- `enrollment-modal.png`: untrusted inventory enrollment workflow.
- `mobile-unconfigured.png`, `mobile-server-cards.png`, `mobile-enrollment.png`: responsive layouts.
- `desktop-simulated.png`: explicitly labeled simulated telemetry, injected only into the isolated browser test; not a runtime demo mode or live evidence.
- `ui-test-results.json`: interaction outcome record.

The reference's fixed sample IPs/uptimes/metrics are not seeded in the application. Documentation-only `192.0.2.*` addresses appear solely in the clearly labeled simulated screenshot fixture.

## Required before production privileged execution

NOT RUN / REQUIRES APPROVED INFRASTRUCTURE:

- Real mTLS handshake, expired/revoked certificates, source-network restrictions, key rotation and controller nonce persistence.
- Real agent command allowlisting, job deduplication through process crash, independent HA-group locks and execution-time preflight.
- Full independent controller/request-store availability during loss of app/database nodes.
- Real rolling web drain/rejoin, LB VIP/provider failover, MySQL witness/fencing/GTID drill, Ceph fault-domain/quorum rehearsal.
- Actual backup restoration, data reconciliation and measured production RTO/RPO.
- Bastion recording, JIT revocation, KVM recovery, network timed revert and configuration rollback.
- SIEM alerts, WORM audit checkpoint verification, load tests and independent penetration/security review.

These unverified gates remain operational blockers. Automated passing tests do not convert them into production-ready infrastructure.

## Local installation verification

The additive migration was applied to the local `NUERS` MySQL database on 127.0.0.1:3307. A read-only post-check confirmed 9 planned nodes, 0 enrolled nodes, execution disabled and a valid infrastructure audit chain. No merchant records or provider resources were changed.
