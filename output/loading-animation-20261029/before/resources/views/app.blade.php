<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=b50631c79afb" />
    <link rel="icon" type="image/png" sizes="48x48" href="/nuers-favicon.png?v=b50631c79afb" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=b50631c79afb" />
    <link rel="manifest" href="/site.webmanifest.json?v=b50631c79afb" />
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
    <div id="root"></div>
  </body>
</html>
