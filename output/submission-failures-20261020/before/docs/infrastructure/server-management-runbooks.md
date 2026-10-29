# Server Management — operator runbooks

These procedures govern the control-plane workflow. Concrete provider commands and node handlers must be supplied by the verified controller operator. Do not turn these instructions into a generic root shell endpoint.

## First enrollment

1. Confirm authority, server code, environment, tier, HA group and independent site/fault domain.
2. Use Add Server or Prepare Enrollment. The resulting record is pending, with no trusted endpoint.
3. Verify host identity through an independent channel. Provision the private agent identity and least-privilege handlers using approved configuration management.
4. Pin fingerprint, environment, tier and HA group in operator-owned configuration; validate mTLS to the independent controller.
5. Run `infrastructure:sync`. Confirm identity, timestamps, OS and metrics. An unavailable node must not appear healthy.
6. Test certificate revocation, stale observations and network partition. Grant only capabilities demonstrated in the isolated test environment.

## Web service / rolling maintenance

1. Inspect WEB-01 and WEB-02; require fresh healthy peers and measured pool health. Record change reason and a one-node maintenance window.
2. Drain the target through the approved controller workflow and verify active requests/queue handling. For the rolling workflow, the controller must drain, act, validate and rejoin one node before proceeding to the next.
3. Select an approved service. Run preflight, enter exact node code and perform fresh step-up. A scheduled request is not yet execution success.
4. The worker repeats policy checks and dispatches one signed job. Watch Action History for postcheck evidence.
5. Stop on failed validation. Do not proceed to the other node. Reconcile the same job ID; use only the recorded rollback plan.

## Load balancer failover

Confirm healthy standby, provider-supported VIP/VLAN behavior, pool/drain state and out-of-band recovery. Open a maintenance window and use the reviewed LB failover runbook ID. A down/unreachable standby blocks the operation. The controller validates VIP ownership and backend reachability after the transition. Never stop both LB members together.

## Database failover

Confirm authoritative current primary/replica identity, zero replication lag, consistent GTID history, independently functioning witness, fencing, current verified backup and recovery plan. Request promotion of the confirmed replica; an independent administrator reviews the exact target and plan. The controller fences the former primary before promotion and verifies one-writer topology. Missing witness/fencing blocks automated promotion; no ping-based promotion is permitted. On ambiguity, preserve logs and lock the group pending incident review.

## Ceph maintenance

Confirm MON quorum, clean placement groups, replica safety, capacity margin and all other members healthy. Authorize one node only with current backup/window and independent approval. The controller must check replication policy and fault-domain tolerance, execute its approved runbook and verify recovery before rejoining. A degraded PG/quorum state blocks requests. No arbitrary pool deletion or disk-wipe control is provided.

## Backup / restore

Use a verified backup capability to request capture. Record actual evidence, not a successful HTTP request as a successful backup. Isolated restores require a controller-registered immutable artifact and isolated target. Production restore additionally requires rehearsal, rollback readiness and two distinct independent reviewers. Preservation obligations, customer impact and restore-point reconciliation must be documented in the approved runbook. The UI does not claim an RTO/RPO from request timestamps.

## Configuration / network change

Register a versioned immutable artifact with reviewed diff, target scope, syntax validation and rollback artifact in the controller's registry. Pass the artifact UUID and runbook reference. Network changes additionally require working out-of-band recovery and an independently enforced timed revert. If syntax validation, target identity or recovery access is uncertain, do not dispatch.

## Console access

Request the exact node and approved runbook; obtain fresh step-up and independent approval. A verified bastion provides the time-limited user identity, session recording and operator connection handoff. This application only records the request and outcome; it does not embed a browser terminal or expose root credentials. A missing bastion blocks the request.

## Uncertain / failed outcome

1. An HTTP timeout or failed postcheck leaves the HA group locked. Do not repeat the action with another UUID.
2. Run the worker's reconciliation path, which performs a GET for the original job UUID. Confirm provider/agent history and real node state independently.
3. The controller returns authenticated terminal result and postcheck evidence. Only then may the workflow release the group.
4. If evidence cannot establish a safe outcome, keep the lock and invoke an approved incident/out-of-band recovery procedure. There is no application “force unlock” bypass.

## Dispatch shutdown

Disable the worker and `NUERS_INFRA_EXECUTION_ENABLED` to stop new dispatch. Do not assume this interrupts already running controller jobs. Reconcile those jobs before releasing locks, rotating credentials or removing the module. No operation automatically sends messages outside the system during this release.
