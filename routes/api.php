<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CustomerController; // <--- Import Controller
use App\Http\Controllers\Api\TransactionController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::apiResource('customers', CustomerController::class);
// Atau manual:
// Route::get('/customers', [CustomerController::class, 'index']);
// Route::post('/customers', [CustomerController::class, 'store']);

// GET /api/customers/{id}/transactions -> Ambil riwayat si Budi
Route::get('/customers/{id}/transactions', [TransactionController::class, 'indexByCustomer']);
Route::post('/transactions', [TransactionController::class, 'store']); // Untuk input transaksi baru nanti