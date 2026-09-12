<?php

namespace App\Http\Controllers\Api;

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

        // 1. Ekstraksi Header Metadata
        $headerSection = strstr($text, 'NO TRANSAKSI', true) ?: substr($text, 0, 1500);

        preg_match('/(L\d{13})/i', $headerSection, $fpkMatch);
        preg_match('/OPTIK\s+[^\r\n]+/i', $headerSection, $providerMatch);
        preg_match('/(January|February|March|April|May|June|July|August|September|October|November|December),\s*\d{4}/i', $headerSection, $bulanMatch);

        $fpkNumber = $fpkMatch[1] ?? null;
        $provider = trim($providerMatch[0] ?? 'Optik CRM');
        $bulanPelayanan = trim($bulanMatch[0] ?? '');

        $headerTotalData = null;
        $headerTotalTagihan = null;

        // Cek format stream Smalot (kolom angka terpisah baris baru sebelum "BPJS Kesehatan")
        if (preg_match('/(\d+)\s*\n([\d,]+)\s*\nBPJS/i', $headerSection, $streamTotals)) {
            $headerTotalData = (int) $streamTotals[1];
            $headerTotalTagihan = (float) str_replace(',', '', $streamTotals[2]);
        } elseif (preg_match('/Total\s+Data\s*[:\s]+(\d+)/i', $headerSection, $tdMatch) &&
                  preg_match('/Total\s+Tagihan\s*[:\s]+([\d,.]+)/i', $headerSection, $ttMatch)) {
            $headerTotalData = (int) $tdMatch[1];
            $headerTotalTagihan = (float) str_replace(',', '', $ttMatch[1]);
        }

        // 2. Ekstraksi Baris Data Transaksi
        // Pola utama (Smalot Stream Order: Invoice, Biaya, Nama, Tanggal, Jenis Klaim)
        $pattern1 = '/(0115[O00-9]006L\d{10,12})\s+([\d,.]+)\s*(.+?)\s*(\d{2}\/\d{2}\/\d{4})\s+Kacamata/i';
        preg_match_all($pattern1, $text, $rawMatches, PREG_SET_ORDER);

        $isOrderInverted = true;
        // Fallback ke urutan visual layout standar jika stream pattern1 tidak menemukan data
        if (empty($rawMatches)) {
            $pattern2 = '/(0115[O00-9]006L\d{10,12})\s+(\d{2}\/\d{2}\/\d{4})\s+(.+?)\s+Kacamata\s+([\d,.]+)/i';
            preg_match_all($pattern2, $text, $rawMatches, PREG_SET_ORDER);
            $isOrderInverted = false;
        }

        if (empty($rawMatches)) {
            return response()->json([
                'success' => false,
                'message' => 'Format tabel klaim BPJS tidak dikenali atau tidak ada data yang cocok dengan pola invoice.',
            ], 422);
        }

        // 3. Kumpulkan Invoices dan Names untuk query efisien ke DB (mencegah N+1)
        $extractedInvoices = [];
        $extractedNames = [];
        $parsedRows = [];

        foreach ($rawMatches as $index => $match) {
            $invoice = trim($match[1]);
            if ($isOrderInverted) {
                $amount = (float) str_replace(',', '', $match[2]);
                $customerName = strtoupper(trim($match[3]));
                $dateRaw = trim($match[4]);
            } else {
                $dateRaw = trim($match[2]);
                $customerName = strtoupper(trim($match[3]));
                $amount = (float) str_replace(',', '', $match[4]);
            }

            // Normalisasi tanggal
            $formattedDate = null;
            try {
                $formattedDate = Carbon::createFromFormat('d/m/Y', $dateRaw)->toDateString();
            } catch (\Throwable $e) {
                $formattedDate = Carbon::now()->toDateString();
            }

            $extractedInvoices[] = $invoice;
            $extractedNames[] = $customerName;

            $parsedRows[] = [
                'temp_id'          => $index + 1,
                'invoice_number'   => $invoice,
                'transaction_date' => $formattedDate,
                'customer_name'    => $customerName,
                'claim_type'       => 'Kacamata',
                'amount'           => $amount,
            ];
        }

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
        $totalAmountParsed = 0;
        $duplicateCount = 0;

        foreach ($parsedRows as $row) {
            $inv = $row['invoice_number'];
            $cName = $row['customer_name'];
            $amt = $row['amount'];
            $totalAmountParsed += $amt;

            $isDuplicate = isset($existingInvoices[$inv]);
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

        $totalParsed = count($items);
        $isCountVerified = ($headerTotalData !== null) ? ($totalParsed === $headerTotalData) : true;
        $isAmountVerified = ($headerTotalTagihan !== null) ? (abs($totalAmountParsed - $headerTotalTagihan) < 1) : true;

        $responseData = [
            'success'  => true,
            'metadata' => [
                'nomor_fpk'            => $fpkNumber,
                'provider'             => $provider,
                'bulan_pelayanan'      => $bulanPelayanan,
                'total_data_header'    => $headerTotalData,
                'total_tagihan_header' => $headerTotalTagihan,
                'total_parsed'         => $totalParsed,
                'total_amount_parsed'  => $totalAmountParsed,
                'duplicate_count'      => $duplicateCount,
                'is_verified'          => ($isCountVerified && $isAmountVerified),
            ],
            'items'    => $items,
        ];

        $json = json_encode($responseData, JSON_UNESCAPED_UNICODE);

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
            'nomor_fpk'               => 'nullable|string',
            'items'                   => 'required|array|min:1',
            'items.*.invoice_number'  => 'required|string',
            'items.*.transaction_date'=> 'required|date',
            'items.*.customer_name'   => 'required|string',
            'items.*.amount'          => 'required|numeric',
            'items.*.phone'           => 'nullable|string',
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

                // 1. Simpan atau perbarui Customer
                $customer = Customer::where('nama', $customerName)->first();
                if (!$customer) {
                    $customer = Customer::create([
                        'nama'  => $customerName,
                        'no_hp' => $phone,
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
                $transactionDate = Carbon::parse($item['transaction_date']);
                $transaction = Transaction::create([
                    'customer_id'      => $customer->id,
                    'invoice_number'   => $invoiceNumber,
                    'amount'           => (float) $item['amount'],
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

            $json = json_encode($commitResult, JSON_UNESCAPED_UNICODE);

            return response($json, 200, [
                'Content-Type'   => 'application/json',
                'Content-Length' => strlen($json),
            ]);
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
        $data = $parseResponse->getData(true);

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
