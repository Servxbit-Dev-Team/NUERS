# NUERS cleanup audit — 20 October 2026

## Completed local cleanup

Removed exact extracted duplicates of retained release ZIPs, regenerable PDF page images, and superseded version 0.0.1 documents. Source code, current builds, dependencies, final version 1.0.0 documents, original screenshot evidence, database, merchant uploads, secrets and all recovery backups are retained.

- Files removed: 12,941
- Empty directories removed: 1,280
- Logical file size removed: 311,493,981 bytes (297.06 MiB)
- Protected files checked: 1,403; changed: 0

Post-cleanup validation: Laravel boots, current frontend manifest references resolve, latest three release archives remain, and the current version 1.0.0 PDF remains. No production database or application code was changed.

## Live cleanup completed after explicit approval

The user explicitly approved the exact 103-entry list. All 103 entries were then permanently removed through Plesk File Manager. The earlier automatic-review block was resolved by this specific authorization.

Completed: 103 entries — 53 superseded release ZIPs and 47 extracted staging folders in the private Home directory, plus 3 old software-identity deployment artifacts in httpdocs. File names and locations below are the complete allowlist. No other entry was removed.

The removed ZIPs/files have retained local copies; their sizes total 342,688,030 bytes (326.81 MiB). Server directory sizes have not been measured. The active scheduler invokes httpdocs/artisan, not these staging directories. Local installers confirm that the selected stage directories were used as copied deployment sources.

Preserve every backup directory and database export, all current October 20 releases, the baseline October 17 release archive and staging folder, runtime directories, logs, audit/deployment results, .env, certificates, keys, uploads, app code, dependencies, current and previous hashed frontend assets, and public downloadable guides.

An unresolved pricing stage variant is excluded. No absence-of-use claim is made for arbitrary source files. Old historical release installers/results remain as deployment evidence.

| Location | Exact target | Type | Retained evidence/copy |
| --- | --- | --- | --- |
| `Home` | `nuers-pricing-stage-20261017` | Staging folder | `.local/pricing-20261017/nuers-pricing-install.php` |
| `Home` | `nuers-branches-stage-20261017` | Staging folder | `.local/branches-20261017/nuers-branches-install.php` |
| `Home` | `nuers-onboarding-stage-20261017-v2` | Staging folder | `.local/registration-documents-20261017/nuers-onboarding-install-v2.php` |
| `Home` | `nuers-wallet-stage-20261017` | Staging folder | `.local/wallet-20261017/nuers-wallet-install.php` |
| `Home` | `nuers-hover-stage-20261017` | Staging folder | `.local/hover-20261017/nuers-hover-install.php` |
| `Home` | `nuers-mfa-design-stage-20261017` | Staging folder | `.local/mfa-20261017/nuers-mfa-design-install.php` |
| `Home` | `nuers-admin-profile-stage-20261017` | Staging folder | `.local/admin-profile-20261017/nuers-admin-profile-install.php` |
| `Home` | `nuers-registration-recovery-stage-20261017` | Staging folder | `.local/registration-recovery-20261017/nuers-registration-recovery-install.php` |
| `Home` | `nuers-form-limits-stage-20261017` | Staging folder | `.local/form-limits-20261017/nuers-form-limits-install.php` |
| `Home` | `nuers-admin-nav-stage-20261017` | Staging folder | `.local/admin-nav-20261017/nuers-admin-nav-install.php` |
| `Home` | `nuers-otp-stage-20261018` | Staging folder | `.local/otp-20261018/nuers-otp-install.php` |
| `Home` | `nuers-business-profile-stage-20261018` | Staging folder | `.local/business-profile-20261018/nuers-business-profile-install.php` |
| `Home` | `nuers-profile-integration-stage-20261018` | Staging folder | `.local/profile-integration-20261018/nuers-profile-integration-install.php` |
| `Home` | `nuers-merchant-admin-stage-20261018` | Staging folder | `.local/merchant-admin-20261018/nuers-merchant-admin-install.php` |
| `Home` | `nuers-merchant-bir-nav-stage-20261018` | Staging folder | `.local/remove-merchant-bir-20261018/nuers-merchant-bir-nav-install.php` |
| `Home` | `nuers-merchant-security-stage-20261018` | Staging folder | `.local/merchant-security-20261018/nuers-merchant-security-install.php` |
| `Home` | `nuers-api-integration-stage-20261018` | Staging folder | `.local/api-integration-20261018/nuers-api-integration-install.php` |
| `Home` | `nuers-admin-business-wallet-stage-20261018` | Staging folder | `.local/admin-business-wallet-20261018/nuers-admin-business-wallet-install.php` |
| `Home` | `nuers-admin-tin-stage-20261018` | Staging folder | `.local/admin-tin-20261018/nuers-admin-tin-install.php` |
| `Home` | `nuers-api-production-stage-20261018` | Staging folder | `.local/api-production-20261018/nuers-api-production-install.php` |
| `Home` | `nuers-mfa-session-stage-20261018` | Staging folder | `.local/mfa-session-20261018/nuers-mfa-session-install.php` |
| `Home` | `nuers-document-100mb-stage-20261018` | Staging folder | `.local/document-100mb-20261018/nuers-document-100mb-install.php` |
| `Home` | `nuers-admin-document-review-stage-20261018` | Staging folder | `.local/admin-document-review-20261018/nuers-admin-document-review-install.php` |
| `Home` | `nuers-review-email-stage-20261018` | Staging folder | `.local/review-email-20261018/nuers-review-email-install.php` |
| `Home` | `nuers-manual-review-mail-stage-20261018` | Staging folder | `.local/manual-review-mail-20261018/nuers-manual-review-mail-install.php` |
| `Home` | `nuers-review-presets-stage-20261018` | Staging folder | `.local/review-presets-20261018/nuers-review-presets-install.php` |
| `Home` | `nuers-mfa-login-session-stage-20261018` | Staging folder | `.local/mfa-login-session-20261018/nuers-mfa-login-session-install.php` |
| `Home` | `nuers-registry-esp-stage-20261018` | Staging folder | `.local/registry-esp-20261018/nuers-registry-esp-install.php` |
| `Home` | `nuers-branch-address-stage-20261018` | Staging folder | `.local/branch-address-20261018/nuers-branch-address-install.php` |
| `Home` | `nuers-table-pagination-stage-20261018` | Staging folder | `.local/table-pagination-20261018/nuers-table-pagination-install.php` |
| `Home` | `nuers-invoice-settings-stage-20261018` | Staging folder | `.local/invoice-settings-20261018/nuers-invoice-settings-install.php` |
| `Home` | `nuers-api-key-simplification-stage-20261018` | Staging folder | `.local/api-key-simplification-20261018/nuers-api-key-simplification-install.php` |
| `Home` | `nuers-api-environments-stage-20261018` | Staging folder | `.local/api-environments-20261018/nuers-api-environments-install.php` |
| `Home` | `nuers-source-profile-discovery-stage-20261018` | Staging folder | `.local/source-profile-discovery-20261018/nuers-source-profile-discovery-install.php` |
| `Home` | `nuers-verified-api-onboarding-stage-20261018` | Staging folder | `.local/verified-api-onboarding-20261018/nuers-verified-api-onboarding-install.php` |
| `Home` | `nuers-merchant-webhooks-stage-20261018` | Staging folder | `.local/merchant-webhooks-20261018/nuers-merchant-webhooks-install.php` |
| `Home` | `nuers-statutory-sales-stage-20261018` | Staging folder | `.local/statutory-sales-20261018/nuers-statutory-sales-install.php` |
| `Home` | `nuers-onboarded-api-fix-stage-20261018` | Staging folder | `.local/onboarded-api-fix-20261018/nuers-onboarded-api-fix-install.php` |
| `Home` | `nuers-admin-connections-stage-20261018` | Staging folder | `.local/admin-merchant-connections-20261018/nuers-admin-connections-install.php` |
| `Home` | `nuers-admin-financial-widgets-stage-20261018` | Staging folder | `.local/admin-financial-widgets-20261018/nuers-admin-financial-widgets-install.php` |
| `Home` | `nuers-merchant-dashboard-wallet-stage-20261018` | Staging folder | `.local/merchant-dashboard-wallet-20261018/nuers-merchant-dashboard-wallet-install.php` |
| `Home` | `nuers-configurable-pricing-stage-20261018` | Staging folder | `.local/pricing-reset-filters-20261018/nuers-configurable-pricing-install.php` |
| `Home` | `nuers-manual-discounts-stage-20261018` | Staging folder | `.local/manual-discounts-20261018/nuers-manual-discounts-install.php` |
| `Home` | `nuers-profile-approval-stage-20261019` | Staging folder | `.local/profile-approval-20261019/nuers-profile-approval-install.php` |
| `Home` | `nuers-google-mfa-devices-stage-20261019` | Staging folder | `.local/google-mfa-devices-20261019/nuers-google-mfa-devices-install.php` |
| `Home` | `nuers-api-customer-receipts-stage-20261019` | Staging folder | `.local/api-customer-receipts-20261019/nuers-api-customer-receipts-install.php` |
| `Home` | `nuers-independent-branding-stage-20261019` | Staging folder | `.local/independent-branding-20261019/nuers-independent-branding-install.php` |
| `Home` | `nuers-pricing-20261017.zip` | File | `.local/pricing-20261017/nuers-pricing-20261017.zip` |
| `Home` | `nuers-pricing-20261017-v2.zip` | File | `.local/pricing-20261017/nuers-pricing-20261017-v2.zip` |
| `Home` | `nuers-branches-20261017.zip` | File | `.local/branches-20261017/nuers-branches-20261017.zip` |
| `Home` | `nuers-onboarding-20261017.zip` | File | `.local/registration-documents-20261017/nuers-onboarding-20261017.zip` |
| `Home` | `nuers-onboarding-20261017-v2.zip` | File | `.local/registration-documents-20261017/nuers-onboarding-20261017-v2.zip` |
| `Home` | `nuers-wallet-20261017.zip` | File | `.local/wallet-20261017/nuers-wallet-20261017.zip` |
| `Home` | `nuers-hover-20261017.zip` | File | `.local/hover-20261017/nuers-hover-20261017.zip` |
| `Home` | `nuers-mfa-design-20261017.zip` | File | `.local/mfa-20261017/nuers-mfa-design-20261017.zip` |
| `Home` | `nuers-admin-profile-20261017.zip` | File | `.local/admin-profile-20261017/nuers-admin-profile-20261017.zip` |
| `Home` | `nuers-registration-recovery-20261017.zip` | File | `.local/registration-recovery-20261017/nuers-registration-recovery-20261017.zip` |
| `Home` | `nuers-form-limits-20261017.zip` | File | `.local/form-limits-20261017/nuers-form-limits-20261017.zip` |
| `Home` | `nuers-admin-nav-20261017.zip` | File | `.local/admin-nav-20261017/nuers-admin-nav-20261017.zip` |
| `Home` | `nuers-otp-20261018.zip` | File | `.local/otp-20261018/nuers-otp-20261018.zip` |
| `Home` | `nuers-business-profile-20261018.zip` | File | `.local/business-profile-20261018/nuers-business-profile-20261018.zip` |
| `Home` | `nuers-profile-integration-20261018.zip` | File | `.local/profile-integration-20261018/nuers-profile-integration-20261018.zip` |
| `Home` | `nuers-merchant-admin-20261018.zip` | File | `.local/merchant-admin-20261018/nuers-merchant-admin-20261018.zip` |
| `Home` | `nuers-merchant-bir-nav-20261018.zip` | File | `.local/remove-merchant-bir-20261018/nuers-merchant-bir-nav-20261018.zip` |
| `Home` | `nuers-merchant-security-20261018.zip` | File | `.local/merchant-security-20261018/nuers-merchant-security-20261018.zip` |
| `Home` | `nuers-api-integration-20261018.zip` | File | `.local/api-integration-20261018/nuers-api-integration-20261018.zip` |
| `Home` | `nuers-admin-business-wallet-20261018.zip` | File | `.local/admin-business-wallet-20261018/nuers-admin-business-wallet-20261018.zip` |
| `Home` | `nuers-admin-tin-20261018.zip` | File | `.local/admin-tin-20261018/nuers-admin-tin-20261018.zip` |
| `Home` | `nuers-api-production-20261018.zip` | File | `.local/api-production-20261018/nuers-api-production-20261018.zip` |
| `Home` | `nuers-mfa-session-20261018.zip` | File | `.local/mfa-session-20261018/nuers-mfa-session-20261018.zip` |
| `Home` | `nuers-document-100mb-20261018.zip` | File | `.local/document-100mb-20261018/nuers-document-100mb-20261018.zip` |
| `Home` | `nuers-admin-document-review-20261018.zip` | File | `.local/admin-document-review-20261018/nuers-admin-document-review-20261018.zip` |
| `Home` | `nuers-review-email-20261018.zip` | File | `.local/review-email-20261018/nuers-review-email-20261018.zip` |
| `Home` | `nuers-manual-review-mail-20261018.zip` | File | `.local/manual-review-mail-20261018/nuers-manual-review-mail-20261018.zip` |
| `Home` | `nuers-review-presets-20261018.zip` | File | `.local/review-presets-20261018/nuers-review-presets-20261018.zip` |
| `Home` | `nuers-mfa-login-session-20261018.zip` | File | `.local/mfa-login-session-20261018/nuers-mfa-login-session-20261018.zip` |
| `Home` | `nuers-registry-esp-20261018.zip` | File | `.local/registry-esp-20261018/nuers-registry-esp-20261018.zip` |
| `Home` | `nuers-branch-address-20261018.zip` | File | `.local/branch-address-20261018/nuers-branch-address-20261018.zip` |
| `Home` | `nuers-table-pagination-20261018.zip` | File | `.local/table-pagination-20261018/nuers-table-pagination-20261018.zip` |
| `Home` | `nuers-invoice-settings-20261018.zip` | File | `.local/invoice-settings-20261018/nuers-invoice-settings-20261018.zip` |
| `Home` | `nuers-api-key-simplification-20261018.zip` | File | `.local/api-key-simplification-20261018/nuers-api-key-simplification-20261018.zip` |
| `Home` | `nuers-api-environments-20261018.zip` | File | `.local/api-environments-20261018/nuers-api-environments-20261018.zip` |
| `Home` | `nuers-source-profile-discovery-20261018.zip` | File | `.local/source-profile-discovery-20261018/nuers-source-profile-discovery-20261018.zip` |
| `Home` | `nuers-verified-api-onboarding-20261018.zip` | File | `.local/verified-api-onboarding-20261018/nuers-verified-api-onboarding-20261018.zip` |
| `Home` | `nuers-merchant-webhooks-20261018.zip` | File | `.local/merchant-webhooks-20261018/nuers-merchant-webhooks-20261018.zip` |
| `Home` | `nuers-statutory-sales-20261018.zip` | File | `.local/statutory-sales-20261018/nuers-statutory-sales-20261018.zip` |
| `Home` | `nuers-onboarded-api-fix-20261018.zip` | File | `.local/onboarded-api-fix-20261018/nuers-onboarded-api-fix-20261018.zip` |
| `Home` | `nuers-admin-connections-20261018.zip` | File | `.local/admin-merchant-connections-20261018/nuers-admin-connections-20261018.zip` |
| `Home` | `nuers-admin-financial-widgets-20261018.zip` | File | `.local/admin-financial-widgets-20261018/nuers-admin-financial-widgets-20261018.zip` |
| `Home` | `nuers-merchant-dashboard-wallet-20261018.zip` | File | `.local/merchant-dashboard-wallet-20261018/nuers-merchant-dashboard-wallet-20261018.zip` |
| `Home` | `nuers-configurable-pricing-20261018.zip` | File | `.local/pricing-reset-filters-20261018/nuers-configurable-pricing-20261018.zip` |
| `Home` | `nuers-manual-discounts-20261018.zip` | File | `.local/manual-discounts-20261018/nuers-manual-discounts-20261018.zip` |
| `Home` | `nuers-profile-approval-20261019.zip` | File | `.local/profile-approval-20261019/nuers-profile-approval-20261019.zip` |
| `Home` | `nuers-google-mfa-devices-20261019.zip` | File | `.local/google-mfa-devices-20261019/nuers-google-mfa-devices-20261019.zip` |
| `Home` | `nuers-api-customer-receipts-20261019.zip` | File | `.local/api-customer-receipts-20261019/nuers-api-customer-receipts-20261019.zip` |
| `Home` | `nuers-doc-assets-20261019.zip` | File | `.local/api-doc-assets-20261019/nuers-doc-assets-20261019.zip` |
| `Home` | `nuers-doc-content-20261019.zip` | File | `.local/api-doc-content-20261019/nuers-doc-content-20261019.zip` |
| `Home` | `nuers-independent-branding-20261019.zip` | File | `.local/independent-branding-20261019/nuers-independent-branding-20261019.zip` |
| `Home` | `nuers-branding-links-20261019.zip` | File | `.local/independent-branding-20261019/nuers-branding-links-20261019.zip` |
| `Home` | `nuers-document-downloads-20261019.zip` | File | `.local/document-downloads-20261019/nuers-document-downloads-20261019.zip` |
| `httpdocs` | `nuers-software-identity-20261019.zip` | File | `.local/system-documentation-20261019/nuers-software-identity-20261019.zip` |
| `httpdocs` | `nuers-software-identity-install.php` | File | `.local/system-documentation-20261019/nuers-software-identity-install.php` |
| `httpdocs` | `nuers-software-identity-run.php` | File | `.local/system-documentation-20261019/nuers-software-identity-run.php` |

## Final live verification

- Home directory: 477 entries before, 377 after; exactly the approved 100 removed.
- Application root: exactly the approved 3 deployment artifacts removed.
- All 68 backup entries and all other Home/application-root entries retained.
- No unapproved removal and no remaining approved target.
- Website, application health, API health, current JavaScript and CSS all returned HTTP 200.
- API health returned NUERS version 1.0.0 with `ok: true`.
- Live JavaScript and CSS SHA-256 hashes match the retained current release.
- No database writes, account changes, merchant-record deletions, code deployment or scheduled-task changes were performed.

## Artifacts

- `local-cleanup-candidates.json`: exact removed file hashes and classification.
- `local-cleanup-result.json`: deletion and protected-file verification result.
- `live-cleanup-candidates.json`: exact approved live targets and retained local archive hashes.
- `live-cleanup-result.json`: completed removals and preservation checks.
- `public-health-final.json`: final public health and current-asset validation.
- `live-cleanup-complete.png`: Plesk screenshot after cleanup.
- `live-scheduler-dependency.txt`: observed NUERS scheduled-task dependency.
- `live-home-refreshed.json` and `live-httpdocs-before.json`: file-manager inventory.

This is housekeeping only; no application deployment or database change is part of this cleanup.
