<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FollowUpSchedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook "Update Message Status" dari Fonnte.
 *
 * Dokumentasi resmi (docs.fonnte.com/webhook-update-message-status) menyebut payload:
 *   device, id, stateid, status, state
 * Fonnte tidak mendokumentasikan tanda tangan (signature), sehingga endpoint ini
 * dilindungi rahasia bersama (FONNTE_WEBHOOK_SECRET) via query `secret` atau header
 * `X-Webhook-Secret`. Tanpa rahasia terkonfigurasi, endpoint menolak semua permintaan.
 *
 * BELUM-DIVERIFIKASI terhadap webhook live: nilai pasti `state` (diasumsikan
 * "delivered"/"read" untuk penanda tersampaikan).
 */
class WebhookController extends Controller
{
    /** Status dari Fonnte yang berarti pesan tidak akan sampai. */
    private const FAILURE_STATUSES = ['invalid', 'failed', 'expired', 'url unreachable'];

    /** Nilai `state` yang kita anggap sebagai tersampaikan ke perangkat penerima. */
    private const DELIVERED_STATES = ['delivered', 'read'];

    public function fonnteStatus(Request $request)
    {
        $secret = (string) config('services.fonnte.webhook_secret', '');
        if ($secret === '') {
            return response()->json(['success' => false, 'message' => 'Webhook belum diaktifkan.'], 503);
        }

        $provided = (string) ($request->header('X-Webhook-Secret') ?? $request->query('secret', ''));
        if (!hash_equals($secret, $provided)) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $id = $request->input('id');
        $stateId = $request->input('stateid');
        $status = $request->input('status');
        $state = $request->input('state');

        if (is_array($id)) {
            $id = $id[0] ?? null;
        }

        $schedule = null;
        if ($id !== null && $id !== '') {
            $schedule = FollowUpSchedule::where('provider_message_id', (string) $id)->first();
        } elseif ($stateId !== null && $stateId !== '') {
            // Pembaruan state saja (tanpa id): cocokkan lewat stateid yang tersimpan sebelumnya
            $schedule = FollowUpSchedule::where('provider_stateid', (string) $stateId)->first();
        }

        if (!$schedule) {
            // Respons 200 agar Fonnte tidak mengulang; pesan di luar sistem ini diabaikan.
            return response()->json(['success' => true, 'matched' => false]);
        }

        $updates = [];

        if ($status !== null && $status !== '') {
            $updates['provider_status'] = strtolower((string) $status);
        }
        if ($state !== null && $state !== '') {
            $updates['provider_state'] = strtolower((string) $state);
        }
        if ($stateId !== null && $stateId !== '') {
            $updates['provider_stateid'] = (string) $stateId;
        }

        if (isset($updates['provider_state'])
            && in_array($updates['provider_state'], self::DELIVERED_STATES, true)
            && $schedule->delivered_at === null) {
            $updates['delivered_at'] = now();
        }

        // Antrean ternyata gagal diproses provider => jangan biarkan berstatus 'sent'
        if (isset($updates['provider_status'])
            && in_array($updates['provider_status'], self::FAILURE_STATUSES, true)
            && $schedule->status === FollowUpSchedule::STATUS_SENT) {
            $updates['status'] = FollowUpSchedule::STATUS_FAILED;
            $updates['notes'] = 'Fonnte melaporkan status: ' . $updates['provider_status'] . '.';
        }

        $schedule->update($updates);

        Log::info('[Fonnte Webhook] Status pesan diperbarui.', [
            'schedule_id' => $schedule->id,
            'status'      => $updates['provider_status'] ?? null,
            'state'       => $updates['provider_state'] ?? null,
        ]);

        return response()->json(['success' => true, 'matched' => true]);
    }
}
