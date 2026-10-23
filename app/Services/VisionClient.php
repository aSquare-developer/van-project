<?php

namespace App\Services;

use App\Models\InspectionAttempt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Throwable;

class VisionClient
{
    /** @return array{error: string, retryable: bool}|array{result: string, detected_class: ?string, confidence: float, boxes: array} */
    public function analyse(InspectionAttempt $attempt): array
    {
        $endpoint = config('inspections.vision.endpoint');
        $token = config('inspections.vision.token');
        $url = is_string($endpoint) ? parse_url($endpoint) : false;
        if (! $url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || ! is_string($token) || $token === '' || ! $attempt->model_version || ! $attempt->check_key || ! $attempt->expected_class || ! $attempt->absent_class) {
            return ['error' => 'configuration_error', 'retryable' => false];
        }
        $stream = null;
        $responseBody = null;
        try {
            $photo = $attempt->photo;
            if ($photo->purged_at || ! Storage::disk('inspection-photos')->exists($photo->path)) {
                return ['error' => 'photo_unavailable', 'retryable' => false];
            }
            $stream = Storage::disk('inspection-photos')->readStream($photo->path);
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)->timeout(30)
                ->withoutRedirecting()->withOptions(['stream' => true])
                ->withHeaders(['Idempotency-Key' => $attempt->submission_key])
                ->attach('image', $stream, 'inspection-photo', ['Content-Type' => $photo->mime])
                ->post($endpoint, [
                    'protocol_version' => '1', 'attempt_id' => $attempt->submission_key,
                    'check_key' => $attempt->check_key, 'model_version' => $attempt->model_version,
                ]);
            $responseBody = $response->toPsrResponse()->getBody();
            if (! $response->successful()) {
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
            if (! is_array($data) || Validator::make($data, [
                'protocol_version' => ['required', 'integer', 'in:1'],
                'attempt_id' => ['required', 'string'],
                'check_key' => ['required', 'string'],
                'model_version' => ['required', 'string'],
                'outcome' => ['required', 'in:present,absent,uncertain'],
                'class' => ['present', 'nullable', 'string', 'max:255'],
                'confidence' => ['required', 'numeric', 'between:0,1'],
                'boxes' => ['present', 'array', 'max:100'],
                'boxes.*' => ['array:x,y,width,height'],
                'boxes.*.x' => ['required', 'numeric', 'between:0,1'],
                'boxes.*.y' => ['required', 'numeric', 'between:0,1'],
                'boxes.*.width' => ['required', 'numeric', 'gt:0', 'max:1'],
                'boxes.*.height' => ['required', 'numeric', 'gt:0', 'max:1'],
            ])->fails()) {
                return ['error' => 'invalid_response', 'retryable' => false];
            }
            if ($data['attempt_id'] !== $attempt->submission_key || $data['check_key'] !== $attempt->check_key || $data['model_version'] !== $attempt->model_version || ($data['outcome'] === 'present' && $data['class'] !== $attempt->expected_class) || ($data['outcome'] === 'absent' && $data['class'] !== $attempt->absent_class) || ($data['outcome'] === 'uncertain' && $data['class'] !== null)) {
                return ['error' => 'invalid_response', 'retryable' => false];
            }
            foreach ($data['boxes'] as $box) {
                if ($box['x'] + $box['width'] > 1 || $box['y'] + $box['height'] > 1) {
                    return ['error' => 'invalid_response', 'retryable' => false];
                }
            }

            return [
                'result' => $data['confidence'] < $attempt->confidence_threshold || $data['outcome'] === 'uncertain' ? 'inconclusive' : ($data['outcome'] === 'present' ? 'passed' : 'failed'),
                'detected_class' => $data['class'], 'confidence' => (float) $data['confidence'], 'boxes' => $data['boxes'],
            ];
        } catch (Throwable) {
            // HTTP exceptions may contain credentials and response bodies.
            return ['error' => 'service_unavailable', 'retryable' => true];
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            $responseBody?->close();
        }
    }
}
