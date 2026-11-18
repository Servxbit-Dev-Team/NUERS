# Two-node Linux staging execution

Prepared for the user's requested staging retest and production-permission remediation. This is not a live application deployment.

## Provisioning action

CloudFormation stack `nuers-vapt-20261114` in account `289829301267`, Singapore. Exact template: `vapt-two-node-2026-11-14.json`.

- Two temporary `t3.large` Ubuntu 24.04 amd64 EC2 nodes, each with an encrypted 30 GiB gp3 disk, in existing staging VPC `vpc-050c7723c3bec78e0` / subnet `subnet-00eaf2f6c80544d32`.
- No public inbound traffic or SSH. Only the two test nodes may communicate on application port 8080 and database port 3306. Outbound HTTP/HTTPS is needed for trusted dependency registries and AWS Systems Manager. The staging VPC has no route to the private production VPC.
- New instance role permits SSM management and only reads `staging-documents-sg/vapt-20261114/candidate/*`, writes `.../results/*`. No production S3, database, Secrets Manager, IAM mutation or infrastructure control permissions. No SSM Parameter Store reads.
- IMDSv2 required. Host response hop limit 1; test app containers receive no AWS credentials.
- Four-hour automatic stop and standard CPU credit mode limit unattended compute use. Stop both nodes when testing finishes. Retain disks/artifacts for evidence pending cleanup; do not delete existing staging or production resources.
- Keep the existing stopped staging instance and its historical files untouched. No production DNS, load balancer, targets or release symlinks change.

This creates a new IAM role and network permissions for new test nodes. Browser automation rules require action-time confirmation for that security-sensitive access creation. Provision only after confirmation and template validation.

## Test plan and release gates

1. Assemble backend from the synchronized live release plus the exact remediation manifest. Add reconstructed frontend source/build inventories and synthetic tests. Exclude .env, credentials, databases, customer uploads, old build artifacts and installed dependencies.
2. Install locked dependencies in Linux; verify PHP 8.4.27 and MariaDB 10.11.19. Rebuild frontend from a fresh lockfile installation and compare emitted assets. Record any platform-dependent differences before accepting them.
3. Run the full 805-test suite and separate frontend/session tests. Repeat database boundaries and real concurrency against disposable MariaDB. Use fake mail, disabled webhooks/infrastructure execution and synthetic records only.
4. Start independent app runtimes on Node A and Node B sharing only a synthetic database and the test APP_KEY. Exercise login/MFA across nodes, stale/idle/absolute expiry, replacement-device invalidation, old-logout/new-login and password-reset/login interleavings, concurrent wallet/OTP, tenant isolation and endpoint authorization. Verify runtime DB users are DML-only and cross-database reads are denied.
5. Reinspect production permission consumers. Rehearse the exact targeted DB REVOKE and S3 archive deny. Verify separate operator/recovery access, then apply the authorized narrowing only when production dependency checks are satisfied. Never expose or rotate production credentials as test output.
6. Record per-check evidence, failed runs and fixes. No application release switch until required tests and broader business acceptance pass. Independent authenticated VAPT remains separate from these internal readiness checks; do not label the result a VAPT certificate.

## Budget constraint (user confirmed November 14)

User ceiling: PHP 100,000 per month, interpreted as total NUERS AWS infrastructure including temporary staging. Do not add ongoing capacity without checking current monthly run rate and headroom. This is a spending constraint, not a configured AWS hard billing cap. Keep production available.

Cost Explorer for November 1–13 returned USD 164.475783062 estimated unblended account spend (including USD 17.60 tax). Forecast API returned DataUnavailableException for insufficient history. AWS Pricing returned USD 0.1056 per t3.large Linux hour in Singapore: two nodes for four hours cost USD 0.8448 compute, excluding disks, public IPv4, transfer and tax. PHP 65/USD may be used only as a conservative planning assumption, not a verified exchange-rate quotation. Record updated costs before any further scaling.
