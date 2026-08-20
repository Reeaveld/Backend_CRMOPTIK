<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\AuthController;

// =====================================================================
// AUTHENTICATION (Public Routes)
// =====================================================================
Route::post('/login', [AuthController::class, 'login']);

// =====================================================================
// PROTECTED API ROUTES (Sanctum Auth Required)
// =====================================================================
Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // Customers CRUD & Profile Completion
    Route::apiResource('customers', CustomerController::class);
    Route::patch('/customers/{id}/complete-profile', [CustomerController::class, 'completeProfile']);

    // Transactions
    Route::get('/customers/{id}/transactions', [TransactionController::class, 'indexByCustomer']);
    Route::post('/transactions', [TransactionController::class, 'store']);

    // Import BPJS
    Route::post('/import/bpjs', [ImportController::class, 'importBpjs']);
});
