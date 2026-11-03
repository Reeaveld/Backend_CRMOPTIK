<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FollowUpSchedule;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    // Android: GET /api/customers
    public function index()
    {
        return response()->json(Customer::latest()->get());
    }

    /**
     * Respons untuk client_uuid yang sudah ada: 200 + data lama (bukan 201 agar jelas replay).
     * Pelanggan yang sudah dihapus di server dijawab 409 supaya klien tidak mengikat
     * remoteId ke baris yang sudah tidak aktif.
     */
    private function replayByClientUuid(string $clientUuid)
    {
        $existing = Customer::withTrashed()->where('client_uuid', $clientUuid)->first();
        if (!$existing) {
            return null;
        }

        if ($existing->trashed()) {
            return response()->json([
                'success' => false,
                'message' => 'Pelanggan ini sudah dihapus di server.',
            ], 409);
        }

        return response()->json($existing, 200);
    }

    // GET /api/customers/{id}
    public function show($id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['message' => 'Customer not found'], 404);
        }

        return response()->json($customer);
    }

    // Android: POST /api/customers
    public function store(Request $request)
    {
        // Idempotensi (outbox Android): bila client_uuid sudah pernah diterima, kembalikan
        // baris yang sama. Harus diperiksa SEBELUM validasi unique no_hp, karena pengulangan
        // request yang sama akan bentrok dengan no_hp yang disimpan oleh percobaan pertama.
        $clientUuid = $request->input('client_uuid');
        if (is_string($clientUuid) && Str::isUuid($clientUuid)) {
            $replay = $this->replayByClientUuid($clientUuid);
            if ($replay) {
                return $replay;
            }
        }

        // Validasi input
        $validated = $request->validate([
            'client_uuid' => 'nullable|uuid',
            'nama' => 'required|string|max:255',
            'no_hp' => [
                'required', 'string', 'max:30',
                // Pelanggan yang sudah di-soft-delete tidak boleh memblokir nomor yang sama
                Rule::unique('customers', 'no_hp')->whereNull('deleted_at'),
            ],
        ], [
            'no_hp.unique' => 'Nomor HP sudah terdaftar pada pelanggan lain!',
        ]);

        // Simpan ke MySQL
        try {
            $customer = Customer::create([
                'client_uuid' => $validated['client_uuid'] ?? null,
                'nama' => $validated['nama'],
                'no_hp' => $validated['no_hp'],
                // Field lain pakai default dulu
            ]);
        } catch (QueryException $e) {
            // Dua request dengan client_uuid sama berlomba: yang kalah mengambil baris pemenang
            if (!empty($validated['client_uuid'])) {
                $replay = $this->replayByClientUuid($validated['client_uuid']);
                if ($replay) {
                    return $replay;
                }
            }
            throw $e;
        }

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
            'no_hp'        => [
                'required', 'string', 'max:30',
                Rule::unique('customers', 'no_hp')->ignore($id)->whereNull('deleted_at'),
            ],
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
                Rule::unique('customers', 'no_hp')->ignore($id)->whereNull('deleted_at'),
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

    /**
     * DELETE /api/customers/{id}
     *
     * Soft delete customer (rekam histori dipertahankan) dan membatalkan jadwal
     * follow-up yang belum terkirim (status 'cancelled', bukan 'failed').
     */
    public function destroy($id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Customer tidak ditemukan',
            ], 404);
        }

        // Batalkan jadwal follow-up yang masih pending atau terblokir
        DB::transaction(function () use ($customer, $id) {
            FollowUpSchedule::where('customer_id', $id)
                ->whereIn('status', [
                    FollowUpSchedule::STATUS_PENDING,
                    FollowUpSchedule::STATUS_BLOCKED,
                ])
                ->update([
                    'status' => FollowUpSchedule::STATUS_CANCELLED,
                    'notes'  => 'Dibatalkan: Pelanggan telah dihapus oleh admin.',
                ]);

            $customer->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'Customer berhasil dihapus',
        ]);
    }
}