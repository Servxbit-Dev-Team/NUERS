# Business registration design and workflow — 2026-10-15

## Reference and implementation

User reference: `/Users/marksioson/Downloads/NUERS Business Registration Portal.png`. Implemented locally at `/register` in `src/pages/auth/register.tsx` and `register.css`, with a navy photographic panel, NUERS branding, official BIR seal, yellow heading, five benefit rows, four-stage progress indicator, paired business fields, help cards and footer. Mobile uses a single-column form with both logos. Existing individual registration remains at `/register?type=client`.

The BIR seal was downloaded directly from the official site's image CDN: https://bir-cdn.bir.gov.ph/BIR/uploads/bir-2024-logo.png (referenced by https://www.bir.gov.ph/). Local asset: `public/auth-assets/bir-official-seal.png`. It links to BIR taxpayer resources; its presence does not claim NUERS accreditation or government ownership.

Generated photographic background: `public/auth-assets/registration-philippines.png`. Original output: `/Users/marksioson/.codex/generated_images/01a13d3c-7487-7b41-8fe2-9b8364ba86c3/exec-156e3cc6-d86c-46ca-bae0-0abdb57db789.png`.

Image-generation prompt (reference image supplied):

> Use case: ads-marketing. Asset type: portrait background artwork for the LEFT side of NUERS business registration page. Reference image is a composition reference: NUERS Business Registration Portal.png. Generate only the photographic background, NO text, NO logos, NO interface, NO icons, NO watermarks. Match the reference: a dramatic but credible Metro Manila business skyline at dusk with deep midnight navy top and left, lighter blue clouds in lower right. On the far right a large Philippine flag on a tall flagpole, with the Rizal Monument at the bottom right and lit green park trees along the bottom. Buildings rise from lower third; flag occupies middle-right starting about one-third down. Lots of dark uncluttered negative space in upper half and left 65% for overlaid HTML headings and five short feature rows. Portrait aspect ratio 2:3, premium realistic architectural photography. Palette navy, cobalt, subtle gold city lights, authentic flag red/blue/white. Keep monument at right edge, not center. No device mockups. No agency logos or endorsement wording.

## Registration records and controls

1. Business information: 9-digit TIN plus 3-digit branch code, registered name/address, business type, industry, active RDO, contact name/position, email, Philippine mobile number, terms and privacy consent.
2. Account setup: confirmed password of at least 12 characters with letters and numbers, VAT status, optional published subscription preference. Landing-page plan selection carries into registration. No payment is processed.
3. Verification: review details, request a six-digit email code, verify and submit. Codes expire after 10 minutes, are hashed at rest, allow five attempts and are rate limited. The verified proof is email-bound and consumed once, inside the account-creation transaction.
4. Complete: show registration reference and pending-review status. Create the user, merchant profile, pending merchant and business registration together. Do not issue an API session token or mark the business approved. Record consent versions and a snapshot of the selected published plan. Email verification does not establish TIN authenticity or BIR authorization.

API implementation: `app/Http/Controllers/Api/BusinessRegistrationController.php`. Migration `2026_10_15_000005_create_business_registration_records.php` adds `business_registrations` and `registration_email_verifications`; applied to the local database only. Existing source records remain untouched. Login uses the existing application flow.

Local environment uses `MAIL_MAILER=log`: a clearly labelled test code is returned only when both environment is `local` and mailer is `log`. Those registrations keep `email_verified_at` null and `email_verified` false. Outside this condition, log/array delivery is rejected; configure an actual mail transport for email verification. Mail failures do not create an account. No real verification emails were sent during implementation.

The registration terms/privacy dialogs describe current behavior, version `2026-10-15`. Before production, the operator should finalize organizational identity, privacy contacts, retention and service terms. The RDO selector uses active offices already managed by the application; it does not invent a nationwide office directory. Help explicitly advises contacting the administrator when an office is unavailable.

## Verification

- `php artisan test --compact`: **48 tests, 357 assertions**, all passing in isolated in-memory SQLite. Seven registration tests cover active RDOs, hashing, rate limits, invalid attempts, expiry, pending-record creation, one-use proof, email binding, privilege injection, required consent, malformed data, duplicate legacy TINs, unpublished plans and local development verification semantics.
- `npm run build`: passes TypeScript and Vite production build. Existing unrelated missing-background warnings and large-bundle warning remain.
- Browser checks: desktop at 1536×1024 and mobile at 390×844; required-field errors block empty progression. No real account, credential or consent was submitted through the browser.
- Screenshots: `.local/registration-desktop.jpg`, `.local/registration-mobile.jpg`.

## Address selectors and automatic RDO — 2026-10-15 follow-up

The registration address is now a dependent Region → Province/Area → City/Municipality → Barangay selector, plus manual unit/building/street and four-digit ZIP. Changing a parent clears its descendants and the previous RDO. NCR automatically selects its single Metro Manila area group. Independent-city groups are labelled as areas rather than claiming they are provinces. Manila district barangays are combined under City of Manila and retain their district label. The completed address is previewed as selections change.

Reference data is bundled in `resources/data/philippine-addresses.json`: 18 regions, 82 actual provinces plus 20 geographic parent groups, 1,656 city/municipality/district records, and 42,010 barangays. Source: the MIT-licensed PSGC Q1 2026 package snapshot at https://github.com/fish-and-bear/psgc (`source.commit` pins the fetched tree). License is preserved in `resources/data/PSGC-LICENSE.txt`. `scripts/build-registration-geography.py` reproduces the compact format from downloaded source JSON files and license. Public registration APIs read local data; no entered address is sent to an external provider. A rejected preliminary PSGC Cloud dataset had inconsistent NCR province relationships and lacked NIR; that dataset is not used.

RDO reference: https://bir-cdn.bir.gov.ph/BIR/pdf/citizens-charter-rr-rdo-2024-v3.pdf. Bundled district names supplement managed local offices without changing existing office rows. Automatic city rules currently cover Mandaluyong, San Juan, Pasig, Taguig/Pateros, Marikina, Pasay, Parañaque, Las Piñas and Muntinlupa. Barangay rules cover the five whole Makati barangays listed for RDO 050 in https://bir-cdn.bir.gov.ph/BIR/pdf/Digest%20RAO%201-2024.pdf (Dasmariñas, Forbes Park, Guadalupe Nuevo, Pinagkaisahan, Urdaneta). Salcedo Village is omitted because it does not map to a whole PSGC barangay. The system does not guess a district for other addresses or reuse the unrelated legacy location controller's region-wide defaults. The selected RDO must be confirmed manually where jurisdiction is not mapped. This is intentionally not a claim of complete nationwide RDO jurisdiction coverage.

Additional explicit rules can be maintained in existing RDO coverage arrays using `psgc:city:<10-digit-code>` or `psgc:barangay:<10-digit-code>`. Whole-barangay matches take priority over city matches; conflicting matches require manual confirmation. Free-text coverage labels are not interpreted as jurisdiction rules. Inactive managed districts are excluded. Existing office labels are preserved.

`RegistrationAddress` validates the full parent chain, rebuilds the full address on the server, enforces a unique mapped RDO and saves an `address_details` JSON snapshot. Merchant city, ZIP and geographic region are populated from these records. Migration `2026_10_15_000006_add_registration_address_details.php` adds one nullable JSON column locally; historical registrations remain unchanged.

Verification: **51 tests, 399 assertions passed**, including national selectors, city scope, Manila barangays, mismatched parents, wrong RDO rejection, structured persistence and conflicting coverage. TypeScript/Vite production build passed. Browser checks verified Pasay/Barangay 1 → 051 and Makati/Dasmariñas → 050, clearing a previous barangay/RDO on city change, and responsive rendering. Desktop evidence: `.local/registration-address-rdo.jpg`; mobile evidence: `.local/registration-address-mobile.jpg`. No account or consent was submitted during browser testing.

## Unified dropdown design — 15 October 2026

All seven business registration dropdowns (Region, Province/Area, City/Municipality, Barangay, BIR RDO, Business Type and Industry Classification) now use `src/components/registration/searchable-select.tsx`. This shares the reference industry popover design: search header, matching field width/border/font/spacing, pale-blue active/selected options, checkmark, scrollable options and result counts. Large lists load additional options on demand. Industry retains its PSIC count and custom-activity action through a thin wrapper.

Cascading address resets, automatic province selection, mapped RDO behavior, disabled dependent fields, loading states and validation messages continue through the existing controlled values. Auto-selected RDOs remain locked. Dropdown navigation scrolls only its own results container. Desktop/mobile browser verification covers text/code filtering, empty results, keyboard selection, selected values, matching widths and dependent/locked states. TypeScript/Vite build passes. This update is scoped to the registration form shown in the user’s reference, not unrelated dashboard filters. No schema or API contract changed for this styling request.
