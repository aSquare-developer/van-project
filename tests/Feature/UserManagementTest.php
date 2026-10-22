<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function data(array $overrides = []): array
    {
        return [...['name' => 'Test User', 'email' => 'new@example.test', 'role' => 'user', 'is_active' => '1', 'password' => 'SecurePassword123!', 'password_confirmation' => 'SecurePassword123!'], ...$overrides];
    }

    public function test_admin_can_create_edit_search_block_unblock_and_delete_users(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin)->get('/admin/users/create')->assertOk();
        $this->post('/admin/users', $this->data(['email' => 'NEW@example.test']))->assertRedirect('/admin/users');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('SecurePassword123!', $user->password));
        $this->get('/admin/users/'.$user->id.'/edit')->assertOk();
        DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
        $this->put('/admin/users/'.$user->id, $this->data(['role' => 'moderator', 'is_active' => '0', 'password' => '', 'password_confirmation' => '']))->assertRedirect('/admin/users');
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(Role::Moderator, $user->fresh()->role);
        $this->assertSame(1, $user->fresh()->session_version);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
        $this->put('/admin/users/'.$user->id, $this->data(['role' => 'moderator', 'password' => '', 'password_confirmation' => '']))->assertRedirect('/admin/users');
        $this->assertTrue($user->fresh()->is_active);
        $this->get('/admin/users?q=new%40example.test')->assertSee('new@example.test')->assertDontSee($admin->email);
        $this->delete('/admin/users/'.$user->id)->assertRedirect('/admin/users');
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_non_admins_are_denied_every_management_action(): void
    {
        $target = User::factory()->create();
        foreach ([Role::User, Role::Moderator] as $role) {
            $this->signIn(User::factory()->create(['role' => $role]));
            $this->get('/admin/users/create')->assertForbidden();
            $this->get('/admin/users/'.$target->id.'/edit')->assertForbidden();
            $this->post('/admin/users', $this->data())->assertForbidden();
            $this->put('/admin/users/'.$target->id, $this->data())->assertForbidden();
            $this->delete('/admin/users/'.$target->id)->assertForbidden();
        }
        $this->assertDatabaseHas('users', ['id' => $target->id]);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_validation_rejects_invalid_role_duplicate_email_and_weak_password(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin)->post('/admin/users', $this->data(['role' => 'owner', 'email' => strtoupper($admin->email), 'password' => 'weak', 'password_confirmation' => 'weak']))->assertSessionHasErrors(['email', 'role', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_passwords_exceeding_bcrypt_byte_limit_are_rejected_without_server_errors(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin);
        foreach (['Secure1!'.str_repeat('x', 80), 'Secure1!'.str_repeat('ä', 40)] as $password) {
            $this->post('/admin/users', $this->data(['password' => $password, 'password_confirmation' => $password]))->assertSessionHasErrors('password');
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_admin_cannot_block_or_delete_self_or_demote_last_active_admin(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin);
        $data = $this->data(['email' => $admin->email, 'role' => 'admin', 'is_active' => '0']);
        $this->put('/admin/users/'.$admin->id, $data)->assertSessionHasErrors('user');
        $this->delete('/admin/users/'.$admin->id)->assertForbidden();
        $this->put('/admin/users/'.$admin->id, [...$data, 'is_active' => '1', 'role' => 'user'])->assertSessionHasErrors('role');
        $this->assertSame(Role::Admin, $admin->fresh()->role);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_only_active_admins_count_and_second_admin_allows_self_demotion(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $blocked = User::factory()->create(['role' => Role::Admin, 'is_active' => false]);
        $this->signIn($admin);
        $data = $this->data(['email' => $admin->email, 'role' => 'moderator']);
        $this->put('/admin/users/'.$admin->id, $data)->assertSessionHasErrors('role');
        $blocked->forceFill(['is_active' => true])->save();
        $this->put('/admin/users/'.$admin->id, $data)->assertRedirect('/admin/users');
        $this->assertSame(Role::Moderator, $admin->fresh()->role);
        $this->get('/admin/users')->assertRedirect('/login');
    }

    public function test_user_values_are_escaped_in_blade(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['name' => '<script>alert(1)</script>']);
        $this->signIn($admin)->get('/admin/users')->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
