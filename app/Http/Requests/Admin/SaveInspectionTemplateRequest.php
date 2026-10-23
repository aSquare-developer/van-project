<?php

namespace App\Http\Requests\Admin;

use App\Models\InspectionTemplate;
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
            'items.*' => ['required', 'array:title,instructions,check_key,is_required'],
            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.instructions' => ['required', 'string', 'max:3000'],
            'items.*.check_key' => ['required', 'string', Rule::in(array_keys(config('inspections.checks', [])))],
            'items.*.is_required' => ['required', 'boolean'],
        ];
    }
}
