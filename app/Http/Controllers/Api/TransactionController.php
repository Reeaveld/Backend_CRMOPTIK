<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use App\Models\TransactionPrescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Http\Resources\TransactionResource;
use Carbon\Carbon;

class TransactionController extends Controller
{
    public function store(Request $request)
    {
        // 1. Validasi Input yang Ekstensif
        $validated = $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount' => 'required|numeric',
            'transaction_date' => 'required|date',
            
            // Validasi Array Prescriptions (Resep)
            'prescriptions' => 'required|array|min:1',
            'prescriptions.*.eye_side' => 'required|in:OD,OS',
            'prescriptions.*.sphere' => 'required|numeric',
            'prescriptions.*.lens_type' => 'required|string',
        ]);

        try {
            // Gunakan DB Transaction untuk integritas data
            $result = DB::transaction(function () use ($validated) {
                
                // A. Generate Invoice Number ala Frappe (INV/TAHUN/RANDOM)
                $invoiceNumber = 'INV/' . date('Y') . '/' . strtoupper(Str::random(5));

                // B. Simpan Header Transaksi
                $transaction = Transaction::create([
                    'customer_id' => $validated['customer_id'],
                    'invoice_number' => $invoiceNumber,
                    'amount' => $validated['amount'],
                    'transaction_date' => $validated['transaction_date'],
                    'status' => 'process'
                ]);

                // C. Simpan Detail Resep (Looping)
                foreach ($validated['prescriptions'] as $prescription) {
                    TransactionPrescription::create([
                        'transaction_id' => $transaction->id,
                        'eye_side' => $prescription['eye_side'],
                        'sphere' => $prescription['sphere'],
                        'cylinder' => $prescription['cylinder'] ?? 0,
                        'axis' => $prescription['axis'] ?? 0,
                        'addition' => $prescription['addition'] ?? 0,
                        'lens_type' => $prescription['lens_type'],
                    ]);
                }

                // D. Auto-create jadwal follow-up
                $customer = Customer::find($validated['customer_id']);
                $transactionDate = Carbon::parse($validated['transaction_date']);

                // Tentukan status awal berdasarkan kelengkapan profil customer
                $initialStatus = $customer->isProfileComplete()
                    ? FollowUpSchedule::STATUS_PENDING
                    : FollowUpSchedule::STATUS_BLOCKED;

                // D1. H+3: Cek kepuasan pelanggan (3 hari setelah transaksi)
                FollowUpSchedule::create([
                    'customer_id'    => $customer->id,
                    'transaction_id' => $transaction->id,
                    'type'           => FollowUpSchedule::TYPE_H_PLUS_3,
                    'scheduled_date' => $transactionDate->copy()->addDays(3),
                    'status'         => $initialStatus,
                ]);

                // D2. H+330: Pengingat periksa mata tahunan (330 hari setelah transaksi)
                FollowUpSchedule::create([
                    'customer_id'    => $customer->id,
                    'transaction_id' => $transaction->id,
                    'type'           => FollowUpSchedule::TYPE_H_PLUS_330,
                    'scheduled_date' => $transactionDate->copy()->addDays(330),
                    'status'         => $initialStatus,
                ]);

                return $transaction->load('prescriptions');
            });

            return response()->json([
                'success' => true,
                'data' => $result
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan transaksi: ' . $e->getMessage()
            ], 500);
        }
    }
    
    public function indexByCustomer($id)
    {
        $transactions = Transaction::with('prescriptions') // 1. Memuat relasi resep (mencegah N+1 query problem)
            ->where('customer_id', $id)                    // 2. Memfilter transaksi khusus untuk ID pelanggan ini
            ->orderBy('transaction_date', 'desc')          // 3. Mengurutkan dari tanggal paling baru (descending)
            ->get();                                       // 4. Mengeksekusi query ke database

        // Mengembalikan data berupa JSON yang sudah diformat rapi oleh Resource
        return TransactionResource::collection($transactions);
    }
}