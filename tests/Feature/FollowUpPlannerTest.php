<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use App\Services\FollowUpPlanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci jenis dan interval follow-up resmi saat ini (h_plus_3 dan h_plus_330).
 * Mengubah test ini berarti mengubah perilaku ke pelanggan: butuh keputusan K2.
 */
class FollowUpPlannerTest extends TestCase
{
    use RefreshDatabase;

    private function transaction(bool $bpjs): Transaction
    {
        $customer = Customer::factory()->create();

        return Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => $bpjs ? '0115O006L2512000001' : 'INV-PLAN-1',
            'amount'           => 100000,
            'is_bpjs'          => $bpjs ? 1 : 0,
            'status'           => 'done',
            'transaction_date' => '2026-01-10',
        ]);
    }

    public function test_default_config_has_exactly_the_two_approved_types(): void
    {
        $this->assertSame(
            ['h_plus_3' => 3, 'h_plus_330' => 330],
            collect(config('followup.types'))->map(fn ($r) => $r['days'])->all()
        );
    }

    public function test_creates_h_plus_3_and_h_plus_330_for_bpjs_and_non_bpjs(): void
    {
        foreach ([false, true] as $bpjs) {
            $trx = $this->transaction($bpjs);
            $created = app(FollowUpPlanner::class)->createFor($trx, Carbon::parse('2026-01-10'), FollowUpSchedule::STATUS_PENDING);

            $this->assertSame(2, $created);

            $rows = FollowUpSchedule::where('transaction_id', $trx->id)->orderBy('scheduled_date')->get();
            $this->assertSame(['h_plus_3', 'h_plus_330'], $rows->pluck('type')->all());
            $this->assertSame('2026-01-13', $rows[0]->scheduled_date->toDateString());
            $this->assertSame('2026-12-06', $rows[1]->scheduled_date->toDateString()); // +330 hari
        }
    }

    public function test_applies_to_filter_is_respected_when_a_type_is_configured_for_bpjs_only(): void
    {
        config(['followup.types' => [
            'h_plus_3'   => ['days' => 3, 'applies_to' => 'all'],
            'h_plus_330' => ['days' => 330, 'applies_to' => 'bpjs'],
        ]]);

        $nonBpjs = $this->transaction(false);
        $bpjs = $this->transaction(true);
        $planner = app(FollowUpPlanner::class);

        $this->assertSame(1, $planner->createFor($nonBpjs, Carbon::parse('2026-01-10'), FollowUpSchedule::STATUS_PENDING));
        $this->assertSame(2, $planner->createFor($bpjs, Carbon::parse('2026-01-10'), FollowUpSchedule::STATUS_PENDING));
    }
}
