<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_shows_real_counts_and_navigation(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        User::factory()->create(['is_active' => false]);
        $this->signIn($admin)->get('/dashboard')->assertOk()->assertViewHas('stats', [
            'Total users' => 2, 'Active accounts' => 1, 'Blocked accounts' => 1, 'Active admins' => 1,
        ])->assertSee('User management')->assertSee('Mobile navigation');
    }

    public function test_non_admin_dashboard_and_profile_do_not_disclose_other_users(): void
    {
        $other = User::factory()->create();
        foreach ([Role::User, Role::Moderator] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->signIn($user)->get('/dashboard')->assertOk()->assertDontSee($other->email)->assertDontSee('User management')->assertViewHas('stats', null);
            $this->get('/profile')->assertOk()->assertSee($user->email)->assertDontSee($other->email);
        }
    }

    public function test_user_search_has_a_useful_empty_state(): void
    {
        $admin = User::factory()->create(['role' => Role::Admin]);
        $this->signIn($admin)->get('/admin/users?q=does-not-exist')->assertOk()->assertSee('No users match your search.');
    }
}
