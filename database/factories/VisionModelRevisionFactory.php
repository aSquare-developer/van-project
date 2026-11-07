<?php

namespace Database\Factories;

use App\Models\VisionModel;
use App\Models\VisionModelRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<VisionModelRevision> */
class VisionModelRevisionFactory extends Factory
{
    public function objectDetection(): static
    {
        return $this->state(fn (array $attributes): array => ['settings' => [
            ...$attributes['settings'], 'analysis_type' => 'object_detection',
            'model_id' => 'lisettes-workspace/silicone-tube-rfdetr-small-1', 'workflow_id' => 'silicone-tube-detection-test',
            'passing_class' => 'tube', 'failing_class' => null, 'uncertain_classes' => [], 'min_count' => 2,
        ]]);
    }

    public function definition(): array
    {
        return [
            'vision_model_id' => VisionModel::factory(), 'version' => 1,
            'settings' => [
                'provider' => 'roboflow', 'contract_version' => 1, 'api_route' => 'infer',
                'analysis_type' => 'classification',
                'model_id' => 'lisettes-workspace/silicone-shelf-status-2-resnet18-t1',
                'workspace' => 'lisettes-workspace', 'workflow_id' => 'silicone-shelf-status-test',
                'input_name' => 'image', 'output_name' => 'predictions',
                'passing_class' => 'occupied', 'failing_class' => 'empty', 'uncertain_classes' => ['invalid'],
                'confidence_threshold' => 0.8, 'automatic_decisions' => false,
            ],
        ];
    }
}
