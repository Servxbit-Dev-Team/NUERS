# NUERS security readiness continuation — 15 November 2026

Status: three reproduced application findings fixed locally, retested and deployed. Both production nodes are healthy. Remaining infrastructure/assurance gaps below are still open; no unconditional VAPT pass is claimed. This is engineering evidence for an independent VAPT, not a certificate or a claim that all attacks have been exhausted.

## Reproduced findings and local corrections

1. **Authenticated malformed query produces HTTP 500 (low availability/input-validation severity).** An array-valued `format[]` reached the privileged recent-MFA classifier, where casting an array to a string raised an exception. Reject non-string values with 422; valid sensitive exports still require recent MFA. Eight cases cover fresh/stale MFA across super_admin, admin, bir and rdo. No authentication bypass was established.
2. **Google sign-in disagrees with approved MFA opt-out policy (functional/security-policy consistency).** Google exchange independently calculated MFA requirements and ignored the recorded admin opt-out. It now uses the same `MfaSession::required` service as other sign-in paths. Twenty-one role/enrollment cases verify unenrolled, explicitly opted-out and enrolled accounts across seven roles, preservation of enrollment, unverified status for the new login, and one-use exchange. This does not automatically opt out or disable MFA on any account.
3. **Offline queue loses durable acceptance after an out-of-order response (medium integrity/availability severity).** A second queue handle could record success, then a late failure from the first handle overwrote the accepted state. The IndexedDB read/compare/write now runs within one readwrite transaction, keeps accepted state terminal, checks immutable event identity and does not recreate missing rows. The server's existing transaction idempotency remains necessary; this finding did not establish duplicate financial charging.

The before-fix run reproduced 12 failures in 29 backend cases (four Google opt-out mismatches and eight malformed-format errors) and five offline race failures. The corrected backend cases passed: **29 tests, 196 assertions**. Node execution passed **23 test entries** across offline, frontend security and session-client scripts; reconstruction tests passed **13/13**. The session script also reports 11 internal scenarios; these are not added again to the Node entry count. The offline tests use `fake-indexeddb` as an explicit test double. A separate browser fixture passed **4/4** using actual IndexedDB/Web Crypto and two simultaneous handles. This does not establish every multi-tab, crash, quota, Safari/iOS or offline-key lifecycle combination.

## Fresh dependency and cloud observations

- Composer locked audit: no reported advisories or abandoned packages. npm audit: zero advisories at all reported severities. These results are time-bound and do not cover OS packages or application logic.
- Both production EC2 nodes have no public IP, require IMDSv2, and allow web ingress only from the load balancer security group. Selected application source and asset files are root-owned 0644; `.env` is root-owned 0640. Debug is disabled; privileged session/MFA lifetimes remain 30/480/5 minutes. The scanner is enabled and ClamAV answers on both nodes.
- Runtime IAM policies were read. S3 migration-archive access is explicitly denied; EFS access is restricted to the named access point with secure transport. Named backup/power Lambda invocation remains an intentional application capability requiring separate Lambda implementation/permission review. This is not a claim that the entire AWS account's IAM graph was exhaustively audited.
- Document bucket blocks all public access, reports non-public policy, uses AES256 server-side encryption, has versioning enabled and denies insecure transport.
- RDS is private and encrypted, permits 3306 only from the application security group, has 14-day backup retention, deletion protection and automatic minor upgrades. A paginated parameter lookup confirmed `require_secure_transport=1`; `tls_version` is engine-default, so a concrete negotiated-cipher/version verification remains separate.
- EFS is encrypted, allows NFS only from the web security group, denies insecure transport and root clients, and restricts client access to the production web role.
- ALB HTTPS listener uses `ELBSecurityPolicy-TLS13-1-2-2021-06`; invalid HTTP headers are dropped, desync mitigation is defensive, and deletion protection is enabled. Origin ingress is restricted to Cloudflare-labelled IPv4 ranges. No AWS WAF is attached; that alone does not establish absence of an edge WAF because Cloudflare fronts this origin.
- CloudTrail management trail is logging and showed recent delivery. **ALB access logging is disabled and VPC-level flow logging is absent.** Existing web/application/CloudTrail logs provide different visibility; they are not equivalent to these missing telemetry streams.
- Both nodes run Ubuntu 24.04.5 LTS, PHP 8.4.27, nginx 1.24.0-2ubuntu7.19, OpenSSL 3.0.13-0ubuntu3.16 and ClamAV 1.5.4. Cached package lists show pending Chrome, snapd and distro-info updates. This is not a refreshed OS vulnerability scan; no unattended broad OS upgrade or restart was performed during this application patch.
- The runtime database checker reports only its obsolete localhost/socket assumption against RDS. The inspected grants did not otherwise trigger its excess-privilege checks. This is recorded as a checker limitation, not evidence of a proven grant escalation or a verified network permission pass. Runtime host scope is assessed separately through private RDS and security-group evidence.

## Remaining coverage and release criteria

| Remaining item | Current evidence / required next evidence |
|---|---|
| ALB access and VPC flow telemetry | Disabled/absent in current read-only review. Prepare bounded-retention logging and delivery policy, validate in staging, then enable under the logging/IAM change workflow. No new logging role or delivery access was granted in this run. |
| OS vulnerability and patch lifecycle | Cached package inventory only; refresh vendor/Inspector assessment and stage package updates, including the PDF-renderer browser, before rolling production upgrades. |
| Edge policy and origin bypass | Cloudflare blocks in public probes show edge denial, not backend coverage. Need read-only Cloudflare rule/TLS/bot policy export and targeted origin/header bypass validation. |
| Browser coverage | New actual-browser queue race coverage plus existing reconstruction/security tests; exhaustive DOM-XSS sinks, all workflows, Safari/iOS, storage exhaustion and browser termination/recovery remain unproven. |
| External providers and recovery | Do not run real BIR/payment/email mutations. Provider sandbox end-to-end checks, restore drills, alert delivery and operator response require their own recorded evidence. |
| Independent assessment | Authenticated, scoped external VAPT with all roles and controlled test tenants is still required for an independent report/sign-off. No automated suite can certify all attacks or future vulnerabilities. |

The previously recorded seven-role endpoint matrices, 65-combination successful-flow expansion, financial process-race tests and two-node tests remain separate dated evidence. They are not presented as having all been rerun by this document unless a new result below explicitly records that run.

Evidence directory: `.local/vapt-readiness-2026-11-15/`. Test tools: `npm install --prefix /private/tmp/nuers-readiness-test-tools --ignore-scripts --no-fund fake-indexeddb@6`; use `NUERS_INDEXEDDB_TEST_MODULE` to select another installed location. No application manifest or dependency lock was changed for that test-only tool.

## Full local release gate

PHPUnit: **941 tests, 13,529 assertions, zero failures/errors/skips**. The full suite includes role and endpoint regression coverage. Production build/typecheck succeeded, with the existing bundle-size warning. The artifact contains three changed source files and 237 generated build files, including the build manifest. Candidate SHA-256: `32760dff586b93bbd0eb7de00969bdb3b1312746e9c231a9fcf55dcb0cd4a2e2`. Runtime configuration, account records, database schema and MFA enrollment are outside the patch. Both-node baseline hashes and native tests must pass before traffic switches.

## Linux gate and fixture correction

Both nodes passed PHP 8.4.27 isolated in-memory validation: **8,372 authenticated probes**, **906 explicit administrative-role denials**, and **99 targeted regressions** (52 existing stateful, 18 financial lock regressions, 29 new readiness cases) per node. These probes use synthetic records, fake mail/queue and prohibited stray HTTP; they do not exercise customer accounts or production database state. The matrix reports the BIR callback and resend-verification unavailable without their external service/configuration fixtures; those responses are not successful provider-flow coverage.

The first native attempt was stopped before any traffic switch because the new fixture dated MFA verification before authentication; a general MFA challenge correctly occurred rather than the expected recent-MFA reason. The fixture now dates login 25 minutes ago and verification 20 minutes ago, with current activity. All 29 local cases were rerun successfully (196 assertions), then both native gates passed. No production source adjustment was needed for that fixture correction. An earlier SSM comment-length validation error also occurred before remote preparation and was corrected. The final deployment script and probe hashes are recorded in `execution-hashes.json`; the original upload is retained separately from the final tested archive.

## Verified deployment and public retest

Release **`production-20261115-readiness1`** completed on both nodes at **22:15:27 PHT on 15 November 2026**. Both targets were healthy. Each node passed **16 post-switch HTTP checks**, exact packaged file hashes, root-owned/non-world-writable code checks and unchanged `.env`. The release changes no schema and runs no customer-account/MFA-enrollment mutation. The rollback baseline is `production-20261115-races1`.

Bounded public post-deployment retest: **79/79 requests passed, zero failures**, completed **22:18:05 PHT**. Seven were blocked by Cloudflare and therefore establish edge denial only. No real authenticated customer sessions were used. The homepage references the new entry bundle; downloaded public main JavaScript, main CSS and offline client-queue JavaScript all match local SHA-256 hashes.

Sources for the telemetry remediation planning: [AWS ALB logging permissions](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/enable-access-logging.html) and [AWS VPC flow-log S3 permissions](https://docs.aws.amazon.com/vpc/latest/userguide/flow-logs-s3-permissions.html). Enabling these telemetry streams requires reviewed delivery permissions. In particular, the VPC documentation warns that automatic policy attachment can overwrite an existing bucket policy; do not blindly enable it against the production document bucket.
