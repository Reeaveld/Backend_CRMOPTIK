<?php

namespace App\Services;

use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Metrik evaluasi Rumusan Masalah 3. Definisi formal: docs/METRICS.md.
 *
 * Notasi: W = rentang [from, to]; tx(c, W) = jumlah transaksi pelanggan c dalam W.
 * Seluruh agregasi dilakukan di SQL (COUNT / COUNT DISTINCT), kecuali Follow-up Return
 * Rate yang kohortnya kecil dan dihitung di PHP tanpa N+1 (satu query per 500 pelanggan).
 * Pelanggan yang sudah di-soft-delete tidak dihitung.
 */
class RetentionMetrics
{
    public const SEGMENT_ALL = 'all';
    public const SEGMENT_BPJS = 'bpjs';
    public const SEGMENT_NON_BPJS = 'non_bpjs';

    /**
     * RPR(W) = |{c : tx(c,W) >= 2}| / |{c : tx(c,W) >= 1}| x 100
     * RCR(W) = |{c : tx(c,W) >= 1 dan ada transaksi sebelum from}| / |{c : tx(c,W) >= 1}| x 100
     * "ada transaksi sebelum from" memeriksa semua transaksi pelanggan (BPJS maupun non-BPJS).
     *
     * @return array{active_customers:int, repeat_customers:int, returning_customers:int,
     *               repeat_purchase_rate:?float, returning_customer_rate:?float}
     */
    public function purchaseMetrics(string $from, string $to, string $segment = self::SEGMENT_ALL): array
    {
        $perCustomer = $this->baseTransactions($from, $to, $segment)
            ->select('t.customer_id')
            ->selectRaw('COUNT(*) as n')
            ->groupBy('t.customer_id');

        $agg = DB::query()->fromSub($perCustomer, 'x')
            ->selectRaw('COUNT(*) as active')
            ->selectRaw('COALESCE(SUM(CASE WHEN n >= 2 THEN 1 ELSE 0 END), 0) as repeaters')
            ->first();

        $active = (int) ($agg->active ?? 0);
        $repeaters = (int) ($agg->repeaters ?? 0);

        $returning = $this->baseTransactions($from, $to, $segment)
            ->whereExists(function ($q) use ($from) {
                $q->select(DB::raw(1))
                    ->from('transactions as p')
                    ->whereColumn('p.customer_id', 't.customer_id')
                    ->where('p.transaction_date', '<', $from);
            })
            ->distinct()
            ->count('t.customer_id');

        return [
            'active_customers'        => $active,
            'repeat_customers'        => $repeaters,
            'returning_customers'     => $returning,
            'repeat_purchase_rate'    => $this->pct($repeaters, $active),
            'returning_customer_rate' => $this->pct($returning, $active),
        ];
    }

    /**
     * queued_rate = sent / (sent + failed) untuk jadwal JATUH TEMPO (scheduled_date <= hari ini)
     * dengan scheduled_date dalam W. "sent" berarti diterima antrean provider, BUKAN terkirim.
     * Pending, blocked, dan cancelled dilaporkan terpisah dan tidak masuk penyebut.
     * Pesan simulasi (dry_run) dilaporkan terpisah dan tidak dihitung sebagai terkirim.
     */
    public function queueMetrics(string $from, string $to): array
    {
        $schedules = FollowUpSchedule::query()
            ->whereDate('scheduled_date', '>=', $from)
            ->whereDate('scheduled_date', '<=', $to);

        $today = Carbon::today()->toDateString();

        $byStatus = (clone $schedules)
            ->selectRaw('status, COUNT(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        $sentSimulated = (clone $schedules)
            ->where('status', FollowUpSchedule::STATUS_SENT)
            ->where('provider_message_id', 'like', 'dry_run_%')
            ->count();

        $delivered = (clone $schedules)
            ->where('status', FollowUpSchedule::STATUS_SENT)
            ->whereNotNull('delivered_at')
            ->count();

        $failedDue = (clone $schedules)
            ->where('status', FollowUpSchedule::STATUS_FAILED)
            ->whereDate('scheduled_date', '<=', $today)
            ->count();

        $sent = (int) ($byStatus[FollowUpSchedule::STATUS_SENT] ?? 0) - $sentSimulated;
        $webhookEnabled = (string) config('services.fonnte.webhook_secret', '') !== '';

        return [
            'sent'              => $sent,
            'sent_simulated'    => $sentSimulated,
            'failed'            => $failedDue,
            'pending'           => (int) ($byStatus[FollowUpSchedule::STATUS_PENDING] ?? 0),
            'blocked'           => (int) ($byStatus[FollowUpSchedule::STATUS_BLOCKED] ?? 0),
            'cancelled'         => (int) ($byStatus[FollowUpSchedule::STATUS_CANCELLED] ?? 0),
            'queued_rate'       => $this->pct($sent, $sent + $failedDue),
            'delivered'         => $delivered,
            // Hanya bermakna bila webhook status aktif; selain itu null (bukan 0%).
            'delivered_rate'    => $webhookEnabled ? $this->pct($delivered, $sent) : null,
            'delivery_tracking' => $webhookEnabled,
        ];
    }

    /**
     * Follow-up Return Rate: dari pelanggan yang menerima follow-up berstatus 'sent' (bukan simulasi)
     * dengan sent_at dalam W, persentase yang bertransaksi BARU dalam N hari setelah tanggal sent_at
     * (tidak menghitung transaksi asal jadwal). Pembanding alami: pelanggan dengan jadwal
     * 'blocked_incomplete_profile' (scheduled_date dalam W) yang tidak menerima pesan.
     * Hanya kohort dengan jendela pengamatan lengkap (ref + N hari <= hari ini) yang dihitung.
     *
     * Korelasional, bukan kausal; pembanding memiliki bias seleksi (profil tidak lengkap).
     */
    public function followUpReturn(string $from, string $to, int $days): array
    {
        $sent = FollowUpSchedule::query()
            ->where('status', FollowUpSchedule::STATUS_SENT)
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where(function ($q) {
                $q->whereNull('provider_message_id')
                  ->orWhere('provider_message_id', 'not like', 'dry_run_%');
            })
            ->get(['customer_id', 'transaction_id', 'sent_at']);

        $treated = [];
        foreach ($sent as $row) {
            $this->addToCohort($treated, $row->customer_id, Carbon::parse($row->sent_at)->startOfDay(), $row->transaction_id);
        }

        $blocked = FollowUpSchedule::query()
            ->where('status', FollowUpSchedule::STATUS_BLOCKED)
            ->whereDate('scheduled_date', '>=', $from)
            ->whereDate('scheduled_date', '<=', $to)
            ->get(['customer_id', 'transaction_id', 'scheduled_date']);

        $comparison = [];
        foreach ($blocked as $row) {
            if (isset($treated[$row->customer_id])) {
                continue;
            }
            $this->addToCohort($comparison, $row->customer_id, Carbon::parse($row->scheduled_date)->startOfDay(), $row->transaction_id);
        }

        return [
            'return_days' => $days,
            'treated'     => $this->returnRate($treated, $days),
            'comparison'  => $this->returnRate($comparison, $days),
            'caveat'      => 'Korelasional, bukan kausal. Pembanding (profil belum lengkap) memiliki bias seleksi.',
        ];
    }

    /**
     * Sebelum-sesudah: dua jendela sama panjang di sekitar go_live_date.
     * before = [go_live - N, go_live - 1]; after = [go_live, go_live + N - 1].
     *
     * @return array|null null bila go_live_date tidak tersedia
     */
    public function beforeAfter(?string $goLiveDate, int $windowDays, string $segment = self::SEGMENT_ALL): ?array
    {
        if (empty($goLiveDate) || $windowDays < 1) {
            return null;
        }

        $goLive = Carbon::parse($goLiveDate)->startOfDay();
        $beforeFrom = $goLive->copy()->subDays($windowDays)->toDateString();
        $beforeTo = $goLive->copy()->subDay()->toDateString();
        $afterFrom = $goLive->toDateString();
        $afterToFull = $goLive->copy()->addDays($windowDays - 1);
        $afterTo = $afterToFull->toDateString();

        $beforeTx = $this->baseTransactions($beforeFrom, $beforeTo, $segment)->count();
        $afterTx = $this->baseTransactions($afterFrom, $afterTo, $segment)->count();

        return [
            'go_live_date'          => $goLive->toDateString(),
            'window_days'           => $windowDays,
            'segment'               => $segment,
            'before'                => ['from' => $beforeFrom, 'to' => $beforeTo, 'transactions' => $beforeTx]
                                       + $this->purchaseMetrics($beforeFrom, $beforeTo, $segment),
            'after'                 => ['from' => $afterFrom, 'to' => $afterTo, 'transactions' => $afterTx]
                                       + $this->purchaseMetrics($afterFrom, $afterTo, $segment),
            'baseline_available'    => $beforeTx > 0,
            'after_window_complete' => $afterToFull->lte(Carbon::today()),
        ];
    }

    // ─── Internal ───────────────────────────────────────────────────

    private function baseTransactions(string $from, string $to, string $segment): Builder
    {
        $q = DB::table('transactions as t')
            ->join('customers as c', 'c.id', '=', 't.customer_id')
            ->whereNull('c.deleted_at')
            ->whereBetween('t.transaction_date', [$from, $to]);

        if ($segment === self::SEGMENT_BPJS) {
            $q->where('t.is_bpjs', 1);
        } elseif ($segment === self::SEGMENT_NON_BPJS) {
            $q->where(function ($w) {
                $w->where('t.is_bpjs', 0)->orWhereNull('t.is_bpjs');
            });
        }

        return $q;
    }

    /**
     * @param array<int, array{ref: Carbon, origin: array<int, int>}> $cohort
     */
    private function addToCohort(array &$cohort, int $customerId, Carbon $ref, int $originTransactionId): void
    {
        if (!isset($cohort[$customerId])) {
            $cohort[$customerId] = ['ref' => $ref, 'origin' => []];
        } elseif ($ref->lt($cohort[$customerId]['ref'])) {
            $cohort[$customerId]['ref'] = $ref;
        }
        $cohort[$customerId]['origin'][] = $originTransactionId;
    }

    /**
     * @param array<int, array{ref: Carbon, origin: array<int, int>}> $cohort customer_id => info
     */
    private function returnRate(array $cohort, int $days): array
    {
        $today = Carbon::today();
        $mature = [];
        $immature = 0;

        foreach ($cohort as $customerId => $info) {
            if ($info['ref']->copy()->addDays($days)->gt($today)) {
                $immature++;
                continue;
            }
            $mature[$customerId] = $info;
        }

        $returned = 0;
        foreach (array_chunk(array_keys($mature), 500) as $chunk) {
            $minRef = collect($chunk)->map(fn ($id) => $mature[$id]['ref'])->min();

            $byCustomer = Transaction::query()
                ->whereIn('customer_id', $chunk)
                ->where('transaction_date', '>', $minRef->toDateString())
                ->get(['id', 'customer_id', 'transaction_date'])
                ->groupBy('customer_id');

            foreach ($chunk as $customerId) {
                $ref = $mature[$customerId]['ref']->toDateString();
                $limit = $mature[$customerId]['ref']->copy()->addDays($days)->toDateString();
                $origin = $mature[$customerId]['origin'];

                $hit = ($byCustomer[$customerId] ?? collect())->first(function ($trx) use ($ref, $limit, $origin) {
                    $date = Carbon::parse($trx->transaction_date)->toDateString();
                    return $date > $ref && $date <= $limit && !in_array($trx->id, $origin, true);
                });

                if ($hit) {
                    $returned++;
                }
            }
        }

        return [
            'cohort_size'       => count($mature),
            'returned'          => $returned,
            'return_rate'       => $this->pct($returned, count($mature)),
            'immature_excluded' => $immature,
        ];
    }

    private function pct(int $num, int $den): ?float
    {
        // null (bukan 0) bila penyebut 0: "tidak ada data" berbeda dari "0%".
        return $den > 0 ? round($num / $den * 100, 1) : null;
    }
}
