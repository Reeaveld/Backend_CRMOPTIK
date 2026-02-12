<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionPrescription extends Model
{
    protected $fillable = [
        'transaction_id',
        'eye_side',
        'sphere',
        'cylinder',
        'axis',
        'addition',
        'pd',
        'lens_type'
    ];
}