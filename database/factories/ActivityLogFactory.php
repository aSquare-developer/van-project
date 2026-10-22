<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ActivityLogFactory extends Factory
{
    public function definition(): array
    {
        return ['event' => 'user.updated', 'actor_id' => null, 'subject_id' => null, 'metadata' => ['fields' => ['name']]];
    }
}
