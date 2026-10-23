<?php

return [
    'vision' => ['endpoint' => env('VISION_ENDPOINT'), 'token' => env('VISION_TOKEN')],
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
