<?php

namespace App\Http\Requests\Admin;

use App\Models\VisionModel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVisionModelRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $classes = $this->input('uncertain_classes');
        if ($classes === null) {
            $this->merge(['uncertain_classes' => []]);
        } elseif (is_string($classes)) {
            $this->merge(['uncertain_classes' => array_values(array_filter(array_map('trim', explode(',', $classes)), fn (string $class): bool => $class !== ''))]);
        }
    }

    public function authorize(): bool
    {
        $model = $this->route('vision_model');

        return $model instanceof VisionModel ? $this->user()->can('update', $model) : $this->user()->can('create', VisionModel::class);
    }

    public function rules(): array
    {
        return self::catalogRules();
    }

    /** @return array<string, array> */
    public static function catalogRules(): array
    {
        $identifier = ['required', 'string', 'max:200', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\z/'];

        return [
            'name' => ['required', 'string', 'max:255'],
            'base_revision' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', Rule::in(['draft', 'active', 'disabled'])],
            'analysis_type' => ['required', Rule::in(['classification'])],
            'model_id' => ['required', 'string', 'max:255', 'regex:/\A[a-zA-Z0-9][a-zA-Z0-9_-]*\/[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/'],
            'workspace' => $identifier,
            'workflow_id' => $identifier,
            'input_name' => ['required', 'string', 'max:80', 'regex:/\A[a-zA-Z][a-zA-Z0-9_]*\z/'],
            'output_name' => ['required', 'string', 'max:80', 'regex:/\A[a-zA-Z][a-zA-Z0-9_]*\z/'],
            'passing_class' => ['required', 'string', 'max:100', 'different:failing_class'],
            'failing_class' => ['required', 'string', 'max:100'],
            'uncertain_classes' => ['present', 'array', 'list', 'max:30'],
            'uncertain_classes.*' => ['required', 'string', 'max:100', 'distinct', 'different:passing_class', 'different:failing_class'],
            'confidence_threshold' => ['required', 'numeric', 'between:0.01,1'],
            'automatic_decisions' => ['required', 'boolean'],
        ];
    }
}
