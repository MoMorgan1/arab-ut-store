<?php

return [
    'cart' => [
        'guest_claim_retention_hours' => max(
            24,
            (int) env('COINS_GUEST_CLAIM_RETENTION_HOURS', 24),
            (int) ceil(((int) env('SESSION_LIFETIME', 120)) / 60),
        ),
        'rate_limit_per_minute' => (int) env('COINS_CART_RATE_LIMIT_PER_MINUTE', 10),
    ],

    /*
     * A pricing run lands every hour and stores its whole snapshot, so without a
     * cutoff price_runs and the rules it supersedes grow forever. A month is long
     * enough to answer why a price was what it was, and short enough that the
     * tables stay small. The run behind the live prices is never deleted.
     */
    'pricing' => [
        'retention_days' => (int) env('COINS_PRICING_RETENTION_DAYS', 30),
    ],
    /*
     * The step widens as the quantity climbs. A single increment cannot serve a
     * range from thousands to twenty million: fine enough at the bottom leaves
     * the slider crawling at three million, coarse enough at the top skips every
     * small order. Each band must divide evenly by its own step.
     *
     * The floor cannot drop below the lowest multiplier the pricing run
     * publishes — today 50,000 — or a quantity arrives with no rate to price it.
     * Lowering it means n8n covering the new range first.
     *
     * These are defaults. The live values are editable from the admin.
     */
    'quantity' => [
        'minimum' => 50_000,

        // What a customer may actually buy: any multiple of this between the
        // floor and the ceiling. The bands below only decide where the slider
        // stops, so every band step has to be a multiple of this value.
        'roundingUnit' => 5_000,
        'tiers' => [
            ['upTo' => 500_000, 'step' => 10_000],
            ['upTo' => 2_000_000, 'step' => 50_000],
            ['upTo' => 20_000_000, 'step' => 250_000],
        ],
        'presets' => [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
    ],
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
            'maximum' => 20_000_000,
            'deliveries' => [],
        ],
    ],
];
