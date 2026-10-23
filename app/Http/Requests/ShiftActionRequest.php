<?php

namespace App\Http\Requests;

use App\Enums\ShiftDecision;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShiftActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can($this->routeIs('admin.*') ? 'review' : 'submit', $this->route('shift'));
    }

    public function rules(): array
    {
        $needsComment = ! $this->routeIs('shifts.complete');

        return [
            'request_key' => ['required', 'uuid'],
            'revision' => ['required', 'integer', 'min:0', 'max:4294967294'],
            'comment' => [$needsComment ? 'required' : 'prohibited', 'string', 'max:2000'],
            'decision' => $this->routeIs('admin.*')
                ? ['required', Rule::in([ShiftDecision::ApproveWithRemarks->value, ShiftDecision::ReturnForCheck->value])]
                : ['prohibited'],
        ];
    }
}
