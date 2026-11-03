<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityAndHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_minimal_public_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertExactJson([
                'status' => 'healthy',
            ]);
    }

    public function test_admin_health_endpoint_requires_auth_and_returns_details(): void
    {
        // Unauthenticated request must fail
        $unauth = $this->getJson('/api/admin/health');
        $unauth->assertStatus(401);

        // Authenticated admin request
        $user = User::factory()->create();
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $response = $this->getJson('/api/admin/health');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'database',
                'fonnte_mode',
                'last_followup',
                'server_time',
            ]);
    }

    public function test_protected_routes_require_sanctum_auth(): void
    {
        $response = $this->getJson('/api/customers');
        $response->assertStatus(401);

        $responsePost = $this->postJson('/api/transactions', []);
        $responsePost->assertStatus(401);
    }

    public function test_login_rate_limiting_triggers_too_many_requests(): void
    {
        User::factory()->create([
            'email'    => 'admin@optikcrm.com',
            'password' => Hash::make('secret123'),
        ]);

        // Kirim 5 request salah
        for ($i = 0; $i < 5; $i++) {
            $response = $this->postJson('/api/login', [
                'email'    => 'admin@optikcrm.com',
                'password' => 'wrongpassword',
            ]);
            $response->assertStatus(401);
        }

        // Request ke-6 harus di-throttle (HTTP 429 Too Many Requests)
        $throttledResponse = $this->postJson('/api/login', [
            'email'    => 'admin@optikcrm.com',
            'password' => 'wrongpassword',
        ]);

        $throttledResponse->assertStatus(429);
    }

    public function test_login_throttle_cannot_be_bypassed_by_spoofing_x_forwarded_for(): void
    {
        // Default aman (TRUSTED_PROXIES kosong): header X-Forwarded-For dari klien diabaikan.
        $this->assertEmpty(config('app.trusted_proxies'));

        User::factory()->create([
            'email'    => 'admin@optikcrm.com',
            'password' => Hash::make('secret123'),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/login', [
                'email'    => 'admin@optikcrm.com',
                'password' => 'wrongpassword',
            ], ['X-Forwarded-For' => "203.0.113.{$i}"])->assertStatus(401);
        }

        // IP spoof baru pada request ke-6 tetap harus kena throttle
        $this->postJson('/api/login', [
            'email'    => 'admin@optikcrm.com',
            'password' => 'wrongpassword',
        ], ['X-Forwarded-For' => '203.0.113.200'])->assertStatus(429);
    }
}
