> **Approved local remediation follow-up — 2026-10-16T23:12:43+08:00.** The original read-only findings below are historical. Source code, dependencies and localhost schema were subsequently changed under the user’s “Please proceed fix all” authorization. Read [NUERS_REMEDIATION_REAUDIT_2026-10-16.md](./NUERS_REMEDIATION_REAUDIT_2026-10-16.md) and the matrix’s `current_*` columns for the current assessment. **BIR production readiness remains NOT READY.**

# NUERS merchant ingestion, security and capacity audit

Audit date: **16 October 2026 (Asia/Manila)**. Compiled 2026-10-16T22:11:55+08:00.

Target: local working tree `/Users/marksioson/Desktop/NUERS`, branch `main`, HEAD `12f05be63f2134f0b96b592d713fecf31396d2f7`. This checkout has substantial pre-existing modified/untracked application files. Findings apply to the inspected working tree, **not** clean HEAD and **not** an independently inspected production deployment. Laravel lockfile: **v12.58.0**; PHP requirement `^8.2`; React/TypeScript frontend.

Method: source and migration inspection, navigation/form/route mapping, redacted local configuration inspection, existing artifact/log review and official-source browsing. **No application/security/load tests, API requests, database queries, scans, jobs, BIR calls or deployments were executed in this audit.** Existing tests are evidence of available scenarios, not freshly executed verification. Only these seven audit deliverables are added under `docs/audits/`; application/configuration/production data remain unchanged.

## Verdict and control totals

**NOT READY** for the target complete EIS lifecycle. Current synchronous intake can persist an authorized invoice plus encrypted internal copy, but no actual merchant connector/event mapping, offline queue, BIR delivery worker/inquiry/response processor or measured capacity is established. Source-confirmed account/tenant flaws must be contained before isolated security/load validation.

All54 controls:2PASS,27PARTIAL,13FAIL,6NOTFOUND,6NOTVERIFIED,0NOTAPPLICABLE. The full CSV is the authoritative row list. Among merchant M01-M10:4 PARTIAL,2 FAIL,4 NOT FOUND. Among H01-H05:1PARTIAL,1FAIL,3NOTVERIFIED. Among SEC01-SEC10:5PARTIAL,4FAIL,1NOTVERIFIED. No fresh runtime/negative/load tests were executed.

## Connector event/contract comparison

| Connector | Actual source-backed integration | Correct trigger and missing proof | Timing / delivery verdict |
|---|---|---|---|
| POS | Generic `POST /api/integrations/transactions`; free-form source_system/transaction_type and key metadata; no POS-specific driver located | No persisted event map proving finalize vs cart/basket/save/cancel behavior; legal trigger and series authority need review | Immediate synchronous handler only; no event-to-accept p95/p99 or restart-safe offline client queue |
| E-commerce | Same generic endpoint; key display classification infers E-Commerce from source/name text | No Shopify/WooCommerce/order webhook implementation located; order_created/payment_authorized/paid/fulfilled/refunded/cancelled/returned not separately modeled | No reliable signed source-webhook consumer/inbox; source event time/version missing; no measured1-5s SLA |
| ERP/accounting | Same endpoint; source_system string; external_reference | No source invoice/finalization contract, separate ERP event identity or ordered batch connector found | No source-specific retry/order/backpressure proof; no original ERP event timestamp preserved |
| NUERS portal | Invoice Center creates draft; separate merchant invoice path creates new draft or non-draft after authority gate | Draft-to-issued transition, approved series, actual cancellation/recovery workflow incomplete | Atomic internal prep for non-draft works structurally; no downstream BIR completion |

Evidence: `IntegrationContract.php:12-34`; `IntegrationTransactionController.php:25-211,369-469`; `MerchantApiKeyController.php:530-543` is display classification, not connector implementation; `InvoiceCenterController.php:142-175`; `BusinessInvoiceController.php:120-292`. Existing public synthetic `/developer/sandbox` is separate and explicitly does not issue; a key named sandbox on the real ingestion route is not that isolation.

### Required timing signals versus actual

| Boundary | Observed persisted signal | Missing / consequence |
|---|---|---|
| Source business event | No original event_type/event_id/event_at/schema version accepted | Cannot measure merchant sync age or prove tax trigger; offline original dates replaced by server issuance time |
| NUERS request start | microtime start, finally request latency | Handler duration is not event-to-durable-accept p95/p99; per-request logging has post-commit failure risk |
| Accepted/issued | DB created_at/issued_at at server now | Durable response after commit exists; accepted vs issued state/time not separate contract |
| Outbox | prepared_at/hash/encrypted normalized copy | No leased relay send boundary or actual delivery queue age |
| BIR send/ACK | Metadata columns exist, certification simulation possible | No genuine provider timestamps/authentic response pipeline; cannot assert real-time BIR delivery |
| Reconciled | Read-time comparisons and manually reviewed timestamps | No complete scheduled durable outbox-to-ACK reconciliation job or SLA evidence |

## Merchant delivery/authentication gaps

- Hashed credential lookup, active status, transactions:write scope, optional exact-IP restriction and per-merchant data selection are implemented. Environment is not enforced; branch label is not an authority policy. Merchant status/current permit evidence must be checked per request. `BirCompliance::integration` selects latest rather than exact key branch/environment. These failures are independent of the currently closed global production gate.
- Existing idempotency hash rejects changed content and merchant/reference unique index prevents a second logical transaction insert. It omits source/branch/family namespace and lacks concurrent duplicate winner recovery. Random invoice generation is not an authorized sequence. A changed client field, ambiguous normalized customer input or repeated reference from a different connector can alter replay behavior; specify exact canonical request identity.
-201 returns transaction/receipt IDs after commit, while duplicate200 only returns transaction ID. No API-key scoped polling route/Location or full state contract exists. Request logging in finally and last-used update occur after commit; an exception there can obscure the successful durable operation. Daily log counting is also part of admission, creating write/read amplification.
- Webhook records encrypt a signing secret but explicitly say delivery not enabled. No signing/verification, timestamp nonce replay guard, scheduler, circuit breaker or DLQ was located. No active arbitrary-webhook SSRF is claimed because the sender is absent; DNS/redirect/metadata restrictions must precede enabling it.
- No encrypted persistent merchant offline event queue, ordered reconnect or manual-invoice contingency register/linkage was located. Do not treat browser localStorage session/device values as an offline invoice queue.

## High-traffic bottleneck register

| Area | Evidence and observed mechanism | Risk / proposed investigation (not a measured failure) |
|---|---|---|
| Inline HTTP write path | `IntegrationTransactionController.php:25-219` performs gates/schema checks/receipt/transaction/prep/audit/key/log in request | Acceptance latency includes DB/crypto/audit work and per-call schema introspection; profile under representative legitimate staging traffic |
| Audit serialization | `BirCompliance.php:155-161` locks one global head | Every audited write shares a lock; assess wait-time/throughput and external append integrity before partitioning |
| Tenant serialization | `IntegrationTransactionController.php:126`, `InvoiceCenterController.php:151`, registry/permit/integration global lock helpers | A hot tenant or global administrative operation can queue writers; preserve consistency while measuring contention |
| Per-key quota/log cost | `IntegrationTransactionController.php:324-366` count daily log then write in finally | Non-atomic concurrent budget; invalid requests still log; missing compound key/time index in initial merchant log schema; verify actual DDL/query plan before proposing indexes |
| Canonical query | `IssuedDocumentLedger.php:25-87` correlated subqueries and window partitions over union | At scale may repeatedly scan/sort source population; need representative EXPLAIN, indexes or durable canonical index/read model |
| Report memory/N+1 | `OperationsReportController.php:15-25,101-138` loads all rows before pagination and per-source readiness inspection | Bounded UI pages do not bound server work; move grouping/pagination/materialization to measured efficient query/read models |
| Live-monitor polling | `LiveMonitoringController.php:28-38` scans last hour transaction timestamps in chunks per request | Chunking bounds memory but not work;1M/hour repeatedly scanned by operators can amplify DB load; aggregate time buckets/probe caching needed |
| Audit integrity polling | `BirCompliance::auditIntegrity` cursor scans entire journal; compliance/audit views call it | Growing O(history) work per page; independent checkpoints and incremental verified status needed |
| Legacy generic reads | `TableController.php:114-119` limit optional and no bounded maximum | Large tenant/admin queries can consume memory/DB capacity; enforce keyset pagination/page maxima and allowed indexes |
| Local state and storage | Local env file cache/session, sync queue; uploads to public_path; `config/filesystems.php:31-59` | Not a demonstrated horizontally scalable deployment. Shared secure state/object storage/broker must be configured and tested |
| Dependency health | Health returns static ok in route; admin checks read stored health rows; System UI uses random CPU | No current probe freshness/availability proof; false green claims conceal queue/storage/database saturation |
| BIR external constraints | No authorized throughput/rate-limit/inquiry contract | NUERS local acceptance benchmark cannot certify BIR throughput; maintain bounded backlog and deadline risk under provider quotas |

No capacity figures are fabricated. Required1M/hour and2K burst remain NOT VERIFIED, and no load request was sent. Hardware proposal is2Web+2DB+3Storage,64physical cores/128GB each per internal design. Actual nodes, effective cores, LB, DB witness/quorum/fencing, independent failure domains, storage quorum, broker, monitoring and DR are not supplied deployment evidence. Two DB nodes alone do not prove split-brain-safe promotion.

## SQL injection and API security assessment

First-party runtime SQL uses Laravel query builder and bound values for searches/TIN/filter/date comparisons. Inspected raw expressions are predominantly fixed SQL fragments or fixed helper identifiers. Table names in the generic API are limited by TABLES; source-based services choose fixed tables after source validation. `TableController.php:288-317` still accepts caller-supplied column/filter/order identifiers without per-table field/operator allowlists. Laravel identifier quoting means this is **not demonstrated SQL injection**; it is incomplete validation/query-surface hardening and can yield invalid/expensive queries. The import repair SQL PREPARE/EXECUTE selects constant DDL statements and was not executed. No SQL injection/brute force/network scan was performed.

More urgent confirmed failures are authorization, not injection: anonymous RDO writes/password changes; anonymous legacy financial/dashboard/RDO reads and expense upserts/GET synchronization (`MerchantExpenseController.php:16-168,217-226`); self-asserted profile/TIN/name authorization; environment/branch gate omissions. They must be fixed even if every SQL value is parameterized. New generic TableController protections do not cover every legacy route.

Human bearer tokens reside as direct API tokens in DB and localStorage without central TTL/request-time device binding/MFA. Password hashing and device login rotation exist; OAuth uses state/existing account linkage. Laravel web CSRF/cookie defaults apply to web routes, while the API uses explicit bearer credentials; do not call missing API CSRF alone a vulnerability when ambient cookie authentication is not used. Production cookie Secure/HttpOnly/SameSite/TLS and proxy trust are external deployment checks, not validated by localhost settings. Login/registration/sandbox have some explicit throttles; there is no default global API throttle in the inspected custom bootstrap/routes and critical legacy RDO has none.

### Defense layers and current evidence

| Layer | Status | Evidence / required next evidence |
|---|---|---|
| Edge L3/L4 mitigation | NOT VERIFIED | Provider service/mitigation scope/contacts/contract not supplied |
| CDN/WAF/L7 bots/budgets | NOT VERIFIED | No actual provider rule export/bot challenges/rate-limit contract inspected |
| Origin shielding / trusted proxies | NOT VERIFIED | No origin ACL/LB/proxy config inspected; optional IP whitelist accuracy depends on correct forwarding trust |
| App quotas | FAIL for high-traffic target | Some per-IP route throttles; non-atomic per-key daily COUNT and no bounded shared fair queue |
| DB/queue/storage/management network isolation | NOT VERIFIED | Local MySQL bind127.0.0.1 is development-only; no production firewall/segmentation/egress evidence |
| TLS/certificate/security headers | NOT VERIFIED deployment; PARTIAL repo | Laravel cookies/config exist; no repository CSP/HSTS/secure-header policy; TLS1.2+/1.3 must be verified at deployed edge |
| Secrets | PARTIAL | API key hashing and encrypted stored provider/outbox data; no managed vault/KMS/custody evidence; plaintext bearer-token lifecycle gap |
| Vulnerability management | PARTIAL | Lockfiles/tests/incident records; no supplied current SBOM/scanner/VAPT/patch evidence; no dependency audit executed |
| Backup/DR | NOT VERIFIED | Prior local SQL backup metadata exists; static dashboard restore pass/RTO/RPO not trustworthy as operational results |
| Uploads | PARTIAL | Image/MIME/size controls; public uploads use client original extension. MIME validation is not proof of safe extension; a content/extension mismatch could be executable if deployed PHP handler serves that suffix. No exploit performed; server upload execution policy unverified |

## Navigation-to-implementation audit

Frontend routes are defined in `src/App.tsx:155-403`; current sidebar in `src/components/enterprise/enterprise-shell.tsx:13-43`. SPA role checks are presentation controls and do not replace API policy. The following covers every navigation category in the brief and associated forms/modals; source-only UI accessibility/interaction checks remain NOT VERIFIED.

| Navigation page / route | Source implementation and backend | Verdict / forms or missing behavior |
|---|---|---|
| Dashboard `/super-admin` | Enterprise dashboard API + legacy dashboard APIs; `enterprise-dashboard.tsx`, `EnterpriseDashboardController.php:18` | Persisted metrics and scoped new API; old unguarded dashboard remains; ACK/source population differs. Search/profile settings inherit API checks |
| Live Transaction Monitor `/monitoring` | `LiveMonitoringController.php:20`, `monitoring/transmissions.tsx` | Real transaction window/TPS; search/date/pause/columns/export are UI/read operations. Mixed-environment latest history and no full canonical source population; queue health/alerts incomplete |
| Electronic Invoices & Receipts `/invoices` | `InvoiceCenterController.php:13-175`; `invoices/center.tsx`, export/readiness components | Scoped filters, preview, draft create, export dialog and evidence workflow; draft type/price-basis semantics incomplete. PDF/QR not BIR proof |
| Electronic Invoicing Engine `/engine` | `engine/index.tsx:28-89` | Static processing queue/capability metrics; not a real worker, no durable operation for depicted queue |
| Transactions `/super-admin/transactions` | `TransactionWorkspaceController.php:17-154`; `operations/workspace.tsx` | Canonical source/history filters/detail/export; role guarded; no actual BIR delivery. Legacy `/transactions/overview` remains unsafe |
| BIR Transmission Center `/super-admin/eis-transmissions` | `api/eis-transmissions.tsx`, `EisController.php:224-431` | Payload validation/queue/retry/synthetic ACK controls persist local records; no verified provider delivery. Form environment/test flags cannot prove synthetic payload contains no real data |
| Reconciliation & Exceptions `/super-admin/reconciliation` | `OperationsReportController.php:15-225`, OperationsWorkspace | Source comparisons/manual review reason/revision; no owner/priority/independent closure, complete durable scheduler or outbox lease reconciliation |
| Summary List of Sales `/super-admin/tax-filings` | `SalesSummaryCenter` -> OperationsWorkspace -> operations-report | Internal calculated draft; date/business/branch grouping/export functional source; official format/delivery not verified |
| Registered Businesses `/super-admin/merchants` | `BusinessRegistryController.php`, `business-registry/index.tsx` | Create/edit/review/invite/import/export/TIN check have admin role/revision/evidence guards; legacy name fallback elsewhere bypasses this membership trust |
| Taxpayer Onboarding `/super-admin/taxpayer-onboarding` | Same registry + `BusinessRegistrationController.php:72` | Review checks and pending status exist; public legacy signup bypasses stronger email/TIN/password/consent schema |
| PTI/PTT `/super-admin/pti-ptt` | `TaxpayerAuthorizationController.php:20,115-189`, permits UI | Separate authorities/device/observation/import; date/revision/role rules exist; branch and mandatory permit binding in issuance incomplete |
| API Integrations `/super-admin/api-platform` | `IntegrationWorkspaceController.php:15,98-246`; integrations UI | Revision-checked connection/credential/cert/endpoint/webhook forms; encrypted secret; local-only diagnostics; no real sender or status adapter |
| Merchant API keys `/merchant/api-keys` | `MerchantApiKeyController.php:147-276` | Hashed one-time key, scoped owner, revoke/config; no key expiry/MFA, free-form scope/IP/branch fields, unaudited key changes, no enforced environment at intake |
| ESP Accreditation `/accreditation` | `EspComplianceController.php:13-105`, accreditation UI | Annex/stage/evidence/certificate controls; external reviewer role requirement; no independent authenticity/module/deployment evidence |
| Compliance Management `/super-admin/compliance` | `admin/compliance.tsx`; separate `compliance/center.tsx` static tables | Mixed legacy operational/placeholder views; no complete material-change deploy guard or independently proven compliance |
| Security & Incidents `/super-admin/security`, `/super-admin/soc-operations` | SOC pages static arrays; actual incident API `EspComplianceController.php:108` used elsewhere | Security dashboards not independent detection; actual record/report/restoration modal exists but notification/escalation not complete |
| Immutable Audit Logs `/super-admin/audit` | `audit/log.tsx` and `LiveMonitoringController.php:147`; `BirCompliance.php:150` | Hash chain and paginated view/export; no external immutable checkpoint/NTP/DB-privilege assurance; export uses same DB trust |
| Data Governance & Portability `/super-admin/data-archive` | `archive/center.tsx:15-50`; admin JSON export separate | Static WORM/hold/retention; no actual hold/release or complete taxpayer migration workflow |
| Analytics & Reports `/super-admin/reports`, `/analytics` | `admin/reports.tsx`, `analytics/center.tsx:29`, legacy data API | Multiple static/stored and operational readers; inconsistent populations/claims; unguarded legacy aggregate routes remain |
| BIR Reports & Exports | Tax-filings alias and admin report pages; actual CSV/JSON controllers | Admin exports audited/escaped in modern paths; no prescribed format validation, taxpayer complete-export control or delivery-SLA tracker |
| Infrastructure Health `/super-admin/system-health` | `system/admin.tsx:26-45`; Enterprise reads stored health rows | CPU randomized/service arrays; no real topology/probe collector/freshness or failing dependency proof |
| Backup & DR `/super-admin/backups` | `bcdr/center.tsx:13-41` | Static job/site/drill/RTO/RPO values; no executable restore/failover or verified object/DB redundancy |
| Configurations & Changes `/system` | SystemAdmin static UI; real integration config elsewhere | No material-change/release approval pipeline; interface switches/labels not deployed policies |
| Users & Permissions `/super-admin/users-rbac` | `admin/users.tsx`, generic profile endpoint | Some server allowlists preserve roles/TIN; no MFA/step-up/verified BIR staff provisioning; anonymous RDO bypass remains |
| Client Support & SLA `/super-admin/support` | `SupportChatController.php:11-66`; support-inbox/chat | Real scoped token/messages/search/triage/revision/idempotency; no independently measured regulatory support SLA/escalation routing |
| Website Subscriptions `/super-admin/subscriptions` | `SubscriptionPlanController.php`; plan forms | Admin revision/type/range controls; catalogue storage offer cannot override retention/hold or taxpayer portability rights |
| Merchant Expenses / B2B Tax Center | `MerchantExpenseController.php:16-168,217-226`; `MerchantTaxCenterController.php:19-47` | Critical: default-merchant fallback allows unauthenticated read/upsert; GET can write sync data; caller-supplied tax/claim statuses unverified. Legacy tax aggregation is not the canonical SLS policy |
| Merchant invoice create/detail | `BusinessInvoiceController.php:120`, `merchant/invoice-create.tsx`, `invoice-detail.tsx:136` | Server arithmetic improved; client initial status and date/type rules incomplete; cancel only toast; correction does not pass original ID |
| Client profile/receipt/TIN/security pages | ClientProfile + Enterprise citizen scope + generic signup | Profile/avatar functional; identity association not verified on legacy signup; MFA display flag is not enforcement |

## Form/control audit dimensions

This read-only audit distinguishes server implementation from unexecuted UX checks. Backend schemas are indexed below with exact source method/line; policies and missing guarantees are in the navigation table and54-control matrix. No disabled or hidden input is accepted as authorization. The route inventory includes aliases and endpoints with no visible form.

| Form group | Server schema and authorization | Atomicity, audit, double submit | Confirmation / accessibility / loading/error |
|---|---|---|---|
| Login/signup/OAuth | AuthController/GoogleAuthController; signup nested meta weak; privileged MFA absent | Device token DB transaction; signup User/Profile not one transaction; auth event coverage incomplete; explicit login throttle | OAuth state/handoff present; UI states source-only; keyboard/native behavior NOT VERIFIED |
| Business registration/email/activation | RegistrationAddress/Industry and password+consent+verification schema; invitation token; legacy signup alternate bypass | Registration/create transaction; invitations one-use, revision/hash guards; email code expiry/rate limits | Pending review/dev delivery explicit; no repeated unavailable legal evidence requested; UX NOT VERIFIED |
| Legacy RDO | Field validation exists; NO authentication guard | User/profile/office writes not one outer transaction; no controlled permission audit | Critical unauthorized change; no confirmation can replace auth; UI not tested |
| Invoice draft/issue/correct/cancel | Shared cents plus field rules; role/merchant lookup; no exact branch/env gate | Atomic issue/prep; draft UUID idempotency; random series and incomplete initial-state logic; cancel no mutation | Cancellation confirm may appear but handler is fake success; source loading/error states exist; UX NOT VERIFIED |
| Invoice/transaction/SLS export filters | Modern role/tenant scope and date/type allowlists; generic identifiers still caller-controlled | Read endpoints may audit/export; bounded row transfer does not bound report aggregation; no export fair queue | Dropdown/date validation and empty/error source states; real interaction/a11y NOT VERIFIED |
| Registry/onboarding/import/review/invitation | Admin write/BIR read, strong schemas and revision/evidence | Global lock, request/hash/revision/audit; documented checks before activation | Reason/decision modals present; not proof real BIR validation; UX NOT VERIFIED |
| Permits/devices/import/observation | Admin write/BIR read; authority type/date/status/evidence; device bound to currentPTT | Global lock/revision/idempotency/audit; separate device observations never establish gateway connection | Forms and local status distinct; no live testing; UX NOT VERIFIED |
| Integration/credential/public-cert/endpoint/webhook | Admin writes; URL/size/X509/revision rules; unverified DNS destination/trust | Lock/revision/audit; secrets encrypted/masked; webhook delivery disabled | One-time key/secret handling needs operational security; UX NOT VERIFIED |
| Transmission/validate/retry/synthetic ACK | Administrative user helper includes BIR role; production gate closed; is_test required for cert | Payload/transmission/retry transaction, bounded retry, manual cert idempotency; no worker/inquiry | Synthetic ACK explicit; no unauthorized BIR send; retry/ACK privilege separation finer than broad admin helper remains incomplete |
| Historical branch/coverage | Admin only; five digits/evidence/revision; ownership-scoped read | Append-only branch fingerprint/revision + prep atomically; coverage revision/audit | Genuine evidence absent remains pending; no test review inserted; UX NOT VERIFIED |
| Reconciliation review / incident reporting | Admin reason/revision; incident date/report refs; no evidence-based closure proof | Review transaction/audit; incident transaction/audit; no assigned case/notification scheduler | Missing owner/priority and independent closure; source UI alone not complete workflow |
| Merchant expense / tax claims | Required amount/reference fields but no enforced login in fallback; optional tax and arbitrary status strings trusted | `updateOrInsert` can overwrite expense/tax/claim state; GET synchronization writes; no reviewed source/approval validation | Read-only GET must become side-effect-free; no UI confirmation can substitute for tenant authority |
| Account profile/avatar/logo/generic table | Token + owner checks vary; generic field names allowlisted but types/ranges weak; name fallback unsafe | File move before DB update; profile writes multi-table nontransactional; key/profile event coverage incomplete | Public branding intentional but client suffix risk; uploader error/keyboard NOT VERIFIED |
| Support/triage and subscriptions | Admin inbox, hashed visitor token; bounded messages; plan admin revision | Message request idempotency/conversation locks/events; triage revision; pricing updated under reviewed role | Loading/error/empty source states present; actual accessibility/confirmation not tested |
| Static engine/SOC/System/BCDR/Archive controls | No applicable backend operation/schema located for depicted assurances | No durable state transition, legal hold, backup/restore or real resource mutation demonstrated | Must be disabled/marked demonstration/unavailable until implemented; never infer success from page appearance |

## Appendix A - Server-side literal validation field index

Every detected literal `Request::validate`/`Validator::make` schema in first-party controllers is indexed below. Fields listed are the actual declared schema keys; exact rule ranges/conditional Rule objects are in the linked source, not replaced by a guessed shared policy. Helper schemas additionally include `IntegrationContract::rules`, `InvoiceCalculator` and the `BusinessRegistryController::validated` / `TaxpayerAuthorizationController::validated` helpers. Generic `values`, `eq`, `in`, `or`, `order`, `ascending`, `limit`, `single`, `maybeSingle`, `head` need explicit types/identifier bounds beyond their present normalization. Indexing fields does not mean every field passed malformed-input/accessibility testing.

| Source/method | Schema start | Declared field keys |
|---|---|---|
| `app/Http/Controllers/Api/AuthController.php::login` | [32](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:32) | `email`, `password` |
| `app/Http/Controllers/Api/AuthController.php::signup` | [58](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/AuthController.php:58) | `email`, `password`, `meta`, `meta.role` |
| `app/Http/Controllers/Api/BusinessAccountController.php::update` | [40](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:40) | `business_name`, `owner_name`, `tin`, `sector`, `business_type`, `bir_registration_date`, `vat_registered`, `email`, `phone`, `address`, `country`, `country_code`, `city`, `city_code`, `barangay`, `barangay_code`, `zip_code`, `region`, `rdo_code`, `rdo_name`, `website`, `employee_count`, `annual_revenue`, `pos_system`, `branch_count`, `notes` |
| `app/Http/Controllers/Api/BusinessAccountController.php::uploadLogo` | [103](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessAccountController.php:103) | `logo` |
| `app/Http/Controllers/Api/BusinessInvoiceController.php::store` | [126](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessInvoiceController.php:126) | `invoice_number`, `document_type`, `status`, `buyer_name`, `buyer_tin`, `buyer_address`, `buyer_email`, `issue_date`, `due_date`, `delivery_date`, `period_start`, `period_end`, `reference_number`, `purchase_order`, `sales_order`, `original_invoice_id`, `correction_reason`, `qr_payload`, `digital_signature`, `validation_hash`, `formats_generated`, `formats_generated.*`, `notes`, `terms_and_conditions`, `footer_note`, `line_items`, `line_items.*.line_number`, `line_items.*.item_code`, `line_items.*.description`, `line_items.*.unit`, `line_items.*.quantity`, `line_items.*.unit_price`, `line_items.*.discount_pct`, `line_items.*.discount_amount`, `line_items.*.taxable_amount`, `line_items.*.vat_rate`, `line_items.*.vat_amount`, `line_items.*.line_total`, `line_items.*.tax_type` |
| `app/Http/Controllers/Api/BusinessRegistrationController.php::locations` | [33](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistrationController.php:33) | `level`, `parent` |
| `app/Http/Controllers/Api/BusinessRegistrationController.php::resolveRdo` | [38](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistrationController.php:38) | `city_code`, `barangay_code` |
| `app/Http/Controllers/Api/BusinessRegistrationController.php::sendCode` | [44](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistrationController.php:44) | `email` |
| `app/Http/Controllers/Api/BusinessRegistrationController.php::verifyCode` | [58](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistrationController.php:58) | `challenge_id`, `code` |
| `app/Http/Controllers/Api/BusinessRegistrationController.php::store` | [74](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistrationController.php:74) | `email`, `password`, `tin`, `business_name`, `street_address`, `zip_code`, `region_code`, `province_code`, `city_code`, `barangay_code`, `business_type`, `industry`, `industry_other`, `rdo_code`, `contact_name`, `contact_position`, `mobile`, `vat_registered`, `subscription_plan_id`, `terms_accepted`, `privacy_accepted`, `notice_version`, `challenge_id`, `verification_token`, `role`, `status` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::query` | [67](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:67) | `search`, `type`, `status`, `eis`, `from`, `to`, `rdo`, `region`, `city`, `industry`, `vat`, `account`, `stage`, `application_type`, `sort`, `per_page`, `page` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::validated` | [169](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:169) | `tin`, `business_name`, `owner_name`, `email`, `phone`, `business_type`, `sector`, `industry_other`, `address`, `city`, `region`, `rdo_code`, `vat_registered`, `bir_registration_date`, `website`, `notes`, `application_type`, `structured_address` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::validated` | [181](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:181) | `region_code`, `province_code`, `city_code`, `barangay_code`, `street_address`, `zip_code` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::store` | [203](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:203) | `request_id` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::update` | [225](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:225) | `revision`, `reason` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::review` | [247](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:247) | `revision`, `reason`, `status`, `stage`, `checks`, `checks.*`, `evidence` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::validateTin` | [283](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:283) | `tin` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::importPreview` | [331](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:331) | `file`, `request_id` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::invitation` | [416](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:416) | `request_id`, `reason` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::activation` | [444](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:444) | `token` |
| `app/Http/Controllers/Api/BusinessRegistryController.php::activate` | [458](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/BusinessRegistryController.php:458) | `token`, `password`, `terms_accepted`, `privacy_accepted`, `notice_version` |
| `app/Http/Controllers/Api/CitizenApprovalController.php::update` | [71](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/CitizenApprovalController.php:71) | `status` |
| `app/Http/Controllers/Api/ClientProfileController.php::update` | [33](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/ClientProfileController.php:33) | `full_name`, `organization`, `email`, `mobile` |
| `app/Http/Controllers/Api/ClientProfileController.php::uploadAvatar` | [88](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/ClientProfileController.php:88) | `avatar` |
| `app/Http/Controllers/Api/DeveloperSandboxController.php::run` | [16](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/DeveloperSandboxController.php:16) | `is_test`, `action`, `scenario`, `payload` |
| `app/Http/Controllers/Api/EisController.php::transmissions` | [149](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisController.php:149) | `page`, `per_page`, `from`, `to`, `environment`, `document` |
| `app/Http/Controllers/Api/EisReadinessController.php::index` | [39](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisReadinessController.php:39) | `page`, `per_page` |
| `app/Http/Controllers/Api/EisReadinessController.php::branchReview` | [68](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisReadinessController.php:68) | `branch_code`, `evidence_reference`, `revision` |
| `app/Http/Controllers/Api/EisReadinessController.php::coverage` | [77](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EisReadinessController.php:77) | `merchant_id`, `branch_code`, `coverage_status`, `effective_from`, `effective_to`, `evidence_reference`, `revision` |
| `app/Http/Controllers/Api/EspComplianceController.php::evidence` | [48](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:48) | `category`, `code`, `evidence_reference` |
| `app/Http/Controllers/Api/EspComplianceController.php::review` | [73](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:73) | `category`, `code`, `status`, `evidence_reference` |
| `app/Http/Controllers/Api/EspComplianceController.php::certificate` | [96](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:96) | `certificate_number`, `issued_at`, `expires_at`, `annual_review_at`, `configuration_reference`, `evidence_reference` |
| `app/Http/Controllers/Api/EspComplianceController.php::incident` | [111](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:111) | `title`, `severity`, `detected_at`, `evidence_reference`, `merchant_id` |
| `app/Http/Controllers/Api/EspComplianceController.php::updateIncident` | [124](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/EspComplianceController.php:124) | `restored_at`, `reported_at`, `report_reference` |
| `app/Http/Controllers/Api/IntegrationWorkspaceController.php::index` | [75](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:75) | `page`, `per_page`, `search`, `from`, `to`, `environment`, `method`, `status` |
| `app/Http/Controllers/Api/IntegrationWorkspaceController.php::save` | [107](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:107) | `request_id`, `revision`, `name`, `provider`, `merchant_id`, `environment`, `base_url`, `client_id`, `api_version`, `timeout_seconds`, `max_retries`, `status`, `evidence`, `reason` |
| `app/Http/Controllers/Api/IntegrationWorkspaceController.php::credential` | [141](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:141) | `revision`, `secret`, `revoke`, `reason` |
| `app/Http/Controllers/Api/IntegrationWorkspaceController.php::certificate` | [161](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:161) | `revision`, `file`, `reason` |
| `app/Http/Controllers/Api/IntegrationWorkspaceController.php::endpoint` | [184](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:184) | `connection_id`, `revision`, `name`, `method`, `path`, `description`, `enabled`, `reason` |
| `app/Http/Controllers/Api/IntegrationWorkspaceController.php::webhook` | [210](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:210) | `connection_id`, `revision`, `url`, `events`, `events.*`, `status`, `secret`, `evidence`, `reason` |
| `app/Http/Controllers/Api/IntegrationWorkspaceController.php::export` | [252](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/IntegrationWorkspaceController.php:252) | `search`, `from`, `to` |
| `app/Http/Controllers/Api/InvoiceCenterController.php::filterValues` | [43](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:43) | `search`, `merchant_id`, `from`, `to`, `status`, `type`, `per_page`, `page` |
| `app/Http/Controllers/Api/InvoiceCenterController.php::store` | [146](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/InvoiceCenterController.php:146) | `request_id`, `merchant_id`, `document_type`, `buyer_name`, `buyer_tin`, `buyer_address`, `issue_date`, `items`, `items.*.description`, `items.*.quantity`, `items.*.unit_price`, `vat_inclusive` |
| `app/Http/Controllers/Api/MerchantApiKeyController.php::store` | [154](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:154) | `name`, `environment`, `scopes`, `scopes.*`, `ip_whitelist`, `ip_whitelist.*`, `rate_limit_per_day`, `branch_type`, `branch_code`, `branch_name`, `branch_location` |
| `app/Http/Controllers/Api/MerchantApiKeyController.php::update` | [215](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantApiKeyController.php:215) | `active`, `name`, `scopes`, `scopes.*`, `ip_whitelist`, `ip_whitelist.*`, `rate_limit_per_day`, `branch_type`, `branch_code`, `branch_name`, `branch_location` |
| `app/Http/Controllers/Api/MerchantExpenseController.php::store` | [59](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/MerchantExpenseController.php:59) | `source_invoice_reference`, `purchase_order_reference`, `supplier_name`, `supplier_tin`, `expense_category`, `description`, `gross_amount`, `vatable_amount`, `input_vat_amount`, `withholding_tax_amount`, `payment_status`, `validation_status`, `reconciliation_status`, `claim_status`, `risk_level`, `issued_at`, `due_at` |
| `app/Http/Controllers/Api/OperationsReportController.php::index` | [104](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:104) | `mode`, `group`, `review`, `exception`, `review_status` |
| `app/Http/Controllers/Api/OperationsReportController.php::review` | [209](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/OperationsReportController.php:209) | `status`, `reason`, `revision` |
| `app/Http/Controllers/Api/RdoController.php::store` | [26](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/RdoController.php:26) | `rdo_code`, `rdo_name`, `region`, `city`, `office_address`, `head_name`, `email`, `phone`, `password`, `coverage_area`, `notes` |
| `app/Http/Controllers/Api/RdoMappingController.php::store` | [47](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/RdoMappingController.php:47) | `city_code`, `barangay_code`, `rdo_code`, `source_reference`, `active`, `revision` |
| `app/Http/Controllers/Api/SubscriptionPlanController.php::update` | [31](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/SubscriptionPlanController.php:31) | `name`, `description`, `badge`, `fee_centavos`, `minimum_transaction_centavos`, `storage_mb`, `retention_years`, `featured`, `published`, `position`, `revision` |
| `app/Http/Controllers/Api/SupportChatController.php::create` | [26](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/SupportChatController.php:26) | `id`, `visitor_token`, `request_id`, `name`, `email`, `subject`, `message` |
| `app/Http/Controllers/Api/SupportChatController.php::inbox` | [37](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/SupportChatController.php:37) | `search`, `status`, `priority`, `page`, `unread` |
| `app/Http/Controllers/Api/SupportChatController.php::messages` | [46](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/SupportChatController.php:46) | `after`, `before` |
| `app/Http/Controllers/Api/SupportChatController.php::send` | [53](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/SupportChatController.php:53) | `request_id`, `body` |
| `app/Http/Controllers/Api/SupportChatController.php::read` | [61](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/SupportChatController.php:61) | `message_id` |
| `app/Http/Controllers/Api/SupportChatController.php::update` | [62](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/SupportChatController.php:62) | `revision`, `status`, `priority`, `assigned_to` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::index` | [77](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:77) | `page`, `per_page`, `search`, `from`, `to` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::validated` | [118](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:118) | `merchant_id`, `kind`, `reference`, `environment`, `scope`, `issued_at`, `valid_until`, `status`, `evidence`, `reason` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::store` | [131](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:131) | `request_id` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::update` | [153](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:153) | `revision` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::device` | [182](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:182) | `authorization_id`, `name`, `serial_number`, `branch`, `device_type`, `status`, `reason`, `revision`, `request_id` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::observation` | [212](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:212) | `request_id`, `status`, `evidence`, `reason`, `observed_at` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::export` | [254](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:254) | `from`, `to`, `search` |
| `app/Http/Controllers/Api/TaxpayerAuthorizationController.php::preview` | [265](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TaxpayerAuthorizationController.php:265) | `file` |
| `app/Http/Controllers/Api/TransactionWorkspaceController.php::filtered` | [50](/Users/marksioson/Desktop/NUERS/app/Http/Controllers/Api/TransactionWorkspaceController.php:50) | `search`, `from`, `to`, `status`, `type`, `bir`, `vat`, `branch`, `payment`, `region`, `rdo`, `min`, `max`, `page`, `per_page`, `sort` |
| `app/Services/IntegrationContract.php::rules` | [12](/Users/marksioson/Desktop/NUERS/app/Services/IntegrationContract.php:12) | `external_reference`, `source_system`, `channel`, `transaction_type`, `payment_method`, `document_type`, `amount`, `gross_amount`, `tax_type`, `vat_rate`, `vat_inclusive`, `customer_name`, `customer_tin`, `branch`, `branch_name`, `branch_code`, `branch_type`, `receipt_number`, `items` (nested item rules missing at request layer; calculator validates amounts later) |

## Appendix B - Complete literal API route inventory

`routes/api.php` contains134 route declarations. Methods are literal declarations; `match` expands to five methods, implicit HEAD/framework routes are not counted. Controller guards are manual in many paths: route middleware absence alone is not proof of anonymous access. The dangerous handlers are specifically traced in F01-F03. This inventory also records mutating/read endpoints with no UI. Registered GETs for verification, exports, diagnostics/cache/session can write logs/state; none was invoked during audit. Framework/web routes add `/up`, OAuth redirect/callback/session and SPA/view fallbacks (`routes/web.php:6-13`).

| API declaration line | HTTP | Path (under `/api`) | Handler | Explicit route throttle |
|---|---|---|---|---|
| [routes/api.php:28](/Users/marksioson/Desktop/NUERS/routes/api.php:28) | GET | `/legal/policies` | `inline closure / static response` | none declared |
| [routes/api.php:30](/Users/marksioson/Desktop/NUERS/routes/api.php:30) | GET | `/health` | `inline closure / static response` | none declared |
| [routes/api.php:32](/Users/marksioson/Desktop/NUERS/routes/api.php:32) | GET | `/registration/options` | `BusinessRegistrationController::options` | none declared |
| [routes/api.php:33](/Users/marksioson/Desktop/NUERS/routes/api.php:33) | GET | `/registration/locations` | `BusinessRegistrationController::locations` | none declared |
| [routes/api.php:34](/Users/marksioson/Desktop/NUERS/routes/api.php:34) | GET | `/registration/rdo` | `BusinessRegistrationController::resolveRdo` | none declared |
| [routes/api.php:35](/Users/marksioson/Desktop/NUERS/routes/api.php:35) | POST | `/registration/email-code` | `BusinessRegistrationController::sendCode` | 5,1 |
| [routes/api.php:36](/Users/marksioson/Desktop/NUERS/routes/api.php:36) | POST | `/registration/verify-code` | `BusinessRegistrationController::verifyCode` | 15,1 |
| [routes/api.php:37](/Users/marksioson/Desktop/NUERS/routes/api.php:37) | POST | `/registration/business` | `BusinessRegistrationController::store` | 5,1 |
| [routes/api.php:39](/Users/marksioson/Desktop/NUERS/routes/api.php:39) | POST | `/support/conversations` | `SupportChatController::create` | 5,1 |
| [routes/api.php:40](/Users/marksioson/Desktop/NUERS/routes/api.php:40) | GET | `/support/conversations/{id}` | `SupportChatController::messages` | none declared |
| [routes/api.php:41](/Users/marksioson/Desktop/NUERS/routes/api.php:41) | POST | `/support/conversations/{id}/messages` | `SupportChatController::send` | 20,1 |
| [routes/api.php:42](/Users/marksioson/Desktop/NUERS/routes/api.php:42) | POST | `/support/conversations/{id}/read` | `SupportChatController::read` | none declared |
| [routes/api.php:43](/Users/marksioson/Desktop/NUERS/routes/api.php:43) | GET | `/admin/support` | `SupportChatController::inbox` | none declared |
| [routes/api.php:44](/Users/marksioson/Desktop/NUERS/routes/api.php:44) | GET | `/admin/support/{id}` | `SupportChatController::adminMessages` | none declared |
| [routes/api.php:45](/Users/marksioson/Desktop/NUERS/routes/api.php:45) | POST | `/admin/support/{id}/messages` | `SupportChatController::adminSend` | 40,1 |
| [routes/api.php:46](/Users/marksioson/Desktop/NUERS/routes/api.php:46) | POST | `/admin/support/{id}/read` | `SupportChatController::adminRead` | none declared |
| [routes/api.php:47](/Users/marksioson/Desktop/NUERS/routes/api.php:47) | PATCH | `/admin/support/{id}` | `SupportChatController::update` | none declared |
| [routes/api.php:49](/Users/marksioson/Desktop/NUERS/routes/api.php:49) | GET | `/subscription-plans` | `SubscriptionPlanController::index` | none declared |
| [routes/api.php:50](/Users/marksioson/Desktop/NUERS/routes/api.php:50) | GET | `/admin/subscription-plans` | `SubscriptionPlanController::manage` | none declared |
| [routes/api.php:51](/Users/marksioson/Desktop/NUERS/routes/api.php:51) | PUT | `/admin/subscription-plans/{id}` | `SubscriptionPlanController::update` | none declared |
| [routes/api.php:53](/Users/marksioson/Desktop/NUERS/routes/api.php:53) | GET | `/invoice-center` | `InvoiceCenterController::index` | none declared |
| [routes/api.php:54](/Users/marksioson/Desktop/NUERS/routes/api.php:54) | GET | `/invoice-center/export` | `InvoiceCenterController::export` | none declared |
| [routes/api.php:55](/Users/marksioson/Desktop/NUERS/routes/api.php:55) | GET | `/invoice-center/businesses` | `InvoiceCenterController::merchants` | none declared |
| [routes/api.php:56](/Users/marksioson/Desktop/NUERS/routes/api.php:56) | POST | `/invoice-center` | `InvoiceCenterController::store` | none declared |
| [routes/api.php:57](/Users/marksioson/Desktop/NUERS/routes/api.php:57) | GET | `/invoice-center/{source}/{id}` | `InvoiceCenterController::show` | none declared |
| [routes/api.php:59](/Users/marksioson/Desktop/NUERS/routes/api.php:59) | GET | `/auth/session` | `AuthController::session` | none declared |
| [routes/api.php:60](/Users/marksioson/Desktop/NUERS/routes/api.php:60) | POST | `/auth/login` | `AuthController::login` | 10,1 |
| [routes/api.php:61](/Users/marksioson/Desktop/NUERS/routes/api.php:61) | POST | `/auth/signup` | `AuthController::signup` | 5,1 |
| [routes/api.php:62](/Users/marksioson/Desktop/NUERS/routes/api.php:62) | POST | `/auth/logout` | `AuthController::logout` | none declared |
| [routes/api.php:63](/Users/marksioson/Desktop/NUERS/routes/api.php:63) | POST | `/merchant-accounts` | `AuthController::createMerchantAccount` | none declared |
| [routes/api.php:64](/Users/marksioson/Desktop/NUERS/routes/api.php:64) | GET | `/client/profile` | `ClientProfileController::show` | none declared |
| [routes/api.php:65](/Users/marksioson/Desktop/NUERS/routes/api.php:65) | PUT | `/client/profile` | `ClientProfileController::update` | none declared |
| [routes/api.php:66](/Users/marksioson/Desktop/NUERS/routes/api.php:66) | POST | `/client/profile/avatar` | `ClientProfileController::uploadAvatar` | none declared |
| [routes/api.php:67](/Users/marksioson/Desktop/NUERS/routes/api.php:67) | GET | `/business-accounts/current` | `BusinessAccountController::current` | none declared |
| [routes/api.php:68](/Users/marksioson/Desktop/NUERS/routes/api.php:68) | PUT | `/business-accounts/current` | `BusinessAccountController::update` | none declared |
| [routes/api.php:69](/Users/marksioson/Desktop/NUERS/routes/api.php:69) | POST | `/business-accounts/current/logo` | `BusinessAccountController::uploadLogo` | none declared |
| [routes/api.php:70](/Users/marksioson/Desktop/NUERS/routes/api.php:70) | GET | `/business-accounts/lookup` | `BusinessAccountController::lookupByTin` | none declared |
| [routes/api.php:71](/Users/marksioson/Desktop/NUERS/routes/api.php:71) | GET | `/citizen-approvals` | `CitizenApprovalController::index` | none declared |
| [routes/api.php:72](/Users/marksioson/Desktop/NUERS/routes/api.php:72) | PATCH | `/citizen-approvals/{id}` | `CitizenApprovalController::update` | none declared |
| [routes/api.php:73](/Users/marksioson/Desktop/NUERS/routes/api.php:73) | GET | `/business-invoices` | `BusinessInvoiceController::index` | none declared |
| [routes/api.php:74](/Users/marksioson/Desktop/NUERS/routes/api.php:74) | POST | `/business-invoices` | `BusinessInvoiceController::store` | none declared |
| [routes/api.php:75](/Users/marksioson/Desktop/NUERS/routes/api.php:75) | GET | `/business-invoices/{invoice}` | `BusinessInvoiceController::show` | none declared |
| [routes/api.php:76](/Users/marksioson/Desktop/NUERS/routes/api.php:76) | GET | `/merchant/api-keys` | `MerchantApiKeyController::index` | none declared |
| [routes/api.php:77](/Users/marksioson/Desktop/NUERS/routes/api.php:77) | POST | `/merchant/api-keys` | `MerchantApiKeyController::store` | none declared |
| [routes/api.php:78](/Users/marksioson/Desktop/NUERS/routes/api.php:78) | PATCH | `/merchant/api-keys/{id}` | `MerchantApiKeyController::update` | none declared |
| [routes/api.php:79](/Users/marksioson/Desktop/NUERS/routes/api.php:79) | DELETE | `/merchant/api-keys/{id}` | `MerchantApiKeyController::destroy` | none declared |
| [routes/api.php:80](/Users/marksioson/Desktop/NUERS/routes/api.php:80) | GET | `/merchant/pos-devices` | `MerchantApiKeyController::connections` | none declared |
| [routes/api.php:81](/Users/marksioson/Desktop/NUERS/routes/api.php:81) | GET | `/merchant/api-branches` | `MerchantApiKeyController::branches` | none declared |
| [routes/api.php:82](/Users/marksioson/Desktop/NUERS/routes/api.php:82) | GET | `/merchant/expenses` | `MerchantExpenseController::index` | none declared |
| [routes/api.php:83](/Users/marksioson/Desktop/NUERS/routes/api.php:83) | POST | `/merchant/expenses` | `MerchantExpenseController::store` | none declared |
| [routes/api.php:84](/Users/marksioson/Desktop/NUERS/routes/api.php:84) | GET | `/merchant/receipts` | `MerchantReceiptController::index` | none declared |
| [routes/api.php:85](/Users/marksioson/Desktop/NUERS/routes/api.php:85) | GET | `/merchant/receipts/verify` | `MerchantReceiptController::verify` | none declared |
| [routes/api.php:86](/Users/marksioson/Desktop/NUERS/routes/api.php:86) | GET | `/merchant/transactions` | `MerchantTransactionController::index` | none declared |
| [routes/api.php:87](/Users/marksioson/Desktop/NUERS/routes/api.php:87) | GET | `/merchant/tax-center` | `MerchantTaxCenterController::show` | none declared |
| [routes/api.php:88](/Users/marksioson/Desktop/NUERS/routes/api.php:88) | POST | `/integrations/transactions` | `IntegrationTransactionController::store` | none declared |
| [routes/api.php:89](/Users/marksioson/Desktop/NUERS/routes/api.php:89) | GET | `/dashboards/{portal}` | `DashboardController::show` | none declared |
| [routes/api.php:90](/Users/marksioson/Desktop/NUERS/routes/api.php:90) | GET | `/enterprise-dashboards/{portal}` | `EnterpriseDashboardController::show` | none declared |
| [routes/api.php:91](/Users/marksioson/Desktop/NUERS/routes/api.php:91) | GET | `/live-monitoring` | `LiveMonitoringController::show` | none declared |
| [routes/api.php:92](/Users/marksioson/Desktop/NUERS/routes/api.php:92) | GET | `/live-monitoring/audit` | `LiveMonitoringController::auditLog` | none declared |
| [routes/api.php:93](/Users/marksioson/Desktop/NUERS/routes/api.php:93) | GET | `/live-monitoring/export` | `LiveMonitoringController::export` | none declared |
| [routes/api.php:94](/Users/marksioson/Desktop/NUERS/routes/api.php:94) | GET | `/esp-compliance` | `EspComplianceController::show` | none declared |
| [routes/api.php:95](/Users/marksioson/Desktop/NUERS/routes/api.php:95) | POST | `/esp-compliance/applications` | `EspComplianceController::application` | none declared |
| [routes/api.php:96](/Users/marksioson/Desktop/NUERS/routes/api.php:96) | POST | `/esp-compliance/applications/{id}/evidence` | `EspComplianceController::evidence` | none declared |
| [routes/api.php:97](/Users/marksioson/Desktop/NUERS/routes/api.php:97) | POST | `/esp-compliance/applications/{id}/reviews` | `EspComplianceController::review` | none declared |
| [routes/api.php:98](/Users/marksioson/Desktop/NUERS/routes/api.php:98) | POST | `/esp-compliance/applications/{id}/certificate` | `EspComplianceController::certificate` | none declared |
| [routes/api.php:99](/Users/marksioson/Desktop/NUERS/routes/api.php:99) | POST | `/esp-compliance/incidents` | `EspComplianceController::incident` | none declared |
| [routes/api.php:100](/Users/marksioson/Desktop/NUERS/routes/api.php:100) | PATCH | `/esp-compliance/incidents/{id}` | `EspComplianceController::updateIncident` | none declared |
| [routes/api.php:101](/Users/marksioson/Desktop/NUERS/routes/api.php:101) | GET | `/admin/rdo-mappings` | `RdoMappingController::index` | none declared |
| [routes/api.php:102](/Users/marksioson/Desktop/NUERS/routes/api.php:102) | POST | `/admin/rdo-mappings` | `RdoMappingController::store` | none declared |
| [routes/api.php:103](/Users/marksioson/Desktop/NUERS/routes/api.php:103) | GET | `/business-registry/export` | `BusinessRegistryController::export` | none declared |
| [routes/api.php:104](/Users/marksioson/Desktop/NUERS/routes/api.php:104) | GET | `/business-registry/template` | `BusinessRegistryController::template` | none declared |
| [routes/api.php:105](/Users/marksioson/Desktop/NUERS/routes/api.php:105) | GET | `/business-registry/tin` | `BusinessRegistryController::validateTin` | none declared |
| [routes/api.php:106](/Users/marksioson/Desktop/NUERS/routes/api.php:106) | POST | `/business-registry/import` | `BusinessRegistryController::importPreview` | none declared |
| [routes/api.php:107](/Users/marksioson/Desktop/NUERS/routes/api.php:107) | POST | `/business-registry/import/{id}` | `BusinessRegistryController::importCommit` | none declared |
| [routes/api.php:108](/Users/marksioson/Desktop/NUERS/routes/api.php:108) | GET | `/business-registry` | `BusinessRegistryController::index` | none declared |
| [routes/api.php:109](/Users/marksioson/Desktop/NUERS/routes/api.php:109) | POST | `/business-registry` | `BusinessRegistryController::store` | none declared |
| [routes/api.php:110](/Users/marksioson/Desktop/NUERS/routes/api.php:110) | GET | `/business-registry/{id}` | `BusinessRegistryController::show` | none declared |
| [routes/api.php:111](/Users/marksioson/Desktop/NUERS/routes/api.php:111) | PUT | `/business-registry/{id}` | `BusinessRegistryController::update` | none declared |
| [routes/api.php:112](/Users/marksioson/Desktop/NUERS/routes/api.php:112) | POST | `/business-registry/{id}/review` | `BusinessRegistryController::review` | none declared |
| [routes/api.php:113](/Users/marksioson/Desktop/NUERS/routes/api.php:113) | POST | `/business-registry/{id}/invitation` | `BusinessRegistryController::invitation` | none declared |
| [routes/api.php:114](/Users/marksioson/Desktop/NUERS/routes/api.php:114) | POST | `/business-activation/inspect` | `BusinessRegistryController::activation` | 30,1 |
| [routes/api.php:115](/Users/marksioson/Desktop/NUERS/routes/api.php:115) | POST | `/business-activation` | `BusinessRegistryController::activate` | 10,1 |
| [routes/api.php:116](/Users/marksioson/Desktop/NUERS/routes/api.php:116) | GET | `/operations-report/export` | `OperationsReportController::export` | none declared |
| [routes/api.php:117](/Users/marksioson/Desktop/NUERS/routes/api.php:117) | GET | `/operations-report` | `OperationsReportController::index` | none declared |
| [routes/api.php:118](/Users/marksioson/Desktop/NUERS/routes/api.php:118) | POST | `/operations-report/{id}/review` | `OperationsReportController::review` | none declared |
| [routes/api.php:119](/Users/marksioson/Desktop/NUERS/routes/api.php:119) | GET | `/eis-readiness` | `EisReadinessController::index` | none declared |
| [routes/api.php:120](/Users/marksioson/Desktop/NUERS/routes/api.php:120) | POST | `/eis-readiness/coverage` | `EisReadinessController::coverage` | none declared |
| [routes/api.php:121](/Users/marksioson/Desktop/NUERS/routes/api.php:121) | POST | `/eis-readiness/{source}/{id}/branch-review` | `EisReadinessController::branchReview` | 20,1 |
| [routes/api.php:122](/Users/marksioson/Desktop/NUERS/routes/api.php:122) | POST | `/eis-readiness/{source}/{id}/prepare` | `EisReadinessController::prepare` | 30,1 |
| [routes/api.php:123](/Users/marksioson/Desktop/NUERS/routes/api.php:123) | GET | `/transaction-workspace/export` | `TransactionWorkspaceController::export` | none declared |
| [routes/api.php:124](/Users/marksioson/Desktop/NUERS/routes/api.php:124) | GET | `/transaction-workspace` | `TransactionWorkspaceController::index` | none declared |
| [routes/api.php:125](/Users/marksioson/Desktop/NUERS/routes/api.php:125) | GET | `/transaction-workspace/{id}` | `TransactionWorkspaceController::show` | none declared |
| [routes/api.php:126](/Users/marksioson/Desktop/NUERS/routes/api.php:126) | GET | `/transactions/overview` | `TransactionController::overview` | none declared |
| [routes/api.php:127](/Users/marksioson/Desktop/NUERS/routes/api.php:127) | GET | `/tax-intelligence/{scope}` | `TaxIntelligenceController::show` | none declared |
| [routes/api.php:128](/Users/marksioson/Desktop/NUERS/routes/api.php:128) | GET | `/bir-eis/readiness` | `EisController::readiness` | none declared |
| [routes/api.php:129](/Users/marksioson/Desktop/NUERS/routes/api.php:129) | GET | `/bir-eis/transmissions` | `EisController::transmissions` | none declared |
| [routes/api.php:130](/Users/marksioson/Desktop/NUERS/routes/api.php:130) | POST | `/bir-eis/validate` | `EisController::validatePayload` | none declared |
| [routes/api.php:131](/Users/marksioson/Desktop/NUERS/routes/api.php:131) | POST | `/bir-eis/transmissions` | `EisController::storeTransmission` | none declared |
| [routes/api.php:132](/Users/marksioson/Desktop/NUERS/routes/api.php:132) | POST | `/bir-eis/transmissions/{id}/retry` | `EisController::retry` | none declared |
| [routes/api.php:133](/Users/marksioson/Desktop/NUERS/routes/api.php:133) | POST | `/bir-eis/transmissions/{id}/acknowledge` | `EisController::acknowledge` | none declared |
| [routes/api.php:134](/Users/marksioson/Desktop/NUERS/routes/api.php:134) | GET | `/rdos` | `RdoController::index` | none declared |
| [routes/api.php:135](/Users/marksioson/Desktop/NUERS/routes/api.php:135) | POST | `/rdos` | `RdoController::store` | none declared |
| [routes/api.php:136](/Users/marksioson/Desktop/NUERS/routes/api.php:136) | GET | `/rdo/dashboard` | `RdoController::dashboard` | none declared |
| [routes/api.php:137](/Users/marksioson/Desktop/NUERS/routes/api.php:137) | GET | `/locations/countries` | `LocationController::countries` | none declared |
| [routes/api.php:138](/Users/marksioson/Desktop/NUERS/routes/api.php:138) | GET | `/locations/cities` | `LocationController::cities` | none declared |
| [routes/api.php:139](/Users/marksioson/Desktop/NUERS/routes/api.php:139) | GET | `/locations/barangays` | `LocationController::barangays` | none declared |
| [routes/api.php:140](/Users/marksioson/Desktop/NUERS/routes/api.php:140) | GET | `/locations/rdo` | `LocationController::rdo` | none declared |
| [routes/api.php:142](/Users/marksioson/Desktop/NUERS/routes/api.php:142) | GET | `/verify-receipt/{receiptNumber}` | `inline closure / static response` | none declared |
| [routes/api.php:177](/Users/marksioson/Desktop/NUERS/routes/api.php:177) | GET/POST/PUT/PATCH/DELETE | `/tables/{table}` | `TableController::handle` | none declared |
| [routes/api.php:179](/Users/marksioson/Desktop/NUERS/routes/api.php:179) | GET | `/taxpayer-authorizations/export` | `TaxpayerAuthorizationController::export` | none declared |
| [routes/api.php:180](/Users/marksioson/Desktop/NUERS/routes/api.php:180) | GET | `/taxpayer-authorizations/template` | `TaxpayerAuthorizationController::template` | none declared |
| [routes/api.php:181](/Users/marksioson/Desktop/NUERS/routes/api.php:181) | POST | `/taxpayer-authorizations/import` | `TaxpayerAuthorizationController::preview` | none declared |
| [routes/api.php:182](/Users/marksioson/Desktop/NUERS/routes/api.php:182) | POST | `/taxpayer-authorizations/import/{id}` | `TaxpayerAuthorizationController::commit` | none declared |
| [routes/api.php:183](/Users/marksioson/Desktop/NUERS/routes/api.php:183) | GET | `/taxpayer-authorizations` | `TaxpayerAuthorizationController::index` | none declared |
| [routes/api.php:184](/Users/marksioson/Desktop/NUERS/routes/api.php:184) | POST | `/taxpayer-authorizations` | `TaxpayerAuthorizationController::store` | none declared |
| [routes/api.php:185](/Users/marksioson/Desktop/NUERS/routes/api.php:185) | POST | `/taxpayer-devices` | `TaxpayerAuthorizationController::device` | none declared |
| [routes/api.php:186](/Users/marksioson/Desktop/NUERS/routes/api.php:186) | PUT | `/taxpayer-devices/{id}` | `TaxpayerAuthorizationController::device` | none declared |
| [routes/api.php:187](/Users/marksioson/Desktop/NUERS/routes/api.php:187) | POST | `/taxpayer-devices/{id}/observations` | `TaxpayerAuthorizationController::observation` | none declared |
| [routes/api.php:188](/Users/marksioson/Desktop/NUERS/routes/api.php:188) | GET | `/taxpayer-authorizations/{id}` | `TaxpayerAuthorizationController::show` | none declared |
| [routes/api.php:189](/Users/marksioson/Desktop/NUERS/routes/api.php:189) | PUT | `/taxpayer-authorizations/{id}` | `TaxpayerAuthorizationController::update` | none declared |
| [routes/api.php:191](/Users/marksioson/Desktop/NUERS/routes/api.php:191) | GET | `/integration-workspace` | `IntegrationWorkspaceController::index` | none declared |
| [routes/api.php:192](/Users/marksioson/Desktop/NUERS/routes/api.php:192) | GET | `/integration-workspace/export` | `IntegrationWorkspaceController::export` | none declared |
| [routes/api.php:193](/Users/marksioson/Desktop/NUERS/routes/api.php:193) | POST | `/integration-workspace/connections` | `IntegrationWorkspaceController::save` | none declared |
| [routes/api.php:194](/Users/marksioson/Desktop/NUERS/routes/api.php:194) | PUT | `/integration-workspace/connections/{id}` | `IntegrationWorkspaceController::save` | none declared |
| [routes/api.php:195](/Users/marksioson/Desktop/NUERS/routes/api.php:195) | POST | `/integration-workspace/connections/{id}/credential` | `IntegrationWorkspaceController::credential` | 10,1 |
| [routes/api.php:196](/Users/marksioson/Desktop/NUERS/routes/api.php:196) | POST | `/integration-workspace/connections/{id}/certificate` | `IntegrationWorkspaceController::certificate` | none declared |
| [routes/api.php:197](/Users/marksioson/Desktop/NUERS/routes/api.php:197) | POST | `/integration-workspace/connections/{id}/diagnostics` | `IntegrationWorkspaceController::diagnose` | 10,1 |
| [routes/api.php:198](/Users/marksioson/Desktop/NUERS/routes/api.php:198) | POST | `/integration-workspace/endpoints` | `IntegrationWorkspaceController::endpoint` | none declared |
| [routes/api.php:199](/Users/marksioson/Desktop/NUERS/routes/api.php:199) | PUT | `/integration-workspace/endpoints/{id}` | `IntegrationWorkspaceController::endpoint` | none declared |
| [routes/api.php:200](/Users/marksioson/Desktop/NUERS/routes/api.php:200) | POST | `/integration-workspace/webhooks` | `IntegrationWorkspaceController::webhook` | none declared |
| [routes/api.php:201](/Users/marksioson/Desktop/NUERS/routes/api.php:201) | PUT | `/integration-workspace/webhooks/{id}` | `IntegrationWorkspaceController::webhook` | none declared |
| [routes/api.php:203](/Users/marksioson/Desktop/NUERS/routes/api.php:203) | POST | `/developer/sandbox` | `DeveloperSandboxController::run` | 20,1 |
| [routes/api.php:204](/Users/marksioson/Desktop/NUERS/routes/api.php:204) | GET | `/developer/openapi` | `inline closure / static response` | none declared |
| [routes/api.php:205](/Users/marksioson/Desktop/NUERS/routes/api.php:205) | GET | `/developer/catalog` | `inline closure / static response` | none declared |

## Appendix C - JSX form/modal source inventory

The list below indexes every first-party page/component file containing a literal form or Dialog/AlertDialog/Sheet/Modal root in the inspected folders (plus custom conditional modals in registry/permit/integration/workspace pages covered above). JSX marker counts include trigger/content tags, not a claimed number of distinct forms. Field/server mapping is in Appendix A/B and the form-group table. Reused primitives, implicit controls and runtime rendering require the proposed approved E2E pass; no full interactive/a11y PASS is claimed. Components with no server counterpart are explicitly unavailable/static in the navigation table.

| JSX source | Marker evidence lines | Backend mapping group |
|---|---|---|
| [src/components/enterprise/enterprise-shell.tsx](/Users/marksioson/Desktop/NUERS/src/components/enterprise/enterprise-shell.tsx:115) | 115 | Other operational/legacy UI; backend guarantees not inferred |
| [src/components/invoices/eis-readiness-panel.tsx](/Users/marksioson/Desktop/NUERS/src/components/invoices/eis-readiness-panel.tsx:38) | 38, 44 | Invoice/exports/readiness |
| [src/components/invoices/export-documents.tsx](/Users/marksioson/Desktop/NUERS/src/components/invoices/export-documents.tsx:62) | 62, 63 | Invoice/exports/readiness |
| [src/components/support/chat.tsx](/Users/marksioson/Desktop/NUERS/src/components/support/chat.tsx:23) | 23, 31 | Support/triage |
| [src/pages/accreditation/esp.tsx](/Users/marksioson/Desktop/NUERS/src/pages/accreditation/esp.tsx:36) | 36, 38, 39 | Accreditation/evidence |
| [src/pages/admin/client-accounts.tsx](/Users/marksioson/Desktop/NUERS/src/pages/admin/client-accounts.tsx:341) | 341, 342 | Generic/admin/support/catalogue; see route inventory |
| [src/pages/admin/merchants.tsx](/Users/marksioson/Desktop/NUERS/src/pages/admin/merchants.tsx:1255) | 1255, 1256, 1582, 1583, 1754, 1755, 1949, 1950, 1970, 1971 | Generic/admin/support/catalogue; see route inventory |
| [src/pages/admin/subscription-plans.tsx](/Users/marksioson/Desktop/NUERS/src/pages/admin/subscription-plans.tsx:17) | 17 | Generic/admin/support/catalogue; see route inventory |
| [src/pages/admin/transactions.tsx](/Users/marksioson/Desktop/NUERS/src/pages/admin/transactions.tsx:306) | 306, 307 | Generic/admin/support/catalogue; see route inventory |
| [src/pages/admin/users.tsx](/Users/marksioson/Desktop/NUERS/src/pages/admin/users.tsx:405) | 405, 406, 451, 452 | Generic/admin/support/catalogue; see route inventory |
| [src/pages/api/eis-transmissions.tsx](/Users/marksioson/Desktop/NUERS/src/pages/api/eis-transmissions.tsx:454) | 454, 455 | EIS/integration; some legacy UIs static |
| [src/pages/api/keys.tsx](/Users/marksioson/Desktop/NUERS/src/pages/api/keys.tsx:101) | 101, 107 | EIS/integration; some legacy UIs static |
| [src/pages/api/webhooks.tsx](/Users/marksioson/Desktop/NUERS/src/pages/api/webhooks.tsx:83) | 83, 87 | EIS/integration; some legacy UIs static |
| [src/pages/auth/client-register.tsx](/Users/marksioson/Desktop/NUERS/src/pages/auth/client-register.tsx:134) | 134 | Login/signup/registration |
| [src/pages/auth/login.tsx](/Users/marksioson/Desktop/NUERS/src/pages/auth/login.tsx:130) | 130 | Login/signup/registration |
| [src/pages/auth/register.tsx](/Users/marksioson/Desktop/NUERS/src/pages/auth/register.tsx:39) | 39, 46 | Login/signup/registration |
| [src/pages/authorizations/index.tsx](/Users/marksioson/Desktop/NUERS/src/pages/authorizations/index.tsx:43) | 43, 48, 49, 50, 51 | Permits/devices |
| [src/pages/bir/rdo-management.tsx](/Users/marksioson/Desktop/NUERS/src/pages/bir/rdo-management.tsx:213) | 213, 214 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/business-registry/activation.tsx](/Users/marksioson/Desktop/NUERS/src/pages/business-registry/activation.tsx:4) | 4 | Registry/activation |
| [src/pages/business-registry/index.tsx](/Users/marksioson/Desktop/NUERS/src/pages/business-registry/index.tsx:58) | 58, 63, 64, 68 | Registry/activation |
| [src/pages/business-registry/rdo-mappings.tsx](/Users/marksioson/Desktop/NUERS/src/pages/business-registry/rdo-mappings.tsx:11) | 11 | Registry/activation |
| [src/pages/client/dashboard.tsx](/Users/marksioson/Desktop/NUERS/src/pages/client/dashboard.tsx:1841) | 1841, 2031, 2032, 2033, 2096, 2097 | Profile/TIN/security; verified membership missing on legacy paths |
| [src/pages/integrations/index.tsx](/Users/marksioson/Desktop/NUERS/src/pages/integrations/index.tsx:45) | 45, 49, 50, 51, 52, 53, 54 | Integration configuration |
| [src/pages/invoices/center.tsx](/Users/marksioson/Desktop/NUERS/src/pages/invoices/center.tsx:86) | 86, 102, 117 | Invoice/exports/readiness |
| [src/pages/landing/index.tsx](/Users/marksioson/Desktop/NUERS/src/pages/landing/index.tsx:39) | 39, 40 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/merchant/api-keys.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/api-keys.tsx:1617) | 1617, 1618, 1695, 1696 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/billing.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/billing.tsx:269) | 269, 270, 318, 319 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/branches.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/branches.tsx:382) | 382, 383 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/expenses.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/expenses.tsx:190) | 190, 191, 202 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/invoice-detail.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/invoice-detail.tsx:583) | 583, 589 | Invoice/exports/readiness |
| [src/pages/merchant/invoice-recurring.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/invoice-recurring.tsx:118) | 118, 124 | Invoice/exports/readiness |
| [src/pages/merchant/pos-settings.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/pos-settings.tsx:463) | 463, 464 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/profile.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/profile.tsx:1599) | 1599, 1600, 1630, 1631 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/receipts.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/receipts.tsx:754) | 754, 755 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/transactions.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/transactions.tsx:915) | 915, 916 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/merchant/vat-reports.tsx](/Users/marksioson/Desktop/NUERS/src/pages/merchant/vat-reports.tsx:708) | 708, 709 | Merchant/account/key/expense; some legacy UIs static |
| [src/pages/monitoring/transmissions.tsx](/Users/marksioson/Desktop/NUERS/src/pages/monitoring/transmissions.tsx:105) | 105 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/notifications/center.tsx](/Users/marksioson/Desktop/NUERS/src/pages/notifications/center.tsx:425) | 425, 426 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/operations/workspace.tsx](/Users/marksioson/Desktop/NUERS/src/pages/operations/workspace.tsx:53) | 53, 66 | Transactions/review/report |
| [src/pages/taxpayer/businesses.tsx](/Users/marksioson/Desktop/NUERS/src/pages/taxpayer/businesses.tsx:296) | 296, 297 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/taxpayer/portal.tsx](/Users/marksioson/Desktop/NUERS/src/pages/taxpayer/portal.tsx:418) | 418, 419 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/taxpayer/registry.tsx](/Users/marksioson/Desktop/NUERS/src/pages/taxpayer/registry.tsx:288) | 288, 289 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/taxpayer/users.tsx](/Users/marksioson/Desktop/NUERS/src/pages/taxpayer/users.tsx:256) | 256, 257 | Other operational/legacy UI; backend guarantees not inferred |
| [src/pages/taxpayer/verification.tsx](/Users/marksioson/Desktop/NUERS/src/pages/taxpayer/verification.tsx:273) | 273, 274 | Other operational/legacy UI; backend guarantees not inferred |

## Appendix D - SQL raw-expression inventory

Read-only text search covered first-party `app/`, `routes/` and database migration/SQL sources. The table identifies files containing raw SQL/value or dynamic identifier APIs and line numbers for reviewer reproducibility. Each line must still be assessed in caller context; count is not a vulnerability count. No stored procedures/live DB grants/triggers were introspected. No scanner was executed. Expenses GET was specifically not invoked because its synchronization path writes rows.

| Source | Raw/dynamic expression lines |
|---|---|
| `app/Http/Controllers/Api/BusinessAccountController.php` | 143, 156 |
| `app/Http/Controllers/Api/BusinessInvoiceController.php` | 77, 312, 368, 507 |
| `app/Http/Controllers/Api/BusinessRegistrationController.php` | 96 |
| `app/Http/Controllers/Api/BusinessRegistryController.php` | 57, 85, 87 |
| `app/Http/Controllers/Api/CitizenApprovalController.php` | 46, 152, 153, 154, 289, 314, 315, 316, 317, 318 |
| `app/Http/Controllers/Api/DashboardController.php` | 163, 280, 289, 298, 333, 344, 345, 371, 372, 373, 374, 395, 396, 417, 418, 419, 446, 447, 448, 449, 474, 475, 476, 612, 613, 617, 618, 663, 992, 1026, 1051, 1545, 1546, 1547, 1548, 1578, 1579, 1580, 1581, 1582, 1638, 1652, 1654, 1690, 1707, 1709, 1749, 1834, 1845, 1856, 1910, 1934, 2047, 2322, 2327, 2410 |
| `app/Http/Controllers/Api/EisController.php` | 26, 43, 86, 167, 184, 185, 675, 704, 705, 706, 707, 724, 725, 726, 727, 730, 747 |
| `app/Http/Controllers/Api/EnterpriseDashboardController.php` | 50, 52, 56, 57, 59, 70, 128 |
| `app/Http/Controllers/Api/IntegrationTransactionController.php` | 267 |
| `app/Http/Controllers/Api/InvoiceCenterController.php` | 31, 32, 38, 83, 118 |
| `app/Http/Controllers/Api/LiveMonitoringController.php` | 49, 66, 196 |
| `app/Http/Controllers/Api/MerchantApiKeyController.php` | 59 |
| `app/Http/Controllers/Api/MerchantExpenseController.php` | 26, 310, 321, 467, 578, 601, 602, 627, 646, 741 |
| `app/Http/Controllers/Api/MerchantReceiptController.php` | 29, 91, 190, 192, 194, 196, 200, 202, 204, 206, 248, 250, 252, 256 |
| `app/Http/Controllers/Api/MerchantTaxCenterController.php` | 194, 199, 247, 253, 317, 327, 337, 360 |
| `app/Http/Controllers/Api/MerchantTransactionController.php` | 82, 121, 153, 154, 155, 156, 157, 180, 182, 184, 186, 190, 192, 194, 196, 200, 201, 203, 204, 206, 207, 254, 256, 258, 270 |
| `app/Http/Controllers/Api/RdoController.php` | 322 |
| `app/Http/Controllers/Api/SupportChatController.php` | 38, 40, 41 |
| `app/Http/Controllers/Api/TableController.php` | 277, 316 |
| `app/Http/Controllers/Api/TaxIntelligenceController.php` | 173, 206, 221, 222, 236, 242, 269, 293, 334, 364, 381, 415, 424 |
| `app/Http/Controllers/Api/TransactionController.php` | 215, 216, 224, 225, 310, 428, 563, 604, 621, 640, 654, 660 |
| `app/Http/Controllers/Api/TransactionWorkspaceController.php` | 36, 37, 39, 40, 42, 127, 129, 150, 154 |
| `app/Http/Controllers/Auth/GoogleAuthController.php` | 80 |
| `app/Services/EisDocumentReadiness.php` | 140, 182 |
| `app/Services/EisReportingPolicy.php` | 19 |
| `app/Services/HistoricalBranchEvidence.php` | 14, 31 |
| `app/Services/IssuedDocumentLedger.php` | 33, 34, 44, 53, 54, 66, 71, 82, 86 |
| `routes/console.php` | 20 |
| `database/migrations/2026_06_02_000013_add_rdo_branch_to_transaction_records.php` | 61, 99 |
| `database/migrations/2026_06_03_000001_fix_tax_graph_edges_index_lengths.php` | 19, 29, 32, 42, 59, 66, 69, 82 |

## Prioritized next work and proposed validation

Contain F01-F03 first, then key/branch/environment gates and deterministic event/sequence contracts. Complete durable delivery and official interoperability after controlled materials are available. Independently validate MFA/authorization/quotas/secrets/uploads/headers and real HA/DR/capacity. Each defect has owner, proposed remediation and acceptance in the54-control matrix; phased files/dependencies/effort in the remediation plan; all execution prerequisites and test IDs in the test plan. **No implementation or disruptive test is authorized by these reports.**

## Approved remediation follow-up

Current source contract, ID-only membership, MFA, expiring keys/tokens, shared atomic quotas, safe uploads, CSP, encrypted offline SDK and disabled signed sender are described in the re-audit and merchant contract. Lock scans report zero advisories. Real connector adoption, native concurrent/fairness tests, approved egress/receiver replay tests and independent security/capacity/DDoS/HA validation remain pending. No capacity target is marked achieved.
