# Server Management — final implementation matrix

Version 1.0.0 · 20 October 2026

| Capability | Status | Source / evidence | Next action |
|---|---|---|---|
| Branded page and Super Admin navigation | IMPLEMENTED | `src/pages/infrastructure/server-management.tsx`, `server-management.css`, `src/App.tsx`, enterprise shell | Local migration applied; available at `/super-admin/server-management` |
| Planned nine-server baseline | IMPLEMENTED | `Catalog.php`, migration | Verify actual inventory and site assignments |
| Grid/table, search/filter/sort/pagination, KPI denominators | IMPLEMENTED | Page and components | Connect verified observations |
| Details, service registry, resource ranges, logs/settings tabs | IMPLEMENTED | Page, detail API, bounded samples | Raw process/OS logs need external collectors |
| Super Administrator backend authorization | IMPLEMENTED | Controller, FormRequest, existing ApiBoundary/ApiAccess | Independent role design before delegating infra duties |
| One-use password/TOTP step-up, approvals and exact target | IMPLEMENTED | Controller, ActionWorkflow, dialogs | Enroll separate authorized reviewers |
| Maintenance windows and revision conflicts | IMPLEMENTED | Controller and tests | Follow approved change management |
| HA safety, witness/fencing and Ceph guards | IMPLEMENTED | `Preflight.php` and failure tests | Real controller must revalidate during execution |
| Typed durable requests, job correlation, idempotency | IMPLEMENTED | `ActionWorkflow.php`, `infra_actions` | Operate opt-in independent worker |
| Reconciliation and fail-closed uncertain outcomes | IMPLEMENTED | Workflow tests | Implement authoritative remote job history |
| mTLS/HMAC fixed-origin adapter | IMPLEMENTED (client); PARTIAL (end-to-end) | `MtlsController.php`, transport tests | Deploy/review independent controller and certificates |
| Live metrics/agent enrollment | NEEDS PROVIDER ACCESS | Default unconfigured state; identity pins | Verify ownership, install approved agents out of band |
| Remote service/reboot/shutdown/rolling handlers | PARTIAL | Typed request and signed dispatch ready; no live handler deployed | Supply tested controller handlers and capabilities |
| DB promotion / storage maintenance / config & network changes | BLOCKED FOR SAFETY until prerequisites | Policy gates, artifacts, approvals | Prove fencing, quorum, rollback and out-of-band access |
| Provider power-on / KVM | NEEDS PROVIDER ACCESS | No power-on API exposed | Obtain actual provider entitlement and adapter |
| Browser terminal, file manager, arbitrary shell | NOT FOUND / intentionally not exposed | No generic execution endpoint | Separate reviewed JIT recorded bastion; no public shell |
| Backup/restore orchestration | PARTIAL | Typed requests, shared recovery-center link, backup observation | Connect approved backup/artifact handler and restore evidence |
| Independent management persistence / outage survival | PARTIAL | Request tables currently use application DB | Move/control request persistence in separate failure domain |
| Infrastructure audit + CSV | IMPLEMENTED | Hash chain, verifier, CSV export | Add independent WORM checkpoints and SIEM delivery |
| Detailed process/log collector, config diff editor, patch scheduler | NOT FOUND | Not represented as working live features | Integrate scoped collectors/artifact registry safely |
| Provider sites / data locations | NOT VERIFIED | Provider dependency document | Confirm Dataplugs/Contabo/AWS roles and regions |
| Desktop/mobile QA | IMPLEMENTED | `output/server-management/` and browser result JSON | Repeat against approved staging infrastructure |
| Production application rollout | DEPLOYED — 20 October 2026 07:51 PHT | `output/server-management/release-result.json`, `live-http-checks.json`, `live-deployment.jpg` | Configure and independently verify the controller and agent identities before enabling operations |

## Delivery boundary

This change implements the application control-plane module and safe controller integration contract. It does not claim to be a complete Plesk replacement, a deployed independent management system, or a tested production fleet. All live infrastructure dependencies are explicitly documented and unconfigured controls fail closed.

Start with [setup](server-management-setup.md), [architecture](server-management-architecture.md), [permission matrix](server-management-permission-matrix.md), [runbooks](server-management-runbooks.md), [security](server-management-security.md), [provider dependencies](server-management-provider-dependencies.md) and [validation record](server-management-test-plan.md).

Local post-migration verification: 9 planned nodes, 0 enrolled nodes, execution disabled, infrastructure audit chain valid. The application module was deployed to nuers.net on 20 October 2026 at 07:51 PHT. Production verification confirms 9 planned nodes, 0 enrolled nodes, 11 routes, disabled controller/execution, valid audit chain and preserved existing records. Public asset checksums match the tested build; anonymous infrastructure API access returns 401. No live host actions were run. Authenticated live UI verification remains dependent on an authorized NUERS login.
