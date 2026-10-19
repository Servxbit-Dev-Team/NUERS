# Website, invoice design and subscription implementation

## Current pricing update — 18 October 2026

The operator superseded the historical PHP500 threshold described below. The current default is one PHP3 fee per newly accepted primary Live sale with a PHP0 minimum. Super Admin can change the minimum prospectively under Wallets & Transaction Pricing; zero charges every sale, and a positive minimum exempts amounts strictly below it. API and portal use the same published policy. Identical retries, supplementary payment copies and Sandbox records do not cause another Live sale debit. Previous ledger entries remain unchanged.

The following earlier notes remain historical evidence.


Implemented locally on 15 October 2026. User references: `NUERS Electronic Receipts Dashboard.png`, `Philippine Digital Receipt Layout.png`, and `NUERS_ A Stronger Digital Philippines.png` from the user's Downloads directory. These are visual references, not evidence of authority approval or live statistics.

## Delivered paths

- `/`: responsive public website, generated hero and audience artwork, feature descriptions, product tour, workflow, subscription plans, fee calculator, developer overview, FAQ and contact/account actions.
- `/invoices`: authenticated electronic document workspace with scoped server queries, filtering, pagination, real counts, row selection, preview/detail view, CSV export, PDF download and printable PDF. “Send to BIR” opens transmission review; it does not claim to submit a record automatically.
- `/super-admin/subscriptions`: administrator-only plan editor. Names, descriptions, fees, minimums, storage allowance, retention years, badge, display order, highlight and publication flags persist in the database. Edits are audited and stale revisions are rejected.

The existing business document schema is reused. New table: `website_subscription_plans`, introduced by migration `2026_10_15_000004_create_website_subscription_plans`. The table stores advertised plan configuration. It does not charge a payment method, enroll a business, meter storage or delete documents after retention expires. Those operations require separate subscription/billing/storage implementation and approved terms.

## User-confirmed pricing contract

The user explicitly confirmed: **no NUERS transaction service fee below PHP 500; charge the plan's flat fee at PHP 500 and above**.

| Plan | Flat service fee per eligible transaction | Minimum eligible transaction | Advertised storage |
| --- | --- | --- | --- |
| Subscription 1 | PHP 2 | PHP 500 | 500 MB included |
| Subscription 2 | PHP 3 | PHP 500 | Unlimited storage with 5-year retention |

Fee values and thresholds are integer centavos. The public calculator reads published database plans and compares the transaction amount at centavo precision. At PHP 499.99 both plans return zero; at PHP 500 the fees are PHP 2 and PHP 3. Provider fees are separate. No monthly platform fee or automatic charge was invented. Subscription 1 has no specified retention term. Unlimited means no fixed MB allowance; it is distinct from retention duration. Plan changes affect advertised content, not existing customer agreements.

## Invoice logic

The center combines business invoice records and transaction receipt records without fabricating document details. Admin/super-admin/BIR users have administrative read access; merchants are scoped to linked businesses; other roles are rejected. Individual document lookup applies the same scope. BIR users cannot create drafts.

The new-document modal saves a draft. Totals are recomputed in centavos and line-level VAT is allocated before aggregation. A request UUID and locked merchant row protect repeated draft creation, and changed reuse of a request ID produces a conflict. Draft creation does not bypass existing official issuance controls. No existing issued documents are mutated by these screens.

Receipt HTML is data-driven and scales for the compact preview, expanded view and PDF. QR codes point to the real local or deployed verification route when a receipt number is available. The verification page pre-fills that number. Authority labels describe stored evidence; no fictional BIR accreditation, government verification domain or production acceptance is inserted. Older source documents may contain incomplete or inconsistent item data; these historical records are displayed without silently rewriting financial values.

CSV exports explicitly cover the current page or checked rows. PDF download exports the selected document and the print action opens its printable PDF. Raster PDF is a visual representation, not a substitute for canonical machine-readable accounting data. Long exports can span multiple A4 pages; the one-page receipt was visually verified.

## Generated artwork

Generated using the built-in ImageGen tool, copied into `public/website-assets/`, and referenced by the website. Existing NUERS and OK Technology logos were retained. Lucide vector icons match the existing interface system and remain sharp at small sizes.

- `public/website-assets/nuers-philippines-hero.png`
- `public/website-assets/nuers-taxpayer-business.png`
- `public/website-assets/nuers-developer.png`

Hero final prompt:

> Use case: ads-marketing. Asset type: wide website hero background artwork, approximately 2.5:1 landscape. Reference image: the supplied NUERS homepage design is a composition/style reference only. Create a polished realistic hero visual closely matching its right-side composition: modern silver laptop in three-quarter view showing a navy-blue electronic invoicing dashboard with small white cards and blue charts; a smartphone leaning in front of the laptop on the right, showing a deep-blue login screen. Behind them a bright Metro Manila skyline, Philippine national flag waving on a tall pole on the right, Rizal monument and green trees. Leftmost 40 percent is uncluttered deep midnight navy with very subtle city silhouettes, reserved for live HTML headline and buttons. Devices mainly occupy rightmost 55 percent, laptop bottom aligned. White/light-blue horizon at lower right; realistic premium fintech website photography and crisp architectural detail, patriotic blue-white-red-gold palette. NO headline, NO marketing copy, NO government seal, NO agency endorsement, NO watermark. Small realistic interface shapes on screens, no fake numbers or approval labels. Do not reproduce the full website screenshot, navigation, footer, or cards. Only the hero artwork. Input reference: NUERS_ A Stronger Digital Philippines.png.

Taxpayer final prompt:

> Photorealistic architecture photograph for a Philippine electronic invoicing website audience card. Low angle looking up at a glass modern Makati business district office tower, blue sky reflected on glass, crisp morning light, corporate navy blue palette, landscape 3:2. Building fills frame. No text, no logo, no watermark.

Developer final prompt:

> Photorealistic landscape 3:2 photograph for a Philippine fintech website developer audience card. Close-up hands typing on a silver laptop at a professional desk, screen showing abstract blue code editor and subtle cybersecurity interface, dark navy environment, premium photographic quality, natural believable hands, selective focus. No legible text, no logo, no watermark.

## Verification and remaining scope

- TypeScript and production Vite build pass.
- Full PHP suite: 41 tests, 283 assertions; includes five new tests for access/scoping, filters/paging, draft idempotency/calculations, published pricing and admin revision controls.
- Browser verified the PHP 499.99/PHP 500 boundary and an actual database save of Subscription 1 retaining its requested values.
- Downloaded receipt PDF inspected with Poppler; A4 output renders the selected seller/items/totals, QR and receipt styling.
- Desktop and 390-pixel mobile website inspected; mobile page width does not overflow. Mobile navigation opens with correct expanded state.
- Existing dependency advisories were found in pre-existing router/build dependencies. Added PDF, QR and image-rendering dependencies were not named by that audit. No unrelated dependency upgrade was applied.

This is a local implementation, not a Plesk deployment. See [the specification review](functional-specification-v1.1-review.md) for the broader backlog. Legacy generic table/account-management endpoint findings remain outside this visual implementation; new endpoints have their own authorization. Live BIR transport and accreditation are not established by this work.

## Header containment and specification-aligned content — 15 October 2026

The landing-page logo now stays within the header at desktop, tablet and mobile sizes. The brand is vertically centered, its image height is bounded by each header breakpoint, and the mobile menu starts below the header. At 1536/1024/390/320px, browser geometry confirmed logo containment and no document overflow. Compact mobile hero actions retain a single line.

New public sections: `#onboarding` (business information, evidence preparation, application stages, PTI/PTT definitions), `#readiness` (available records, documentary setup, pending live certification and distinct issued/submitted/acknowledged states), and `#records` (taxpayer ownership, supported downloads, review history and storage/retention distinctions). Added matching navigation/search entries, onboarding and authorization FAQs, the published NUERS support email, and aligned footer legal links.

Content follows the saved v2.0 complete EIS specification and accreditation baseline. Account activation is separate from BIR permission. Live production transport, token exchange and webhook delivery are described as dependent on verified adapters/certification. Replaced the underspecified developer-panel transaction example with a clearly labelled planning example. Follow-up inspection on 2026-10-15 confirmed that POST /api/integrations/transactions exists; its actual contract is now documented in the public developer portal. It is not an official BIR payload schema. Existing database-driven plans and the confirmed PHP 500 fee boundary remain unchanged.

Validation: frontend production build and browser checks at four widths, mobile menu/anchor navigation, new section rendering and website search. This is a website content/layout update, not remediation of the separate system audit findings or proof of BIR approval.

### Shared content alignment

All landing-page bands now use one 1,360px content maximum and shared responsive gutters (60px desktop, 40px intermediate, 20px mobile, 12px on the narrowest screens). Header, hero, feature/audience bands, workflow, onboarding, readiness, pricing, FAQs, contact and footer align to that grid. Full-width section backgrounds remain full bleed. Section headings use a left title/right introduction on desktop and stack on mobile. Pricing cards and the fee explainer now span the shared content grid; contact uses a full-width band with an aligned inner container.

Verified section edges match the header at 1920, 1280, 1024, 768, 390 and 320px, with no document horizontal overflow. Production build and `git diff --check` passed. Preview: `.local/website-content-aligned.jpg`.
