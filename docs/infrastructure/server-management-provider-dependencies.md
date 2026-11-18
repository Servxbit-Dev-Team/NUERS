# Server Management — provider and site dependencies

**Confirmed by this audit:** source code, planned nine-node inventory and existing NUERS authentication. **Not confirmed:** hosting rights, active hosts, APIs, certificates, IPs, health, replication, backup availability or site placement.

Dataplugs, Contabo, AWS Singapore and AWS Hong Kong were identified by the owner as future provider/region choices. Assigning them production, backup or disaster-recovery roles requires a deployment decision and evidence. The screenshot's IP addresses and utilization values are visual references and were not copied into live inventory.

| Dependency | Current status | Evidence / provider request |
|---|---|---|
| Independent controller / private management network | Needs infrastructure setup | Host, network route, service owner, redundancy and approved mTLS identity |
| Dataplugs dedicated node control | Needs provider access | API documentation, account entitlement, read/write scopes, rate limits, test tenant |
| Contabo server control | Needs provider access | Actual subscribed product, API rights, out-of-band console/recovery behavior |
| AWS Singapore / Hong Kong | Planned only | Account/region/resource plan, IAM roles, storage location, KMS key residency, cross-region policy |
| IPMI/BMC/KVM / power-on | Not found | Contractual access, VPN restrictions, recording, emergency recovery route |
| Floating IP / VLAN / VRRP | Not verified | L2/VLAN support, provider MAC/ARP restrictions, failover rules and rehearsal |
| MySQL HA | Not verified | Third-party witness/arbiter, fencing, GTID/lag signals, primary identity, failover runbook |
| Ceph HA | Not verified | MON quorum, pool replication/min_size, PG states, fault domains and recovery capacity |
| Backups / restore | Not verified by infrastructure module | Independent immutable storage, protected keys, verified backup IDs and restore evidence |
| Configuration artifacts | Not found | Immutable artifact IDs, version/diff/reviewer, syntax result, target scope and rollback artifact |
| Recorded console | Not found | Bastion, JIT user mapping, session recording, approval and retention policy |
| Time-series / logs / SIEM | Not connected | Private endpoint, scoped reader identity, redaction and retention controls |

Do not enable a capability by guessing a URL or unit name. Obtain the provider contract and verify behavior in an isolated environment first. Unknown controls remain blocked. No provider portal credentials were collected or used during implementation.
