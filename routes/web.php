<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\UploadedImageController;

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1');
Route::get('/auth/google/session', [GoogleAuthController::class, 'session'])->middleware('throttle:20,1');

// Merchant and client images: repository-root and public-root hosting aliases, and the only path to them when they live in an object store.
Route::get('/public/uploads/{folder}/{file}', UploadedImageController::class)->where('file', '[a-fA-F0-9-]{36}\.png');
Route::get('/uploads/{folder}/{file}', UploadedImageController::class)->where('file', '[a-fA-F0-9-]{36}\.png');

Route::view('/api/docs', 'app');
Route::view('/api/sandbox', 'app');

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');


