<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_transaction_with_complete_profile_creates_pending_schedules(): void
    {
        $customer = Customer::create([
            'nama'  => 'Budi Santoso',
            'no_hp' => '081234567890',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/transactions', [
                             'customer_id'      => $customer->id,
                             'amount'           => 500000,
                             'transaction_date' => '2026-08-19',
                             'prescriptions'    => [
                                 [
                                     'eye_side'  => 'OD',
                                     'sphere'    => -2.50,
                                     'cylinder'  => -0.75,
                                     'axis'      => 90,
                                     'lens_type' => 'Single Vision',
                                 ],
                             ],
                         ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true);

        $schedules = FollowUpSchedule::where('customer_id', $customer->id)->get();
        $this->assertCount(2, $schedules);

        $this->assertEquals(
            FollowUpSchedule::STATUS_PENDING,
            $schedules->where('type', 'h_plus_3')->first()->status
        );
        $this->assertEquals(
            FollowUpSchedule::STATUS_PENDING,
            $schedules->where('type', 'h_plus_330')->first()->status
        );

        $this->assertEquals(
            '2026-08-22',
            $schedules->where('type', 'h_plus_3')->first()->scheduled_date->toDateString()
        );
    }

    public function test_transaction_with_incomplete_profile_creates_blocked_schedules(): void
    {
        $customer = Customer::create([
            'nama'  => 'Siti Aminah',
            'no_hp' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->postJson('/api/transactions', [
                             'customer_id'      => $customer->id,
                             'amount'           => 350000,
                             'transaction_date' => '2026-08-19',
                             'prescriptions'    => [
                                 [
                                     'eye_side'  => 'OS',
                                     'sphere'    => -1.00,
                                     'lens_type' => 'Bifocal',
                                 ],
                             ],
                         ]);

        $response->assertStatus(201);

        $schedules = FollowUpSchedule::where('customer_id', $customer->id)->get();
        $this->assertCount(2, $schedules);

        foreach ($schedules as $schedule) {
            $this->assertEquals(FollowUpSchedule::STATUS_BLOCKED, $schedule->status);
        }
    }

    public function test_complete_profile_unblocks_follow_up_schedules(): void
    {
        $customer = Customer::create([
            'nama'  => 'Ahmad Fauzi',
            'no_hp' => null,
        ]);

        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/transactions', [
                 'customer_id'      => $customer->id,
                 'amount'           => 200000,
                 'transaction_date' => '2026-08-19',
                 'prescriptions'    => [
                     ['eye_side' => 'OD', 'sphere' => -1.50, 'lens_type' => 'Single Vision'],
                 ],
             ]);

        $this->assertEquals(2, FollowUpSchedule::blockedForCustomer($customer->id)->count());

        $response = $this->actingAs($this->user, 'sanctum')
                         ->patchJson("/api/customers/{$customer->id}/complete-profile", [
                             'no_hp' => '081298765432',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        $this->assertEquals(0, FollowUpSchedule::blockedForCustomer($customer->id)->count());
        $this->assertEquals(
            2,
            FollowUpSchedule::where('customer_id', $customer->id)
                ->where('status', FollowUpSchedule::STATUS_PENDING)
                ->count()
        );
    }

    public function test_complete_profile_rejects_invalid_phone(): void
    {
        $customer = Customer::create([
            'nama'  => 'Invalid Phone Test',
            'no_hp' => null,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
                         ->patchJson("/api/customers/{$customer->id}/complete-profile", [
                             'no_hp' => '12345',
                         ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);
    }

    public function test_send_followups_command_processes_due_schedules(): void
    {
        config(['services.fonnte.mode' => 'dry_run']);

        $customer = Customer::create([
            'nama'  => 'Dewi Lestari',
            'no_hp' => '081377889900',
        ]);

        $this->actingAs($this->user, 'sanctum')
             ->postJson('/api/transactions', [
                 'customer_id'      => $customer->id,
                 'amount'           => 450000,
                 'transaction_date' => now()->subDays(4)->toDateString(),
                 'prescriptions'    => [
                     ['eye_side' => 'OD', 'sphere' => -3.00, 'lens_type' => 'Progressive'],
                 ],
             ]);

        $dueCount = FollowUpSchedule::dueToday()->count();
        $this->assertGreaterThanOrEqual(1, $dueCount);

        $this->artisan('crm:send-followups')
             ->assertSuccessful();

        $h3Schedule = FollowUpSchedule::where('customer_id', $customer->id)
            ->where('type', 'h_plus_3')
            ->first();
        $this->assertEquals(FollowUpSchedule::STATUS_SENT, $h3Schedule->status);
        $this->assertNotNull($h3Schedule->sent_at);
    }

    public function test_is_profile_complete_with_valid_phone(): void
    {
        $customer = Customer::create(['nama' => 'Test', 'no_hp' => '081234567890']);
        $this->assertTrue($customer->isProfileComplete());
    }

    public function test_is_profile_complete_with_null_phone(): void
    {
        $customer = Customer::create(['nama' => 'Test', 'no_hp' => null]);
        $this->assertFalse($customer->isProfileComplete());
    }

    public function test_is_profile_complete_with_bpjs_dummy(): void
    {
        $customer = Customer::create(['nama' => 'Test', 'no_hp' => 'BPJS-abc123']);
        $this->assertFalse($customer->isProfileComplete());
    }

    public function test_is_profile_complete_with_invalid_format(): void
    {
        $customer = Customer::create(['nama' => 'Test', 'no_hp' => '12345']);
        $this->assertFalse($customer->isProfileComplete());
    }
}
