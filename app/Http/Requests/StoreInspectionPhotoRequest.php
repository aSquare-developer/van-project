<?php

namespace App\Http\Requests;

use App\Models\ShiftCheck;
use App\Services\InspectionPhotoStore;
use Illuminate\Foundation\Http\FormRequest;

class StoreInspectionPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $check = $this->route('check');

        return $check instanceof ShiftCheck && $this->user()->can('submitPhoto', $check);
    }

    public function rules(): array
    {
        return [
            'photo' => ['required', ...InspectionPhotoStore::rules()],
            'submission_key' => ['required', 'uuid'],
        ];
    }
}
