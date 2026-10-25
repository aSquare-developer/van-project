<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Shift::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'shift_date' => ['required', 'date_format:Y-m-d'],
            'worker_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->where('role', Role::User->value)],
            'van_id' => ['required', 'integer', Rule::exists('vans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'inspection_template_id' => ['required', 'integer', Rule::exists('inspection_templates', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }
}
