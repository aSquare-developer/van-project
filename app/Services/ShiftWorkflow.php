<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\ShiftDecision;
use App\Enums\ShiftStatus;
use App\Models\InspectionAttempt;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\ShiftTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShiftWorkflow
{
    public function __construct(private AuditLogger $audit) {}

    /** @return array{can_complete: bool, can_request_review: bool, blocking_reason: ?string, snapshot: array} */
    public function assessment(Shift $shift): array
    {
        $shift->loadMissing('checks.latestAttempt.photo');
        $required = $shift->checks->where('is_required', true);
        $snapshot = $shift->checks->map(fn (ShiftCheck $check): array => [
            'check_id' => $check->id, 'position' => $check->position, 'required' => $check->is_required,
            'attempt_id' => $check->latestAttempt?->id, 'result' => $check->status->value,
        ])->values()->all();
        $reason = null;
        if ($required->isEmpty()) {
            $reason = 'At least one required item must be configured. Contact an administrator.';
        } elseif ($shift->checks->contains(fn (ShiftCheck $check): bool => $check->latestAttempt && in_array($check->latestAttempt->status, ['uploaded', 'processing'], true))) {
            $reason = 'Wait for the latest photo analysis to finish, then refresh this page.';
        } elseif ($required->contains(fn (ShiftCheck $check): bool => ! $this->hasEvidence($check, $shift))) {
            $reason = 'Every required item needs a photo and a finished analysis. Open the unchecked items first.';
        }
        $returned = $shift->transitions()->where('decision', ShiftDecision::ReturnForCheck->value)->latest('id')->first();
        if (! $reason && $returned) {
            foreach ($returned->snapshot as $item) {
                $check = $shift->checks->firstWhere('id', $item['check_id']);
                if ($item['required'] && $item['result'] !== CheckStatus::Passed->value && (! $check?->latestAttempt || $check->latestAttempt->id <= $item['attempt_id'])) {
                    $reason = 'Retake every required item that was not confirmed when this inspection was returned.';
                    break;
                }
            }
        }
        $allPassed = $required->every(fn (ShiftCheck $check): bool => $check->status === CheckStatus::Passed);
        $editable = in_array($shift->status, [ShiftStatus::Assigned, ShiftStatus::InProgress, ShiftStatus::ReturnedForCheck], true);

        return [
            'can_complete' => $editable && ! $reason && $allPassed,
            'can_request_review' => $editable && ! $reason && ! $allPassed,
            'blocking_reason' => $reason,
            'snapshot' => $snapshot,
        ];
    }

    /** @param array<string, mixed> $data */
    public function act(User $actor, Shift $shift, ShiftDecision $decision, array $data): ShiftTransition
    {
        return DB::transaction(function () use ($actor, $shift, $decision, $data): ShiftTransition {
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $shift = Shift::query()->lockForUpdate()->findOrFail($shift->id);
            Gate::forUser($actor)->authorize($decision->isWorkerAction() ? 'submit' : 'review', $shift);
            $data = Validator::make($data, [
                'request_key' => ['required', 'uuid'],
                'revision' => ['required', 'integer', 'min:0', 'max:4294967294'],
                'comment' => [$decision === ShiftDecision::Complete ? 'prohibited' : 'required', 'string', 'max:2000'],
            ])->validate();
            $comment = isset($data['comment']) ? trim($data['comment']) : null;
            if ($decision !== ShiftDecision::Complete && $comment === '') {
                throw ValidationException::withMessages(['comment' => 'Explain why the inspection needs this decision.']);
            }
            $existing = $shift->transitions()->where('request_key', $data['request_key'])->first();
            if ($existing) {
                if ($existing->actor_id !== $actor->id || $existing->decision !== $decision || $existing->comment !== $comment || $existing->revision_before !== (int) $data['revision']) {
                    throw ValidationException::withMessages(['request_key' => 'This action key has already been used. Reload the page.']);
                }

                return $existing;
            }
            if ($shift->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'The inspection changed. Reload the page before deciding.']);
            }
            $shift->setRelation('checks', $shift->checks()->lockForUpdate()->get());
            $assessment = $this->assessment($shift);
            if ($decision->isWorkerAction()) {
                $allowed = $decision === ShiftDecision::Complete ? $assessment['can_complete'] : $assessment['can_request_review'];
                if (! $allowed) {
                    throw ValidationException::withMessages(['shift' => $assessment['blocking_reason'] ?? 'This action is not available for the current inspection state.']);
                }
            } else {
                $request = $shift->transitions()->where('decision', ShiftDecision::RequestReview->value)->latest('id')->first();
                if ($shift->status !== ShiftStatus::AwaitingReview || ! $request || $request->revision_after !== $shift->revision || $request->snapshot != $assessment['snapshot'] || ($decision === ShiftDecision::ApproveWithRemarks && $assessment['blocking_reason'])) {
                    throw ValidationException::withMessages(['shift' => 'Only an unchanged inspection awaiting review can be decided. Reload the page.']);
                }
            }
            $target = match ($decision) {
                ShiftDecision::Complete => ShiftStatus::Completed,
                ShiftDecision::RequestReview => ShiftStatus::AwaitingReview,
                ShiftDecision::ApproveWithRemarks => ShiftStatus::CompletedWithRemarks,
                ShiftDecision::ReturnForCheck => ShiftStatus::ReturnedForCheck,
            };
            $transition = new ShiftTransition;
            $transition->forceFill([
                'shift_id' => $shift->id, 'actor_id' => $actor->id, 'request_key' => $data['request_key'],
                'decision' => $decision, 'from_status' => $shift->status, 'to_status' => $target,
                'revision_before' => $shift->revision, 'revision_after' => $shift->revision + 1,
                'comment' => $comment, 'snapshot' => $assessment['snapshot'],
            ])->save();
            $shift->forceFill([
                'status' => $target, 'revision' => $shift->revision + 1,
                'completed_at' => in_array($target, [ShiftStatus::Completed, ShiftStatus::CompletedWithRemarks], true) ? now() : null,
            ])->save();
            $this->audit->record('shift.'.$decision->value, $actor->id, $shift->worker_id, resource: $transition);

            return $transition;
        }, 3);
    }

    private function hasEvidence(ShiftCheck $check, Shift $shift): bool
    {
        $attempt = $check->latestAttempt;
        if (! $attempt || ! $attempt->analysed_at || $attempt->result !== $check->status->value
            || $attempt->check_key !== $check->check_key || $attempt->expected_class !== $check->expected_class
            || $attempt->photo->uploaded_by !== $shift->worker_id || $attempt->photo->purged_at
            || ! Storage::disk('inspection-photos')->exists($attempt->photo->path)) {
            return false;
        }
        if ($check->status === CheckStatus::AnalysisError) {
            return $attempt->status === 'error' && $attempt->error_code !== null;
        }
        if ($attempt->status !== 'analysed' || ! $attempt->model_version || $attempt->confidence === null || $attempt->confidence < 0 || $attempt->confidence > 1) {
            return false;
        }

        return match ($check->status) {
            CheckStatus::Passed => $this->confidentClass($attempt, $check->expected_class),
            CheckStatus::Failed => $attempt->absent_class && $this->confidentClass($attempt, $attempt->absent_class),
            CheckStatus::Inconclusive => true,
            default => false,
        };
    }

    private function confidentClass(InspectionAttempt $attempt, string $class): bool
    {
        return $attempt->detected_class === $class && $attempt->confidence >= $attempt->confidence_threshold;
    }
}
