<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Models\FollowUpSchedule;

// =====================================================================
// HEALTH CHECK (Public Diagnostic Endpoint)
// =====================================================================
Route::get('/health', function () {
    $dbOk = false;

    try {
        DB::connection()->getPdo();
        $dbOk = true;
    } catch (\Throwable $e) {
        \Illuminate\Support\Facades\Log::error('Health check DB connection error: ' . $e->getMessage());
    }

    return response()->json([
        'status' => $dbOk ? 'healthy' : 'degraded',
    ], $dbOk ? 200 : 503);
});

// =====================================================================
// AUTHENTICATION (Public Routes with Brute-Force Rate Limiting)
// =====================================================================
// Maksimal 5 percobaan login per menit per IP
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// =====================================================================
// PROTECTED API ROUTES (Sanctum Auth Required + Throttling)
// =====================================================================
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // Dashboard Analytics & Reports
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/customers/search', [DashboardController::class, 'searchByDate']);
    Route::get('/dashboard/export', [DashboardController::class, 'exportCsv']);

    // Customers CRUD & Profile Completion
    Route::apiResource('customers', CustomerController::class);
    Route::patch('/customers/{id}/complete-profile', [CustomerController::class, 'completeProfile']);

    // Transactions
    Route::get('/customers/{id}/transactions', [TransactionController::class, 'indexByCustomer']);
    Route::post('/transactions', [TransactionController::class, 'store']);

    // Import BPJS (dibatasi 10 request/menit untuk menjaga resource parsing PDF)
    Route::post('/import/bpjs', [ImportController::class, 'importBpjs'])->middleware('throttle:10,1');
    Route::post('/import/bpjs/parse', [ImportController::class, 'parseBpjs'])->middleware('throttle:10,1');
    Route::post('/import/bpjs/commit', [ImportController::class, 'commitBpjs'])->middleware('throttle:10,1');
});
