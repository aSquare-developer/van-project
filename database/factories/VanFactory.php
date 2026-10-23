<?php

namespace Database\Factories;

use App\Models\Van;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Van> */
class VanFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Van '.fake()->unique()->numerify('####'), 'registration' => fake()->unique()->bothify('###???'), 'is_active' => true];
    }
}
