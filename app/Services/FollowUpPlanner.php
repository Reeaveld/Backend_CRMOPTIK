<?php

namespace App\Services;

use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use Carbon\Carbon;

/**
 * Membuat jadwal follow-up untuk sebuah transaksi berdasarkan config/followup.php.
 * Satu-satunya tempat yang menerjemahkan "jenis follow-up" menjadi baris jadwal.
 */
class FollowUpPlanner
{
    /**
     * @param array<string, string> $notesByType catatan opsional per jenis, mis. ['h_plus_3' => '...']
     * @return int jumlah jadwal yang dibuat
     */
    public function createFor(Transaction $transaction, Carbon $transactionDate, string $status, array $notesByType = []): int
    {
        $created = 0;

        foreach (config('followup.types', []) as $type => $rule) {
            if (!$this->applies($rule['applies_to'] ?? 'all', (bool) $transaction->is_bpjs)) {
                continue;
            }

            FollowUpSchedule::create([
                'customer_id'    => $transaction->customer_id,
                'transaction_id' => $transaction->id,
                'type'           => $type,
                'scheduled_date' => $transactionDate->copy()->addDays((int) $rule['days']),
                'status'         => $status,
                'notes'          => $notesByType[$type] ?? null,
            ]);
            $created++;
        }

        return $created;
    }

    private function applies(string $appliesTo, bool $isBpjs): bool
    {
        return match ($appliesTo) {
            'bpjs'     => $isBpjs,
            'non_bpjs' => !$isBpjs,
            default    => true,
        };
    }
}
