<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AdminInvariantTest extends TestCase
{
    use DatabaseMigrations;

    public function test_concurrent_self_demotions_preserve_one_active_administrator(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL row locking; exercised in CI.');
        }
        $users = User::factory()->count(2)->create(['role' => Role::Admin]);
        $barrier = sys_get_temp_dir().'/van-concurrency-'.bin2hex(random_bytes(8));
        mkdir($barrier);
        $config = config('database.connections.mysql');
        $env = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'] ?? ''];
        $processes = $users->map(fn ($user) => new Process([PHP_BINARY, base_path('tests/Fixtures/demote-admin.php'), (string) $user->id, $barrier], base_path(), $env, timeout: 20));
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 10;
            while (count(glob($barrier.'/ready-*')) !== 2 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(2, glob($barrier.'/ready-*'), implode('\n', $processes->map(fn ($p) => $p->getErrorOutput())->all()));
            touch($barrier.'/go');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $outcomes[] = trim($process->getOutput());
            }
            sort($outcomes);
            $this->assertSame(['changed', 'protected'], $outcomes);
            $this->assertSame(1, User::where('role', Role::Admin)->where('is_active', true)->count());
        } finally {
            foreach ($processes as $process) {
                $process->stop();
            }
            foreach (glob($barrier.'/*') as $file) {
                unlink($file);
            }
            rmdir($barrier);
        }
    }
}
