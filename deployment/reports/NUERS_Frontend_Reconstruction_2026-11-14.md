# NUERS frontend reconstruction and local retest

**Date:** 14 November 2026 · **Status:** reconstructed candidate installed locally; no live deployment.

The retained local frontend source has been reconciled against the compiled assets and backend contracts of live release `production-20261106-analytics-grouping2`. The project now typechecks and builds, including the document renderer. This establishes a new maintainable source baseline. It does **not** recover the original developer source, prove exact visual/behavioral equivalence across every screen, or establish a VAPT pass.

## Reconstructed behavior

- Infrastructure fleet monitoring: provider-specific telemetry, freshness/gaps, request/latency/error metrics, database connections and object storage health. Missing readings remain “not reported” rather than invented zero values.
- Capacity requests: provider/resource/tier catalog, automatic compatible tier selection, numeric limits, password plus current authenticator proof, stable idempotency and submission guards.
- AWS power confirmation: prepare/confirm workflow, short-lived proof, preflight blockers, target/action/revision/session binding and one-time use. No live start, stop, restart or purchase was executed.
- Merchant invoices: manual-entry record mode, VAT-exclusive and VAT-inclusive choices, server-provided industry benefit eligibility, duplicate/save feedback and permission-controlled customer lookup.
- Registration accepts the supported TIN branch suffix lengths; merchant receipts use server pagination. Receipt presentation identifies manual entries and added VAT.
- Merchant role editor uses the backend permission catalog and dependency rules. Selecting a dependent action includes its required view permission; removing that view also removes its dependents. Unknown grants are filtered, missing catalogs cannot be saved, and unsaved edits are guarded.
- Customer, report and wallet controls follow the current permission/ownership contracts. Document verification copy and privileged MFA copy reflect current enforcement.
- `npm run typecheck` now invokes `tsc -b` so the solution references are actually checked. The old session-client harness was updated to load current transport dependencies and test the MFA return path, loop prevention and rate-limit behavior.

The four newly reconstructed infrastructure components are `fleet-monitoring.tsx`, `capacity.tsx`, `provider-fields.tsx` and `power-dialog.tsx`. Existing retained components were reconciled with live behavior; the application was not rebuilt from screenshots alone.

## Evidence and reproducibility

| Check | Result |
|---|---|
| TypeScript solution check | Pass (`tsc -b`) |
| Isolated frontend and document-renderer build | Pass |
| Second build against the same locked installed dependencies | **233 files compared; zero hash differences** |
| Installed local candidate vs verified build | All 233 hashes match |
| Original source manifest entries retained | **56 of 56**, plus one explicit authorization applications split |
| Static compiled-string comparison | No globally missing `/api/` URL literals; not a behavioral equivalence proof |
| Frontend regression tests | **9 passed** |
| Session-client scenarios | **11 passed** |
| Complete isolated SQLite PHPUnit run | **805 tests / 10,022 assertions; no failures, errors, skips or risky tests** |
| Infrastructure confirmation boundary tests (included above) | **5 tests / 32 assertions**: expiry, replay, different target/revision/action/idempotency, actor/session binding, role rejection and stale MFA |
| Final MariaDB boundary, endpoint and session suite | **23 tests / 1,648 assertions passed** |
| MariaDB wallet concurrency rerun | **2 tests / 19 assertions passed** |
| MariaDB registration OTP concurrency rerun | **2 tests / 39 assertions passed** |
| PDF regression rerun | **28 tests / 314 assertions passed** with installed Chrome permission |

The first full run in the restricted shell had five PDF rendering failures and two consequent risky tests because Chrome could not launch correctly. All affected test classes then passed with local renderer permission; the entire 805-test suite was rerun successfully in that environment. Failed intermediate logs remain retained rather than being represented as passes. The legacy session-client harness initially failed on its unsupported `./rate-limit` import; after loading the actual current dependencies, all eleven scenarios pass without changing runtime session behavior.

Temporary database/user counts were independently verified as zero after all tests. The synthetic browser service and dedicated MariaDB server were shut down; the normal localhost service remains available.

The final MariaDB security and concurrency results are recorded in `NUERS_VAPT_Retest_Evidence_2026-11-14.json` and the main remediation report. These use MariaDB 10.11.19 and disposable synthetic databases with DML-only runtime accounts, not the copied customer database.

## Browser acceptance performed

Browser checks ran on `http://localhost:8002` with a separate synthetic SQLite database, array mail transport, disabled infrastructure execution and no queue worker. The normal localhost snapshot was not used for test writes.

- Administrator login and TOTP, infrastructure inventory, monitoring and provider/capacity screens render.
- Changing the capacity resource to an object-storage bucket selects the storage tier and hides irrelevant CPU/RAM controls. The request was closed without submission.
- Expiring the synthetic administrator session redirects to `/login?session=invalid` with the expected message.
- Merchant login and TOTP, invoice editor and server-provided industry policy render. A synthetic 10,000 line totals 11,200 with VAT-exclusive pricing and 10,000 after switching to inclusive pricing; no invoice was saved.
- Role dependency selection/removal works and the discard guard protects unsaved changes; no access grant was submitted.
- At 390 px viewport width, the merchant page and role modal have no document-level horizontal overflow. The viewport override was reset afterward.
- No browser console errors were captured. Four transient Recharts initial-container-size warnings were observed on dashboard mount; these are not claimed as zero warnings.

Screenshots: `frontend-reconstruction-2026-11-14/capacity-dialog.png`, `invoice-vat-basis.png`, `role-editor.png` and `role-editor-mobile.png`.

## Source and build backups

`NUERS_Frontend_Reconstruction_Provenance_2026-11-14.json` inventories **532 source/configuration/test/public-asset files** and **233 generated files**, with SHA-256 hashes, archive hashes, baseline entries and comparison limits.

Local backups:

- `.local/frontend-reconstruction-2026-11-14/reconstructed-frontend-source.tar.gz`
- `.local/frontend-reconstruction-2026-11-14/reconstructed-frontend-build.tar.gz`
- `.local/frontend-reconstruction-2026-11-14/retained-source-before.tar.gz`
- `.local/frontend-reconstruction-2026-11-14/live-manifest.json` and `live-document-renderer.mjs`

The source archive includes the source tree, build scripts, lockfile/configurations, static reference data, regression tests and allowlisted public assets. It excludes `.env`, credentials, databases, customer uploads, dependencies, Git and private test evidence. The build archive contains only the verified candidate output and SSR renderer. The old live files remain locally for comparison, but are not part of the candidate build archive.

To reproduce in an isolated checkout without a production environment file: install Node 24.21.0 and the locked dependencies with `npm ci`; run `npm run typecheck`, `npm run build`, `node scripts/test-frontend-reconstruction.mjs` and `node scripts/test-session-client.mjs`. Compare the output with the provenance inventory. The two recorded builds reused the same installed dependency tree; a fresh dependency installation and Linux build remain staging acceptance checks. The normal Laravel build is the tested target; the separate static-hosting build is not claimed as retested or approved for deployment.

## Deliberate differences and remaining gates

The new hashed entry files are `main-UQaxun_z.js` and `main-B1fhC_MF.css`; they are not the original live hashes. MFA guidance is intentionally updated for privileged step-up. Infrastructure warnings are consolidated into the dedicated power dialog instead of the obsolete generic action branch. Registration/customer-import guidance retains the requirements with different text segmentation; role form layout differs slightly from the original compiled layout. Generated chunk organization also differs. These are reconstructed equivalents and security improvements, not a claim of byte-identical recovered source.

Before live: validate this exact candidate on Linux/PHP 8.4.27 with MariaDB 10.11.19 and both web nodes; complete browser/business acceptance for the wider application; apply the reviewed production database identity separation and runtime archive IAM restriction through the rollout gate; and complete independent authenticated VAPT/infrastructure testing. Existing endpoint probes cover registered route/method boundaries, not every payload, role/resource combination or full DOM-XSS/cloud/OS attack surface.

Use the scoped backend remediation manifest together with this frontend provenance and candidate archive. Do not deploy the dirty workspace, copied customer database, `.env`, or all accumulated files in `public/build` wholesale.

## Linux staging verification update

The initial automatic Tailwind scan included generated output and logs, causing cross-platform build differences. `src/index.css` now explicitly scans only `src`. Fresh locked npm installs on macOS and both isolated Ubuntu nodes generated identical SHA-256 inventories across all 233 outputs; a further local repeat matched. The required receipt-reference asset was added to the source/archive inventory (533 files). The verified build is installed on localhost; its served main JavaScript, CSS and receipt asset match the on-disk hashes. Earlier archives and provenance are preserved under `.local/linux-staging-2026-11-14/`. See the updated provenance JSON for current archive hashes.
