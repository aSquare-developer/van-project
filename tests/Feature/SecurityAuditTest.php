<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\UserManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class SecurityAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_events_are_logged_without_passwords_tokens_or_email(): void
    {
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong-secret']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/logout');
        $token = Password::createToken($user);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'])->assertRedirect('/login');
        foreach (['auth.failed', 'auth.login', 'auth.logout', 'auth.password_reset'] as $event) {
            $this->assertDatabaseHas('activity_logs', ['event' => $event]);
        }
        $serialized = ActivityLog::all()->toJson();
        foreach (['wrong-secret', 'NewPassword123!', $token, $user->email, $user->password] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertSame(1, ActivityLog::where('event', 'auth.login')->count());
    }

    public function test_admin_changes_are_logged_atomically_and_survive_subject_deletion(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $manager = app(UserManager::class);
        $data = ['name' => 'Secret Name', 'email' => 'private@example.test', 'password' => 'PrivatePassword123!', 'role' => 'user', 'is_active' => true];
        $user = $manager->create($admin, $data);
        $manager->update($admin, $user->id, [...$data, 'role' => 'moderator', 'is_active' => false]);
        $manager->update($admin, $user->id, [...$data, 'role' => 'moderator']);
        $manager->delete($admin, $user->id);
        foreach (['user.created', 'user.updated', 'user.role_changed', 'user.blocked', 'user.unblocked', 'user.deleted'] as $event) {
            $this->assertDatabaseHas('activity_logs', ['event' => $event, 'actor_id' => $admin->id, 'subject_id' => $user->id]);
        }
        $serialized = ActivityLog::all()->toJson();
        foreach ([$data['email'], $data['password'], $data['name']] as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_audit_failure_rolls_back_user_creation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->mock(AuditLogger::class)->shouldReceive('record')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        try {
            app(UserManager::class)->create($admin, ['name' => 'User', 'email' => 'rollback@example.test', 'password' => 'SecurePassword123!', 'role' => 'user', 'is_active' => true]);
            $this->fail('Expected transaction failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Audit unavailable', $exception->getMessage());
        }
        $this->assertDatabaseMissing('users', ['email' => 'rollback@example.test']);
    }

    public function test_activity_log_is_admin_only(): void
    {
        ActivityLog::factory()->create();
        foreach (Role::cases() as $role) {
            $this->signIn(User::factory()->create(['role' => $role]))->get('/admin/activity')->assertStatus($role === Role::Admin ? 200 : 403);
        }
    }

    public function test_security_headers_are_enabled(): void
    {
        $this->get('/login')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->app['env'] = 'production';
        $this->get('/login')->assertHeader('Content-Security-Policy');
    }

    public function test_password_reset_removes_database_sessions_and_blocked_tokens_are_unusable(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $token = Password::createToken($user);
        $data = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
        $user->forceFill(['is_active' => false])->save();
        $this->post('/reset-password', $data)->assertSessionHasErrors('email');
        $user->forceFill(['is_active' => true])->save();
        $this->post('/reset-password', $data)->assertRedirect('/login');
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    }
}
