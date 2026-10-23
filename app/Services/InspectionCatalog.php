<?php

namespace App\Services;

use App\Models\InspectionTemplate;
use App\Models\User;
use App\Models\Van;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

class InspectionCatalog
{
    public function __construct(private AuditLogger $audit, private InspectionPhotoStore $photos) {}

    private function synchronized(User $actor, Closure $operation): mixed
    {
        return DB::transaction(function () use ($actor, $operation): mixed {
            DB::table('administration_lock')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = User::query()->lockForUpdate()->findOrFail($actor->id);

            return $operation($actor);
        });
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
        $newPhotos = [];
        try {
            return $this->synchronized($actor, function (User $actor) use ($data, $template, &$newPhotos): InspectionTemplate {
                $record = $template ? InspectionTemplate::query()->lockForUpdate()->findOrFail($template->id) : new InspectionTemplate;
                Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : InspectionTemplate::class);
                $event = $record->exists ? 'template.updated' : 'template.created';
                $oldItems = $record->exists ? $record->items()->get()->keyBy('id') : collect();
                $record->fill([
                    'name' => $data['name'], 'description' => $data['description'] ?? null, 'is_active' => $data['is_active'],
                ])->save();
                $record->items()->delete();
                foreach (array_values($data['items']) as $index => $item) {
                    $definition = config('inspections.checks.'.$item['check_key']);
                    if (! is_array($definition) || empty($definition['expected_class'])) {
                        throw ValidationException::withMessages(['items' => 'Select a supported analysis check.']);
                    }
                    $oldItem = ! empty($item['id']) ? $oldItems->get($item['id']) : null;
                    if (! empty($item['id']) && ! $oldItem) {
                        throw ValidationException::withMessages(['items' => 'An item does not belong to this template. Reload the form.']);
                    }
                    $referenceId = ! empty($item['remove_reference_photo']) ? null : $oldItem?->reference_photo_id;
                    if (! empty($item['reference_photo'])) {
                        $photo = $this->photos->store($actor, $item['reference_photo'], 'reference', 'items.'.$index.'.reference_photo');
                        $newPhotos[] = $photo;
                        $referenceId = $photo->id;
                    }
                    $record->items()->create([
                        'position' => $index + 1, 'title' => $item['title'], 'instructions' => $item['instructions'],
                        'check_key' => $item['check_key'], 'expected_class' => $definition['expected_class'],
                        'is_required' => $item['is_required'], 'reference_photo_id' => $referenceId,
                    ]);
                }
                $this->audit->record($event, $actor->id, resource: $record);

                return $record->load('items');
            });
        } catch (Throwable $exception) {
            foreach ($newPhotos as $photo) {
                $this->photos->discard($photo);
            }
            throw $exception;
        }
    }
}
