<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'nama',
        'no_hp',
        'jenis_lensa',
        'ukuran_kiri',
        'ukuran_kanan',
        'last_follow_up',
    ];

    /**
     * Atribut yang otomatis di-append ke JSON/array representation.
     * Memungkinkan Android mengecek kelengkapan profil tanpa
     * harus menghitung sendiri di client.
     */
    protected $appends = ['is_profile_complete'];

    protected $casts = [
        'last_follow_up' => 'datetime',
    ];

    // ─── Relationships ──────────────────────────────────────────────

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function followUpSchedules(): HasMany
    {
        return $this->hasMany(FollowUpSchedule::class);
    }

    // ─── Business Logic ─────────────────────────────────────────────

    /**
     * Mengecek apakah profil customer sudah lengkap untuk menerima
     * pesan follow-up via WhatsApp.
     *
     * Kriteria:
     * 1. no_hp tidak null dan tidak kosong
     * 2. no_hp bukan dummy BPJS (diawali "BPJS-")
     * 3. no_hp adalah format nomor Indonesia yang valid:
     *    - Diawali 08 atau 628 atau +628
     *    - Panjang 10-15 digit (setelah normalisasi)
     *
     * @return bool
     */
    public function isProfileComplete(): bool
    {
        $phone = $this->no_hp;

        // Null atau kosong
        if (empty($phone)) {
            return false;
        }

        // Defensive guard: ImportController sekarang menulis no_hp = null (bukan 'BPJS-xxx'),
        // tetapi pengecekan ini SENGAJA dipertahankan karena:
        //   1. Database production mungkin masih memiliki record lama dengan 'BPJS-' prefix.
        //   2. Data bisa ter-restore dari backup/dump sebelum fix diterapkan.
        //   3. Biaya mempertahankan guard ini nol (satu if-statement) vs. risiko
        //      mengirim pesan WhatsApp ke string non-telepon.
        if (str_starts_with($phone, 'BPJS-')) {
            return false;
        }

        // Normalisasi: hapus spasi, strip, dan karakter non-digit kecuali +
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);

        // Validasi format nomor Indonesia
        // Bentuk valid: 08xxx (10-13 digit), 628xxx (11-15 digit), +628xxx (12-16 karakter)
        if (preg_match('/^(\+62|62|0)8[0-9]{7,12}$/', $cleaned)) {
            return true;
        }

        return false;
    }

    /**
     * Accessor agar `is_profile_complete` otomatis muncul di JSON response.
     * Laravel 12 accessor via get{Attribute}Attribute().
     */
    public function getIsProfileCompleteAttribute(): bool
    {
        return $this->isProfileComplete();
    }
}