# NUERS staging tester access - approval package
Prepared 16 November 2026, Asia/Manila. Not yet activated.

## Accounts
Create ten synthetic application accounts ONLY in `nuers_vapt_multi` on existing staging node A `i-0c1a905d6656ce491` (10.85.0.70). Exact aliases: super_admin, admin, bir, rdo_a, rdo_b, merchant_a, merchant_b, client_a, client_b, consumer. Emails: `vapt.20261116.<alias>@example.test`. Seven supported application roles; paired Merchant/RDO/Client accounts permit horizontal access tests. Super Admin has full application-level privileges in the synthetic staging database, not AWS/SSH/production privileges.

Seeder: deployment/staging/TesterAccounts.php. Local check: two tests / 99 assertions, including real password login, TOTP verification for all ten, merchant isolation, RDO dashboard, duplicate refusal, and credential/session revocation. Unique random passwords and TOTP seeds generated on staging; store private root-only packet, with a separate local restricted credential PDF after operator retrieval. No production credentials or customer data. Existing accounts remain unchanged. No email is sent.

## Tester access
Proposed URL https://vapt.nuers.net/login. Create one dedicated Cloudflare Tunnel named nuers-vapt-20261116, publishing only that hostname to existing synthetic staging app. Prefer authenticated TLS to origin with a staging-only CA; do not disable certificate verification. No public inbound SSH/database port and no new AWS IAM tester identity. Cloudflare edge HTTPS covers the hostname. Login page is Internet-reachable; application records require the dedicated accounts. This changes the prior staging isolation boundary by adding an external application route.

No production hostname, origin route, database or account change. Do not grant Cloudflare account access to the tester. Tunnel connector must be limited to the staging app origin; no private-network CIDR routes. No public storage bucket or credential download route. No password/token in general handbook or git.

## Duration and costs
Proposed test window: through 23 November 2026 17:00 Asia/Manila. Provision a persistent deadline-based local revocation check and stop the connector/application at expiry; verify timers before exposure. Retain synthetic evidence, not a permanent test service. Only existing node A runs; node B stays stopped unless a separately coordinated multi-node test needs it. No new EC2, ALB, RDS or paid Cloudflare subscription. Planning allowance USD25 (~PHP1750 at a conservative PHP70/USD) for about seven days of existing staging compute/public IPv4, excluding existing baseline costs. Check actual budget before activation. PHP100000 total monthly user ceiling remains binding; USD1300 AWS budget is an alert, not a hard cap.

## Gate before handoff
Check hostname does not replace an existing record, tunnel permissions, application source baseline and isolated side-effect flags. Verify all ten logins and TOTP, authorization boundaries, HTTPS chain, no secret/debug exposure, safe output headers, revocation mechanism and synthetic-only data. State any source/environment differences and provider gaps in PDF. Do not label staging as production-identical without a manifest comparison.

## Revocation
Operator runs guarded TesterAccounts::revoke() to randomize only this batch's passwords and revoke sessions. Stop dedicated connector, remove dedicated hostname route after evidence retention, and stop node A. Keep existing production and other staging resources intact. Tester cannot extend access with the provided application accounts. No credential creation or external publication until action-time confirmation.

## Completed setup — 16 November 2026

The approved staging setup is active at https://vapt.nuers.net/login. Ten synthetic accounts cover seven roles. All ten passed HTTPS login, MFA, session identity and logout checks during provisioning. The dedicated Cloudflare Tunnel routes only to the staging HTTPS origin, with origin TLS verification enabled. Node A is running; Node B is stopped. Public inbound SSH/database access remains closed. The expiry timer is installed for 23 November 2026 at 09:00 UTC.

The owner subsequently requested a simpler initial password while keeping TOTP unchanged. The owner entered and submitted the password in CloudShell. Guarded rotation updated exactly ten staging accounts, preserved their MFA fields and revoked old sessions. Public browser Super Admin and Merchant logins passed with the new password and original authenticator codes. CloudShell-to-public login verification encountered Cloudflare HTTP 403; browser verification succeeded without changing edge policies. The confidential PDF contains the current credentials and unchanged TOTP QR codes. No production account password or MFA setting was changed.

The 691-file tested source overlay is installed on staging. Targeted local and Linux checks passed: 77 tests / 984 assertions. The 947-test local regression run had six PDF-browser sandbox failures; affected classes passed on authorized local browser recheck: 34 tests / 433 assertions. See TEST_RESULTS.json for the precise results. Application storage ownership was corrected and the staging dashboard reports Writable.

The report-source and optional-MFA fixes were deployed to production-20261116-report-mfa1 after both inactive production candidates passed 8,372 isolated request checks each. Both live nodes are healthy. Post-deployment checks confirmed unchanged production MFA enrollment state. The existing opted-out merchant now reaches API Integration, and Sales Reports shows API key names. See DEPLOYMENT_RESULTS.json. No additional compute resources were provisioned for this rollout.

Deliverables: output/pdf/NUERS_VAPT_Tester_Handbook_2026-11-16.pdf (26 pages), separate confidential NUERS_VAPT_Credentials_2026-11-16.pdf (10 pages), and the endpoint checklist CSV. Credential material is git-ignored and mode 0600.
