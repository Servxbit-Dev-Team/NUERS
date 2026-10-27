<?php

namespace App\Http\Controllers;

/** Raster branding alias for both repository-root and public-root hosting. A controller (not a closure) so `route:cache` works. */
class BusinessLogoController extends Controller
{
    public function __invoke(string $file)
    {
        abort_unless(preg_match('/^[a-f0-9-]{36}\.png$/i', $file), 404);
        $path = public_path('uploads/business-logos/'.$file);
        abort_unless(is_file($path), 404);

        return response()->file($path, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=31536000, immutable']);
    }
}
