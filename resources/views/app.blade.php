<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=b50631c79afb" />
    <link rel="icon" type="image/png" sizes="48x48" href="/nuers-favicon.png?v=b50631c79afb" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=b50631c79afb" />
    <link rel="manifest" href="/site.webmanifest.json?v=b50631c79afb" />
    <link rel="stylesheet" href="/brand/nuers-loading.css?v=20261029" />
    <link rel="preload" as="image" href="/brand/nuers-loading-ring.gif?v=20261029" media="(prefers-reduced-motion: no-preference)" />
    <meta name="theme-color" content="#062344" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>NUERS | Electronic Invoicing & Digital Receipts</title>
    <meta name="description" content="Manage electronic invoices, digital receipts and business records with NUERS. Explore configurable transaction fees, unlimited storage and five-year record retention and connected compliance workflows." />
    <meta property="og:title" content="NUERS | Electronic Invoicing & Digital Receipts" />
    <meta property="og:description" content="Manage electronic invoices, digital receipts and business records with NUERS. Explore configurable transaction fees, unlimited storage and five-year record retention and connected compliance workflows." />
    <meta property="og:image" content="/brand/nuers-official-logo.png" />
    <meta property="og:type" content="website" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:title" content="NUERS | Electronic Invoicing & Digital Receipts" />
    <meta name="twitter:description" content="Manage electronic invoices, digital receipts and business records with NUERS. Explore configurable transaction fees, unlimited storage and five-year record retention and connected compliance workflows." />
    <meta name="twitter:image" content="/brand/nuers-official-logo.png" />
    @php
        // Repository-root Plesk hosting serves compiled Laravel assets under /public/.
        // Standard public-root hosts continue using Vite's normal /build/ URL.
        $documentRoot = realpath((string) request()->server('DOCUMENT_ROOT', ''));
        if ($documentRoot && $documentRoot === realpath(base_path())) {
            app(\Illuminate\Foundation\Vite::class)->createAssetPathsUsing(
                fn (string $path, ?bool $secure = null) => asset('public/'.ltrim($path, '/'), $secure)
            );
        }
    @endphp
    @viteReactRefresh
    @vite(['src/main.tsx'])
  </head>
  <body>
    <div id="root">
      <div class="nuers-loading nuers-loading--screen" role="status" aria-live="polite" aria-atomic="true">
        <picture class="nuers-loading__art" aria-hidden="true">
          <source media="(prefers-reduced-motion: reduce)" srcset="/app-icon-192.png?v=b50631c79afb" />
          <img src="/brand/nuers-loading-ring.gif?v=20261029" width="168" height="168" alt="" fetchpriority="high" />
        </picture>
        <span class="nuers-loading__brand">NUERS</span>
        <p class="nuers-loading__label">Loading NUERS…</p>
      </div>
    </div>
  </body>
</html>
