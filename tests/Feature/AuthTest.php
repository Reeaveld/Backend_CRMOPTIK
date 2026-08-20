<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature test untuk autentikasi Sanctum (P5):
 *   1. Login sukses mengembalikan bearer token
 *   2. Login gagal (wrong password) mengembalikan HTTP 401
 *   3. Route yang dilindungi menolak request tanpa token (HTTP 401)
 *   4. Route yang dilindungi menerima request dengan token (HTTP 200/201)
 */
class AuthTest extends TestCase
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

    public function test_login_returns_token_on_correct_credentials(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'admin@optikcrm.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonStructure(['success', 'message', 'token', 'user']);
    }

    public function test_login_fails_on_wrong_password(): void
    {
        $response = $this->postJson('/api/login', [
            'email'    => 'admin@optikcrm.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
                 ->assertJsonPath('success', false);
    }

    public function test_protected_routes_require_authentication(): void
    {
        // Tanpa token → HTTP 401
        $response = $this->getJson('/api/customers');
        $response->assertStatus(401);
    }

    public function test_protected_routes_accessible_with_sanctum_token(): void
    {
        // Dengan token → HTTP 200
        $response = $this->actingAs($this->user, 'sanctum')
                         ->getJson('/api/customers');

        $response->assertStatus(200);
    }
}
