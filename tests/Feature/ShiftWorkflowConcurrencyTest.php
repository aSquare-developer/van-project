<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\ShiftDecision;
use App\Enums\ShiftStatus;
use App\Models\InspectionAttempt;
use App\Models\ShiftTransition;
use App\Models\User;
use App\Services\ShiftWorkflow;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Fixtures\CreatesWorkflowEvidence;
use Tests\TestCase;

class ShiftWorkflowConcurrencyTest extends TestCase
{
    use CreatesWorkflowEvidence, DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Requires MySQL row locking; exercised in CI.');
        }
        Storage::fake('inspection-photos');
    }

    /** @param list<array{actor: User, decision: string, key: string}> $actions
     * @return list<string>
     */
    private function race(InspectionAttempt $attempt, array $actions): array
    {
        $shift = $attempt->check->shift->fresh();
        $barrier = sys_get_temp_dir().'/van-shift-race-'.bin2hex(random_bytes(8));
        mkdir($barrier);
        $config = config('database.connections.mysql');
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'],
            'DB_PASSWORD' => $config['password'] ?? '',
        ];
        $processes = [];
        foreach ($actions as $index => $action) {
            $processes[] = new Process([
                PHP_BINARY, base_path('tests/Fixtures/shift-workflow-race.php'), (string) $index,
                $barrier, (string) $action['actor']->id, (string) $shift->id, $action['decision'],
                (string) $shift->revision, $action['key'], 'Inspect the stored evidence.',
                Storage::disk('inspection-photos')->path(''), Storage::disk('inspection-photos')->path($attempt->photo->path),
            ], base_path(), $env, timeout: 20);
        }
        try {
            foreach ($processes as $process) {
                $process->start();
            }
            $deadline = microtime(true) + 10;
            while (count(glob($barrier.'/ready-*')) !== count($actions) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(count($actions), glob($barrier.'/ready-*'), implode('\n', array_map(fn (Process $process): string => $process->getErrorOutput(), $processes)));
            touch($barrier.'/go');
            $outcomes = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $outcomes[] = trim($process->getOutput());
            }
            sort($outcomes);

            return $outcomes;
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

    public function test_simultaneous_duplicate_completion_creates_one_transition(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $revision = $shift->fresh()->revision;
        $key = Str::uuid()->toString();
        $action = ['actor' => $shift->worker, 'decision' => 'complete', 'key' => $key];
        $this->assertSame(['recorded', 'recorded'], $this->race($attempt, [$action, $action]));
        $this->assertDatabaseCount('shift_transitions', 1);
        $this->assertSame(ShiftStatus::Completed, $shift->refresh()->status);
        $this->assertSame($revision + 1, $shift->revision);
        $this->assertSame(1, DB::table('activity_logs')->where('event', 'shift.complete')->count());
    }

    public function test_photo_upload_and_completion_cannot_both_change_the_checklist(): void
    {
        $attempt = $this->evidence();
        $shift = $attempt->check->shift;
        $this->assertSame(['protected', 'recorded'], $this->race($attempt, [
            ['actor' => $shift->worker, 'decision' => 'complete', 'key' => Str::uuid()->toString()],
            ['actor' => $shift->worker, 'decision' => 'upload', 'key' => Str::uuid()->toString()],
        ]));
        $shift->refresh();
        if ($shift->status === ShiftStatus::Completed) {
            $this->assertDatabaseCount('inspection_attempts', 1);
            $this->assertDatabaseCount('shift_transitions', 1);
            $this->assertSame(CheckStatus::Passed, $attempt->check->fresh()->status);
        } else {
            $this->assertSame(ShiftStatus::InProgress, $shift->status);
            $this->assertDatabaseCount('inspection_attempts', 2);
            $this->assertDatabaseCount('shift_transitions', 0);
            $this->assertSame(CheckStatus::Pending, $attempt->check->fresh()->status);
        }
    }

    public function test_competing_administrators_record_exactly_one_decision(): void
    {
        $attempt = $this->evidence(result: CheckStatus::Failed);
        $shift = $attempt->check->shift;
        app(ShiftWorkflow::class)->act($shift->worker, $shift, ShiftDecision::RequestReview, [
            'request_key' => Str::uuid()->toString(), 'revision' => $shift->fresh()->revision, 'comment' => 'Please review.',
        ]);
        $otherAdmin = User::factory()->create(['role' => Role::Admin]);
        $this->assertSame(['protected', 'recorded'], $this->race($attempt, [
            ['actor' => $shift->assignedBy, 'decision' => 'approve_with_remarks', 'key' => Str::uuid()->toString()],
            ['actor' => $otherAdmin, 'decision' => 'return_for_check', 'key' => Str::uuid()->toString()],
        ]));
        $this->assertContains($shift->refresh()->status, [ShiftStatus::CompletedWithRemarks, ShiftStatus::ReturnedForCheck]);
        $this->assertDatabaseCount('shift_transitions', 2);
        $this->assertSame(1, ShiftTransition::whereIn('decision', ['approve_with_remarks', 'return_for_check'])->count());
        $this->assertSame(CheckStatus::Failed, $attempt->check->fresh()->status);
    }
}
