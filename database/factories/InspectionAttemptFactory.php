<?php

namespace Database\Factories;

use App\Models\InspectionAttempt;
use App\Models\InspectionPhoto;
use App\Models\ShiftCheck;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<InspectionAttempt> */
class InspectionAttemptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_check_id' => ShiftCheck::factory(), 'inspection_photo_id' => InspectionPhoto::factory(),
            'submission_key' => Str::uuid(), 'status' => 'uploaded',
        ];
    }
}
