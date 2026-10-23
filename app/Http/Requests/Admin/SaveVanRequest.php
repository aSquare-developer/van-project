<?php

namespace App\Http\Requests\Admin;

use App\Models\Van;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveVanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $van = $this->route('van');

        return $van instanceof Van ? $this->user()->can('update', $van) : $this->user()->can('create', Van::class);
    }

    protected function prepareForValidation(): void
    {
        $registration = strtoupper(trim((string) $this->input('registration')));
        $this->merge(['registration' => $registration === '' ? null : $registration]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'registration' => ['nullable', 'string', 'max:32', Rule::unique('vans')->ignore($this->route('van'))],
            'is_active' => ['required', 'boolean'],
        ];
    }
}
