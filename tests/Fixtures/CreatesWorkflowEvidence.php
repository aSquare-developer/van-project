<?php

namespace Tests\Fixtures;

use App\Enums\CheckStatus;
use App\Models\InspectionAttempt;
use App\Models\ShiftCheck;
use App\Services\InspectionPhotoSubmission;
use Illuminate\Support\Str;

trait CreatesWorkflowEvidence
{
    use CreatesInspectionPhotos;

    private function evidence(?ShiftCheck $check = null, CheckStatus $result = CheckStatus::Passed): InspectionAttempt
    {
        $check ??= ShiftCheck::factory()->create();
        config(['inspections.checks.silicone_holder.model_version' => 'holder/1']);
        $attempt = app(InspectionPhotoSubmission::class)->submit($check->shift->worker, $check, $this->photo(), Str::uuid()->toString());
        $attempt->forceFill([
            'status' => $result === CheckStatus::AnalysisError ? 'error' : 'analysed',
            'result' => $result->value, 'analysed_at' => now(),
            'confidence' => $result === CheckStatus::AnalysisError ? null : ($result === CheckStatus::Inconclusive ? 0.5 : 0.95),
            'detected_class' => $result === CheckStatus::Failed ? 'holder_empty' : ($result === CheckStatus::AnalysisError ? null : 'holder_stocked'),
            'boxes' => [], 'error_code' => $result === CheckStatus::AnalysisError ? 'service_unavailable' : null,
        ])->save();
        $check->refresh()->forceFill(['status' => $result])->save();
        $check->shift->increment('revision');

        return $attempt;
    }
}
