<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\FollowUpSchedule;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FonnteWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'rahasia-test-webhook';
    private const URL = '/api/webhooks/fonnte/status';

    private function sentSchedule(string $providerId = '98765'): FollowUpSchedule
    {
        $customer = Customer::factory()->create();
        $transaction = Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => 'INV-WH-' . uniqid(),
            'amount'           => 100000,
            'status'           => 'done',
            'transaction_date' => now()->toDateString(),
        ]);

        return FollowUpSchedule::create([
            'customer_id'         => $customer->id,
            'transaction_id'      => $transaction->id,
            'type'                => FollowUpSchedule::TYPE_H_PLUS_3,
            'scheduled_date'      => now()->toDateString(),
            'status'              => FollowUpSchedule::STATUS_SENT,
            'sent_at'             => now(),
            'provider_message_id' => $providerId,
            'provider_status'     => 'pending',
        ]);
    }

    public function test_webhook_is_disabled_when_no_secret_configured(): void
    {
        config(['services.fonnte.webhook_secret' => '']);

        $this->postJson(self::URL . '?secret=', ['id' => '1'])->assertStatus(503);
    }

    public function test_webhook_rejects_wrong_or_missing_secret(): void
    {
        config(['services.fonnte.webhook_secret' => self::SECRET]);
        $schedule = $this->sentSchedule();

        $this->postJson(self::URL, ['id' => '98765', 'status' => 'sent'])->assertStatus(401);
        $this->postJson(self::URL . '?secret=salah', ['id' => '98765', 'status' => 'sent'])->assertStatus(401);

        $this->assertSame('pending', $schedule->fresh()->provider_status);
    }

    public function test_webhook_updates_status_and_marks_delivered_via_query_secret(): void
    {
        config(['services.fonnte.webhook_secret' => self::SECRET]);
        $schedule = $this->sentSchedule();

        $this->postJson(self::URL . '?secret=' . self::SECRET, [
            'device'  => '62800000000',
            'id'      => 98765,
            'stateid' => 'abc123',
            'status'  => 'sent',
            'state'   => 'delivered',
        ])->assertStatus(200)->assertJson(['success' => true, 'matched' => true]);

        $schedule->refresh();
        $this->assertSame('sent', $schedule->provider_status);
        $this->assertSame('delivered', $schedule->provider_state);
        $this->assertSame('abc123', $schedule->provider_stateid);
        $this->assertNotNull($schedule->delivered_at);
        $this->assertSame(FollowUpSchedule::STATUS_SENT, $schedule->status);
    }

    public function test_webhook_accepts_secret_in_header_and_state_only_update(): void
    {
        config(['services.fonnte.webhook_secret' => self::SECRET]);
        $schedule = $this->sentSchedule();
        $schedule->update(['provider_stateid' => 'abc123']);

        $this->postJson(self::URL, ['stateid' => 'abc123', 'state' => 'read'], ['X-Webhook-Secret' => self::SECRET])
            ->assertStatus(200)->assertJson(['matched' => true]);

        $schedule->refresh();
        $this->assertSame('read', $schedule->provider_state);
        $this->assertNotNull($schedule->delivered_at);
    }

    public function test_invalid_status_downgrades_sent_schedule_to_failed(): void
    {
        config(['services.fonnte.webhook_secret' => self::SECRET]);
        $schedule = $this->sentSchedule();

        $this->postJson(self::URL . '?secret=' . self::SECRET, ['id' => '98765', 'status' => 'invalid'])
            ->assertStatus(200);

        $schedule->refresh();
        $this->assertSame(FollowUpSchedule::STATUS_FAILED, $schedule->status);
        $this->assertSame('invalid', $schedule->provider_status);
        $this->assertNull($schedule->delivered_at);
        $this->assertStringContainsString('invalid', $schedule->notes);
    }

    public function test_unknown_message_id_is_ignored_with_200(): void
    {
        config(['services.fonnte.webhook_secret' => self::SECRET]);

        $this->postJson(self::URL . '?secret=' . self::SECRET, ['id' => 'tidak-ada', 'status' => 'sent'])
            ->assertStatus(200)->assertJson(['success' => true, 'matched' => false]);
    }

    public function test_followup_command_stores_provider_id_and_raw_process_status_in_live_mode(): void
    {
        config([
            'services.fonnte.mode'      => 'live',
            'services.fonnte.api_token' => 'token-dummy-test',
            'services.fonnte.api_url'   => 'https://fonnte.test/send',
        ]);
        // FonnteService membaca config di constructor: paksa instance baru
        $this->app->forgetInstance(\App\Services\Contracts\WhatsAppServiceInterface::class);

        Http::fake([
            'fonnte.test/*' => Http::response([
                'detail'  => 'success! message in queue',
                'id'      => ['55501'],
                'process' => 'pending',
                'status'  => true,
                'target'  => ['6281200000001'],
            ], 200),
        ]);

        $customer = Customer::factory()->create(['no_hp' => '081200000001']);
        $transaction = Transaction::create([
            'customer_id'      => $customer->id,
            'invoice_number'   => 'INV-LIVE-1',
            'amount'           => 100000,
            'status'           => 'done',
            'transaction_date' => now()->subDays(3)->toDateString(),
        ]);
        $schedule = FollowUpSchedule::create([
            'customer_id'    => $customer->id,
            'transaction_id' => $transaction->id,
            'type'           => FollowUpSchedule::TYPE_H_PLUS_3,
            'scheduled_date' => now()->subDay()->toDateString(), // sqlite membandingkan string; hindari "hari ini" persis
            'status'         => FollowUpSchedule::STATUS_PENDING,
        ]);

        $this->artisan('crm:send-followups')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame(FollowUpSchedule::STATUS_SENT, $schedule->status);
        $this->assertSame('55501', $schedule->provider_message_id);
        // Antrean, bukan terkirim:
        $this->assertSame('pending', $schedule->provider_status);
        $this->assertNull($schedule->delivered_at);
    }

    public function test_rejected_by_fonnte_is_marked_failed_with_reason(): void
    {
        config([
            'services.fonnte.mode'      => 'live',
            'services.fonnte.api_token' => 'token-dummy-test',
            'services.fonnte.api_url'   => 'https://fonnte.test/send',
        ]);
        $this->app->forgetInstance(\App\Services\Contracts\WhatsAppServiceInterface::class);

        Http::fake(['fonnte.test/*' => Http::response(['status' => false, 'reason' => 'device disconnected'], 200)]);

        $customer = Customer::factory()->create(['no_hp' => '081200000002']);
        $transaction = Transaction::create([
            'customer_id' => $customer->id, 'invoice_number' => 'INV-LIVE-2', 'amount' => 1,
            'status' => 'done', 'transaction_date' => now()->subDays(3)->toDateString(),
        ]);
        $schedule = FollowUpSchedule::create([
            'customer_id' => $customer->id, 'transaction_id' => $transaction->id,
            'type' => FollowUpSchedule::TYPE_H_PLUS_3, 'scheduled_date' => now()->subDay()->toDateString(),
            'status' => FollowUpSchedule::STATUS_PENDING,
        ]);

        $this->artisan('crm:send-followups')->assertExitCode(0);

        $schedule->refresh();
        $this->assertSame(FollowUpSchedule::STATUS_FAILED, $schedule->status);
        $this->assertStringContainsString('device disconnected', $schedule->notes);
    }
}
