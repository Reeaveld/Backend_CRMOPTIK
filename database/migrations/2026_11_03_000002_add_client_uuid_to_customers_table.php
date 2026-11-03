<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * client_uuid: kunci idempoten dari aplikasi Android (outbox). Bila respons POST
     * hilang dan klien mengulang, server mengembalikan baris yang sama, bukan duplikat.
     * Nullable agar data lama (dan import BPJS) tidak terpengaruh; UNIQUE mengizinkan
     * banyak NULL di MySQL maupun SQLite.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->char('client_uuid', 36)->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
