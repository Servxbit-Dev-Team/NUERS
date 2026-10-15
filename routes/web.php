<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\GoogleAuthController;

Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:20,1');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:20,1');
Route::get('/auth/google/session', [GoogleAuthController::class, 'session'])->middleware('throttle:20,1');

Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');


