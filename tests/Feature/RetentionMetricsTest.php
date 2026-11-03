<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RetentionMetrics;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test rumus metrik RM3 dengan dataset terkontrol. Hari ini dibekukan ke 2026-11-03.
 * Definisi formal: docs/METRICS.md.
 */
class RetentionMetricsTest extends TestCase
{
    use RefreshDatabase;

    private RetentionMetrics $metrics;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-03 10:00:00'));
        $this->metrics = new RetentionMetrics();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function customer(array $attrs = []): Customer
    {
        return Customer::factory()->create($attrs);
    }

    private function tx(Customer $c, string $date, bool $bpjs = false): Transaction
    {
        return Transaction::create([
            'customer_id'      => $c->id,
            'invoice_number'   => 'INV-' . (++$this->seq),
            'amount'           => 100000,
            'is_bpjs'          => $bpjs ? 1 : 0,
            'status'           => 'done',
            'transaction_date' => $date,
        ]);
    }

    private function schedule(Customer $c, string $status, string $scheduled, array $extra = [], ?Transaction $origin = null): FollowUpSchedule
    {
        $origin ??= $this->tx($c, Carbon::parse($scheduled)->subDays(3)->toDateString());

        return FollowUpSchedule::create(array_merge([
            'customer_id'    => $c->id,
            'transaction_id' => $origin->id,
            'type'           => FollowUpSchedule::TYPE_H_PLUS_3,
            'scheduled_date' => $scheduled,
            'status'         => $status,
        ], $extra));
    }

    // ─── RPR dan RCR ────────────────────────────────────────────────

    public function test_repeat_purchase_and_returning_customer_rates_with_segments(): void
    {
        $a = $this->customer(); // repeat + returning (non-BPJS)
        $this->tx($a, '2026-01-10');
        $this->tx($a, '2026-10-05');
        $this->tx($a, '2026-10-20');

        $b = $this->customer(); // pelanggan baru, satu transaksi
        $this->tx($b, '2026-10-10');

        $c = $this->customer(); // BPJS returning
        $this->tx($c, '2024-10-12', true);
        $this->tx($c, '2026-10-12', true);

        $d = $this->customer(); // tidak aktif dalam W
        $this->tx($d, '2026-09-01');

        $e = $this->customer(); // soft-deleted: diabaikan
        $this->tx($e, '2026-10-01');
        $this->tx($e, '2026-10-02');
        $e->delete();

        $all = $this->metrics->purchaseMetrics('2026-10-01', '2026-10-31');
        $this->assertSame(3, $all['active_customers']);
        $this->assertSame(1, $all['repeat_customers']);
        $this->assertSame(33.3, $all['repeat_purchase_rate']);
        $this->assertSame(2, $all['returning_customers']);
        $this->assertSame(66.7, $all['returning_customer_rate']);

        $bpjs = $this->metrics->purchaseMetrics('2026-10-01', '2026-10-31', RetentionMetrics::SEGMENT_BPJS);
        $this->assertSame(1, $bpjs['active_customers']);
        $this->assertSame(0.0, $bpjs['repeat_purchase_rate']);
        $this->assertSame(100.0, $bpjs['returning_customer_rate']);

        $non = $this->metrics->purchaseMetrics('2026-10-01', '2026-10-31', RetentionMetrics::SEGMENT_NON_BPJS);
        $this->assertSame(2, $non['active_customers']);
        $this->assertSame(50.0, $non['repeat_purchase_rate']);
        $this->assertSame(50.0, $non['returning_customer_rate']);
    }

    public function test_rates_are_null_not_zero_when_no_active_customers(): void
    {
        $m = $this->metrics->purchaseMetrics('2026-10-01', '2026-10-31');

        $this->assertSame(0, $m['active_customers']);
        $this->assertNull($m['repeat_purchase_rate']);
        $this->assertNull($m['returning_customer_rate']);
    }

    // ─── queued_rate ────────────────────────────────────────────────

    public function test_queued_rate_uses_only_sent_and_failed_due_schedules(): void
    {
        $c = $this->customer();
        $this->schedule($c, FollowUpSchedule::STATUS_SENT, '2026-10-05', ['provider_message_id' => '111', 'delivered_at' => '2026-10-05 10:00:00']);
        $this->schedule($c, FollowUpSchedule::STATUS_SENT, '2026-10-06', ['provider_message_id' => '222']);
        $this->schedule($c, FollowUpSchedule::STATUS_SENT, '2026-10-07', ['provider_message_id' => 'dry_run_abc']);
        $this->schedule($c, FollowUpSchedule::STATUS_FAILED, '2026-10-08');
        $this->schedule($c, FollowUpSchedule::STATUS_PENDING, '2026-10-09');
        $this->schedule($c, FollowUpSchedule::STATUS_BLOCKED, '2026-10-10');
        $this->schedule($c, FollowUpSchedule::STATUS_CANCELLED, '2026-10-11');

        config(['services.fonnte.webhook_secret' => '']);
        $q = $this->metrics->queueMetrics('2026-10-01', '2026-10-31');

        $this->assertSame(2, $q['sent']);
        $this->assertSame(1, $q['sent_simulated']);
        $this->assertSame(1, $q['failed']);
        $this->assertSame(1, $q['pending']);
        $this->assertSame(1, $q['blocked']);
        $this->assertSame(1, $q['cancelled']);
        $this->assertSame(66.7, $q['queued_rate']); // 2 / (2 + 1)
        $this->assertFalse($q['delivery_tracking']);
        $this->assertNull($q['delivered_rate']);

        config(['services.fonnte.webhook_secret' => 'rahasia']);
        $q = $this->metrics->queueMetrics('2026-10-01', '2026-10-31');
        $this->assertTrue($q['delivery_tracking']);
        $this->assertSame(1, $q['delivered']);
        $this->assertSame(50.0, $q['delivered_rate']); // 1 delivered / 2 sent nyata
    }

    public function test_queued_rate_is_null_when_nothing_is_due(): void
    {
        $c = $this->customer();
        $this->schedule($c, FollowUpSchedule::STATUS_PENDING, '2026-10-09');

        $this->assertNull($this->metrics->queueMetrics('2026-10-01', '2026-10-31')['queued_rate']);
    }

    // ─── Follow-up Return Rate ──────────────────────────────────────

    public function test_followup_return_rate_with_comparison_group_and_maturity(): void
    {
        $sent = function (string $sentAt, ?string $originDate = null, array $extra = []) {
            $c = $this->customer();
            $origin = $this->tx($c, $originDate ?? Carbon::parse($sentAt)->subDays(3)->toDateString());
            $s = $this->schedule($c, FollowUpSchedule::STATUS_SENT, Carbon::parse($sentAt)->toDateString(), array_merge([
                'sent_at'             => $sentAt . ' 09:00:00',
                'provider_message_id' => (string) random_int(1000, 999999),
            ], $extra), $origin);
            return [$c, $origin, $s];
        };

        [$t1] = $sent('2026-09-10');
        $this->tx($t1, '2026-09-25');               // kembali dalam 30 hari

        $sent('2026-09-10');                         // t2: tidak kembali

        [$t3] = $sent('2026-09-12');
        $this->tx($t3, '2026-11-01');               // terlalu lambat (> ref + 30)

        [$t4] = $sent('2026-09-30');
        $this->tx($t4, '2026-10-15');               // kembali

        $sent('2026-09-10', null, ['provider_message_id' => 'dry_run_xyz']); // simulasi: dikecualikan

        $sent('2026-10-25');                         // belum matang: ref + 30 > hari ini

        // t8: transaksi asal bertanggal setelah sent_at (anomali data) tidak boleh dihitung kembali
        $sent('2026-09-10', '2026-09-15');

        // Pembanding: jadwal blocked (profil belum lengkap)
        $b1 = $this->customer(['no_hp' => null]);
        $o1 = $this->tx($b1, '2026-09-05');
        $this->schedule($b1, FollowUpSchedule::STATUS_BLOCKED, '2026-09-08', [], $o1);
        $this->tx($b1, '2026-09-20');                // kembali

        $b2 = $this->customer(['no_hp' => null]);
        $this->schedule($b2, FollowUpSchedule::STATUS_BLOCKED, '2026-09-08');

        $r = $this->metrics->followUpReturn('2026-09-01', '2026-10-31', 30);

        $this->assertSame(5, $r['treated']['cohort_size']);   // t1,t2,t3,t4,t8
        $this->assertSame(2, $r['treated']['returned']);      // t1,t4
        $this->assertSame(40.0, $r['treated']['return_rate']);
        $this->assertSame(1, $r['treated']['immature_excluded']);

        $this->assertSame(2, $r['comparison']['cohort_size']);
        $this->assertSame(1, $r['comparison']['returned']);
        $this->assertSame(50.0, $r['comparison']['return_rate']);
        $this->assertStringContainsString('Korelasional', $r['caveat']);
    }

    // ─── Sebelum-sesudah ────────────────────────────────────────────

    public function test_before_after_windows_around_go_live(): void
    {
        $x = $this->customer();
        $y = $this->customer();
        $z = $this->customer();

        $this->tx($x, '2026-09-10');
        $this->tx($y, '2026-09-11');
        $this->tx($y, '2026-09-12');

        $this->tx($x, '2026-10-05');
        $this->tx($x, '2026-10-06');
        $this->tx($y, '2026-10-07');
        $this->tx($z, '2026-10-08');

        $r = $this->metrics->beforeAfter('2026-10-01', 30);

        $this->assertSame('2026-09-01', $r['before']['from']);
        $this->assertSame('2026-09-30', $r['before']['to']);
        $this->assertSame('2026-10-01', $r['after']['from']);
        $this->assertSame('2026-10-30', $r['after']['to']);

        $this->assertSame(3, $r['before']['transactions']);
        $this->assertSame(2, $r['before']['active_customers']);
        $this->assertSame(50.0, $r['before']['repeat_purchase_rate']);

        $this->assertSame(4, $r['after']['transactions']);
        $this->assertSame(3, $r['after']['active_customers']);
        $this->assertSame(33.3, $r['after']['repeat_purchase_rate']);
        $this->assertSame(66.7, $r['after']['returning_customer_rate']);

        $this->assertTrue($r['baseline_available']);
        $this->assertTrue($r['after_window_complete']);
    }

    public function test_before_after_is_null_without_go_live_and_flags_missing_baseline(): void
    {
        $this->assertNull($this->metrics->beforeAfter(null, 90));

        $x = $this->customer();
        $this->tx($x, '2026-10-05');
        $r = $this->metrics->beforeAfter('2026-10-01', 90);

        $this->assertFalse($r['baseline_available']);
        $this->assertFalse($r['after_window_complete']); // 2026-10-01 + 89 hari > 2026-11-03
        $this->assertNull($r['before']['repeat_purchase_rate']);
    }

    // ─── Endpoint ───────────────────────────────────────────────────

    public function test_retention_endpoint_returns_all_sections_and_matches_service(): void
    {
        Sanctum::actingAs(User::factory()->create());
        config(['crm.go_live_date' => '2026-10-01', 'crm.comparison_window_days' => 30]);

        $a = $this->customer();
        $this->tx($a, '2026-10-05');
        $this->tx($a, '2026-10-20');

        $response = $this->getJson('/api/dashboard/retention?from=2026-10-01&to=2026-10-31&return_days=60');

        $response->assertStatus(200)->assertJsonStructure([
            'date_range' => ['from', 'to'],
            'purchase'   => ['all' => ['active_customers', 'repeat_purchase_rate', 'returning_customer_rate'], 'bpjs', 'non_bpjs'],
            'followup_queue' => ['sent', 'failed', 'pending', 'blocked', 'queued_rate'],
            'followup_return' => ['return_days', 'treated' => ['cohort_size', 'return_rate'], 'comparison', 'caveat'],
            'before_after' => ['all' => ['go_live_date', 'before', 'after'], 'bpjs', 'non_bpjs'],
            'engagement' => ['available', 'reason'],
        ]);

        $expected = $this->metrics->purchaseMetrics('2026-10-01', '2026-10-31');
        $this->assertEquals($expected['repeat_purchase_rate'], $response->json('purchase.all.repeat_purchase_rate'));
        $this->assertSame(60, $response->json('followup_return.return_days'));
        $this->assertFalse($response->json('engagement.available'));
    }

    public function test_retention_endpoint_without_go_live_returns_null_before_after(): void
    {
        Sanctum::actingAs(User::factory()->create());
        config(['crm.go_live_date' => null]);

        $this->getJson('/api/dashboard/retention')
            ->assertStatus(200)
            ->assertJsonPath('before_after', null);
    }

    public function test_retention_endpoint_validates_parameters_and_requires_auth(): void
    {
        $this->getJson('/api/dashboard/retention')->assertStatus(401);

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard/retention?return_days=45')->assertStatus(422);
        $this->getJson('/api/dashboard/retention?from=2026-10-31&to=2026-10-01')->assertStatus(422);
        $this->getJson('/api/dashboard/retention?from=bukan-tanggal')->assertStatus(422);
    }

    public function test_stats_kpi_reports_range_based_rates_matching_service(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $a = $this->customer();
        $this->tx($a, '2026-10-05');
        $this->tx($a, '2026-10-20');
        $b = $this->customer();
        $this->tx($b, '2026-10-10');

        $r = $this->getJson('/api/dashboard/stats?from=2026-10-01&to=2026-10-31');

        $r->assertStatus(200);
        $this->assertSame(2, $r->json('kpi.active_customers_count'));
        $this->assertSame(1, $r->json('kpi.repeat_customers_count'));
        $this->assertEquals(50.0, $r->json('kpi.repeat_purchase_rate'));
    }
}
