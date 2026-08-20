<?php

namespace App\Services;

use App\Services\Contracts\WhatsAppServiceInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Implementasi WhatsApp Gateway menggunakan Fonnte API.
 *
 * Mode operasi dikontrol oleh env variable FONNTE_MODE:
 *   - 'dry_run' (default): Pesan di-log ke storage/logs, TIDAK ada HTTP call.
 *   - 'live': Pesan dikirim sungguhan ke API Fonnte.
 *
 * Konfigurasi .env yang diperlukan:
 *   FONNTE_MODE=dry_run
 *   FONNTE_API_TOKEN=your_api_token_here
 *   FONNTE_API_URL=https://api.fonnte.com/send   (opsional, ada default)
 */
class FonnteService implements WhatsAppServiceInterface
{
    private string $mode;
    private string $apiToken;
    private string $apiUrl;

    public function __construct()
    {
        $this->mode     = config('services.fonnte.mode', 'dry_run');
        $this->apiToken = config('services.fonnte.api_token', '');
        $this->apiUrl   = config('services.fonnte.api_url', 'https://api.fonnte.com/send');
    }

    /**
     * {@inheritDoc}
     *
     * Normalisasi nomor HP ke format 628xxx sebelum pengiriman.
     * Mencatat log detail untuk kedua mode (dry_run dan live).
     */
    public function sendMessage(string $phone, string $message, ?int $scheduleTimestamp = null): bool
    {
        // Normalisasi nomor: 08xxx → 628xxx
        $normalizedPhone = $this->normalizePhone($phone);

        if (empty($normalizedPhone)) {
            Log::warning('[Fonnte] Nomor HP kosong atau tidak valid, pesan dibatalkan.', [
                'original_phone' => $phone,
            ]);
            return false;
        }

        // Bangun payload request
        $payload = [
            'target'  => $normalizedPhone,
            'message' => $message,
        ];

        // Tambahkan schedule jika ada
        if ($scheduleTimestamp !== null) {
            $payload['schedule'] = $scheduleTimestamp;
        }

        // ─── DRY RUN MODE ───────────────────────────────────────────
        if ($this->mode === 'dry_run') {
            Log::info('[Fonnte Dry-Run] Pesan TIDAK dikirim (mode dry_run).', [
                'to'       => $normalizedPhone,
                'message'  => $message,
                'schedule' => $scheduleTimestamp
                    ? date('Y-m-d H:i:s', $scheduleTimestamp)
                    : 'immediate',
                'payload'  => $payload,
            ]);
            return true;
        }

        // ─── LIVE MODE ──────────────────────────────────────────────
        if (empty($this->apiToken)) {
            Log::error('[Fonnte] API token tidak dikonfigurasi. Set FONNTE_API_TOKEN di .env');
            return false;
        }

        try {
            $response = Http::withHeaders([
                    'Authorization' => $this->apiToken,
                ])
                ->retry(3, 200, function (\Exception $exception) {
                    // Retry hanya untuk network error, bukan client error 4xx
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->timeout(15)
                ->post($this->apiUrl, $payload);

            if ($response->successful()) {
                $body = $response->json();
                // Fonnte mengembalikan { "status": true } jika berhasil
                $success = $body['status'] ?? false;

                Log::info('[Fonnte Live] Pesan terkirim.', [
                    'to'       => $normalizedPhone,
                    'status'   => $success,
                    'response' => $body,
                ]);

                return (bool) $success;
            }

            Log::error('[Fonnte Live] HTTP error.', [
                'to'          => $normalizedPhone,
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);
            return false;

        } catch (\Exception $e) {
            Log::error('[Fonnte Live] Exception saat mengirim pesan.', [
                'to'      => $normalizedPhone,
                'error'   => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Normalisasi nomor HP Indonesia ke format internasional tanpa +.
     *
     * 08123456789  → 628123456789
     * +628123456789 → 628123456789
     * 628123456789  → 628123456789 (sudah benar)
     *
     * @param  string $phone
     * @return string Nomor yang sudah dinormalisasi, atau string kosong jika invalid
     */
    public function normalizePhone(string $phone): string
    {
        // Bersihkan: hapus spasi, strip, kurung
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);

        // Hapus + di depan jika ada
        $cleaned = ltrim($cleaned, '+');

        // Konversi 0 di depan → 62
        if (str_starts_with($cleaned, '0')) {
            $cleaned = '62' . substr($cleaned, 1);
        }

        // Validasi: harus diawali 628 dan total 11-15 digit
        if (preg_match('/^628[0-9]{7,12}$/', $cleaned)) {
            return $cleaned;
        }

        return '';
    }
}
