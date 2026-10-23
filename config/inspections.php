<?php

return [
    'photo_retention_days' => 90,
    'orphan_reference_days' => 7,
    'checks' => [
        'silicone_holder' => [
            'name' => 'Silicone cartridge holder',
            'expected_class' => 'holder_stocked',
        ],
    ],
];
