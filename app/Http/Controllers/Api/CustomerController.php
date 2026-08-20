<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FollowUpSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

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
            'no_hp' => 'required|string|unique:customers,no_hp',
        ]);

        // Simpan ke MySQL
        $customer = Customer::create([
            'nama' => $validated['nama'],
            'no_hp' => $validated['no_hp'],
            // Field lain pakai default dulu
        ]);

        return response()->json($customer, 201);
    }

    // Android: PUT /api/customers/{id}
    public function update(Request $request, $id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        // Validasi konsisten dengan skema tabel customers (Indonesia)
        // Kolom DB: nama, no_hp, jenis_lensa, ukuran_kiri, ukuran_kanan, last_follow_up
        $validator = Validator::make($request->all(), [
            'nama'         => 'required|string|max:255',
            // Wajib unik di kolom no_hp, KECUALI baris customer ini sendiri
            'no_hp'        => 'required|string|max:30|unique:customers,no_hp,' . $id,
            'jenis_lensa'  => 'nullable|string|max:50',
            'ukuran_kiri'  => 'nullable|string|max:20',
            'ukuran_kanan' => 'nullable|string|max:20',
        ], [
            'no_hp.unique' => 'Nomor HP sudah terdaftar pada pelanggan lain!',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi Gagal',
                'errors'  => $validator->errors()
            ], 422); // 422 Unprocessable Entity
        }

        // Whitelist hanya kolom yang valid (lebih aman daripada $request->all())
        $customer->update($validator->validated());

        return response()->json(['success' => true, 'data' => $customer]);
    }

    /**
     * PATCH /api/customers/{id}/complete-profile
     *
     * Endpoint khusus untuk melengkapi data profil customer yang belum lengkap
     * (terutama customer hasil import BPJS yang belum punya no_hp).
     *
     * Saat no_hp valid berhasil disimpan dan isProfileComplete() bernilai true,
     * semua follow_up_schedules milik customer ini yang berstatus
     * 'blocked_incomplete_profile' otomatis di-unblock menjadi 'pending'.
     */
    public function completeProfile(Request $request, $id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer tidak ditemukan',
            ], 404);
        }

        // Validasi: no_hp wajib, format nomor Indonesia, unik
        $validator = Validator::make($request->all(), [
            'no_hp' => [
                'required',
                'string',
                'max:30',
                // Format nomor HP Indonesia: 08xx, 628xx, +628xx
                'regex:/^(\+62|62|0)8[0-9]{7,12}$/',
                'unique:customers,no_hp,' . $id,
            ],
        ], [
            'no_hp.required' => 'Nomor HP wajib diisi untuk melengkapi profil.',
            'no_hp.regex'    => 'Format nomor HP tidak valid. Gunakan format 08xxx atau 628xxx.',
            'no_hp.unique'   => 'Nomor HP sudah terdaftar pada pelanggan lain!',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validasi Gagal',
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Update nomor HP
        $customer->update($validator->validated());

        // Jika profil sekarang lengkap, unblock semua jadwal follow-up yang tertunda
        if ($customer->isProfileComplete()) {
            $unblockedCount = FollowUpSchedule::blockedForCustomer($customer->id)
                ->update(['status' => FollowUpSchedule::STATUS_PENDING]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Profil berhasil dilengkapi'
                       . (isset($unblockedCount) && $unblockedCount > 0
                          ? " dan {$unblockedCount} jadwal follow-up diaktifkan."
                          : '.'),
            'data'    => $customer->fresh(),  // Reload agar is_profile_complete ter-update
        ]);
    }
}