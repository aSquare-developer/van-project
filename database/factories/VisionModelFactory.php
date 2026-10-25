<?php

namespace Database\Factories;

use App\Models\VisionModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisionModel> */
class VisionModelFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Silicone shelf', 'status' => 'active'];
    }
}
