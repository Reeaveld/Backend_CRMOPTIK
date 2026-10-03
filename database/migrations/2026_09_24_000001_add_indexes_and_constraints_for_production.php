<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Menambahkan indeks komposit dan constraint integritas data untuk lingkungan hosting produksi:
     * 1. Indeks pada transactions.transaction_date dan (customer_id, transaction_date) untuk query cepat Dashboard & Search.
     * 2. Indeks pada customers.nama untuk pencarian cepat dan pencegahan N+1 saat import BPJS.
     * 3. Kolom softDeletes pada customers untuk mencegah kehilangan rekam medis dan histori optik pasien secara permanen.
     * 4. Indeks pada follow_up_schedules.sent_at untuk health check dan audit blast.
     * 5. Constraint unik (transaction_id, type) pada follow_up_schedules untuk mencegah duplikasi pengiriman pesan akibat network retry.
     */
    public function up(): void
    {
        // 1. Indeks pada Tabel Transactions
        Schema::table('transactions', function (Blueprint $table) {
            $table->index('transaction_date');
            $table->index(['customer_id', 'transaction_date']);
        });

        // 2. Indeks & SoftDeletes pada Tabel Customers
        Schema::table('customers', function (Blueprint $table) {
            $table->index('nama');
            $table->softDeletes();
        });

        // 3. Indeks & Constraint Unik pada Tabel FollowUpSchedules
        Schema::table('follow_up_schedules', function (Blueprint $table) {
            $table->index('sent_at');
            $table->unique(['transaction_id', 'type'], 'trx_type_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follow_up_schedules', function (Blueprint $table) {
            $table->dropUnique('trx_type_unique');
            $table->dropIndex(['sent_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['nama']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'transaction_date']);
            $table->dropIndex(['transaction_date']);
        });
    }
};
