<?php

namespace App\Services\Contracts;

/**
 * Kontrak abstraksi untuk pengiriman pesan WhatsApp.
 *
 * Interface ini memisahkan business logic (scheduler, controller)
 * dari provider WhatsApp API tertentu. Jika di masa depan provider
 * diganti dari Fonnte ke Twilio, WaBlas, atau yang lain, cukup
 * buat implementasi baru tanpa mengubah caller manapun.
 */
interface WhatsAppServiceInterface
{
    /**
     * Mengirim pesan WhatsApp ke nomor tujuan.
     *
     * @param  string   $phone             Nomor HP tujuan (format: 628xxx atau 08xxx)
     * @param  string   $message           Isi pesan teks yang akan dikirim
     * @param  int|null $scheduleTimestamp  Unix timestamp untuk pengiriman terjadwal (null = kirim sekarang)
     *
     * @return bool  true jika pengiriman berhasil (atau berhasil di-log pada dry_run), false jika gagal
     */
    public function sendMessage(string $phone, string $message, ?int $scheduleTimestamp = null): bool;
}
