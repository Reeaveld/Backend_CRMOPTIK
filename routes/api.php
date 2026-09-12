<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\AuthController;
use App\Models\FollowUpSchedule;

// =====================================================================
// HEALTH CHECK (Public Diagnostic Endpoint)
// =====================================================================
Route::get('/health', function () {
    $dbOk = false;
    $dbError = null;

    try {
        DB::connection()->getPdo();
        $dbOk = true;
    } catch (\Throwable $e) {
        $dbError = $e->getMessage();
    }

    // Cek aktivitas terakhir follow up blast
    $lastSent = FollowUpSchedule::whereNotNull('sent_at')->latest('sent_at')->value('sent_at');

    return response()->json([
        'status'         => $dbOk ? 'healthy' : 'degraded',
        'app_name'       => config('app.name'),
        'timezone'       => config('app.timezone'),
        'server_time'    => now()->toDateTimeString(),
        'database'       => [
            'connected' => $dbOk,
            'driver'    => DB::connection()->getDriverName(),
            'error'     => $dbError,
        ],
        'fonnte_mode'    => config('services.fonnte.mode'),
        'last_followup'  => $lastSent ? $lastSent->toDateTimeString() : null,
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
