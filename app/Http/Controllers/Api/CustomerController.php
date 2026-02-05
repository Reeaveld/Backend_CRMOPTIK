<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    // Android: GET /api/customers
    public function index()
    {
        return response()->json(Customer::latest()->get());
    }

    // Android: POST /api/customers
    public function store(Request $request)
    {
        // Validasi input
        $validated = $request->validate([
            'nama' => 'required|string',
            'no_hp' => 'required|string',
        ]);

        // Simpan ke MySQL
        $customer = Customer::create([
            'nama' => $validated['nama'],
            'no_hp' => $validated['no_hp'],
            // Field lain pakai default dulu
        ]);

        return response()->json($customer, 201);
    }
}