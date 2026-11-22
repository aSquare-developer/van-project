<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Models\InspectionAttempt;

class AttemptSummary
{
    private const RETAKE = 'Retake the photo in good light with the whole item in the frame.';

    /** @return array{headline: string, detail: string} */
    public function describe(InspectionAttempt $attempt): array
    {
        $config = $attempt->analysis_config ?? [];
        $detection = ($config['analysis_type'] ?? null) === 'object_detection';
        $target = (string) $attempt->expected_class;
        $class = (string) $attempt->detected_class;
        $found = (int) $attempt->detected_count;
        $minimum = (int) ($config['min_count'] ?? 1);
        $score = $attempt->confidence === null ? null : $this->percent($attempt->confidence);

        return match ($attempt->result) {
            'passed' => [
                'headline' => 'Check passed'.($score ? " with a score of {$score}." : '.'),
                'detail' => $detection ? "Found {$found} × {$target} (at least {$minimum} required)." : "The model recognised “{$class}”.",
            ],
            'failed' => [
                'headline' => $detection
                    ? ($found === 0 ? "Check failed: no {$target} found." : "Check failed: found {$found} × {$target}, at least {$minimum} required.")
                    : "Check failed: the model sees “{$class}” instead of “{$target}”.",
                'detail' => 'If the item is in place, retake the photo with it clearly in the frame.',
            ],
            'inconclusive' => $this->inconclusive($attempt, $detection, $target, $class, $found, $minimum, $score),
            'analysis_error' => [
                'headline' => 'Analysis could not be completed.',
                'detail' => 'Analysis is unavailable. Contact your administrator or submit a new photo when the service is available.',
            ],
            default => ['headline' => CheckStatus::tryFrom((string) $attempt->result)?->label() ?? 'Not checked', 'detail' => CheckStatus::tryFrom((string) $attempt->result)?->description() ?? ''],
        };
    }

    /** @return array{headline: string, detail: string} */
    private function inconclusive(InspectionAttempt $attempt, bool $detection, string $target, string $class, int $found, int $minimum, ?string $score): array
    {
        if ($attempt->decision_mode === 'advisory') {
            $observed = $detection
                ? "The model found {$found} × {$target} (at least {$minimum} required)"
                : "The model sees “{$class}”";

            return [
                'headline' => 'Waiting for administrator review.',
                'detail' => $observed.($score && $attempt->detected_class !== null ? " with a score of {$score}" : '').'. The result is not final until an administrator confirms it.',
            ];
        }
        if ($attempt->confidence !== null && $attempt->confidence < $attempt->confidence_threshold) {
            return [
                'headline' => "Cannot verify: score {$score} is below the required {$this->percent($attempt->confidence_threshold, true)}.",
                'detail' => self::RETAKE,
            ];
        }

        return [
            'headline' => 'Cannot verify: the model could not decide clearly.',
            'detail' => ($detection ? "Found {$found} × {$target} with enough confidence, other detections were too weak. " : '').self::RETAKE,
        ];
    }

    private function percent(float $value, bool $trimWholeNumbers = false): string
    {
        $text = number_format($value * 100, 1);

        return ($trimWholeNumbers ? preg_replace('/\.0$/', '', $text) : $text).'%';
    }
}
