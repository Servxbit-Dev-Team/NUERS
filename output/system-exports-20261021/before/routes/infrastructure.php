<?php

use App\Http\Controllers\Api\ServerManagementController as Infra;
use Illuminate\Support\Facades\Route;

Route::prefix('admin/infrastructure')->middleware('throttle:90,1,infrastructure:')->group(function () {
    Route::get('/servers', [Infra::class, 'index']);
    Route::get('/servers/{id}', [Infra::class, 'detail'])->whereUuid('id');
    Route::post('/servers', [Infra::class, 'enroll'])->middleware('throttle:10,1,infrastructure-enroll:');
    Route::post('/servers/{id}/maintenance', [Infra::class, 'maintenance'])->whereUuid('id');
    Route::post('/step-up', [Infra::class, 'stepUp'])->middleware('throttle:5,1,infrastructure-step-up:');
    Route::post('/preflight', [Infra::class, 'preflight']);
    Route::get('/actions', [Infra::class, 'actions']);
    Route::post('/actions', [Infra::class, 'createAction'])->middleware('throttle:10,1,infrastructure-action:');
    Route::post('/actions/{id}/review', [Infra::class, 'review'])->whereUuid('id');
    Route::get('/events', [Infra::class, 'events']);
    Route::get('/export', [Infra::class, 'export'])->middleware('throttle:5,1,infrastructure-export:');
});
