<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Tabel ini menyimpan jadwal follow-up otomatis yang dibuat setiap kali
     * transaksi baru disimpan. Dua jadwal dibuat per transaksi:
     *   - h_plus_3   : Cek kepuasan pelanggan, 3 hari setelah transaksi.
     *   - h_plus_330  : Pengingat periksa mata tahunan, 330 hari setelah transaksi.
     *
     * Status lifecycle:
     *   pending                    -> Siap kirim saat scheduled_date tiba.
     *   blocked_incomplete_profile -> Customer belum punya no_hp valid (misal dari import BPJS).
     *                                 Otomatis berubah ke 'pending' saat admin melengkapi profil
     *                                 via PATCH /customers/{id}/complete-profile.
     *   sent                       -> Pesan berhasil terkirim via WhatsApp Gateway.
     *   failed                     -> Pengiriman gagal (network error, API error, dll).
     */
    public function up(): void
    {
        Schema::create('follow_up_schedules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                  ->constrained('customers')
                  ->cascadeOnDelete();

            $table->foreignId('transaction_id')
                  ->constrained('transactions')
                  ->cascadeOnDelete();

            // Tipe follow-up: h_plus_3 (cek kepuasan) atau h_plus_330 (pengingat tahunan)
            $table->string('type');          // 'h_plus_3', 'h_plus_330'

            // Tanggal jadwal pengiriman pesan
            $table->date('scheduled_date');

            // Status lifecycle jadwal
            $table->string('status')->default('pending');
            // Enum logis: 'pending', 'blocked_incomplete_profile', 'sent', 'failed'

            // Waktu aktual pesan terkirim (null jika belum)
            $table->timestamp('sent_at')->nullable();

            // Catatan tambahan (pesan error, dsb)
            $table->text('notes')->nullable();

            $table->timestamps();

            // Index untuk query scheduler: cari jadwal jatuh tempo hari ini
            $table->index(['status', 'scheduled_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('follow_up_schedules');
    }
};
