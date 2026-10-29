# Server Management — setup and controller contract

## Local application installation

1. Review the migration and preserve any existing infrastructure action history before an upgrade.
2. Run the normal application dependency/build process, then apply `2026_10_20_000001_create_server_management.php` through the normal reviewed migration procedure.
3. Open **Super Admin → System Administration → Server Management**. Nine planned records appear. No node is automatically trusted or contacted.
4. Keep both infrastructure environment switches disabled until the independent controller and handlers have passed isolated acceptance testing.

```dotenv
NUERS_INFRA_CONTROLLER_ENABLED=false
NUERS_INFRA_EXECUTION_ENABLED=false
# Fixed HTTPS origin only; no path, query, user info or fragment.
NUERS_INFRA_CONTROLLER_URL=
NUERS_INFRA_CONTROLLER_HOST=
# Absolute mounted file paths OUTSIDE the application/public directories.
NUERS_INFRA_CA_FILE=
NUERS_INFRA_CLIENT_CERT=
NUERS_INFRA_CLIENT_KEY=
NUERS_INFRA_SIGNING_KEY_FILE=
```

No keys are included in the repository. The signing key must contain at least 32 characters of cryptographically random key material. Distribute it through the approved secret manager, not through a browser or deployment log. Rotate client certificates and keys according to the approved key-management policy.

Configure reviewed `node_pins` and `capabilities` in the operator-owned `config/infrastructure.php`, then refresh Laravel's configuration cache in the reviewed release. Pins include all of:

```php
'node_pins' => [
    'WEB-01' => [
        'fingerprint' => '<verified SHA-256 certificate/agent fingerprint>',
        'environment' => 'staging',
        'tier' => 'web',
        'ha_group' => 'WEB-HA',
    ],
],
'capabilities' => ['service.restart'], // Only after that handler is validated.
```

The corresponding inventory environment must match. A browser enrollment request cannot install a pin. Do not add a capability solely because a button is visible.

## Worker commands

```sh
php artisan infrastructure:sync
php artisan infrastructure:work --limit=5
php artisan infrastructure:audit-verify
```

`sync` is read-only against the controller. `work` is disabled unless execution and the controller are explicitly configured. It reconciles existing jobs by ID before dispatching new requests. These commands are **not automatically scheduled** by this release. Establish a dedicated, supervised worker and non-overlapping read-only sync at 30–60 second intervals in the approved management environment. Avoid running infrastructure workers on the database or web nodes they may stop.

The current request store uses the application database. Complete the independent-persistence/failure-domain work described in the architecture before claiming out-of-band availability.

## Outbound protocol NUERS-INFRA-1

Fixed paths:

| Method / path | Controller responsibility |
|---|---|
| GET `/v1/snapshot` | Current pinned node observations; bounded JSON |
| POST `/v1/jobs` | Validate, atomically deduplicate/reserve, enqueue typed job |
| GET `/v1/jobs/{uuid}` | Authoritative status for the original job; no re-execution |

Every request uses mTLS with hostname/CA verification and these headers:

- `X-NUERS-Protocol: NUERS-INFRA-1`
- `X-NUERS-Timestamp`: Unix seconds
- `X-NUERS-Nonce`: fresh UUID
- `X-NUERS-Signature`: lowercase hex HMAC-SHA256

Canonical UTF-8 input uses newline separators, without a trailing newline:

```text
NUERS-INFRA-1
HTTP_METHOD
/v1/path
UNIX_TIMESTAMP
NONCE_UUID
SHA256_OF_EXACT_BODY_BYTES
```

GET body bytes are empty. The controller must check a ±30-second timestamp allowance, persist consumed nonces, compare signatures in constant time and reject expired jobs. Host/port is deployment-pinned; redirects are never followed. Responses must include `schema_version` and the request's exact `request_nonce`. The verified TLS channel authenticates the response. There is no public webhook ingress for infrastructure.

## Snapshot contract

```json
{
  "schema_version": "NUERS-INFRA-1",
  "request_nonce": "<echo request UUID>",
  "nodes": [{
    "code": "WEB-01",
    "fingerprint": "<64 lowercase hex characters>",
    "environment": "staging",
    "observed_at": "<actual current ISO-8601 UTC timestamp>",
    "reachable": true,
    "healthy": true,
    "hostname": "nuers-web-01",
    "os": "Ubuntu 24.04 LTS",
    "services": {"nginx": "running"},
    "capabilities": ["service.restart"],
    "checks": {"drained": true, "pool_healthy": true}
  }]
}
```

This is a schema illustration, not deployment evidence. Optional measured fields include `ip`, `kernel`, `role`, `uptime_seconds`, `cpu_cores`, `cpu_percent`, `memory_percent`, `memory_bytes`, `disk_percent`, `disk_used_bytes`, `disk_total_bytes`, network byte counters, zero-or-positive `replication_lag_seconds`, timezone-qualified `backup_verified_at` and immutable `artifacts` UUIDs. Percentages must be 0–100 and byte counts nonnegative. Missing metrics remain unknown. Services must belong to the server tier and be `running`, `stopped`, `failed` or `unknown`.

Accepted boolean safety checks are defined in `Telemetry::CHECKS`. They are factual assertions the controller must substantiate, not client toggles. Snapshot limits: 100 nodes, 20 capabilities/node, 50 artifacts/node and a 1 MiB HTTP response. Older/same observation timestamps are ignored rather than renewing trust.

## Typed job contract

Jobs include `schema_version`, `job_id`, `node_code`, `fingerprint`, `environment`, `action`, typed `parameters`, `actor_id`, approval identities, immutable policy snapshot, `issued_at` and `expires_at`. Jobs are not shell scripts. Parameter fields are only `service`, `artifact_id`, `runbook_id`, as applicable. Current service names are logical registry keys; handlers must resolve real OS unit identities from reviewed node-specific configuration.

A controller must not execute expired/unapproved work, repurpose a UUID with changed content, trust an unverified artifact or skip its own HA lock/preflight. It must retain result records durably so a timeout can be reconciled without retrying execution.

Result schema:

```json
{
  "schema_version": "NUERS-INFRA-1",
  "request_nonce": "<echo current request UUID>",
  "job_id": "<original job UUID>",
  "status": "succeeded",
  "postcheck_passed": true,
  "code": "COMPLETED",
  "evidence_id": "<optional immutable evidence UUID>"
}
```

Statuses: `running`, `succeeded`, `failed`, `rolled_back`. Codes: `ACCEPTED`, `COMPLETED`, `HANDLER_FAILED`, `POSTCHECK_FAILED`, `ROLLED_BACK`, `PREFLIGHT_DENIED`. A terminal result without a passing safety postcheck becomes `uncertain` and retains the group lock. A failure with passing safety postcheck can mean the operation failed harmlessly. Arbitrary output fields are discarded. No console URL or root credential is returned by this application.

## Isolated test preview

`tests/Support/prepare-server-management-preview.php` refuses any database except a testing SQLite file named `/private/tmp/nuers-infra-preview-*`. It creates a synthetic administrator and private temporary session file. Use `APP_ENV=testing`, SQLite, array cache/session and array mail transport. Start a loopback-only app on port 8098, then run `tests/Support/server-management-ui.cjs` with `NUERS_PLAYWRIGHT_PATH` pointing to the installed Playwright module. The test must not run against nuers.net or a production database. Remove the private temporary session/database when the preview is no longer needed.
