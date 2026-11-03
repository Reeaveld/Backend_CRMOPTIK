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

    /**
     * Seperti sendMessage(), tetapi mengembalikan detail dari provider.
     *
     * Catatan penting: success=true berarti pesan DITERIMA ANTREAN provider, bukan
     * terkirim ke pelanggan. Status pengiriman sebenarnya hanya tersedia lewat webhook.
     *
     * @return array{success: bool, id: ?string, process: ?string, status: string, reason: ?string}
     *         - id      : id pesan dari provider (untuk pencocokan webhook), null bila tidak ada
     *         - process : status proses mentah dari provider (mis. "pending"), null bila tidak ada
     *         - status  : 'sent' (diterima antrean) atau 'failed'
     *         - reason  : alasan kegagalan dari provider/sistem, null bila sukses
     */
    public function sendWhatsAppMessage(string $phone, string $message, ?int $scheduleTimestamp = null): array;
}
