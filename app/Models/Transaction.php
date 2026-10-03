<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'customer_id',
        'invoice_number',
        'amount',
        'is_bpjs',
        'status',
        'notes',
        'transaction_date'
    ];

    protected $casts = [
        'is_bpjs' => 'boolean',
        'amount'  => 'float',
    ];

    protected static function booted()
    {
        static::creating(function ($transaction) {
            if ($transaction->is_bpjs === null && !empty($transaction->invoice_number)) {
                $transaction->is_bpjs = \App\Helpers\BpjsHelper::isBpjsInvoice($transaction->invoice_number);
            }
        });
    }

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