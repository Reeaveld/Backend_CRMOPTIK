<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->boolean('is_bpjs')->default(false)->after('amount')->index();
        });

        // Backfill data eksisting: tandai transaksi yang memiliki nomor invoice BPJS (angka 0 atau huruf O)
        \Illuminate\Support\Facades\DB::table('transactions')
            ->where(function ($query) {
                $query->where('invoice_number', 'LIKE', '%01150006L%')
                      ->orWhere('invoice_number', 'LIKE', '%0115O006L%')
                      ->orWhere('invoice_number', 'LIKE', '%0115o006L%');
            })
            ->update(['is_bpjs' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn('is_bpjs');
        });
    }
};
