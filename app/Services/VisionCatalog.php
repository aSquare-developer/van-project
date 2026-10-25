<?php

namespace App\Services;

use App\Http\Requests\Admin\SaveVisionModelRequest;
use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use App\Models\User;
use App\Models\VisionModel;
use App\Models\VisionModelRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VisionCatalog
{
    public function __construct(private AuditLogger $audit) {}

    public function save(User $actor, array $data, ?VisionModel $model = null): VisionModel
    {
        return DB::transaction(function () use ($actor, $data, $model): VisionModel {
            DB::table('administration_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);
            $record = $model ? VisionModel::query()->lockForUpdate()->findOrFail($model->id) : new VisionModel;
            Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : VisionModel::class);
            $values = Validator::make($data, SaveVisionModelRequest::catalogRules())->validate();
            $latest = $record->exists ? $record->latestRevision()->first() : null;
            if ($record->exists && (int) ($data['base_revision'] ?? 0) !== $latest?->id) {
                throw ValidationException::withMessages(['name' => 'The model changed. Reload this page before saving.']);
            }
            $route = config('inspections.roboflow.workflow_route', 'infer');
            if (! in_array($route, ['infer', 'workspace'], true)) {
                throw ValidationException::withMessages(['workflow_id' => 'The server workflow route is not configured correctly.']);
            }
            $settings = [
                'provider' => 'roboflow', 'contract_version' => 1, 'api_route' => $route,
                ...array_diff_key($values, array_flip(['name', 'status', 'base_revision'])),
                'confidence_threshold' => (float) $values['confidence_threshold'],
                'automatic_decisions' => (bool) $values['automatic_decisions'],
            ];
            $record->forceFill(['name' => $values['name'], 'status' => $values['status']])->save();
            if (! $latest || $latest->settings != $settings) {
                $revision = new VisionModelRevision;
                $revision->forceFill(['vision_model_id' => $record->id, 'version' => ($latest?->version ?? 0) + 1, 'settings' => $settings])->save();
                $this->audit->record('vision_model.revision_created', $actor->id, resource: $revision);
            }
            $this->audit->record('vision_model.saved', $actor->id, resource: $record);

            return $record->load('latestRevision');
        }, 3);
    }

    /** @return array<string, array<string, mixed>> */
    public function choices(?InspectionTemplate $template = null): array
    {
        $choices = [];
        $referenced = $template?->exists ? $template->items()->whereNotNull('vision_model_revision_id')->pluck('vision_model_revision_id') : collect();
        $revisions = VisionModelRevision::query()->with('model')
            ->where(fn ($query) => $query->whereHas('model', fn ($models) => $models->where('status', 'active'))->orWhereIn('id', $referenced))
            ->orderByDesc('id')->get();
        foreach ($revisions as $revision) {
            $choices['rf_revision_'.$revision->id] = [
                'name' => $revision->model->name.' · revision '.$revision->version.($revision->model->status === 'active' ? '' : ' (unavailable for assignment)'),
            ];
        }

        return [...$choices, ...config('inspections.checks', [])];
    }

    /** @return array<string, mixed> */
    public function definition(string $key, ?InspectionTemplateItem $existing = null): array
    {
        if (preg_match('/\Arf_revision_([1-9][0-9]*)\z/', $key, $matches)) {
            $revision = VisionModelRevision::query()->with('model')->find($matches[1]);
            if (! $revision || ($revision->model->status !== 'active' && $existing?->vision_model_revision_id !== $revision->id)) {
                throw ValidationException::withMessages(['items' => 'Select an active analysis model.']);
            }

            return [
                ...$revision->settings, 'revision_id' => $revision->id,
                'expected_class' => $revision->settings['passing_class'],
                'absent_class' => $revision->settings['failing_class'],
                'model_version' => $revision->settings['model_id'],
            ];
        }
        $definition = config('inspections.checks.'.$key);
        if (! is_array($definition) || empty($definition['expected_class'])) {
            throw ValidationException::withMessages(['items' => 'Select a supported analysis check.']);
        }

        return ['provider' => 'custom', ...$definition];
    }
}
