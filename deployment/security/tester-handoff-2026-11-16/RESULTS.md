## Superseding handoff scope — 16 November 2026

The user narrowed the external tester handoff to **Admin and Merchant only**. The supplied accounts are `admin`, `merchant_a`, and `merchant_b`. The other seven original aliases now have the non-authorized `disabled` role and their active API sessions were revoked. Their records are preserved; no credentials or TOTP secrets of the three retained accounts were changed. Production was not changed.

All three retained accounts passed public HTTPS password login, original TOTP verification, and dashboard loading after the scope change. The retired RDO browser session was redirected to login with `session=invalid`.

The regenerated handbook contains only the retained tester roles, and the confidential credentials PDF contains exactly three account pages. Infrastructure/IP inventory and the original expiry remain included. Historical ten-account evidence below is retained as historical evidence and does not describe current external access.

An RDO-only own-profile bootstrap defect caused the reported loading loop. A narrow fix passed 23 tests / 450 assertions locally and in isolated Linux execution, but was not deployed after the scope was narrowed. Admin and Merchant do not use that missing role branch.

# Completed staging handoff and live fixes

Staging access: https://vapt.nuers.net/login. Ten dedicated synthetic accounts cover Super Admin, Admin, BIR, two RDOs, two Merchants, two Clients and Consumer. The owner-requested initial password is in the confidential PDF. Original TOTP secrets/QR codes remain unchanged. Access expires 23 November 2026 at 17:00 Philippine time.

Node A: 18.143.143.181 public / 10.85.0.70 private, running. Node B: 10.85.0.208 private, stopped. The approved tester entry point is HTTPS through Cloudflare Tunnel. Inventory IPs do not authorize public SSH/database access. Host inspection remains owner-operated through SSM. The full infrastructure inventory, route checklist, role matrix and procedures are in the 26-page handbook.

Production release production-20261116-report-mfa1 is active on both healthy nodes. The optional-MFA credential gates now honor the saved opt-out state; enrolled accounts retain their challenge. The existing merchant's forced-setup loop was reproduced and then verified fixed in the live browser. No existing production account was automatically unenrolled.

Merchant/admin report rows and exports now include API key/source branch metadata. New API transactions snapshot the key name at intake. Historical linked transactions use the current key name, explicitly labeled as current metadata. Manual or unlinked transactions are not assigned a guessed API key. BIR branch codes remain separate identifiers. Live merchant reports display API: POS (current name).

Validation: targeted local and Linux 77 tests / 984 assertions; frontend build and reconstruction checks passed. The full local suite ran 947 tests; six sandbox-blocked PDF failures passed in affected-class rechecks (34 tests / 433 assertions). Before switching traffic, each production node passed 8,372 isolated request checks, 15 report/MFA regressions, 52 stateful regressions, 18 financial-race regressions and 29 prior-readiness regressions. Live deployment verified 254 artifact hashes, permissions, environment preservation and HTTP authentication boundaries on both nodes. Existing MFA state matched before and after rollout.

This handoff is not a VAPT certification. Actual BIR provider authentication/submission remains untested because certification credentials are unavailable. Staging email/provider delivery is deliberately disabled; the endpoint inventory is scope, not a claim that every integration path passed. No new instances or paid Cloudflare services were added; the existing approved temporary staging allowance and expiry remain in force.
