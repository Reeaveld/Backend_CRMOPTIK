<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            // 1. Identitas Utama
            'id' => $this->id,
            'invoice' => $this->invoice_number, // Di DB 'invoice_number', di JSON kita singkat jadi 'invoice'
            
            // 2. Format Tanggal (Human Readable)
            // Android tidak perlu repot format tanggal lagi.
            // Output: "14 Feb 2024"
            'date_formatted' => $this->transaction_date->format('d M Y'), 
            
            // 3. Format Uang
            // Kita kirim angka murni (int/float) untuk kalkulasi, 
            // DAN string terformat untuk tampilan (Rp ...)
            'amount_raw' => (double) $this->amount,
            'amount_formatted' => 'Rp ' . number_format($this->amount, 0, ',', '.'),

            // 4. Logika Status & Warna (Server-Driven UI)
            // Kita tentukan label dan warna badge langsung dari sini.
            'status_label' => $this->getStatusLabel($this->status),
            'status_color' => $this->getStatusColor($this->status),
            
            // 5. Relasi Data Resep (Child Table)
            // Kita muat jika datanya ada
            // Menggunakan method ::collection karena datanya berbentuk Array (Banyak)
            'prescriptions' => PrescriptionResource::collection($this->whenLoaded('prescriptions')),
        ];
    }

    // Helper untuk mengubah "process" menjadi "Diproses"
    private function getStatusLabel($status)
    {
        return match ($status) {
            'process' => 'Diproses',
            'done' => 'Selesai',
            'cancelled' => 'Dibatalkan',
            default => 'Unknown',
        };
    }

    // Helper untuk menentukan warna Badge di Android (Hex Code)
    private function getStatusColor($status)
    {
        return match ($status) {
            'process' => '#F59E0B', // Kuning (Amber)
            'done' => '#10B981',    // Hijau (Emerald)
            'cancelled' => '#EF4444', // Merah
            default => '#9CA3AF',   // Abu-abu
        };
    }
}