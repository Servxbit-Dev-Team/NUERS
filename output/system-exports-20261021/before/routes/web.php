<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1');
Route::get('/auth/google/session', [GoogleAuthController::class, 'session'])->middleware('throttle:20,1');

// Raster branding alias for both repository-root and public-root hosting.
Route::get('/public/uploads/business-logos/{file}', function (string $file) {
    abort_unless(preg_match('/^[a-f0-9-]{36}\.png$/i', $file), 404);
    $path=public_path('uploads/business-logos/'.$file);abort_unless(is_file($path),404);
    return response()->file($path,['Content-Type'=>'image/png','X-Content-Type-Options'=>'nosniff','Cache-Control'=>'public, max-age=31536000, immutable']);
})->where('file','[a-fA-F0-9-]{36}\.png');

Route::view('/api/docs', 'app');
Route::view('/api/sandbox', 'app');

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');


