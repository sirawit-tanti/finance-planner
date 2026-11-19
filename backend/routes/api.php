<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\InstallmentController;
use App\Http\Controllers\Api\WhatIfController;
use Illuminate\Support\Facades\Route;

// Liveness probe for the frontend and Railway. No login, no database.
Route::get('/health', fn () => response()->json([
    'status' => 'ok',
    'time' => now()->toIso8601String(),
]));

Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:auth');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('forecast', [ForecastController::class, 'show']);

    Route::post('installments/simulate', [InstallmentController::class, 'simulate']);
    Route::post('installments', [InstallmentController::class, 'store']);
    Route::get('installments/{plan}', [InstallmentController::class, 'show'])->whereUuid('plan');

    Route::post('what-if/run', [WhatIfController::class, 'run']);
});