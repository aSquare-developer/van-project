<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Models\InspectionAttempt;
use App\Models\ShiftCheck;
use App\Models\User;
use App\Models\VisionModelRevision;
use App\Services\InspectionAnalysis;
use App\Services\InspectionPhotoSubmission;
use App\Services\VisionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\CreatesInspectionPhotos;
use Tests\TestCase;

class RoboflowVisionTest extends TestCase
{
    use CreatesInspectionPhotos, RefreshDatabase;

    private array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection-photos');
        Http::preventStrayRequests();
        config(['inspections.roboflow.api_key' => 'server-test-key', 'inspections.roboflow.automatic_decisions' => false]);
    }

    private function check(array $settings = []): ShiftCheck
    {
        $revision = VisionModelRevision::factory()->create();
        if ($settings !== []) {
            $revision = VisionModelRevision::factory()->create(['settings' => array_replace($revision->settings, $settings)]);
        }
        $definition = app(VisionCatalog::class)->definition('rf_revision_'.$revision->id);

        return ShiftCheck::factory()->create(['check_key' => 'rf_revision_'.$revision->id,
            'expected_class' => $definition['passing_class'], 'analysis_config' => $definition]);
    }

    private function attempt(?ShiftCheck $check = null): InspectionAttempt
    {
        $check ??= $this->check();

        return app(InspectionPhotoSubmission::class)->submit($check->shift->worker, $check, $this->photo(), Str::uuid()->toString());
    }

    private function response(InspectionAttempt $attempt, array $prediction = []): array
    {
        return ['outputs' => [[$attempt->analysis_config['output_name'] => array_replace([
            'inference_id' => 'test-inference-id', 'time' => 0.015,
            'resolved_model' => ['model_id' => $attempt->model_version, 'backend' => 'trt', 'quantization' => 'fp16'],
            'image' => ['width' => 1086, 'height' => 1448],
            'predictions' => [['class' => 'occupied', 'class_id' => 0, 'confidence' => 0.968]],
            'top' => 'occupied', 'confidence' => 0.968, 'prediction_type' => 'classification',
        ], $prediction)]], 'profiler_trace' => []];
    }

    private function fakeResponse(array|string $body, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['serverless.roboflow.com/*' => function (Request $request, array $options) use ($body, $status) {
            $this->options = $options;

            return Http::response($body, $status, ['Location' => 'https://attacker.test']);
        }]);
    }

    public function test_worker_upload_is_queued_and_classification_is_advisory_then_reviewable(): void
    {
        $check = $this->check();
        $this->signIn($check->shift->worker)->post('/shifts/'.$check->shift_id.'/checks/'.$check->id.'/photos', [
            'photo' => $this->photo(), 'submission_key' => Str::uuid()->toString(),
            'result' => 'passed', 'model_version' => 'injected', 'analysis_config' => ['provider' => 'attacker'],
        ])->assertRedirect();
        $attempt = InspectionAttempt::firstOrFail();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertSame('roboflow', $attempt->analysis_config['provider']);
        $this->assertSame($check->analysis_config['model_id'], $attempt->model_version);
        Http::assertNothingSent();
        $this->fakeResponse($this->response($attempt));
        Queue::connection('database')->pop('inspections')->fire();
        $attempt->refresh();
        $this->assertSame('analysed', $attempt->status);
        $this->assertSame('advisory', $attempt->decision_mode);
        $this->assertSame('present', $attempt->observed_outcome);
        $this->assertSame('occupied', $attempt->detected_class);
        $this->assertSame([], $attempt->boxes);
        $this->assertSame(CheckStatus::Inconclusive, $check->fresh()->status);
        $this->assertSame(0.968, $attempt->confidence);
        Http::assertSent(function (Request $request) use ($attempt): bool {
            $this->assertFalse($this->options['allow_redirects']);
            $this->assertSame(5, $this->options['connect_timeout']);
            $this->assertSame(30, $this->options['timeout']);
            $this->assertSame(['inputs'], array_keys($request->data()));
            $this->assertSame('base64', $request['inputs']['image']['type']);
            $this->assertSame(Storage::disk('inspection-photos')->get($attempt->photo->path), base64_decode($request['inputs']['image']['value'], true));

            return $request->url() === 'https://serverless.roboflow.com/infer/workflows/lisettes-workspace/silicone-shelf-status-test'
                && $request->hasHeader('Authorization', 'Bearer server-test-key')
                && $request->hasHeader('Idempotency-Key', $attempt->submission_key);
        });
        $this->get('/shifts/'.$check->shift_id.'/checks/'.$check->id)->assertOk()->assertSee('Advisory analysis only')
            ->assertSee('Predicted class: occupied')->assertSee('not measured accuracy')->assertDontSee('server-test-key');
        $this->assertStringNotContainsString('inference_id', $attempt->toJson());
        $this->assertStringNotContainsString('base64', $attempt->toJson());
        $this->post('/shifts/'.$check->shift_id.'/review-request', ['request_key' => Str::uuid()->toString(),
            'revision' => $check->shift->fresh()->revision, 'comment' => 'Pilot result needs review.'])->assertRedirect();
        $this->assertSame(ShiftStatus::AwaitingReview, $check->shift->fresh()->status);
        $this->signIn(User::factory()->create(['role' => Role::Admin]))->get('/admin/shifts/'.$check->shift_id)->assertOk();
        app(InspectionAnalysis::class)->run($attempt->id);
        Http::assertSentCount(1);
    }

    public function test_class_names_not_class_ids_control_opted_in_decisions_and_low_scores_are_inconclusive(): void
    {
        config(['inspections.roboflow.automatic_decisions' => true]);
        foreach ([
            ['occupied', 0.95, CheckStatus::Passed], ['empty', 0.99, CheckStatus::Failed],
            ['invalid', 0.99, CheckStatus::Inconclusive], ['occupied', 0.79, CheckStatus::Inconclusive],
        ] as [$class, $confidence, $expected]) {
            $attempt = $this->attempt($this->check(['automatic_decisions' => true]));
            $this->fakeResponse($this->response($attempt, ['top' => $class, 'confidence' => $confidence,
                'predictions' => [['class' => $class, 'class_id' => 999, 'confidence' => $confidence]]]));
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame($expected, $attempt->check->fresh()->status);
            $this->assertSame($class, $attempt->refresh()->detected_class);
        }
    }

    public function test_enabling_server_automatic_flag_does_not_upgrade_existing_advisory_revisions(): void
    {
        $attempt = $this->attempt();
        config(['inspections.roboflow.automatic_decisions' => true]);
        $this->fakeResponse($this->response($attempt));
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('inconclusive', $attempt->refresh()->result);
        $this->assertSame('advisory', $attempt->decision_mode);
    }

    public function test_server_kill_switch_suppresses_automatic_results_for_queued_opted_in_attempts(): void
    {
        $attempt = $this->attempt($this->check(['automatic_decisions' => true]));
        $this->fakeResponse($this->response($attempt));
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('inconclusive', $attempt->refresh()->result);
        $this->assertSame('advisory', $attempt->decision_mode);
    }

    public function test_different_registered_models_use_their_snapshotted_workflows_and_output_names(): void
    {
        $first = $this->attempt();
        $second = $this->attempt($this->check(['model_id' => 'workspace/tools-3', 'workspace' => 'workspace',
            'workflow_id' => 'tools-workflow', 'input_name' => 'photo', 'output_name' => 'classification', 'api_route' => 'workspace']));
        foreach ([$first, $second] as $attempt) {
            $this->fakeResponse($this->response($attempt));
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('analysed', $attempt->refresh()->status);
        }
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://serverless.roboflow.com/workspace/workflows/tools-workflow'
            && $request['inputs']['photo']['type'] === 'base64');
    }

    public function test_mismatched_models_invalid_confidence_or_inconsistent_predictions_never_pass(): void
    {
        foreach ([
            ['resolved_model' => ['model_id' => 'wrong/model']], ['prediction_type' => 'object-detection'],
            ['confidence' => '0.968'], ['confidence' => 1.1], ['top' => 'unexpected'],
            ['predictions' => []], ['predictions' => [['class' => 'occupied', 'confidence' => 0.2]]],
            ['predictions' => [['class' => 'occupied', 'confidence' => 0.968], ['class' => 'empty', 'confidence' => 0.999]]],
            ['predictions' => [['class' => 'occupied', 'confidence' => 0.968], ['class' => 'occupied', 'confidence' => 0.968]]],
            ['image' => ['width' => 0, 'height' => 1448]], ['image' => ['width' => '1086', 'height' => 1448]],
        ] as $invalid) {
            $attempt = $this->attempt();
            $this->fakeResponse($this->response($attempt, $invalid));
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('invalid_response', $attempt->refresh()->error_code);
            $this->assertSame(CheckStatus::AnalysisError, $attempt->check->fresh()->status);
        }
    }

    public function test_missing_or_multiple_outputs_non_json_and_oversized_responses_are_rejected(): void
    {
        foreach ([[], ['outputs' => []], ['outputs' => [[], []]], '<html>gateway error</html>', str_repeat('x', 65537)] as $invalid) {
            $attempt = $this->attempt();
            $this->fakeResponse($invalid);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('invalid_response', $attempt->refresh()->error_code);
        }
    }

    public function test_tied_class_predictions_require_review_even_with_automatic_decisions_enabled(): void
    {
        config(['inspections.roboflow.automatic_decisions' => true]);
        $attempt = $this->attempt($this->check(['automatic_decisions' => true]));
        $this->fakeResponse($this->response($attempt, ['predictions' => [
            ['class' => 'occupied', 'confidence' => 0.968], ['class' => 'empty', 'confidence' => 0.968],
        ]]));
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('inconclusive', $attempt->refresh()->result);
        $this->assertSame('uncertain', $attempt->observed_outcome);
    }

    public function test_missing_key_invalid_snapshot_and_missing_photo_do_not_send_data(): void
    {
        $attempt = $this->attempt();
        config(['inspections.roboflow.api_key' => '']);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        config(['inspections.roboflow.api_key' => 'server-test-key']);
        $attempt = $this->attempt();
        $attempt->forceFill(['analysis_config' => [...$attempt->analysis_config, 'workspace' => '../attacker']])->save();
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        $attempt = $this->attempt();
        Storage::disk('inspection-photos')->delete($attempt->photo->path);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('photo_unavailable', $attempt->refresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_outages_have_bounded_retries_and_auth_or_redirect_errors_are_terminal(): void
    {
        $attempt = $this->attempt();
        $this->fakeResponse(['error' => 'private server-test-key'], 503);
        for ($run = 1; $run <= 3; $run++) {
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame($run, $attempt->refresh()->analysis_runs);
            $this->travel(61)->seconds();
        }
        $this->assertSame('error', $attempt->status);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $attempt->submission_key));
        $this->assertStringNotContainsString('server-test-key', $attempt->toJson());
        foreach ([401, 403, 302, 429] as $status) {
            $attempt = $this->attempt();
            $this->fakeResponse('private-provider-error', $status);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame($status === 429 ? 'uploaded' : 'error', $attempt->refresh()->status);
        }
    }

    public function test_connection_exception_is_sanitized_and_can_recover(): void
    {
        $attempt = $this->attempt();
        Http::fake(['*' => Http::failedConnection('private exception with server-test-key')]);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('service_unavailable', $attempt->refresh()->error_code);
        $this->travel(31)->seconds();
        $this->fakeResponse($this->response($attempt));
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('analysed', $attempt->refresh()->status);
        $this->assertNull($attempt->error_code);
    }

    public function test_old_or_closed_shift_result_cannot_overwrite_newer_check_state(): void
    {
        config(['inspections.roboflow.automatic_decisions' => true]);
        $check = $this->check(['automatic_decisions' => true]);
        $old = $this->attempt($check);
        $latest = $this->attempt($check);
        $this->fakeResponse($this->response($latest, ['top' => 'empty', 'confidence' => 0.99,
            'predictions' => [['class' => 'empty', 'confidence' => 0.99]]]));
        app(InspectionAnalysis::class)->run($latest->id);
        $this->fakeResponse($this->response($old));
        app(InspectionAnalysis::class)->run($old->id);
        $this->assertSame(CheckStatus::Failed, $check->fresh()->status);
        $attempt = $this->attempt($this->check(['automatic_decisions' => true]));
        $attempt->check->shift->forceFill(['status' => ShiftStatus::Completed])->save();
        $this->fakeResponse($this->response($attempt));
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('passed', $attempt->refresh()->result);
        $this->assertSame(CheckStatus::Pending, $attempt->check->fresh()->status);
    }
}
