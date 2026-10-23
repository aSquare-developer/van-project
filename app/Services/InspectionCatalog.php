<?php

namespace App\Services;

use App\Models\InspectionTemplate;
use App\Models\User;
use App\Models\Van;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InspectionCatalog
{
    public function __construct(private AuditLogger $audit) {}

    private function synchronized(User $actor, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation): mixed {
            DB::table('administration_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);

            return $operation($actor);
        }, 3);
    }

    public function saveVan(User $actor, array $data, ?Van $van = null): Van
    {
        return $this->synchronized($actor, function (User $actor) use ($data, $van): Van {
            $record = $van ? Van::query()->lockForUpdate()->findOrFail($van->id) : new Van;
            Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : Van::class);
            $event = $record->exists ? 'van.updated' : 'van.created';
            $record->fill([
                'name' => $data['name'],
                'registration' => $data['registration'] ?? null,
                'is_active' => $data['is_active'],
            ])->save();
            $this->audit->record($event, $actor->id, resource: $record);

            return $record;
        });
    }

    public function saveTemplate(User $actor, array $data, ?InspectionTemplate $template = null): InspectionTemplate
    {
        return $this->synchronized($actor, function (User $actor) use ($data, $template): InspectionTemplate {
            $record = $template ? InspectionTemplate::query()->lockForUpdate()->findOrFail($template->id) : new InspectionTemplate;
            Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : InspectionTemplate::class);
            $event = $record->exists ? 'template.updated' : 'template.created';
            $record->fill([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'],
            ])->save();
            $record->items()->delete();
            foreach (array_values($data['items']) as $index => $item) {
                $definition = config('inspections.checks.'.$item['check_key']);
                if (! is_array($definition) || empty($definition['expected_class'])) {
                    throw ValidationException::withMessages(['items' => 'Select a supported analysis check.']);
                }
                $record->items()->create([
                    'position' => $index + 1,
                    'title' => $item['title'],
                    'instructions' => $item['instructions'],
                    'check_key' => $item['check_key'],
                    'expected_class' => $definition['expected_class'],
                    'is_required' => $item['is_required'],
                ]);
            }
            $this->audit->record($event, $actor->id, resource: $record);

            return $record->load('items');
        });
    }
}
