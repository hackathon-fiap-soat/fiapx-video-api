<?php

use App\Presentation\Http\Controllers\HealthController;
use App\Presentation\Http\Controllers\VideoController;
use Illuminate\Support\Facades\Route;

// Contrato: fiapx-docs/api/openapi.yaml (servidor /api).
Route::get('/health/live', [HealthController::class, 'live']);
Route::get('/health/ready', [HealthController::class, 'ready']);

Route::middleware('gateway.user')->group(function () {
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/videos', [VideoController::class, 'index']);
    Route::get('/videos/{id}', [VideoController::class, 'show']);
    Route::get('/videos/{id}/download', [VideoController::class, 'download']);
});
