<?php

namespace Database\Factories;

use App\Enums\CheckStatus;
use App\Models\Shift;
use App\Models\ShiftCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ShiftCheck> */
class ShiftCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'shift_id' => Shift::factory(),
            'position' => 1,
            'title' => 'Silicone cartridges',
            'instructions' => 'Photograph the complete cartridge holder.',
            'check_key' => 'silicone_holder',
            'expected_class' => 'holder_stocked',
            'is_required' => true,
            'status' => CheckStatus::Pending,
        ];
    }
}
