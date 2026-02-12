<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'customer_id',
        'invoice_number',
        'amount',
        'status',
        'notes',
        'transaction_date'
    ];

    // Relasi ke Customer (Milik Siapa?)
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    // Relasi ke Resep (Punya Apa Saja?) -> HasMany (Satu transaksi punya banyak data resep OD/OS)
    public function prescriptions()
    {
        return $this->hasMany(TransactionPrescription::class);
    }
}