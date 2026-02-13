<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Customer;
use App\Models\Transaction;
use Smalot\PdfParser\Parser;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ImportController extends Controller
{
    public function importBpjs(Request $request)
    {
        // 1. Validasi File
        $request->validate([
            'file' => 'required|mimes:pdf|max:10000', // Maks 10MB
        ]);

        $file = $request->file('file');
        
        // 2. Parse PDF
        $parser = new Parser();
        $pdf = $parser->parseFile($file->getPathname());
        $text = $pdf->getText();

        // 3. Ekstraksi Data per Baris (Regex atau logic pemecah string)
        // Kita pecah teks berdasarkan baris baru
        $lines = explode("\n", $text);
        
        $importedCount = 0;
        
        DB::beginTransaction(); // Atomic Transaction (Semua atau Tidak Sama Sekali)
        try {
            foreach ($lines as $line) {
                // LOGIKA PARSING (Perlu disesuaikan dengan format PDF spesifik Anda)
                // Contoh: Mencari pola yang diawali angka transaksi panjang
                // "01150006L25..."
                if (strpos($line, '01150006L') !== false) {
                    
                    // Asumsi pemisahan data berdasarkan koma atau tab (sesuai PDF)
                    // Mari kita anggap kita sudah dapat variabel: $nama, $tanggal, $harga, $invoice
                    
                    // --- SIMULASI EKSTRAKSI (Nanti kita sesuaikan regex-nya) ---
                    $parts = preg_split('/\s{2,}/', trim($line)); // Split by multiple spaces
                    if (count($parts) < 3) continue; 
                    
                    // Mapping data (Contoh kasar, harus dituning saat tes nyata)
                    $invoiceNumber = $parts[0] ?? null;
                    $dateRaw = $parts[1] ?? null; // "01/12/2025"
                    $customerName = $parts[2] ?? 'Unknown';
                    // Bersihkan harga (hapus koma)
                    $amountRaw = str_replace(',', '', end($parts)); 

                    // 4. STRATEGI IMPORT (Mode Aman)
                    // Cari customer berdasarkan NAMA saja (karena HP tidak ada)
                    // Jika tidak ada, buat baru dengan HP Dummy
                    $customer = Customer::where('name', strtoupper($customerName))
                                      ->where('address', 'Data Import BPJS') // Cek flag khusus
                                      ->first();

                    if (!$customer) {
                        $customer = Customer::create([
                            'name' => strtoupper($customerName),
                            // PHONE DUMMY UNIK (Penting agar tidak error DB Unique)
                            'phone' => 'BPJS-' . uniqid(), 
                            'address' => 'Data Import BPJS', // Penanda Visual
                            'email' => null,
                        ]);
                    }

                    // 5. Simpan Transaksi
                    Transaction::firstOrCreate(
                        ['invoice_number' => $invoiceNumber], // Cek duplikat invoice
                        [
                            'customer_id' => $customer->id,
                            'amount' => (float) $amountRaw,
                            'status' => 'done', // BPJS biasanya dianggap selesai/tagihan
                            'transaction_date' => Carbon::createFromFormat('d/m/Y', $dateRaw),
                            'lens_summary' => 'Klaim Kacamata BPJS', // Default text
                        ]
                    );

                    $importedCount++;
                }
            }
            
            DB::commit();
            return response()->json([
                'success' => true, 
                'message' => "Berhasil mengimport $importedCount data transaksi."
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
}