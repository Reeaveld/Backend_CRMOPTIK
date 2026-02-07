<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    // Tambahkan array ini (Sesuaikan dengan nama kolom di Database)
    protected $fillable = [
        'nama',
        'no_hp',
        'jenis_lensa',
        'ukuran_kiri',
        'ukuran_kanan',
        'last_follow_up'
    ];
}