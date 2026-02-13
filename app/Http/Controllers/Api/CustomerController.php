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

    // Di dalam method update()
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        // --- IMPLEMENTASI OPSI 1 (Strict Validation) ---
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            // Validasi: Wajib Unik di tabel customers kolom phone, KECUALI id customer ini sendiri
            'phone' => 'required|string|unique:customers,phone,' . $id, 
            'address' => 'nullable|string',
        ], [
            // Custom Error Message (Bahasa Indonesia)
            'phone.unique' => 'Nomor HP sudah terdaftar pada pelanggan lain!',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi Gagal',
                'errors' => $validator->errors()
            ], 422); // 422 Unprocessable Entity
        }

        // Jika lolos, update data...
        $customer->update($request->all());

        return response()->json(['success' => true, 'data' => $customer]);
    }
}