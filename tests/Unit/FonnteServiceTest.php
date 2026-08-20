<?php

namespace Tests\Unit;

use App\Services\FonnteService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Unit test untuk FonnteService.
 *
 * Semua test menggunakan mode dry_run — TIDAK ada HTTP request sungguhan.
 * Memverifikasi:
 *   1. Pesan berhasil di-log pada dry_run
 *   2. Normalisasi nomor HP Indonesia
 *   3. Nomor kosong/invalid ditolak
 *   4. Parameter schedule diteruskan dengan benar
 */
class FonnteServiceTest extends TestCase
{
    private FonnteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Force dry_run mode via config
        config(['services.fonnte.mode' => 'dry_run']);
        config(['services.fonnte.api_token' => 'test-token-not-real']);

        $this->service = new FonnteService();
    }

    // ─── Mode Dry Run ───────────────────────────────────────────────

    public function test_dry_run_returns_true_and_logs_message(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return str_contains($message, '[Fonnte Dry-Run]')
                    && $context['to'] === '6281234567890'
                    && str_contains($context['message'], 'Halo pelanggan')
                    && $context['schedule'] === 'immediate';
            });

        $result = $this->service->sendMessage('081234567890', 'Halo pelanggan');

        $this->assertTrue($result);
    }

    public function test_dry_run_with_schedule_timestamp(): void
    {
        $scheduleTime = strtotime('2026-09-01 08:00:00');

        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context) use ($scheduleTime) {
                return str_contains($message, '[Fonnte Dry-Run]')
                    && $context['to'] === '6281234567890'
                    && $context['schedule'] === date('Y-m-d H:i:s', $scheduleTime);
            });

        $result = $this->service->sendMessage('081234567890', 'Test scheduled', $scheduleTime);

        $this->assertTrue($result);
    }

    // ─── Normalisasi Nomor ──────────────────────────────────────────

    public function test_normalize_phone_08_prefix(): void
    {
        $this->assertEquals('6281234567890', $this->service->normalizePhone('081234567890'));
    }

    public function test_normalize_phone_62_prefix(): void
    {
        $this->assertEquals('6281234567890', $this->service->normalizePhone('6281234567890'));
    }

    public function test_normalize_phone_plus62_prefix(): void
    {
        $this->assertEquals('6281234567890', $this->service->normalizePhone('+6281234567890'));
    }

    public function test_normalize_phone_with_spaces_and_dashes(): void
    {
        $this->assertEquals('6281234567890', $this->service->normalizePhone('0812-3456-7890'));
    }

    // ─── Validasi Nomor Invalid ─────────────────────────────────────

    public function test_empty_phone_returns_false(): void
    {
        Log::shouldReceive('warning')->once();

        $result = $this->service->sendMessage('', 'Test');

        $this->assertFalse($result);
    }

    public function test_invalid_phone_returns_false(): void
    {
        Log::shouldReceive('warning')->once();

        $result = $this->service->sendMessage('12345', 'Test');

        $this->assertFalse($result);
    }

    public function test_normalize_phone_returns_empty_for_invalid(): void
    {
        $this->assertEquals('', $this->service->normalizePhone('12345'));
        $this->assertEquals('', $this->service->normalizePhone(''));
        $this->assertEquals('', $this->service->normalizePhone('abc'));
        $this->assertEquals('', $this->service->normalizePhone('BPJS-12345'));
    }
}
