<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveVisionModelRequest;
use App\Models\InspectionAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class RoboflowVisionClient
{
    /** @return array<string, mixed> */
    public function analyse(InspectionAttempt $attempt): array
    {
        $settings = $attempt->analysis_config;
        $key = config('inspections.roboflow.api_key');
        $rules = array_diff_key(SaveVisionModelRequest::catalogRules(), array_flip(['name', 'status', 'base_revision']));
        if (! is_array($settings) || Validator::make($settings, $rules)->fails()
            || ($settings['provider'] ?? null) !== 'roboflow' || ($settings['contract_version'] ?? null) !== 1
            || ! in_array($settings['api_route'] ?? null, ['infer', 'workspace'], true)
            || ! is_bool($settings['automatic_decisions']) || ! is_string($key) || trim($key) === ''
            || $settings['model_id'] !== $attempt->model_version || $settings['passing_class'] !== $attempt->expected_class
            || $settings['failing_class'] !== $attempt->absent_class
            || abs((float) $settings['confidence_threshold'] - $attempt->confidence_threshold) > 0.000001) {
            return ['error' => 'configuration_error', 'retryable' => false];
        }
        $responseBody = null;
        try {
            $photo = $attempt->photo;
            $disk = Storage::disk('inspection-photos');
            if (! $photo || $photo->purged_at || ! in_array($photo->mime, ['image/jpeg', 'image/png', 'image/webp'], true)
                || ! $disk->exists($photo->path) || $disk->size($photo->path) > 10 * 1024 * 1024) {
                return ['error' => 'photo_unavailable', 'retryable' => false];
            }
            $bytes = $disk->get($photo->path);
            if (! is_string($bytes) || $bytes === '') {
                return ['error' => 'photo_unavailable', 'retryable' => false];
            }
            $workspace = rawurlencode($settings['workspace']);
            $workflow = rawurlencode($settings['workflow_id']);
            $path = $settings['api_route'] === 'infer' ? '/infer/workflows/'.$workspace.'/'.$workflow : '/'.$workspace.'/workflows/'.$workflow;
            $response = Http::withToken($key)->acceptJson()->connectTimeout(5)->timeout(30)
                ->withoutRedirecting()->withOptions(['stream' => true])
                ->withHeaders(['Idempotency-Key' => $attempt->submission_key])
                ->post('https://serverless.roboflow.com'.$path, [
                    'inputs' => [$settings['input_name'] => ['type' => 'base64', 'value' => base64_encode($bytes)]],
                ]);
            $responseBody = $response->toPsrResponse()->getBody();
            if ($response->status() !== 200) {
                return ['error' => 'service_unavailable', 'retryable' => $response->status() === 429 || $response->serverError()];
            }
            $body = '';
            while (! $responseBody->eof() && strlen($body) < 65537) {
                $chunk = $responseBody->read(65537 - strlen($body));
                if ($chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            $data = strlen($body) <= 65536 ? json_decode($body, true) : null;

            return $this->normalize($attempt, $settings, $data);
        } catch (Throwable) {
            // HTTP exception text can contain private photographs and authentication headers.
            return ['error' => 'service_unavailable', 'retryable' => true];
        } finally {
            $responseBody?->close();
        }
    }

    /** @return array<string, mixed> */
    private function normalize(InspectionAttempt $attempt, array $settings, mixed $data): array
    {
        $invalid = ['error' => 'invalid_response', 'retryable' => false];
        if (! is_array($data) || ! is_array($data['outputs'] ?? null) || ! array_is_list($data['outputs']) || count($data['outputs']) !== 1) {
            return $invalid;
        }
        $prediction = $data['outputs'][0][$settings['output_name']] ?? null;
        if (! is_array($prediction) || ($prediction['prediction_type'] ?? null) !== 'classification'
            || ($prediction['resolved_model']['model_id'] ?? null) !== $attempt->model_version
            || ! is_string($prediction['top'] ?? null) || ! $this->score($prediction['confidence'] ?? null)
            || ! is_array($prediction['predictions'] ?? null) || ! array_is_list($prediction['predictions'])
            || count($prediction['predictions']) < 1 || count($prediction['predictions']) > 100
            || ! is_int($prediction['image']['width'] ?? null) || ! is_int($prediction['image']['height'] ?? null)
            || $prediction['image']['width'] < 1 || $prediction['image']['height'] < 1
            || $prediction['image']['width'] > 65536 || $prediction['image']['height'] > 65536) {
            return $invalid;
        }
        $known = [$settings['passing_class'], $settings['failing_class'], ...$settings['uncertain_classes']];
        $scores = [];
        foreach ($prediction['predictions'] as $item) {
            if (! is_array($item) || ! is_string($item['class'] ?? null) || ! in_array($item['class'], $known, true)
                || array_key_exists($item['class'], $scores) || ! $this->score($item['confidence'] ?? null)) {
                return $invalid;
            }
            $scores[$item['class']] = (float) $item['confidence'];
        }
        $top = $prediction['top'];
        $confidence = (float) $prediction['confidence'];
        if (! array_key_exists($top, $scores) || abs($scores[$top] - $confidence) > 0.0001 || max($scores) > $confidence + 0.0001) {
            return $invalid;
        }
        $tied = count(array_filter($scores, fn (float $score): bool => abs($score - $confidence) <= 0.0001)) > 1;
        $outcome = $tied ? 'uncertain' : match ($top) {
            $settings['passing_class'] => 'present',
            $settings['failing_class'] => 'absent',
            default => 'uncertain',
        };
        $automatic = $settings['automatic_decisions'] && config('inspections.roboflow.automatic_decisions') === true;
        $decisive = $automatic && $confidence >= $attempt->confidence_threshold && $outcome !== 'uncertain';

        return [
            'result' => $decisive ? ($outcome === 'present' ? 'passed' : 'failed') : 'inconclusive',
            'detected_class' => $top, 'confidence' => $confidence, 'boxes' => [],
            'observed_outcome' => $outcome, 'decision_mode' => $automatic ? 'automatic' : 'advisory',
        ];
    }

    private function score(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 1;
    }
}
