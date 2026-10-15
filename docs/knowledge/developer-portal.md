# Public developer portal — 2026-10-15

## Pages and reference scope

- `/developers/integration`: implementation workflow, identity boundaries, six-step onboarding and production handover, reliability and capability status.
- `/api/docs`: public integration-focused reference for 13 implemented operations. Authentication, field/query contracts, illustrative responses, errors, cURL/Node.js/Python examples, searchable endpoint/group selector, OpenAPI 3.1 and Postman collection exports, versioning and support. This subset is explicitly not every administrative route.
- `/api/sandbox`: isolated public server-backed preview. Validates shared ingestion rules, previews existing tax calculations and illustrates response handling. Memory-only activity history, JSON formatting/reset, presets, copy/export and actual HTTP/timing inspection.
- Landing developer menu links to these public resources. Explicit web routes serve `/api/docs` and `/api/sandbox`; the general SPA fallback excludes API paths.

## Backend and isolation

`IntegrationContract` centralizes the existing ingestion validation rules and tax calculation, reused by the real merchant ingestion controller and the public preview. Existing ingestion behavior is preserved, including gross_amount precedence and current non_vat storage classification. No migrations are required.

`POST /api/developer/sandbox` accepts `is_test=true`, an action (`validate`, `preview`, `scenario`) and synthetic payload. It never invokes financial ingestion or BIR, never writes financial/queue records and never grants authority. No Authorization/API-key headers are accepted. References require TEST-/DEMO-/SAMPLE-. The optional TIN must be the synthetic example 999999999000 (hyphenated form also accepted). Nested credential/private-key/email keys are rejected. These guards do not guarantee detection of every real identifier embedded in free text: the page explicitly requires synthetic input only.

Limit: 20 requests/minute, 64 KB body, PHP 1 billion preview amount. Results explicitly include persisted=false, bir_contacted=false and bir_status=not_submitted. Scenario HTTP codes are illustrative JSON fields inside actual HTTP 200. Real validation errors return 422; oversized bodies return 413 and limiter denials 429. No credentials/payloads are saved in localStorage by this tool.

## Source of truth and important distinctions

`python3 scripts/build-developer-catalog.py` builds the JSON catalogue and OpenAPI snapshot in `resources/data`. `/api/developer/catalog` serves catalogue JSON; `/api/developer/openapi` serves the downloadable specification. Postman export derives from the same catalogue in the browser and contains empty credential variables. Examples never log full credential-bearing responses.

Follow-up inspection corrects an earlier website/audit shorthand: **POST /api/integrations/transactions exists**. Its controller already enforces active merchant keys, transactions:write scope, exact-IP policy and daily quota. It resolves the merchant from the hashed credential. It does not provide a public batch route, Idempotency-Key header or machine-key status endpoint. Duplicate merchant+reference returns the original record without comparing changed payload hashes. A key labelled sandbox does not isolate a financial ledger; the new public preview is the isolated tester.

Provider credentials, user sessions and merchant keys are separate. Encrypted provider configuration does not establish live BIR connectivity. The saved EIS specification and accreditation baseline govern target authority/certification and evidence requirements; the portal distinguishes those targets from current implementation. Live BIR transport, signed webhook delivery and full outbox acceptance remain pending. Existing critical audit findings about legacy access control and accounting remain open.

## Verification

- Full PHP suite: 89 tests, 1,070 assertions passed.
- Frontend TypeScript/Vite production build passed; existing large-chunk and three runtime asset-reference warnings remain.
- Browser checked direct public access, real VAT-inclusive 1120 → VAT 120, negative amount → actual 422 field error, duplicate scenario labels, searchable operation selector, documentation search and selectable examples. No document overflow at 320px (all three pages) or 390px (documentation and sandbox); viewport overrides were reset. OpenAPI and Postman downloads were downloaded through the browser and parsed successfully, with 13 operations, empty credential variables and required JSON content-type headers. Desktop preview: `.local/developer-documentation.png`.
- OpenAPI/catalogue routes and all 13 referenced operations are verified against registered Laravel routes. Public preview tests run with no financial tables, exercise throttle/size/credentials/synthetic identity and assert no BIR interaction markers.

Changes are local. No deployment, provider calls, real merchant issuance, credential creation or database migration was performed for this developer portal.
