<?php

namespace Database\Factories;

use App\Models\InspectionTemplate;
use App\Models\InspectionTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InspectionTemplateItem> */
class InspectionTemplateItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'inspection_template_id' => InspectionTemplate::factory(),
            'position' => 1,
            'title' => 'Silicone cartridges',
            'instructions' => 'Photograph the complete cartridge holder.',
            'check_key' => 'silicone_holder',
            'expected_class' => 'holder_stocked',
            'is_required' => true,
        ];
    }
}
