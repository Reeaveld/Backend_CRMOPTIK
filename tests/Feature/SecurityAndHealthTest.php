<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SecurityAndHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok_with_jakarta_timezone(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJson([
                'status'   => 'healthy',
                'timezone' => 'Asia/Jakarta',
            ])
            ->assertJsonStructure([
                'status',
                'timezone',
                'server_time',
                'database' => ['connected', 'driver'],
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
}
