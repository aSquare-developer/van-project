<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_roles_have_dashboard_but_only_admin_can_manage_users(): void
    {
        foreach (Role::cases() as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->signIn($user)->get('/dashboard')->assertOk();
            $this->get('/admin/users')->assertStatus($role === Role::Admin ? 200 : 403);
            $this->assertSame($role === Role::Admin, Gate::forUser($user)->allows('create', User::class));
        }
    }

    public function test_policies_reject_privilege_escalation_for_non_admins(): void
    {
        $subject = User::factory()->create();
        foreach ([Role::User, Role::Moderator] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach (['view', 'update', 'delete'] as $ability) {
                $this->assertFalse(Gate::forUser($user)->allows($ability, $subject));
            }
        }
        $admin = User::factory()->create(['role' => Role::Admin, 'is_active' => false]);
        $this->assertFalse(Gate::forUser($admin)->allows('create', User::class));
    }

    public function test_roles_and_account_status_are_not_mass_assignable(): void
    {
        $user = User::factory()->create();
        $user->fill(['role' => 'admin', 'is_active' => false, 'session_version' => 99])->save();
        $this->assertSame(Role::User, $user->fresh()->role);
        $this->assertTrue($user->fresh()->is_active);
        $this->assertSame(0, $user->fresh()->session_version);
    }
}
