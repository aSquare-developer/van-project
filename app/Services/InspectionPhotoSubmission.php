<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\ShiftStatus;
use App\Models\InspectionAttempt;
use App\Models\Shift;
use App\Models\ShiftCheck;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class InspectionPhotoSubmission
{
    public function __construct(private InspectionPhotoStore $photos, private AuditLogger $audit) {}

    public function submit(User $actor, ShiftCheck $check, UploadedFile $file, string $submissionKey): InspectionAttempt
    {
        $photo = null;
        try {
            return DB::transaction(function () use ($actor, $check, $file, $submissionKey, &$photo): InspectionAttempt {
                $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
                $shift = Shift::query()->lockForUpdate()->findOrFail($check->shift_id);
                $check = ShiftCheck::query()->lockForUpdate()->findOrFail($check->id);
                $check->setRelation('shift', $shift);
                Gate::forUser($actor)->authorize('submitPhoto', $check);
                $existing = InspectionAttempt::query()->where('submission_key', $submissionKey)->first();
                if ($existing) {
                    if ($existing->shift_check_id !== $check->id || $existing->photo->sha256 !== hash_file('sha256', $file->getRealPath())) {
                        throw ValidationException::withMessages(['photo' => 'Reload this item before submitting a different photo.']);
                    }

                    return $existing;
                }
                $photo = $this->photos->store($actor, $file, 'attempt');
                $attempt = new InspectionAttempt;
                $attempt->forceFill([
                    'shift_check_id' => $check->id, 'inspection_photo_id' => $photo->id,
                    'submission_key' => $submissionKey, 'status' => 'uploaded',
                ])->save();
                $check->forceFill(['status' => CheckStatus::Pending])->save();
                if ($shift->status !== ShiftStatus::InProgress) {
                    $shift->forceFill(['status' => ShiftStatus::InProgress])->save();
                }
                $this->audit->record('inspection.photo_submitted', $actor->id, resource: $attempt);

                return $attempt;
            });
        } catch (Throwable $exception) {
            if ($photo) {
                $this->photos->discard($photo);
            }
            throw $exception;
        }
    }
}
