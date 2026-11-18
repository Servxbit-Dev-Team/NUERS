# Server Management — permission and approval matrix

The current application role model is reused. Proposed infrastructure-specific roles from the specification are **not** silently granted or simulated.

| Identity | Inventory / audit | Request action | Review another request | Enroll / maintenance | Raw root shell |
|---|---|---|---|---|---|
| Super Administrator | Yes | Typed, policy-gated | Yes; independent identity required | Fresh one-use proof | Never through this app |
| Ordinary administrator | Denied | Denied | Denied | Denied | Denied |
| Merchant / client / BIR / RDO | Denied | Denied | Denied | Denied | Denied |
| Anonymous / expired session | Denied | Denied | Denied | Denied | Denied |
| Infrastructure worker | Server-side durable jobs only | Cannot fabricate user approval | Cannot approve | Cannot enroll from arbitrary data | No shell handler in worker |
| Verified controller | Signed requests and authenticated result channel | Approved capabilities only | Cannot replace required app review | Out-of-band pins required | Separate reviewed bastion only |

## Action controls

All mutating browser operations use a current password plus a non-replayed six-digit authenticator. Proofs are one-use, expire after five minutes and are bound to the login token and password. Recovery codes cannot authorize infrastructure operations. Normal NUERS once-per-login MFA behavior is unchanged.

| Action | Risk | Independent approvals | Additional requirements |
|---|---|---:|---|
| Service restart / reload on web or LB | Medium | 0 | Fresh node and all peers, correct service registry, healthy pool, drained target |
| Backup request | Medium | 0 | Verified identity, fresh healthy target, explicit capability |
| Web reboot / shutdown / drain / rejoin / rolling restart | High | 0 | Current maintenance window, fresh peers, current verified backup, runbook; pool health; out-of-band recovery for power changes |
| LB failover | High | 0 | Same controls plus healthy standby; controller rechecks VIP/drain conditions |
| DB service / reboot / promotion | Critical | 1 | Witness, fencing, GTID consistency, zero lag, backup, window, healthy peer; promotion targets confirmed replica |
| Storage service / reboot / Ceph maintenance | Critical | 1 | Healthy quorum, clean PGs, replication margin, all healthy peers, current backup/window |
| Versioned configuration | Critical | 1 | Controller-verified artifact, syntax result, rollback readiness |
| Network change | Critical | 1 | Verified artifact, out-of-band recovery, timed revert, rollback |
| Recorded console request | Critical | 1 | Verified recording-capable bastion, runbook, backup and window |
| Isolated restore test | High (critical on DB/storage) | 0 or 1 | Verified backup artifact, isolated destination, runbook, safe peers |
| Production restore | Critical | 2 | Two distinct independent reviewers, verified artifact, rehearsal and rollback readiness |

No automatic break-glass bypass is provided. The last healthy node cannot be shut down through this workflow. Emergency operator recovery requires a separately governed, recorded procedure outside the application.

## Review and expiry

The requester cannot approve their own action. A reviewer cannot count twice. A stale revision returns 409. Reviewer account role, session validity and session binding are rechecked immediately before dispatch. Cancel is available only to the requester before dispatch; cancellation is not a promise to interrupt a running remote operation. Failed postchecks and ambiguous outcomes lock the HA group pending reconciliation.

Infrastructure-specific least-privilege roles, WebAuthn-based step-up, delegated target scopes and time-bound break-glass remain future integration work. Do not grant regular administrators Super Administrator solely to work around these missing roles.
