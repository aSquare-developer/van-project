<?php

return [
    'vision' => [
        'provider' => env('VISION_PROVIDER', 'custom'),
        'endpoint' => env('VISION_ENDPOINT'),
        'token' => env('VISION_TOKEN'),
        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('VISION_OPENAI_MODEL'),
            'automatic_decisions' => env('VISION_OPENAI_AUTOMATIC_DECISIONS', false),
        ],
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
