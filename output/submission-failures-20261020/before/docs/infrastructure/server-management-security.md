# Server Management — security and threat model

## Trust boundaries

Browser data is untrusted. A saved server label/site is not proof of host ownership. A configured capability is not proof that a provider granted access. Only out-of-band identity pins plus the authenticated independent controller may establish current host identity and safety observations.

The controller is a privileged trust boundary: it must be reviewed, independently hosted, network-restricted and protected by mTLS. Compromise of this controller could forge telemetry or results; application preflight checks do not eliminate that risk.

## Implemented controls

- Backend Super Administrator authorization, existing hashed/device-bound bearer session, absolute expiry and privileged MFA.
- Password reauthentication and replay-protected TOTP before each mutation. The database stores only a short-lived proof ID, actor/session binding and timestamps.
- Strict action enum and nested parameter allowlist. No shell string, user-selected management endpoint, file path or SQL execution facility.
- Outbound management traffic uses only an operator-configured fixed HTTPS origin, exact configured hostname, verified CA, client certificate and private key. Redirects are disabled; connection/request deadlines and a 1 MiB response bound apply.
- Signed versioned requests, timestamp, random nonce, body digest and expiring job start deadline. Results are bound to the outbound nonce and authenticated by the verified TLS channel. No public agent callback route exists.
- Snapshot identity pins include fingerprint, environment, tier and HA group. Revoked or mismatched pins fail closed. Duplicate/older observations do not renew freshness.
- Idempotency key + request hash; independent approval uniqueness; optimistic record revisions; serialized group reservation.
- Only enumerated outcome codes, evidence UUID and postcheck state enter the application. Raw stdout/stderr, controller exception text and arbitrary response fields are discarded, reducing the risk of leaked credentials.
- Audit events are append-only through the module and chained by SHA-256. Inventory export is audited and CSV formula prefixes are escaped.
- Inventory is bounded to 100 nodes; telemetry to 672 samples/node; list endpoints paginate; operations do not run in invoice request threads.

## Required controller enforcement (not deployed by this change)

1. Verify the client certificate chain, key usage and service identity; restrict source addresses on a private management network.
2. Verify HMAC in constant time. Reject missing/incorrect protocol, timestamp outside ±30 seconds, duplicate nonce, body digest mismatch, invalid UUID, expired start deadline or mismatched target pin.
3. Persist nonce and job deduplication in an independent durable store before starting a handler. Same UUID + changed payload is a conflict; same UUID must never execute twice.
4. Map actions to code-reviewed handlers, never interpolate request strings into a shell. Resolve service and artifact IDs from protected registries.
5. Recheck HA topology, fencing, quorum, backup/window and approvals at execution time. Atomically serialize HA groups across every request origin, including provider consoles.
6. Validate service/host health after execution. Perform only preapproved rollback. Failed or unknown safety checks require reconciliation, not repeated execution.
7. Redact and isolate raw logs in an authorized log system; return only the bounded structured result contract to NUERS.
8. Alert on invalid signatures, authentication bursts, denied actions, revoked certificates and unresolved outcomes. Preserve recordings and audit checkpoints off-host.

## Secrets and certificate lifecycle

Mount CA, client certificate, client private key and HMAC key files outside every web root. Private files must be owned by the worker identity with restrictive permissions; do not commit them. Use a secrets manager to deliver/rotate these files. Record certificate serial/expiry and rotation approval outside the app. Rotate node pins through independent identity verification, revoke old identities, and confirm stale telemetry no longer authorizes work. Do not paste credentials into reason, site, label or runbook fields.

## Residual gaps / production gates

The request store currently shares the application database. Dedicated management persistence, independently available controller/worker, WORM audit export, security alerts/SIEM integration, live node agents, certificate issuance/rotation automation, provider KVM/power, privileged log collector, signed configuration artifact registry and recorded bastion are **not deployed or proven**. No penetration test, provider entitlement, production HA exercise or production disaster recovery claim is made.

The audit chain detects ordinary row modification but cannot prevent a database administrator rewriting the entire chain. Off-host trusted checkpoints are mandatory before production privileged execution. Ordinary infrastructure inventory exports do not include keys, controller URLs or service credentials.

## Rollback and release handling

The migration only creates `infra_*` tables. It does not change merchant records, invoices, wallets, MFA configuration or provider state. Disable dispatch first during rollback. Preserve action/audit records and reconcile running/uncertain jobs before code rollback. A schema down migration is destructive to infrastructure history and must only be used on disposable test databases, or after an explicitly authorized preservation plan.
