<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\ShiftStatus;
use App\Jobs\AnalyseInspectionAttempt;
use App\Models\InspectionAttempt;
use App\Models\Shift;
use App\Models\ShiftCheck;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class InspectionAnalysis
{
    public function __construct(private VisionClient $client, private AuditLogger $audit) {}

    public function enqueue(int $attemptId): bool
    {
        try {
            return DB::transaction(function () use ($attemptId): bool {
                $attempt = InspectionAttempt::query()->lockForUpdate()->find($attemptId);
                if (! $attempt || ! in_array($attempt->status, ['uploaded', 'processing'], true)
                    || ($attempt->retry_at && $attempt->retry_at->isFuture())
                    || ($attempt->dispatched_at && $attempt->dispatched_at->gt(now()->subMinutes(2)))
                    || ($attempt->status === 'processing' && $attempt->started_at?->gt(now()->subMinutes(2)))) {
                    return false;
                }
                $attempt->forceFill(['dispatched_at' => now()])->save();
                Bus::dispatch((new AnalyseInspectionAttempt($attemptId))->onConnection('database')->onQueue('inspections'));

                return true;
            });
        } catch (Throwable) {
            Log::warning('Inspection analysis dispatch unavailable.', ['attempt_id' => $attemptId]);

            return false;
        }
    }

    public function run(int $attemptId): void
    {
        $exhausted = false;
        $attempt = DB::transaction(function () use ($attemptId, &$exhausted): ?InspectionAttempt {
            $attempt = InspectionAttempt::query()->lockForUpdate()->find($attemptId);
            if (! $attempt || ! in_array($attempt->status, ['uploaded', 'processing'], true)
                || ($attempt->retry_at && $attempt->retry_at->isFuture())
                || ($attempt->status === 'processing' && $attempt->started_at?->gt(now()->subMinutes(2)))) {
                return null;
            }
            $exhausted = $attempt->analysis_runs >= 3;
            $attempt->forceFill([
                'status' => 'processing', 'started_at' => now(), 'processing_token' => Str::uuid()->toString(),
                'analysis_runs' => min(3, $attempt->analysis_runs + 1),
            ])->save();

            return $attempt;
        });
        if (! $attempt) {
            return;
        }
        $result = $exhausted
            ? ['error' => 'retries_exhausted', 'retryable' => false]
            : $this->client->analyse($attempt);
        $this->finish($attempt, $result);
    }

    /** @param array<string, mixed> $result */
    private function finish(InspectionAttempt $claimed, array $result): void
    {
        DB::transaction(function () use ($claimed, $result): void {
            $check = $claimed->check;
            $shift = Shift::query()->lockForUpdate()->findOrFail($check->shift_id);
            $check = ShiftCheck::query()->lockForUpdate()->findOrFail($check->id);
            $attempt = InspectionAttempt::query()->lockForUpdate()->findOrFail($claimed->id);
            if ($attempt->status !== 'processing' || $attempt->processing_token !== $claimed->processing_token) {
                return;
            }
            if (isset($result['error']) && $result['retryable'] && $attempt->analysis_runs < 3) {
                $attempt->forceFill([
                    'status' => 'uploaded', 'error_code' => $result['error'], 'processing_token' => null,
                    'retry_at' => now()->addSeconds($attempt->analysis_runs === 1 ? 30 : 60), 'dispatched_at' => null,
                ])->save();

                return;
            }
            $status = isset($result['error']) ? CheckStatus::AnalysisError : CheckStatus::from($result['result']);
            $attempt->forceFill([
                'status' => isset($result['error']) ? 'error' : 'analysed',
                'error_code' => $result['error'] ?? null, 'result' => $status->value,
                'detected_class' => $result['detected_class'] ?? null, 'confidence' => $result['confidence'] ?? null,
                'boxes' => $result['boxes'] ?? null, 'analysed_at' => now(), 'processing_token' => null, 'retry_at' => null,
                'observed_outcome' => $result['observed_outcome'] ?? null, 'summary' => $result['summary'] ?? null,
            ])->save();
            $latestId = $check->attempts()->max('id');
            if ((int) $latestId === $attempt->id && in_array($shift->status, [ShiftStatus::Assigned, ShiftStatus::InProgress, ShiftStatus::ReturnedForCheck], true)) {
                $check->forceFill(['status' => $status])->save();
            }
            $this->audit->record('inspection.analysis_finished', resource: $attempt);
        }, 3);
    }
}
