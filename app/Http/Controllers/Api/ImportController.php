<?php

namespace App\Http\Controllers\Api;

use App\Helpers\BpjsHelper;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class ImportController extends Controller
{
    /** Batas atas jumlah item per commit (satu berkas FPK bulanan ~141 baris). */
    private const MAX_COMMIT_ITEMS = 500;

    /**
     * TAHAP 1: PARSE & PREVIEW (STATELESS)
     * POST /api/import/bpjs/parse
     *
     * Menerima file PDF klaim kacamata BPJS, mengekstrak teks multi-halaman,
     * mendeteksi header metadata, mencocokkan baris klaim dengan database eksisting,
     * menandai duplikasi transaksi secara otomatis (auto-uncheck), dan mengembalikan
     * staging preview dalam bentuk JSON.
     */
    public function parseBpjs(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:pdf|max:10240', // Maksimal 10 MB
        ]);

        $file = $request->file('file');

        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            Log::error('parseBpjs: Gagal membaca file PDF', ['err' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'PDF tidak dapat dibaca: ' . $e->getMessage(),
            ], 422);
        }

        // Ekstraksi Metadata & Baris Data Transaksi BPJS via BpjsPdfTextParser
        $parseResult = \App\Services\BpjsPdfTextParser::parse($text);
        if (!$parseResult['success']) {
            return response()->json([
                'success' => false,
                'message' => $parseResult['message'],
            ], 422);
        }

        $metadata = $parseResult['metadata'];
        $parsedRows = $parseResult['rows'];

        // Kumpulkan Invoices dan Names untuk query efisien ke DB (mencegah N+1)
        $extractedInvoices = array_column($parsedRows, 'invoice_number');
        $extractedNames = array_column($parsedRows, 'customer_name');

        // 4. Cek Database untuk Duplikasi & Customer Lama
        $existingInvoices = Transaction::whereIn('invoice_number', $extractedInvoices)
            ->pluck('invoice_number')
            ->flip()
            ->toArray();

        $existingCustomers = Customer::whereIn('nama', array_unique($extractedNames))
            ->get()
            ->keyBy(function ($c) {
                return strtoupper(trim($c->nama));
            });

        // 5. Rakit Item Response dengan Status Duplikasi dan Sugesti No HP
        $items = [];
        $duplicateCount = 0;

        foreach ($parsedRows as $row) {
            $inv = $row['invoice_number'];
            $cName = $row['customer_name'];
            $amt = $row['amount'];

            // Duplikat terhadap DB, atau kemunculan ke-2 dst. dalam berkas yang sama
            $isDuplicate = isset($existingInvoices[$inv]) || ($row['duplicate_in_file'] ?? false);
            if ($isDuplicate) {
                $duplicateCount++;
            }

            $existingCust = $existingCustomers->get($cName);
            $hasExistingCust = ($existingCust !== null);
            $suggestedPhone = $hasExistingCust ? $existingCust->no_hp : null;

            $items[] = [
                'temp_id'              => $row['temp_id'],
                'invoice_number'       => $inv,
                'transaction_date'     => $row['transaction_date'],
                'customer_name'        => $cName,
                'claim_type'           => $row['claim_type'],
                'amount'               => $amt,
                'phone'                => $suggestedPhone,
                'suggested_phone'      => $suggestedPhone,
                'is_duplicate'         => $isDuplicate,
                'is_existing_customer' => $hasExistingCust,
                'existing_customer_id' => $hasExistingCust ? $existingCust->id : null,
                'selected'             => !$isDuplicate, // Auto-uncheck jika sudah ada di DB
            ];
        }

        // Verifikasi header (is_verified) sudah dihitung oleh BpjsPdfTextParser.
        $metadata['duplicate_count'] = $duplicateCount;

        return $this->jsonResponse([
            'success'      => true,
            'metadata'     => $metadata,
            'items'        => $items,
            'skipped_rows' => $parseResult['skipped_rows'] ?? [],
        ]);
    }

    /**
     * Respons JSON. Header Content-Length manual adalah tambalan untuk server dev
     * (`php artisan serve`) yang memotong payload besar (commit 25093ee). Pada server
     * produksi dengan kompresi, header manual berpotensi bentrok; matikan lewat
     * IMPORT_FORCE_CONTENT_LENGTH=false dan uji (lihat DEPLOYMENT.md, BE-8).
     */
    private function jsonResponse(array $data)
    {
        if (!config('app.import_force_content_length', true)) {
            return response()->json($data);
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE);

        return response($json, 200, [
            'Content-Type'   => 'application/json',
            'Content-Length' => strlen($json),
        ]);
    }

    /**
     * TAHAP 2: COMMIT & ATOMIC TRANSACTION
     * POST /api/import/bpjs/commit
     *
     * Menerima array data hasil tinjauan admin (nomor HP yang sudah dilengkapi,
     * status checklist item terpilih), lalu menyimpannya ke database dalam satu transaksi utuh:
     * 1. Customer: Buat baru atau perbarui nomor HP jika sebelumnya kosong.
     * 2. Transaction: Simpan invoice klaim berstatus 'done'.
     * 3. FollowUpSchedule: Otomatis buat jadwal H+3 dan H+330 (status pending jika no HP valid,
     *    atau blocked_incomplete_profile jika kosong).
     */
    public function commitBpjs(Request $request)
    {
        $validated = $request->validate([
            'nomor_fpk'               => 'nullable|string|max:50',
            'items'                   => 'required|array|min:1|max:' . self::MAX_COMMIT_ITEMS,
            'items.*.invoice_number'  => [
                'required', 'string', 'max:50',
                function ($attribute, $value, $fail) {
                    if (!BpjsHelper::isBpjsInvoice($value)) {
                        $fail('Format nomor invoice BPJS tidak valid.');
                    }
                },
            ],
            'items.*.transaction_date'=> 'required|date|after_or_equal:2020-01-01|before_or_equal:today',
            'items.*.customer_name'   => 'required|string|max:255',
            'items.*.amount'          => 'required|numeric|min:0',
            'items.*.phone'           => 'nullable|string|max:30',
        ]);

        $fpk = $validated['nomor_fpk'] ?? null;
        $items = $validated['items'];

        $importedCount = 0;
        $customersCreated = 0;
        $customersUpdated = 0;
        $schedulesPending = 0;
        $schedulesBlocked = 0;

        DB::beginTransaction();
        try {
            foreach ($items as $item) {
                $invoiceNumber = trim($item['invoice_number']);

                // Defensive guard: Jangan masukkan invoice yang sudah ada
                if (Transaction::where('invoice_number', $invoiceNumber)->exists()) {
                    continue;
                }

                $customerName = strtoupper(trim($item['customer_name']));
                $phone = !empty($item['phone']) ? trim($item['phone']) : null;
                $transactionDate = Carbon::parse($item['transaction_date']);

                // 1. Simpan atau perbarui Customer
                $customer = Customer::where('nama', $customerName)->first();
                if (!$customer) {
                    $customer = Customer::create([
                        'nama'       => $customerName,
                        'no_hp'      => $phone,
                        'created_at' => $transactionDate,
                        'updated_at' => $transactionDate,
                    ]);
                    $customersCreated++;
                } else {
                    // Jika pelanggan lama belum punya nomor HP dan kini disediakan:
                    if (empty($customer->no_hp) && !empty($phone)) {
                        $customer->update(['no_hp' => $phone]);
                        $customersUpdated++;

                        // Buka blokir jadwal follow up lama yang tertunda
                        FollowUpSchedule::blockedForCustomer($customer->id)->update([
                            'status' => FollowUpSchedule::STATUS_PENDING,
                            'notes'  => 'Jadwal diaktifkan otomatis setelah no HP dilengkapi via import BPJS',
                        ]);
                    }
                }

                // 2. Simpan Transaksi
                $transaction = Transaction::create([
                    'customer_id'      => $customer->id,
                    'invoice_number'   => $invoiceNumber,
                    'amount'           => (float) $item['amount'],
                    'is_bpjs'          => true,
                    'status'           => 'done', // Klaim BPJS disepakati selesai
                    'notes'            => 'Klaim Kacamata BPJS' . ($fpk ? " (FPK: {$fpk})" : ''),
                    'transaction_date' => $transactionDate->toDateString(),
                ]);

                // 3. Buat Jadwal Follow-Up Otomatis (H+3 & H+330)
                $initialStatus = $customer->isProfileComplete()
                    ? FollowUpSchedule::STATUS_PENDING
                    : FollowUpSchedule::STATUS_BLOCKED;

                if ($initialStatus === FollowUpSchedule::STATUS_PENDING) {
                    $schedulesPending += 2;
                } else {
                    $schedulesBlocked += 2;
                }

                // H+3: Cek kepuasan kacamata baru (3 hari setelah transaksi)
                FollowUpSchedule::create([
                    'customer_id'    => $customer->id,
                    'transaction_id' => $transaction->id,
                    'type'           => FollowUpSchedule::TYPE_H_PLUS_3,
                    'scheduled_date' => $transactionDate->copy()->addDays(3),
                    'status'         => $initialStatus,
                    'notes'          => 'Follow-up H+3 Klaim Kacamata BPJS',
                ]);

                // H+330: Pengingat hak klaim kacamata BPJS tahunan (11 bulan / 330 hari)
                FollowUpSchedule::create([
                    'customer_id'    => $customer->id,
                    'transaction_id' => $transaction->id,
                    'type'           => FollowUpSchedule::TYPE_H_PLUS_330,
                    'scheduled_date' => $transactionDate->copy()->addDays(330),
                    'status'         => $initialStatus,
                    'notes'          => 'Follow-up H+330 Pengingat Hak Klaim BPJS Tahunan',
                ]);

                $importedCount++;
            }

            DB::commit();

            $commitResult = [
                'success'           => true,
                'message'           => "Berhasil menyimpan {$importedCount} transaksi klaim BPJS ke database.",
                'imported_count'    => $importedCount,
                'customers_created' => $customersCreated,
                'customers_updated' => $customersUpdated,
                'schedules_pending' => $schedulesPending,
                'schedules_blocked' => $schedulesBlocked,
            ];

            return $this->jsonResponse($commitResult);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('commitBpjs: Rollback terjadi', ['err' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan data import: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * LEGACY BACKWARD COMPATIBILITY
     * POST /api/import/bpjs
     */
    public function importBpjs(Request $request)
    {
        $parseResponse = $this->parseBpjs($request);
        $data = json_decode($parseResponse->getContent(), true);

        if (!$data['success']) {
            return $parseResponse;
        }

        $itemsToCommit = array_filter($data['items'], fn($it) => $it['selected'] ?? true);
        if (empty($itemsToCommit)) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak ada item yang dapat diimpor (semua terdeteksi duplikat).',
            ], 422);
        }

        $commitRequest = new Request([
            'nomor_fpk' => $data['metadata']['nomor_fpk'] ?? null,
            'items'     => array_values($itemsToCommit),
        ]);

        return $this->commitBpjs($commitRequest);
    }
}
