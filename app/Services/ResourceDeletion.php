<?php

namespace App\Services;

use App\Models\InspectionAttempt;
use App\Models\InspectionTemplate;
use App\Models\Shift;
use App\Models\User;
use App\Models\Van;
use App\Models\VisionModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ResourceDeletion
{
    public function __construct(private AuditLogger $audit) {}

    public function delete(User $actor, Van|Shift|InspectionTemplate|VisionModel $record): void
    {
        DB::transaction(function () use ($actor, $record): void {
            DB::table('administration_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('delete', $record);
            if ($record instanceof Shift) {
                $checks = $record->checks()->lockForUpdate()->pluck('id');
                InspectionAttempt::query()->whereIn('shift_check_id', $checks)
                    ->whereIn('status', ['uploaded', 'processing'])->update([
                        'status' => 'cancelled', 'processing_token' => null, 'retry_at' => null,
                        'error_code' => 'shift_deleted',
                    ]);
                $record->forceFill(['revision' => $record->revision + 1])->save();
            }
            $this->audit->record(match (true) {
                $record instanceof Van => 'van.deleted',
                $record instanceof Shift => 'shift.deleted',
                $record instanceof InspectionTemplate => 'template.deleted',
                $record instanceof VisionModel => 'vision_model.deleted',
            }, $actor->id, resource: $record);
            $record->delete();
        }, 3);
    }
}
