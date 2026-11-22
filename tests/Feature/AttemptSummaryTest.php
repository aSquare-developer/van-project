<?php

namespace Tests\Feature;

use App\Models\InspectionAttempt;
use App\Services\AttemptSummary;
use Tests\TestCase;

class AttemptSummaryTest extends TestCase
{
    /** @param array<string, mixed> $attributes */
    private function summary(array $attributes): array
    {
        $attempt = new InspectionAttempt;
        $attempt->forceFill(['confidence_threshold' => 0.8, 'decision_mode' => 'automatic', ...$attributes]);

        return app(AttemptSummary::class)->describe($attempt);
    }

    private function detection(array $attributes): array
    {
        return $this->summary([
            'expected_class' => 'paper roll', 'detected_class' => 'paper roll', 'analysis_config' => ['analysis_type' => 'object_detection', 'min_count' => 1],
            ...$attributes,
        ]);
    }

    private function classification(array $attributes): array
    {
        return $this->summary([
            'expected_class' => 'occupied', 'absent_class' => 'empty', 'detected_class' => 'occupied', 'analysis_config' => ['analysis_type' => 'classification'],
            ...$attributes,
        ]);
    }

    public function test_passed_results_state_the_score_and_what_was_found(): void
    {
        $this->assertSame(['headline' => 'Check passed with a score of 82.8%.', 'detail' => 'Found 1 × paper roll (at least 1 required).'],
            $this->detection(['result' => 'passed', 'confidence' => 0.828, 'detected_count' => 1]));
        $this->assertSame(['headline' => 'Check passed with a score of 99.1%.', 'detail' => 'The model recognised “occupied”.'],
            $this->classification(['result' => 'passed', 'confidence' => 0.991]));
    }

    public function test_failed_results_state_the_reason(): void
    {
        $retake = 'If the item is in place, retake the photo with it clearly in the frame.';
        $this->assertSame(['headline' => 'Check failed: no paper roll found.', 'detail' => $retake],
            $this->detection(['result' => 'failed', 'detected_class' => null, 'confidence' => 0.0, 'detected_count' => 0]));
        $this->assertSame('Check failed: found 1 × paper roll, at least 2 required.',
            $this->detection(['result' => 'failed', 'confidence' => 0.9, 'detected_count' => 1, 'analysis_config' => ['analysis_type' => 'object_detection', 'min_count' => 2]])['headline']);
        $this->assertSame('Check failed: the model sees “empty” instead of “occupied”.',
            $this->classification(['result' => 'failed', 'detected_class' => 'empty', 'confidence' => 0.97])['headline']);
    }

    public function test_inconclusive_results_explain_whether_review_low_score_or_indecision_applies(): void
    {
        $this->assertSame(['headline' => 'Waiting for administrator review.', 'detail' => 'The model sees “occupied” with a score of 96.8%. The result is not final until an administrator confirms it.'],
            $this->classification(['result' => 'inconclusive', 'decision_mode' => 'advisory', 'confidence' => 0.968]));
        $this->assertSame('The model found 0 × paper roll (at least 1 required). The result is not final until an administrator confirms it.',
            $this->detection(['result' => 'inconclusive', 'decision_mode' => 'advisory', 'detected_class' => null, 'confidence' => 0.0, 'detected_count' => 0])['detail']);
        $this->assertSame('Cannot verify: score 66.6% is below the required 80%.',
            $this->classification(['result' => 'inconclusive', 'confidence' => 0.666])['headline']);
        $uncertain = $this->detection(['result' => 'inconclusive', 'confidence' => 0.95, 'detected_count' => 1, 'analysis_config' => ['analysis_type' => 'object_detection', 'min_count' => 2]]);
        $this->assertSame('Cannot verify: the model could not decide clearly.', $uncertain['headline']);
        $this->assertStringContainsString('Found 1 × paper roll with enough confidence', $uncertain['detail']);
    }

    public function test_errors_and_unknown_results_use_safe_wording(): void
    {
        $this->assertSame('Analysis could not be completed.', $this->summary(['result' => 'analysis_error'])['headline']);
        $this->assertSame('Not checked', $this->summary(['result' => 'pending'])['headline']);
    }
}
