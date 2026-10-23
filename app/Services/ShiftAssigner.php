<?php

namespace App\Services;

use App\Enums\CheckStatus;
use App\Enums\Role;
use App\Enums\ShiftStatus;
use App\Models\InspectionTemplate;
use App\Models\Shift;
use App\Models\User;
use App\Models\Van;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ShiftAssigner
{
    public function __construct(private AuditLogger $audit) {}

    public function assign(User $actor, array $data): Shift
    {
        return DB::transaction(function () use ($actor, $data): Shift {
            DB::table('administration_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($actor)->authorize('create', Shift::class);
            $data = Validator::make($data, [
                'title' => ['required', 'string', 'max:255'],
                'shift_date' => ['required', 'date_format:Y-m-d'],
                'worker_id' => ['required', 'integer', 'exists:users,id'],
                'van_id' => ['required', 'integer', 'exists:vans,id'],
                'inspection_template_id' => ['required', 'integer', 'exists:inspection_templates,id'],
            ])->validate();
            $worker = User::query()->lockForUpdate()->findOrFail($data['worker_id']);
            $van = Van::query()->lockForUpdate()->findOrFail($data['van_id']);
            $template = InspectionTemplate::query()->lockForUpdate()->findOrFail($data['inspection_template_id']);
            if (! $worker->is_active || $worker->role !== Role::User) {
                throw ValidationException::withMessages(['worker_id' => 'Select an active worker.']);
            }
            if (! $van->is_active || ! $template->is_active) {
                throw ValidationException::withMessages(['inspection_template_id' => 'The van and template must be active.']);
            }
            $items = $template->items()->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['inspection_template_id' => 'The template must contain at least one item.']);
            }
            foreach ($items as $item) {
                $definition = config('inspections.checks.'.$item->check_key);
                if (! is_array($definition) || ($definition['expected_class'] ?? null) !== $item->expected_class) {
                    throw ValidationException::withMessages(['inspection_template_id' => 'The template contains an unsupported analysis check.']);
                }
            }
            $shift = new Shift;
            $shift->forceFill([
                ...$data,
                'assigned_by' => $actor->id,
                'template_name' => $template->name,
                'van_name' => $van->name,
                'van_registration' => $van->registration,
                'status' => ShiftStatus::Assigned,
            ])->save();
            foreach ($items as $item) {
                $check = $shift->checks()->make();
                $check->forceFill([
                    'position' => $item->position,
                    'title' => $item->title,
                    'instructions' => $item->instructions,
                    'check_key' => $item->check_key,
                    'expected_class' => $item->expected_class,
                    'is_required' => $item->is_required,
                    'status' => CheckStatus::Pending,
                ])->save();
            }
            $this->audit->record('shift.assigned', $actor->id, $worker->id);

            return $shift->load('checks');
        }, 3);
    }
}
