<?php

return [
    'quantity' => [
        'minimum' => 50_000,
        'increment' => 10_000,
        'presets' => [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
    ],
    'product_image_url' => '/images/store/coins/ut-coin-80.webp',
    'platforms' => [
        'playstation' => [
            'icon_urls' => [
                '/images/store/platforms/ps-logo-white-80.webp',
                '/images/store/platforms/xbox-logo-white-80.webp',
            ],
            'maximum' => 20_000_000,
            'deliveries' => [
                'normal' => [
                    'maximum' => 2_000_000,
                    'minutes_per_million' => 150,
                ],
                'fast' => [
                    'maximum' => 20_000_000,
                    'minutes_per_million' => 45,
                ],
            ],
        ],
        'pc' => [
            'icon_urls' => ['/images/store/platforms/pc-logo.svg'],
            'maximum' => 2_000_000,
            'deliveries' => [],
        ],
    ],
];
