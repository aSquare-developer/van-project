<?php

return [
    'vision' => ['endpoint' => env('VISION_ENDPOINT'), 'token' => env('VISION_TOKEN')],
    'roboflow' => [
        'api_key' => env('ROBOFLOW_API_KEY'),
        'workflow_route' => env('ROBOFLOW_WORKFLOW_ROUTE', 'infer'),
        'automatic_decisions' => env('ROBOFLOW_AUTOMATIC_DECISIONS', false),
    ],
    'photo_retention_days' => 90,
    'orphan_reference_days' => 7,
    'checks' => [
        'silicone_holder' => [
            'name' => 'Silicone cartridge holder',
            'expected_class' => 'holder_stocked',
            'absent_class' => 'holder_empty',
            'model_version' => env('VISION_SILICONE_MODEL_VERSION'),
            'confidence_threshold' => 0.8,
        ],
    ],
];
