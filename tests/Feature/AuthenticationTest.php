<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_regenerates_session_and_logout_invalidates_it(): void
    {
        $user = User::factory()->create();
        $this->get('/login')->assertOk();
        $before = session()->getId();
        $this->post('/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertRedirect('/dashboard')->assertSessionHas('auth_version', 0);
        $this->assertNotSame($before, session()->getId());
        $this->assertAuthenticatedAs($user);
        $this->get('/dashboard')->assertOk();
        $this->post('/logout')->assertRedirect('/login')->assertSessionMissing('auth_version');
        $this->assertGuest();
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_invalid_and_blocked_credentials_are_indistinguishable(): void
    {
        $user = User::factory()->create(['is_active' => false]);
        foreach ([$user->email, 'unknown@example.test'] as $email) {
            $this->post('/login', ['email' => $email, 'password' => 'password'])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
    }

    public function test_login_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['email' => 'throttled@example.test', 'password' => 'wrong'])->assertStatus(302);
        }
        $this->post('/login', ['email' => 'throttled@example.test', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_registration_and_guest_dashboard_access_are_disabled(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', [])->assertNotFound();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->get('/logout')->assertStatus(405);
    }

    public function test_blocking_or_revoking_account_rejects_existing_session(): void
    {
        $user = User::factory()->create();
        $this->signIn($user);
        $user->forceFill(['session_version' => 1])->save();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
        $user->refresh();
        $this->signIn($user);
        $user->forceFill(['is_active' => false])->save();
        $this->get('/dashboard')->assertRedirect('/login');
        $this->assertGuest();
    }
}
