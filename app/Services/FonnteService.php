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
     */
    public function sendMessage(string $phone, string $message, ?int $scheduleTimestamp = null): bool
    {
        $result = $this->sendWhatsAppMessage($phone, $message, $scheduleTimestamp);
        return $result['success'];
    }

    /**
     * Mengirim pesan dan mengembalikan payload detail (provider message id & status).
     *
     * success=true berarti pesan diterima antrean Fonnte (respons memuat "process":"pending"),
     * BUKAN terkirim ke pelanggan. Status pengiriman nyata hanya datang lewat webhook.
     *
     * {@inheritDoc}
     */
    public function sendWhatsAppMessage(string $phone, string $message, ?int $scheduleTimestamp = null): array
    {
        // Normalisasi nomor: 08xxx → 628xxx
        $normalizedPhone = $this->normalizePhone($phone);

        if (empty($normalizedPhone)) {
            Log::warning('[Fonnte] Nomor HP kosong atau tidak valid, pesan dibatalkan.', [
                'original_phone' => $phone,
            ]);
            return [
                'success' => false,
                'id'      => null,
                'process' => null,
                'status'  => 'failed',
                'reason'  => 'Nomor HP kosong atau tidak valid.',
            ];
        }

        // Bangun payload request
        $payload = [
            'target'  => $normalizedPhone,
            'message' => $message,
        ];

        if ($scheduleTimestamp !== null) {
            $payload['schedule'] = $scheduleTimestamp;
        }

        // ─── DRY RUN MODE ───────────────────────────────────────────
        if ($this->mode === 'dry_run') {
            $simulatedId = 'dry_run_' . bin2hex(random_bytes(6));
            Log::info('[Fonnte Dry-Run] Pesan TIDAK dikirim (mode dry_run).', [
                'to'           => $normalizedPhone,
                'message'      => $message,
                'simulated_id' => $simulatedId,
                'schedule'     => $scheduleTimestamp ? date('Y-m-d H:i:s', $scheduleTimestamp) : 'immediate',
            ]);
            return [
                'success' => true,
                'id'      => $simulatedId,
                'process' => 'dry_run',
                'status'  => 'sent',
                'reason'  => null,
            ];
        }

        // ─── LIVE MODE ──────────────────────────────────────────────
        if (empty($this->apiToken)) {
            Log::error('[Fonnte] API token tidak dikonfigurasi. Set FONNTE_API_TOKEN di .env');
            return [
                'success' => false,
                'id'      => null,
                'process' => null,
                'status'  => 'failed',
                'reason'  => 'FONNTE_API_TOKEN belum dikonfigurasi.',
            ];
        }

        try {
            $response = Http::withHeaders([
                    'Authorization' => $this->apiToken,
                ])
                ->retry(3, 200, function (\Exception $exception) {
                    return $exception instanceof \Illuminate\Http\Client\ConnectionException;
                })
                ->timeout(15)
                ->post($this->apiUrl, $payload);

            if ($response->successful()) {
                $body = $response->json();
                $success = $body['status'] ?? false;

                // Fonnte mengembalikan ID array misal "id": ["123456"] atau string
                $rawId = $body['id'] ?? null;
                $messageId = is_array($rawId) ? ($rawId[0] ?? null) : $rawId;

                Log::info('[Fonnte Live] Pesan diterima antrean Fonnte.', [
                    'to'       => $normalizedPhone,
                    'status'   => $success,
                    'id'       => $messageId,
                    'process'  => $body['process'] ?? null,
                ]);

                return [
                    'success' => (bool) $success,
                    'id'      => $messageId ? (string) $messageId : null,
                    'process' => $body['process'] ?? null,
                    'status'  => $success ? 'sent' : 'failed',
                    'reason'  => $success ? null : (string) ($body['reason'] ?? 'Ditolak oleh Fonnte.'),
                ];
            }

            Log::error('[Fonnte Live] HTTP error.', [
                'to'          => $normalizedPhone,
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);

            return [
                'success' => false,
                'id'      => null,
                'process' => null,
                'status'  => 'failed',
                'reason'  => 'HTTP ' . $response->status() . ' dari Fonnte.',
            ];

        } catch (\Exception $e) {
            Log::error('[Fonnte Live] Exception saat mengirim pesan.', [
                'to'    => $normalizedPhone,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'id'      => null,
                'process' => null,
                'status'  => 'failed',
                'reason'  => 'Exception: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Normalisasi nomor HP Indonesia ke format internasional tanpa +.
     */
    public function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9+]/', '', $phone);
        $cleaned = ltrim($cleaned, '+');

        if (str_starts_with($cleaned, '0')) {
            $cleaned = '62' . substr($cleaned, 1);
        }

        if (preg_match('/^628[0-9]{7,12}$/', $cleaned)) {
            return $cleaned;
        }

        return '';
    }
}
