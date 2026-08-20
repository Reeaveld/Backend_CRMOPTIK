<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Smalot\PdfParser\Parser;

class ImportController extends Controller
{
    /**
     * POST /api/import/bpjs
     *
     * Mengimpor data klaim kacamata BPJS dari file PDF.
     * Catatan skema (HARUS dijaga konsisten dengan migrations):
     *   - customers: (id, nama, no_hp, jenis_lensa, ukuran_kiri, ukuran_kanan, last_follow_up)
     *   - transactions: (id, customer_id, invoice_number UNIQUE, amount, status, notes, transaction_date)
     *
     * Karena PDF BPJS tidak menyediakan no_hp pelanggan, kita pakai dummy
     * "BPJS-<uniqid>" agar tidak melanggar constraint, dan tandai asal data
     * lewat kolom `notes` di tabel transactions.
     */
    public function importBpjs(Request $request)
    {
        // 1. Validasi File
        $request->validate([
            'file' => 'required|mimes:pdf|max:10240', // Maks 10 MB
        ]);

        $file = $request->file('file');

        // 2. Parse PDF
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($file->getPathname());
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            Log::error('ImportBpjs: gagal parse PDF', ['err' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'PDF tidak dapat dibaca: ' . $e->getMessage(),
            ], 422);
        }

        // 3. Pecah teks per baris
        $lines = preg_split("/\r\n|\n|\r/", $text);

        $importedCount = 0;
        $skipped = [];

        DB::beginTransaction(); // Atomic — semua atau tidak sama sekali
        try {
            foreach ($lines as $line) {
                // Heuristik: baris klaim BPJS diawali pola invoice "01150006L"
                // (Sesuaikan polanya bila kontrak data BPJS berubah.)
                if (strpos($line, '01150006L') === false) {
                    continue;
                }

                // Pecah baris berdasarkan dua spasi atau lebih (kolom PDF)
                $parts = preg_split('/\s{2,}/', trim($line));
                if (!is_array($parts) || count($parts) < 4) {
                    $skipped[] = ['line' => $line, 'reason' => 'kolom kurang dari 4'];
                    continue;
                }

                $invoiceNumber = $parts[0] ?? null;
                $dateRaw       = $parts[1] ?? null;     // "01/12/2025"
                $customerName  = strtoupper(trim($parts[2] ?? 'Unknown'));
                // Bersihkan harga (hapus pemisah ribuan)
                $amountRaw     = preg_replace('/[^\d.]/', '', str_replace(',', '', end($parts)));

                // Validasi minimal per baris
                $parsedDate = null;
                try {
                    if ($dateRaw) {
                        $parsedDate = Carbon::createFromFormat('d/m/Y', $dateRaw)->startOfDay();
                    }
                } catch (\Throwable $e) {
                    $skipped[] = ['line' => $line, 'reason' => 'format tanggal invalid'];
                    continue;
                }

                if (!$invoiceNumber || !$parsedDate || $amountRaw === '' || $amountRaw === null) {
                    $skipped[] = ['line' => $line, 'reason' => 'field wajib kosong'];
                    continue;
                }

                // 4. STRATEGI IMPORT (Mode Aman, sesuai schema Indonesia)
                // PDF BPJS TIDAK menyediakan nomor HP pelanggan.
                // Customer dibuat dengan no_hp = null, profil dianggap belum lengkap
                // sampai admin melengkapinya via PATCH /customers/{id}/complete-profile.
                $customer = Customer::query()
                    ->where('nama', $customerName)
                    ->whereNull('no_hp')  // Customer BPJS yang belum dilengkapi
                    ->first();

                if (!$customer) {
                    $customer = Customer::create([
                        'nama'  => $customerName,
                        'no_hp' => null,  // Profil belum lengkap — isProfileComplete() = false
                    ]);
                }

                // 5. Simpan Transaksi (idempoten via invoice_number UNIQUE)
                Transaction::firstOrCreate(
                    ['invoice_number' => $invoiceNumber],
                    [
                        'customer_id'      => $customer->id,
                        'amount'           => (float) $amountRaw,
                        'status'           => 'done', // Klaim BPJS = sudah selesai
                        'notes'            => 'Klaim Kacamata BPJS (impor PDF)',
                        'transaction_date' => $parsedDate->toDateString(),
                    ]
                );

                $importedCount++;
            }

            DB::commit();

            return response()->json([
                'success'    => true,
                'message'    => "Berhasil mengimport {$importedCount} data transaksi.",
                'imported'   => $importedCount,
                'skipped'    => count($skipped),
                'skip_log'   => $skipped, // untuk debugging — boleh dihapus di produksi
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('ImportBpjs: rollback', ['err' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
