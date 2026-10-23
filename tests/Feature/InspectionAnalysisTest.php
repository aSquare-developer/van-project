<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\ShiftStatus;
use App\Models\InspectionAttempt;
use App\Models\ShiftCheck;
use App\Services\InspectionAnalysis;
use App\Services\InspectionPhotoSubmission;
use App\Services\VisionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\CreatesInspectionPhotos;
use Tests\TestCase;

class InspectionAnalysisTest extends TestCase
{
    use CreatesInspectionPhotos, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection-photos');
        Http::preventStrayRequests();
        config([
            'inspections.vision.endpoint' => 'https://vision.example.test/v1/analyse',
            'inspections.vision.token' => 'server-only-test-token',
            'inspections.checks.silicone_holder.model_version' => 'silicone-holder/1',
        ]);
    }

    private function fakeHttp(array|callable $callbacks): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($callbacks);
    }

    private function attempt(?ShiftCheck $check = null): InspectionAttempt
    {
        $check ??= ShiftCheck::factory()->create();

        return app(InspectionPhotoSubmission::class)->submit($check->shift->worker, $check, $this->photo(), Str::uuid()->toString());
    }

    private function response(InspectionAttempt $attempt, array $overrides = []): array
    {
        return array_replace([
            'protocol_version' => 1, 'attempt_id' => $attempt->submission_key,
            'check_key' => 'silicone_holder', 'model_version' => 'silicone-holder/1',
            'outcome' => 'present', 'class' => 'holder_stocked', 'confidence' => 0.95,
            'boxes' => [['x' => 0.1, 'y' => 0.2, 'width' => 0.3, 'height' => 0.4]],
        ], $overrides);
    }

    public function test_upload_queues_database_job_and_worker_persists_trusted_result(): void
    {
        $check = ShiftCheck::factory()->create();
        $this->signIn($check->shift->worker)->post('/shifts/'.$check->shift_id.'/checks/'.$check->id.'/photos', [
            'photo' => $this->photo(), 'submission_key' => Str::uuid()->toString(), 'result' => 'passed',
            'model_version' => 'attacker-model', 'endpoint' => 'https://attacker.example',
        ])->assertRedirect();
        $attempt = InspectionAttempt::firstOrFail();
        $this->assertSame('silicone-holder/1', $attempt->model_version);
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame(CheckStatus::Pending, $check->fresh()->status);
        Http::assertNothingSent();
        $this->fakeHttp(['vision.example.test/*' => Http::response($this->response($attempt))]);
        Queue::connection('database')->pop('inspections')->fire();
        $attempt->refresh();
        $this->assertSame('analysed', $attempt->status);
        $this->assertSame(CheckStatus::Passed, $check->fresh()->status);
        $this->assertSame('holder_stocked', $attempt->detected_class);
        $this->assertSame(0.95, $attempt->confidence);
        $this->assertCount(1, $attempt->boxes);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://vision.example.test/v1/analyse'
            && $request->hasHeader('Authorization', 'Bearer server-only-test-token')
            && $request->hasHeader('Idempotency-Key', $attempt->submission_key)
            && $request->hasFile('attempt_id', $attempt->submission_key) && $request->hasFile('image')
            && $request->hasFile('model_version', 'silicone-holder/1'));
        $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertOk()
            ->assertSee('95.0%')->assertSee('required condition was confirmed')
            ->assertDontSee('server-only-test-token')->assertDontSee($attempt->submission_key);
        $this->assertDatabaseHas('activity_logs', ['event' => 'inspection.analysis_finished']);
        app(InspectionAnalysis::class)->run($attempt->id);
        Http::assertSentCount(1);
    }

    public function test_empty_holder_fails_and_low_confidence_or_uncertain_is_inconclusive(): void
    {
        foreach ([
            ['absent', 'holder_empty', 0.95, CheckStatus::Failed],
            ['present', 'holder_stocked', 0.79, CheckStatus::Inconclusive],
            ['absent', 'holder_empty', 0.5, CheckStatus::Inconclusive],
            ['uncertain', null, 0.99, CheckStatus::Inconclusive],
            ['present', 'holder_stocked', 0.8, CheckStatus::Passed],
        ] as [$outcome, $class, $confidence, $expected]) {
            $attempt = $this->attempt();
            $this->fakeHttp(['*' => Http::response($this->response($attempt, compact('outcome', 'class', 'confidence')))]);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame($expected, $attempt->check->fresh()->status);
        }
    }

    public function test_invalid_contracts_never_mark_missing_or_passed(): void
    {
        foreach ([
            ['attempt_id' => Str::uuid()->toString()], ['model_version' => 'wrong'], ['check_key' => 'wrong'],
            ['protocol_version' => 2], ['class' => 'wrong'], ['confidence' => 1.1],
            ['outcome' => 'uncertain', 'class' => 'holder_stocked'],
            ['boxes' => [['x' => 0.9, 'y' => 0.1, 'width' => 0.5, 'height' => 0.2]]],
            ['boxes' => [['x' => 0.1]]], ['boxes' => 'not-an-array'], ['outcome' => 'passed'],
        ] as $invalid) {
            $attempt = $this->attempt();
            $this->fakeHttp(['*' => Http::response($this->response($attempt, $invalid))]);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('invalid_response', $attempt->refresh()->error_code);
            $this->assertSame(CheckStatus::AnalysisError, $attempt->check->fresh()->status);
        }
    }

    public function test_non_json_and_oversized_responses_are_rejected(): void
    {
        foreach (['<html>bad gateway</html>', str_repeat('x', 65537)] as $body) {
            $attempt = $this->attempt();
            $this->fakeHttp(['*' => Http::response($body)]);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('invalid_response', $attempt->refresh()->error_code);
        }
    }

    public function test_transient_failures_retry_at_most_three_times_with_stable_idempotency_key(): void
    {
        $attempt = $this->attempt();
        $this->fakeHttp(['*' => Http::response(['secret' => 'never-store-this'], 503)]);
        $analysis = app(InspectionAnalysis::class);
        for ($i = 1; $i <= 3; $i++) {
            $analysis->run($attempt->id);
            $attempt->refresh();
            $this->assertSame($i, $attempt->analysis_runs);
            if ($i < 3) {
                $this->assertSame('uploaded', $attempt->status);
                $analysis->run($attempt->id);
                Http::assertSentCount($i);
                $this->travel(61)->seconds();
            }
        }
        $this->assertSame('error', $attempt->status);
        $this->assertSame(CheckStatus::AnalysisError, $attempt->check->fresh()->status);
        $analysis->run($attempt->id);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $attempt->submission_key));
        $this->assertStringNotContainsString('never-store-this', $attempt->toJson());
    }

    public function test_connection_error_is_sanitized_and_retries_then_can_succeed(): void
    {
        $attempt = $this->attempt();
        $this->fakeHttp(['*' => Http::failedConnection('secret token and private response')]);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('service_unavailable', $attempt->refresh()->error_code);
        $this->assertStringNotContainsString('secret token', $attempt->toJson());
        $this->travel(31)->seconds();
        $this->fakeHttp(['*' => Http::response($this->response($attempt))]);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('passed', $attempt->refresh()->result);
        $this->assertNull($attempt->error_code);
    }

    public function test_auth_error_and_redirect_are_terminal_without_following_redirects(): void
    {
        foreach ([401, 403, 302] as $status) {
            $attempt = $this->attempt();
            $this->fakeHttp(['*' => Http::response('private-service-error', $status, ['Location' => 'https://attacker.example'])]);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('error', $attempt->refresh()->status);
            $this->assertSame(1, $attempt->analysis_runs);
        }
    }

    public function test_missing_configuration_or_insecure_endpoint_never_sends_photo(): void
    {
        foreach ([null, 'http://vision.example.test/api', 'https://user:pass@vision.example.test/api', 'https://vision.example.test/api?token=unsafe'] as $endpoint) {
            config(['inspections.vision.endpoint' => $endpoint]);
            $attempt = $this->attempt();
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        }
        Http::assertNothingSent();
    }

    public function test_missing_token_or_model_does_not_send_photo_and_retry_after_429_is_bounded(): void
    {
        config(['inspections.vision.token' => null]);
        $attempt = $this->attempt();
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        config(['inspections.vision.token' => 'server-only-test-token', 'inspections.checks.silicone_holder.model_version' => null]);
        $attempt = $this->attempt();
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        Http::assertNothingSent();
        config(['inspections.checks.silicone_holder.model_version' => 'silicone-holder/1']);
        $attempt = $this->attempt();
        $this->fakeHttp(['*' => Http::response([], 429)]);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('uploaded', $attempt->refresh()->status);
        $this->assertNotNull($attempt->retry_at);
        $this->assertSame(CheckStatus::Pending, $attempt->check->fresh()->status);
    }

    public function test_request_disables_redirects_and_enforces_network_timeouts(): void
    {
        $attempt = $this->attempt();
        $this->fakeHttp(function (Request $request, array $options) use ($attempt) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(30, $options['timeout']);

            return Http::response($this->response($attempt));
        });
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('passed', $attempt->refresh()->result);
    }

    public function test_missing_photo_is_an_infrastructure_error(): void
    {
        $attempt = $this->attempt();
        Storage::disk('inspection-photos')->delete($attempt->photo->path);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('photo_unavailable', $attempt->refresh()->error_code);
        $this->assertSame(CheckStatus::AnalysisError, $attempt->check->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_new_photo_prevents_old_result_from_overwriting_current_check(): void
    {
        $old = $this->attempt();
        $new = $this->attempt($old->check);
        $this->fakeHttp(['*' => Http::response($this->response($new, ['outcome' => 'absent', 'class' => 'holder_empty']))]);
        app(InspectionAnalysis::class)->run($new->id);
        $this->fakeHttp(['*' => Http::response($this->response($old))]);
        app(InspectionAnalysis::class)->run($old->id);
        $this->assertSame('passed', $old->refresh()->result);
        $this->assertSame(CheckStatus::Failed, $new->check->fresh()->status);
    }

    public function test_late_result_does_not_mutate_closed_or_reviewed_shift(): void
    {
        foreach ([ShiftStatus::Completed, ShiftStatus::CompletedWithRemarks, ShiftStatus::AwaitingReview] as $status) {
            $attempt = $this->attempt();
            $attempt->check->shift->forceFill(['status' => $status])->save();
            $this->fakeHttp(['*' => Http::response($this->response($attempt))]);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('passed', $attempt->refresh()->result);
            $this->assertSame(CheckStatus::Pending, $attempt->check->fresh()->status);
            $this->assertSame($status, $attempt->check->shift->fresh()->status);
        }
    }

    public function test_duplicate_job_cannot_claim_an_active_analysis(): void
    {
        $attempt = $this->attempt();
        $this->fakeHttp(function () use ($attempt) {
            app(InspectionAnalysis::class)->run($attempt->id);

            return Http::response($this->response($attempt));
        });
        app(InspectionAnalysis::class)->run($attempt->id);
        Http::assertSentCount(1);
        $this->assertSame(1, $attempt->refresh()->analysis_runs);
    }

    public function test_replaced_processing_token_prevents_stale_worker_commit(): void
    {
        $attempt = $this->attempt();
        $this->mock(VisionClient::class)->shouldReceive('analyse')->once()->andReturnUsing(function () use ($attempt): array {
            $attempt->forceFill(['processing_token' => Str::uuid()->toString()])->save();

            return ['result' => 'passed', 'detected_class' => 'holder_stocked', 'confidence' => 0.9, 'boxes' => []];
        });
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertNull($attempt->refresh()->result);
        $this->assertSame(CheckStatus::Pending, $attempt->check->fresh()->status);
    }

    public function test_scheduler_recovers_unqueued_and_abandoned_work_without_duplicate_dispatch(): void
    {
        $attempt = $this->attempt();
        $this->artisan('inspections:dispatch-analysis')->expectsOutput('Analysis jobs queued: 1')->assertSuccessful();
        $this->artisan('inspections:dispatch-analysis')->expectsOutput('Analysis jobs queued: 0')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);
        $attempt->forceFill(['status' => 'processing', 'started_at' => now(), 'analysis_runs' => 1])->save();
        $this->travel(121)->seconds();
        $this->artisan('inspections:dispatch-analysis')->expectsOutput('Analysis jobs queued: 1')->assertSuccessful();
        $this->fakeHttp(['*' => Http::response($this->response($attempt))]);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('passed', $attempt->refresh()->result);
        $this->assertSame(2, $attempt->analysis_runs);
    }

    public function test_abandoned_final_run_is_not_sent_a_fourth_time(): void
    {
        $attempt = $this->attempt();
        $attempt->forceFill(['status' => 'processing', 'started_at' => now()->subMinutes(3), 'analysis_runs' => 3])->save();
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('retries_exhausted', $attempt->refresh()->error_code);
        $this->assertSame(CheckStatus::AnalysisError, $attempt->check->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_queue_dispatch_failure_preserves_photo_for_recovery(): void
    {
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('private queue exception'));
        $check = ShiftCheck::factory()->create();
        $this->signIn($check->shift->worker)->post('/shifts/'.$check->shift_id.'/checks/'.$check->id.'/photos', [
            'photo' => $this->photo(), 'submission_key' => Str::uuid()->toString(),
        ])->assertRedirect();
        $attempt = InspectionAttempt::firstOrFail();
        $this->assertSame('uploaded', $attempt->status);
        $this->assertNull($attempt->dispatched_at);
        Storage::disk('inspection-photos')->assertExists($attempt->photo->path);
    }
}
