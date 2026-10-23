<?php

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Models\InspectionAttempt;
use App\Models\ShiftCheck;
use App\Services\InspectionAnalysis;
use App\Services\InspectionPhotoStore;
use App\Services\InspectionPhotoSubmission;
use App\Services\OpenAiVisionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Fixtures\CreatesInspectionPhotos;
use Tests\TestCase;

class OpenAiVisionTest extends TestCase
{
    use CreatesInspectionPhotos, RefreshDatabase;

    private array $httpOptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('inspection-photos');
        Http::preventStrayRequests();
        config([
            'inspections.vision.provider' => 'openai',
            'inspections.vision.openai.api_key' => 'test-private-key',
            'inspections.vision.openai.model' => 'vision-test-2026-01-01',
            'inspections.vision.openai.automatic_decisions' => false,
        ]);
    }

    private function attempt(bool $reference = true): InspectionAttempt
    {
        $check = ShiftCheck::factory()->create();
        if ($reference) {
            $photo = app(InspectionPhotoStore::class)->store($check->shift->worker, $this->photo('reference.png'), 'reference');
            $check->forceFill(['reference_photo_id' => $photo->id])->save();
        }

        return app(InspectionPhotoSubmission::class)->submit($check->shift->worker, $check, $this->photo(), Str::uuid()->toString());
    }

    private function response(array $observation = [], array $envelope = []): array
    {
        return array_replace([
            'status' => 'completed', 'model' => 'vision-test-2026-01-01',
            'output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed', 'content' => [[
                'type' => 'output_text', 'text' => json_encode(array_replace([
                    'target_visible' => true, 'outcome' => 'present', 'confidence' => 0.95,
                    'summary' => 'One cartridge is visible inside the holder.',
                ], $observation)),
            ]]]],
        ], $envelope);
    }

    private function fakeResponse(array|string $body, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['https://api.openai.com/v1/responses' => function (Request $request, array $options) use ($body, $status) {
            $this->httpOptions = $options;

            return Http::response($body, $status);
        }]);
    }

    public function test_pilot_sends_private_current_and_reference_images_but_does_not_pass_check(): void
    {
        $attempt = $this->attempt();
        $this->fakeResponse($this->response());
        app(InspectionAnalysis::class)->run($attempt->id);
        $attempt->refresh();
        $this->assertSame(CheckStatus::Inconclusive, $attempt->check->fresh()->status);
        $this->assertSame('present', $attempt->observed_outcome);
        $this->assertSame('holder_stocked', $attempt->detected_class);
        $this->assertSame('One cartridge is visible inside the holder.', $attempt->summary);
        $this->assertSame([], $attempt->boxes);
        $this->assertSame(OpenAiVisionClient::PROMPT_VERSION, $attempt->prompt_version);
        $this->assertFalse($attempt->automatic_decisions);
        Http::assertSent(function (Request $request) use ($attempt): bool {
            $this->assertFalse($this->httpOptions['allow_redirects']);
            $this->assertSame(5, $this->httpOptions['connect_timeout']);
            $this->assertSame(30, $this->httpOptions['timeout']);
            $this->assertFalse($request['store']);
            $this->assertSame(800, $request['max_output_tokens']);
            $this->assertTrue($request['text']['format']['strict']);
            $this->assertFalse($request['text']['format']['schema']['additionalProperties']);
            $content = $request['input'][0]['content'];
            $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']);
            $this->assertStringStartsWith('data:image/png;base64,', $content[3]['image_url']);
            $this->assertStringStartsWith('REFERENCE', $content[0]['text']);
            $this->assertStringStartsWith('CURRENT PHOTO', $content[2]['text']);
            $this->assertArrayNotHasKey('tools', $request->data());

            return $request->hasHeader('Authorization', 'Bearer test-private-key')
                && $request->hasHeader('Idempotency-Key', $attempt->submission_key);
        });
        $this->signIn($attempt->check->shift->worker)
            ->get('/shifts/'.$attempt->check->shift_id.'/checks/'.$attempt->shift_check_id)
            ->assertOk()->assertSee('Advisory analysis only')->assertSee('One cartridge')
            ->assertSee('not measured accuracy')->assertDontSee('test-private-key');
        $this->assertStringNotContainsString('data:image', $attempt->toJson());
    }

    public function test_automatic_decisions_require_visible_target_sufficient_confidence_and_explicit_enablement(): void
    {
        config(['inspections.vision.openai.automatic_decisions' => true]);
        foreach ([
            [true, 'present', 0.95, CheckStatus::Passed, 'holder_stocked'],
            [true, 'absent', 0.95, CheckStatus::Failed, 'holder_empty'],
            [true, 'uncertain', 0.95, CheckStatus::Inconclusive, null],
            [false, 'present', 0.99, CheckStatus::Inconclusive, null],
            [false, 'absent', 0.99, CheckStatus::Inconclusive, null],
            [true, 'present', 0.79, CheckStatus::Inconclusive, 'holder_stocked'],
        ] as [$visible, $outcome, $confidence, $expected, $class]) {
            $attempt = $this->attempt();
            $this->fakeResponse($this->response(['target_visible' => $visible, 'outcome' => $outcome, 'confidence' => $confidence]));
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame($expected, $attempt->check->fresh()->status);
            $this->assertSame($class, $attempt->refresh()->detected_class);
        }
    }

    public function test_provider_model_prompt_and_pilot_policy_are_snapshotted_at_submission(): void
    {
        $attempt = $this->attempt();
        config([
            'inspections.vision.provider' => 'custom',
            'inspections.vision.openai.model' => 'replacement-model',
            'inspections.vision.openai.automatic_decisions' => true,
        ]);
        $this->fakeResponse($this->response());
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('inconclusive', $attempt->refresh()->result);
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'vision-test-2026-01-01');
        $this->assertSame('openai', $attempt->vision_provider);
    }

    public function test_server_kill_switch_disables_automatic_decisions_for_already_queued_attempts(): void
    {
        config(['inspections.vision.openai.automatic_decisions' => true]);
        $attempt = $this->attempt();
        config(['inspections.vision.openai.automatic_decisions' => false]);
        $this->fakeResponse($this->response());
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('inconclusive', $attempt->refresh()->result);
    }

    public function test_queued_custom_attempt_is_not_redirected_to_openai_after_provider_switch(): void
    {
        config([
            'inspections.vision.provider' => 'custom',
            'inspections.vision.endpoint' => 'https://vision.example.test/v1/analyse',
            'inspections.vision.token' => 'custom-test-token',
            'inspections.checks.silicone_holder.model_version' => 'holder/1',
        ]);
        $attempt = $this->attempt();
        config(['inspections.vision.provider' => 'openai']);
        Http::fake(['https://vision.example.test/v1/analyse' => Http::response([
            'protocol_version' => 1, 'attempt_id' => $attempt->submission_key,
            'check_key' => 'silicone_holder', 'model_version' => 'holder/1',
            'outcome' => 'present', 'class' => 'holder_stocked', 'confidence' => 0.95, 'boxes' => [],
        ])]);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('passed', $attempt->refresh()->result);
        $this->assertSame('custom', $attempt->vision_provider);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://vision.example.test/v1/analyse');
    }

    public function test_missing_configuration_unsupported_prompt_and_unknown_provider_never_send_photos(): void
    {
        foreach ([
            ['model_version' => null], ['prompt_version' => 'unknown'], ['check_key' => 'unknown'],
            ['expected_class' => 'unknown'], ['vision_provider' => 'unknown'],
        ] as $invalid) {
            $attempt = $this->attempt();
            $attempt->forceFill($invalid)->save();
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        }
        config(['inspections.vision.openai.api_key' => '']);
        $attempt = $this->attempt();
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('configuration_error', $attempt->refresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_missing_or_purged_reference_and_missing_current_image_never_send_photos(): void
    {
        $attempt = $this->attempt(false);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('reference_unavailable', $attempt->refresh()->error_code);
        $attempt = $this->attempt();
        $attempt->check->referencePhoto->forceFill(['purged_at' => now()])->save();
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('photo_unavailable', $attempt->refresh()->error_code);
        $attempt = $this->attempt();
        Storage::disk('inspection-photos')->delete($attempt->photo->path);
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->assertSame('photo_unavailable', $attempt->refresh()->error_code);
        Http::assertNothingSent();
    }

    public function test_malformed_observations_cannot_pass_or_fail_a_check(): void
    {
        foreach ([
            ['target_visible' => 'true'], ['confidence' => '0.99'], ['confidence' => 1.1], ['outcome' => 'passed'],
            ['summary' => str_repeat('x', 601)], ['summary' => ''], ['unexpected' => 'secret'],
        ] as $invalid) {
            $attempt = $this->attempt();
            $this->fakeResponse($this->response($invalid));
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('invalid_response', $attempt->refresh()->error_code);
            $this->assertSame(CheckStatus::AnalysisError, $attempt->check->fresh()->status);
            $this->assertNull($attempt->summary);
        }
    }

    public function test_refused_incomplete_wrong_model_non_json_and_oversized_responses_are_terminal(): void
    {
        foreach ([
            $this->response([], ['status' => 'incomplete']), $this->response([], ['model' => 'wrong-model']),
            $this->response([], ['output' => [['type' => 'message', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'refusal', 'refusal' => 'private refusal']]]]]),
            $this->response([], ['output' => []]), '<html>private response</html>', str_repeat('x', 65537),
        ] as $invalid) {
            $attempt = $this->attempt();
            $this->fakeResponse($invalid);
            app(InspectionAnalysis::class)->run($attempt->id);
            $this->assertSame('invalid_response', $attempt->refresh()->error_code);
            $this->assertSame('error', $attempt->status);
            $this->assertStringNotContainsString('private', $attempt->toJson());
        }
    }

    public function test_provider_outages_retry_and_auth_or_redirect_failures_are_terminal(): void
    {
        foreach ([429, 503, 401, 302] as $status) {
            $attempt = $this->attempt();
            $this->fakeResponse(['error' => 'private token'], $status);
            app(InspectionAnalysis::class)->run($attempt->id);
            $attempt->refresh();
            $this->assertSame(in_array($status, [429, 503], true) ? 'uploaded' : 'error', $attempt->status);
            $this->assertStringNotContainsString('private token', $attempt->toJson());
        }
    }

    public function test_model_summary_is_escaped_in_the_browser(): void
    {
        $attempt = $this->attempt();
        $this->fakeResponse($this->response(['summary' => '<script>alert(1)</script>']));
        app(InspectionAnalysis::class)->run($attempt->id);
        $this->signIn($attempt->check->shift->worker)
            ->get('/shifts/'.$attempt->check->shift_id.'/checks/'.$attempt->shift_check_id)
            ->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }
}
