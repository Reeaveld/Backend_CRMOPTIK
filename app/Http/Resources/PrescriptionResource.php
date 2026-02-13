<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrescriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            
            // 1. Identitas Mata
            'side_code' => $this->eye_side, // "OD" atau "OS" (untuk logic kodingan)
            'side_label' => $this->eye_side === 'OD' ? 'Mata Kanan' : 'Mata Kiri', // (untuk tampilan UI)
            
            // 2. Data Refraksi (Diformat Standar Optik)
            // Sphere: Selalu tampil (misal: -2.00, +0.50, Plano)
            'sph' => $this->formatDiopter($this->sphere),
            
            // Cylinder: Tampil jika ada nilainya
            'cyl' => $this->cylinder != 0 ? $this->formatDiopter($this->cylinder) : '-',
            
            // Axis: Tambahkan derajat
            'axis' => $this->axis ? $this->axis . '°' : '-',
            
            // Addition (Untuk Progressive/Bifocal)
            'add' => $this->addition > 0 ? $this->formatDiopter($this->addition, true) : null,
            
            // Pupil Distance
            'pd' => $this->pd ? $this->pd . ' mm' : '-',
            
            // Jenis Lensa
            'lens_type' => $this->lens_type,
        ];
    }

    /**
     * Helper Analitis: Memformat angka desimal menjadi standar resep kacamata.
     * Contoh: 
     * -2.5  -> "-2.50"
     * 0.75  -> "+0.75" (Otomatis tambah plus jika positif)
     * 0     -> "Plano" (Atau 0.00 tergantung selera, optik biasanya sebut Plano)
     */
    private function formatDiopter($value, $forcePlus = false)
    {
        if ($value == 0) {
            return 'Plano'; // Atau '0.00'
        }

        // Format 2 desimal
        $formatted = number_format(abs($value), 2);
        
        // Tentukan tanda baca (+ atau -)
        $sign = $value > 0 ? '+' : '-';

        return $sign . $formatted;
    }
}