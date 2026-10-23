<?php

namespace Database\Factories;

use App\Models\InspectionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InspectionTemplate> */
class InspectionTemplateFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'End of shift inspection', 'description' => 'Check the van before leaving.', 'is_active' => true];
    }
}
