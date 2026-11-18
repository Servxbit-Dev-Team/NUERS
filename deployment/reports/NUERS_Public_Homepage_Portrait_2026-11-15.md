# Public homepage portrait correction — 15 November 2026

Status: deployed to https://nuers.net as `production-20261115-homeportrait1` at 19:32:22 PHT. Both production targets were healthy on completion. Previous release: `production-20261115-portrait1`.

## User-reported issue and scope

The supplied iPhone Duo portrait screenshot showed cropped header actions and feature content, with narrow text laid over the desktop hero artwork. The previous portrait release covered authenticated account-security/header layouts, not this public landing page. A 669px browser baseline did not reproduce every clipping detail from the physical screenshot, but retained the desktop-style hero overlap. This correction changes the public portrait composition directly rather than relying on clipping overflowing content.

At widths up to 1100px the header now stays in normal document flow, with a separate row for search, login and registration. The hero text uses the available width and the artwork follows below it. Feature links use two columns, falling back to one on narrow phones, without offset cards. Desktop composition above the breakpoint is retained.

Testing also exposed a search dialog extending above and below a 382 × 580 viewport. Search and tour dialogs now have viewport/safe-area height limits, internal scrolling, side margins and 44px Close controls.

Only `src/pages/landing/readability.css` and 237 rebuilt frontend files were deployed. No PHP, authentication setting, environment configuration, schema or customer data change was included. Existing 2FA enrollments were not modified.

## Verification

- Typecheck, production frontend build and document-renderer build passed.
- All 13 frontend reconstruction/rendering regression tests passed.
- Local and freshly loaded live homepage each passed 19 viewport checks: 320×568, 382×580, 382×618, 385×571, 385×609, 390×844, 430×932, 540×960, 600×900, 669×867, 669×902, 740×1000, 820×1180, 951×585, 1024×1366, 1100×900, 1101×900, 1280×800 and 1536×960.
- Measurements asserted actual viewport dimensions, document width and bounds of rendered links, controls, headings, paragraphs and articles. No horizontal overflow was found in those checks. Live checks additionally confirmed the new header/hero CSS was loaded.
- Local and live search/tour dialogs each passed six bounds checks at 320×568, 382×580 and 669×902. Navigation opened within bounds at 382×580; dialogs and menus were closed without submitting account actions.
- Both nodes verified the source baseline, all 238 candidate-file hashes, root-owned non-writable code, unchanged environment, healthy PHP/nginx and expected home/login/health (200), unauthenticated MFA disable/devices (401) responses. Assets were staged on both old releases before rolling replacement.
- Fresh post-deployment public security probes: 59/59 passed, 19:33:20–19:34:10 PHT, including availability afterwards. Seven requests were blocked at Cloudflare; those are edge-level evidence only.

These are browser viewport simulations, not a physical iPhone or Safari-engine certification. Reopening/reloading the page loads the new content-addressed assets.

Artifact SHA-256: `c3b19ef17bed4b46c080f9ef57817a53c1b3dc7050eed8323de93a8ff2e4a888`.
Evidence: `.local/home-portrait-2026-11-15/` contains source baseline/archive, build/render logs, candidate manifest/archive, local/live layout and dialog results, screenshots, rollout evidence and bounded public probes.

The concurrent VAPT continuation passed 834 PHP tests / 11,556 assertions and 8,505 role/endpoint probes; see `NUERS_VAPT_Credential_Boundaries_2026-11-15.md` for coverage and remaining limits. No new confirmed security finding was identified in that bounded pass.
