<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use App\Models\TransactionPrescription;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email'    => 'admin@optikcrm.com',
            'password' => bcrypt('password123'),
        ]);
    }

    public function test_dashboard_stats_requires_authentication(): void
    {
        $response = $this->getJson('/api/dashboard/stats');
        $response->assertStatus(401);
    }

    public function test_dashboard_stats_returns_expected_structure(): void
    {
        // Setup data
        $customer1 = Customer::create([
            'nama'        => 'Ahmad Budi',
            'no_hp'       => '081234567890',
            'jenis_lensa' => 'Single Vision',
            'ukuran_kiri' => '-1.50',
            'ukuran_kanan'=> '-2.00',
        ]);

        $customer2 = Customer::create([
            'nama'        => 'Siti Aminah',
            'no_hp'       => null, // Incomplete profile
            'jenis_lensa' => 'Bluechromic',
            'ukuran_kiri' => '-4.00',
            'ukuran_kanan'=> '-3.50',
        ]);

        $trx1 = Transaction::create([
            'customer_id'      => $customer1->id,
            'invoice_number'   => 'INV-2026-001',
            'amount'           => 350000,
            'status'           => 'done',
            'transaction_date' => Carbon::now()->toDateString(),
        ]);

        TransactionPrescription::create([
            'transaction_id' => $trx1->id,
            'eye_side'       => 'OD',
            'sphere'         => -2.00,
            'cylinder'       => -0.50,
            'axis'           => 90,
            'lens_type'      => 'Single Vision',
        ]);

        TransactionPrescription::create([
            'transaction_id' => $trx1->id,
            'eye_side'       => 'OS',
            'sphere'         => -1.50,
            'cylinder'       => 0,
            'axis'           => 0,
            'lens_type'      => 'Single Vision',
        ]);

        FollowUpSchedule::create([
            'customer_id'    => $customer1->id,
            'transaction_id' => $trx1->id,
            'type'           => FollowUpSchedule::TYPE_H_PLUS_3,
            'scheduled_date' => Carbon::now()->toDateString(),
            'status'         => FollowUpSchedule::STATUS_SENT,
            'sent_at'        => Carbon::now(),
        ]);

        FollowUpSchedule::create([
            'customer_id'    => $customer2->id,
            'transaction_id' => $trx1->id,
            'type'           => FollowUpSchedule::TYPE_H_PLUS_330,
            'scheduled_date' => Carbon::now()->toDateString(),
            'status'         => FollowUpSchedule::STATUS_BLOCKED,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'date_range' => ['from', 'to', 'prev_from', 'prev_to'],
                'kpi' => [
                    'total_customers',
                    'new_customers_period',
                    'new_customers_prev',
                    'revenue',
                    'revenue_prev',
                    'transactions_count',
                    'transactions_count_prev',
                    'followup_sent',
                    'followup_total',
                    'delivery_rate',
                    'complete_profiles_count',
                    'incomplete_profiles_count',
                    'reachability_rate',
                ],
                'revenue_trend',
                'followup_distribution',
                'customer_growth',
                'lens_distribution',
                'refraction_distribution',
            ]);

        $this->assertEquals(2, $response->json('kpi.total_customers'));
        $this->assertEquals(1, $response->json('kpi.complete_profiles_count'));
        $this->assertEquals(1, $response->json('kpi.incomplete_profiles_count'));
        $this->assertEquals(50.0, $response->json('kpi.reachability_rate'));
        $this->assertEquals(350000, $response->json('kpi.revenue'));
        $this->assertEquals(1, $response->json('kpi.followup_sent'));
        $this->assertEquals(2, $response->json('kpi.followup_total'));
        $this->assertEquals(50.0, $response->json('kpi.delivery_rate'));
    }

    public function test_customer_search_by_date(): void
    {
        $today = Carbon::now()->toDateString();
        $yesterday = Carbon::yesterday()->toDateString();

        $customer = Customer::create([
            'nama'  => 'Rudi Hartono',
            'no_hp' => '081299998888',
        ]);

        $trx = Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => 'INV-2026-TODAY',
            'amount'           => 250000,
            'status'           => 'done',
            'transaction_date' => $today,
        ]);

        // Search for today
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/customers/search?date={$today}");

        $response->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('customers.0.nama', 'Rudi Hartono');

        // Search for yesterday (should be 0)
        $responseYesterday = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/customers/search?date={$yesterday}");

        $responseYesterday->assertStatus(200)
            ->assertJsonPath('total', 0);
    }

    public function test_dashboard_export_csv(): void
    {
        $today = Carbon::now()->toDateString();

        $customer = Customer::create([
            'nama'  => 'Dewi Lestari',
            'no_hp' => '081377776666',
        ]);

        Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => 'INV-EXPORT-001',
            'amount'           => 500000,
            'status'           => 'done',
            'transaction_date' => $today,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->get("/api/dashboard/export?from={$today}&to={$today}");

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('Dewi Lestari', $response->streamedContent());
        $this->assertStringContainsString('INV-EXPORT-001', $response->streamedContent());
    }
}
