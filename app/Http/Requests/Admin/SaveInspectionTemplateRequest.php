<?php

namespace App\Http\Requests\Admin;

use App\Models\InspectionTemplate;
use App\Services\InspectionPhotoStore;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveInspectionTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');

        return $template instanceof InspectionTemplate ? $this->user()->can('update', $template) : $this->user()->can('create', InspectionTemplate::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['required', 'boolean'],
            'items' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'items.*' => ['required', 'array:id,title,instructions,check_key,is_required,reference_photo,remove_reference_photo'],
            'items.*.id' => ['nullable', 'integer', 'distinct'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.instructions' => ['required', 'string', 'max:3000'],
            'items.*.check_key' => ['required', 'string', Rule::in(array_keys(config('inspections.checks', [])))],
            'items.*.is_required' => ['required', 'boolean'],
            'items.*.reference_photo' => ['nullable', ...InspectionPhotoStore::rules()],
            'items.*.remove_reference_photo' => ['sometimes', 'boolean'],
        ];
    }
}
