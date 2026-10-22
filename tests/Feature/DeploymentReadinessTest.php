<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeploymentReadinessTest extends TestCase
{
    use DatabaseMigrations;

    public function test_migrations_can_be_rolled_back_and_reapplied(): void
    {
        $this->artisan('migrate:reset', ['--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('users'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $this->assertTrue(Schema::hasTable('activity_logs'));
        $this->assertSame(1, DB::table('administration_lock')->count());
    }

    public function test_application_entry_health_and_empty_seed_are_safe(): void
    {
        $this->get('/')->assertRedirect('/dashboard');
        $this->get('/up')->assertOk();
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
