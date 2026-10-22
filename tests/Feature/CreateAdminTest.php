<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_admin_is_created_without_default_credentials(): void
    {
        $this->artisan('admin:create', ['--name' => 'First Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Password', 'SecurePassword123!')
            ->expectsQuestion('Confirm password', 'SecurePassword123!')
            ->expectsOutput('Administrator created.')->assertSuccessful();
        $user = User::firstOrFail();
        $this->assertSame(Role::Admin, $user->role);
        $this->assertTrue(Hash::check('SecurePassword123!', $user->password));
        $this->artisan('admin:create', ['--name' => 'Another', '--email' => 'another@example.test'])
            ->expectsQuestion('Password', 'SecurePassword123!')
            ->expectsQuestion('Confirm password', 'SecurePassword123!')
            ->assertFailed();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_non_interactive_bootstrap_refuses_to_create_an_account(): void
    {
        $this->artisan('admin:create', ['--no-interaction' => true])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }
}
