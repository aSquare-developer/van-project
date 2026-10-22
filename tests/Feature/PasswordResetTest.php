<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_email_uses_trusted_app_url_and_generic_response(): void
    {
        Notification::fake();
        config(['app.url' => 'https://van.example.test']);
        $user = User::factory()->create();
        $this->get('/forgot-password')->assertOk();
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $message = session('status');
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            $this->assertStringStartsWith('https://van.example.test/reset-password/', $notification->toMail($user)->actionUrl);
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))->assertOk();

            return true;
        });
        $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertSessionHas('status', $message);
        $blocked = User::factory()->create(['is_active' => false]);
        $this->post('/forgot-password', ['email' => $blocked->email])->assertSessionHas('status', $message);
        Notification::assertNotSentTo($blocked, ResetPassword::class);
    }

    public function test_valid_token_resets_password_revokes_sessions_and_cannot_be_reused(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $user->refresh();
        $this->assertTrue(Hash::check($data['password'], $user->password));
        $this->assertSame(1, $user->session_version);
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
    }

    public function test_invalid_expired_and_weak_password_resets_fail(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
        $this->post('/reset-password', [...$data, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/reset-password', [...$data, 'token' => 'invalid'])->assertSessionHasErrors('email');
        $this->travel(61)->minutes();
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_reset_endpoint_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/forgot-password', ['email' => 'limited@example.test'])->assertRedirect();
        }
        $this->post('/forgot-password', ['email' => 'limited@example.test'])->assertStatus(429);
    }
}
