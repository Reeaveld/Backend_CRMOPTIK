<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        Sanctum::actingAs($this->admin);
    }

    private function makeSchedule(Customer $customer, string $status, string $type = FollowUpSchedule::TYPE_H_PLUS_3): FollowUpSchedule
    {
        $transaction = Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => 'INV-' . uniqid(),
            'transaction_date' => now()->toDateString(),
            'amount'           => 200000,
            'is_bpjs'          => 0,
            'status'           => 'done',
        ]);

        return FollowUpSchedule::create([
            'customer_id'    => $customer->id,
            'transaction_id' => $transaction->id,
            'type'           => $type,
            'scheduled_date' => now()->addDays(3)->toDateString(),
            'status'         => $status,
        ]);
    }

    public function test_get_customers_returns_list(): void
    {
        Customer::factory()->count(3)->create();

        $this->getJson('/api/customers')
            ->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function test_show_customer_returns_record_or_404(): void
    {
        $customer = Customer::factory()->create();

        $this->getJson('/api/customers/' . $customer->id)
            ->assertStatus(200)
            ->assertJsonPath('id', $customer->id);

        $this->getJson('/api/customers/99999')->assertStatus(404);
    }

    public function test_delete_customer_soft_deletes_and_cancels_unsent_schedules(): void
    {
        $customer = Customer::factory()->create();
        $pending = $this->makeSchedule($customer, FollowUpSchedule::STATUS_PENDING);
        $blocked = $this->makeSchedule($customer, FollowUpSchedule::STATUS_BLOCKED);
        $sent = $this->makeSchedule($customer, FollowUpSchedule::STATUS_SENT);

        $this->deleteJson('/api/customers/' . $customer->id)
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'Customer berhasil dihapus']);

        // Soft delete: baris tetap ada (histori dipertahankan) namun tidak muncul di API
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
        $this->getJson('/api/customers')->assertJsonCount(0);

        // Jadwal belum terkirim => cancelled (bukan failed agar tidak mencemari metrik)
        $this->assertSame(FollowUpSchedule::STATUS_CANCELLED, $pending->fresh()->status);
        $this->assertSame(FollowUpSchedule::STATUS_CANCELLED, $blocked->fresh()->status);
        $this->assertStringContainsString('Dibatalkan', $pending->fresh()->notes);

        // Jadwal yang sudah terkirim tidak diubah
        $this->assertSame(FollowUpSchedule::STATUS_SENT, $sent->fresh()->status);
    }

    public function test_cancelled_schedules_are_never_sent_by_the_blast_command(): void
    {
        $customer = Customer::factory()->create();
        $schedule = $this->makeSchedule($customer, FollowUpSchedule::STATUS_PENDING);
        $schedule->update(['scheduled_date' => now()->subDay()->toDateString()]);

        $this->deleteJson('/api/customers/' . $customer->id)->assertStatus(200);

        $this->artisan('crm:send-followups')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame(FollowUpSchedule::STATUS_CANCELLED, $schedule->status);
        $this->assertNull($schedule->sent_at);
    }

    public function test_delete_non_existent_customer_returns_404(): void
    {
        $this->deleteJson('/api/customers/99999')
            ->assertStatus(404)
            ->assertJson(['success' => false, 'message' => 'Customer tidak ditemukan']);
    }
}
