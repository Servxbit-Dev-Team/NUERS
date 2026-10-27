<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\BusinessLogoController;

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1');
Route::get('/auth/google/session', [GoogleAuthController::class, 'session'])->middleware('throttle:20,1');

// Raster branding alias for both repository-root and public-root hosting.
Route::get('/public/uploads/business-logos/{file}', BusinessLogoController::class)->where('file', '[a-fA-F0-9-]{36}\.png');

Route::view('/api/docs', 'app');
Route::view('/api/sandbox', 'app');

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');


