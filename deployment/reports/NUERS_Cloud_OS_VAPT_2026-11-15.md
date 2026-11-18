# NUERS cloud and OS VAPT continuation — 15 November 2026

Status: approved WAF/logging changes and OS remediation deployed and verified. Remaining OS advisory/applicability and operational assurance gaps are open; an unconditional VAPT-ready result is not claimed. This is a targeted remediation record, not an independent VAPT certificate.

## Scope and safeguards

AWS account 289829301267, Singapore; production VPC and ALB; the two existing web nodes; nuers.net Cloudflare zone. Production application release remains `production-20261115-readiness1`. No database migration, customer transaction, notification, credential, or MFA enrollment changes. Existing isolated staging node B was restarted with a bounded automatic stop and synthetic data only.

## Findings and remediation

1. **Missing network audit logs.** ALB access logging was disabled and the production VPC had no flow log. The approved dedicated bucket uses SSE-S3, public access blocking, bucket-owner ownership, TLS-only access and narrowly scoped AWS service delivery permissions. New logs expire after 90 days. Existing CloudTrail and business-document bucket policies were preserved. Actual compressed traffic logs were verified in both ALB and VPC prefixes (permission-test objects excluded). VPC delivery is ACTIVE/SUCCESS; bucket policy exactly matches the reviewed policy. Existing CloudTrail logging/digest delivery remains active.
2. **Old Chrome PDF runtime.** Production had 157.0.8103.38; the official vendor update is 157.0.8103.78. The Linux staging renderer passed real PDF generation and 25 tests/495 assertions both before and after reboot with its sandbox preserved.
3. **Pending security reboot.** Kernel 7.0.0-1017 was installed but both production nodes were still running 7.0.0-1014, with libc6 also requesting reboot. Staging successfully rebooted onto 1017. Production uses a one-node-at-a-time drain, reboot and verification gate.
4. **Unused vulnerable Python bootstrap wheel.** Canonical reported an ESM update for python3-pip-whl. Neither production node had a virtual environment under /opt, /usr/local or /srv; inspected service and NUERS runtime scripts had no venv/pip bootstrap dependency. Removal simulation identified only python3-pip-whl, python3-venv and python3.12-venv. Staging already ran without them. Production remediation removes those unused bootstrap packages, retains recovery packages locally and does not remove Python itself or application data.
5. **Supplemental WAF normalization gap.** Existing SQL/XSS custom rules matched only raw lowercase substrings. After explicit approval, they retain original matches and add lowercase, URL-decoded and twice-decoded variants, plus form-encoded SQL spaces. Nineteen local corpus cases passed; live retest passed 13/13 (seven blocked attack-pattern variants, six accessible ordinary queries). This is supplemental filtering, not complete SQLi/XSS parsing or a replacement for application validation/parameterized queries.

## Cloudflare policy evidence

Full (strict) origin TLS; TLS 1.2 minimum; TLS 1.3, Always Use HTTPS and HSTS enabled (six months; no preload or subdomain inclusion). Private page/API cache bypass active. Two legacy Page Rules only enforce strict SSL, with no cache-everything override. API rate limit active at 600 requests per IP per 10 seconds, with a 10-second block. Geo-blocking and blanket IPv4 challenge remain disabled. Bot Fight Mode, Browser Integrity Check and the dashboard's always-active managed ruleset/HTTP DDoS protection were observed enabled. No paid plan upgrade was introduced. Account Access setup is not a requirement for the public merchant website.

## Budget

Recorded AWS spend: US$212.53 through Nov14 in Cost Explorer; US$222.004 at the later budget refresh. AWS forecast unavailable due to insufficient history. Approved budget alert reduced from US$1,450 to US$1,300; existing 50/80/95/100% actual and 100% forecast thresholds/recipients preserved.

Planning only: at ₱70/US$, US$1,300 is ₱91,000. US$19/day ×31×1.12 tax allowance +US$50 log allowance +US$5 temporary staging allowance is approximately ₱50,028/month. Traffic, retention volume, exchange rates and other services can change actual spending. Alerts and this estimate are not a hard AWS spending cap. First-day volume/cost needs follow-up measurement; no paid Inspector or Cloudflare upgrade is enabled.

## Evidence

Local evidence directory: `.local/vapt-cloud-2026-11-15/`. Reviewed rollout files and exact WAF expressions: `deployment/security/cloud-observability-2026-11-15/`.

IAM Access Analyzer returned zero findings for the logging policy. Local preparation asserted account/region/service/action/prefix isolation. Post-deployment evidence is recorded below.


## Verified post-deployment results

The rolling update completed at **2026-11-15 14:46:19 UTC**. Both ALB targets are healthy. Each production node now runs kernel **7.0.0-1017-aws** and Chrome **157.0.8103.78**. The release and `.env` hash were preserved. Recovery packages are retained in root-only `/var/backups/nuers-os-20261115/` on each node.

| Check | Result |
|---|---|
| Linux staging before/after reboot | 25 tests, 495 assertions passed on each run; actual sandbox PDF passed |
| Native authenticated checks, each production node | 8,372 checks; 906 explicit admin denials; 52 stateful +18 financial race +29 readiness regressions passed |
| Roles in native fixtures | super_admin, admin, bir, rdo, merchant, client, consumer |
| Actual PDF renderer, each production node | Passed with sandbox preserved |
| Seven local HTTP smoke checks, each production node | Expected 200/401/422 responses |
| Canonical package status, each production node | 889 installed after six-package kernel cleanup; zero reported standard/ESM security updates; no reboot required |
| Public post-update probes | 79/79 passed, nine edge blocks, completed 15:07:12 UTC after kernel cleanup |
| Focused WAF regression | 13/13 passed; seven blocked patterns and six benign query cases |
| ALB/VPC delivery | Real `.log.gz` objects observed; both targets healthy; exact policy verified |

The native harness reports two unavailable route families (`api/bir-eis/receive_invoice_result/{id}` and `api/auth/resend-verification`, 14 combinations each). These are not successful-flow passes. Full external BIR/provider callbacks and real verification-email delivery are outside this non-mutating retest. Prior application evidence is recorded in `NUERS_VAPT_Readiness_Continuation_2026-11-15.md`.

## Limits

Canonical package security-status checks and vendor advisories are not equivalent to an exhaustive installed-package CVE scan. A supplemental Trivy 0.75.0 inventory scan recognized Ubuntu 24.04 and 895 packages/node; its initial zero-package run was rejected as invalid coverage. Actual `/etc/lsb-release`, `/etc/debian_version`, OS release and dpkg package/source versions were then supplied. This scans package metadata, not all files or third-party bundled libraries. Source-package CVE matches need configuration/reachability triage, especially kernel tools/headers. Inspector remains disabled, so an empty Inspector result is not evidence of no vulnerabilities. No volumetric DoS, customer mutation or live financial submission was performed. Edge-blocked tests establish edge enforcement, not backend payload coverage; authenticated role/endpoint checks use isolated native fixtures. Formal external VAPT and periodic scanning remain separate from these development checks. Provider sandbox end-to-end tests, restore drills, alert-recipient delivery and operator response still require separate evidence; this session verified log ingestion and configured budget thresholds, not those operational exercises.

## Final inventory and cost-control outcome

The final inventory scan completed after cleanup. Its summary reports **zero fixable package matches**, with residual source-package matches including **five critical and 222 high entries** (repeated CVEs across kernel-related binary packages, not that many independently demonstrated exploits). The critical entries are assigned to `linux-tools-common`; current 1017 kernel packages also retain vendor-pending high matches. They remain open for applicability/configuration analysis or vendor fixes; no blanket suppression was added.

Both isolated staging instances (`i-0c1a905d6656ce491`, `i-0fd5169e6c271bf76`) were verified **stopped** after scanning. Both production ALB targets were verified healthy. Stopped staging retains EBS storage charges. Complete post-cleanup machine evidence remains in the authorized AWS CloudShell directory `~/nuers-cloud-vapt-20261115/closeout.json`; full package-match reports are retained on stopped staging B under `/srv/nuers-vapt/evidence/os-inventory-20261115/`. The locally collected pre-cleanup classifications are `deployment/security/cloud-observability-2026-11-15/os-scan-before-cleanup.json`.

## Outstanding OS assurance

The deeper inventory scan exposed installed inactive kernel 1014 packages with fixes in the already-running 1017 version. Cleanup completed on staging and then both production nodes at 15:03:25 UTC, with exact recovery `.deb` files saved before removal. Six inactive 1014 packages were removed per node; the active 1017 kernel, boot image/initrd and application remained intact. Staging passed 25 tests/495 assertions and actual PDF generation again; both production nodes repeated all native role checks, actual PDF generation and HTTP checks before rejoining the ALB. Current kernel and linux-tools-common source-package advisories also include matches with no listed fixed version. These are not automatically waived or represented as remotely exploitable application findings. Canonical's current records still label the noble `linux-aws-7.0` package vulnerable for examples [CVE-2014-4323](https://ubuntu.com/security/CVE-2014-4323) and [CVE-2026-98420](https://ubuntu.com/security/CVE-2026-98420); runtime applicability and vendor resolution remain distinct from patch availability. No speculative kernel downgrade or custom kernel build was deployed.

## Primary references

- [AWS ALB access logging](https://docs.aws.amazon.com/elasticloadbalancing/latest/application/enable-access-logging.html)
- [AWS flow log S3 permissions](https://docs.aws.amazon.com/vpc/latest/userguide/flow-logs-s3-permissions.html)
- [Google Chrome Linux security update](https://chromereleases.googleblog.com/2026/11/stable-channel-update-for-desktop_01744469280.html)
- [Canonical pip notice](https://ubuntu.com/security/notices/USN-8344-3)
- [Trivy Ubuntu analyzer and required distribution metadata](https://raw.githubusercontent.com/aquasecurity/trivy/main/pkg/fanal/analyzer/os/ubuntu/ubuntu.go)
- [Trivy OS package scan limitations](https://www.trivy.dev/docs/dev/guide/scanner/vulnerability/)
