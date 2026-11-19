<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\CardPaymentController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CreditCardController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\IncomeSourceController;
use App\Http\Controllers\Api\InstallmentController;
use App\Http\Controllers\Api\InstallmentPaymentController;
use App\Http\Controllers\Api\PlannedItemController;
use App\Http\Controllers\Api\RecurringExpenseController;
use App\Http\Controllers\Api\RecurringOverrideController;
use App\Http\Controllers\Api\SavingsContributionController;
use App\Http\Controllers\Api\SavingsGoalController;
use App\Http\Controllers\Api\ScenarioController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StatementController;
use App\Http\Controllers\Api\TransactionController;
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
    Route::get('dashboard', [DashboardController::class, 'show']);
    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);

    // ---- forecast, what-if ----
    Route::get('forecast', [ForecastController::class, 'show']);
    Route::post('what-if/run', [WhatIfController::class, 'run']);

    // ---- installments ----
    Route::get('installments', [InstallmentController::class, 'index']);
    Route::post('installments/simulate', [InstallmentController::class, 'simulate']);
    Route::post('installments', [InstallmentController::class, 'store']);
    Route::get('installments/{plan}', [InstallmentController::class, 'show'])->whereUuid('plan');
    Route::delete('installments/{plan}', [InstallmentController::class, 'cancel'])->whereUuid('plan');
    Route::get('installments/{plan}/payoff-quote', [InstallmentPaymentController::class, 'quote'])->whereUuid('plan');
    Route::post('installments/{plan}/payments', [InstallmentPaymentController::class, 'pay'])->whereUuid('plan');
    Route::post('installments/{plan}/payoff', [InstallmentPaymentController::class, 'payoff'])->whereUuid('plan');

    // ---- credit cards ----
    Route::apiResource('credit-cards', CreditCardController::class);
    Route::get('credit-cards/{card}/statements', [StatementController::class, 'index'])->whereUuid('card');
    Route::get('statements/{statement}', [StatementController::class, 'show'])->whereUuid('statement');
    Route::patch('statements/{statement}', [StatementController::class, 'update'])->whereUuid('statement');
    Route::post('statements/{statement}/payments', [CardPaymentController::class, 'store'])->whereUuid('statement');
    Route::delete('card-payments/{payment}', [CardPaymentController::class, 'destroy']);

    // ---- money in / out ----
    Route::apiResource('transactions', TransactionController::class);
    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('income-sources', IncomeSourceController::class);
    Route::post('income-sources/{id}/receive', [IncomeSourceController::class, 'receive']);
    Route::apiResource('recurring-expenses', RecurringExpenseController::class);
    Route::post('recurring-expenses/{id}/settle', [RecurringExpenseController::class, 'settle']);
    Route::apiResource('recurring-overrides', RecurringOverrideController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('planned-items', PlannedItemController::class);

    // ---- savings, budgets, scenarios ----
    Route::apiResource('savings-goals', SavingsGoalController::class);
    Route::get('savings-goals/{goal}/contributions', [SavingsContributionController::class, 'index']);
    Route::post('savings-goals/{goal}/contributions', [SavingsContributionController::class, 'store']);
    Route::delete('savings-contributions/{contribution}', [SavingsContributionController::class, 'destroy']);
    Route::get('budgets/status', [BudgetController::class, 'status']); // before the resource: "status" is not an id
    Route::apiResource('budgets', BudgetController::class);
    Route::apiResource('scenarios', ScenarioController::class);
});