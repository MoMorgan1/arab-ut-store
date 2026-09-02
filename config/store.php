<?php

return [
    'default_locale' => 'ar',
    'locales' => ['ar', 'en'],
    'checkout_currency' => 'SAR',
    'default_display_currency' => 'SAR',
    'features' => [
        'my_account_enabled' => env('MY_ACCOUNT_ENABLED', true),
        'legacy_history_enabled' => env('LEGACY_HISTORY_ENABLED', false),
        'loyalty_enabled' => env('STORE_LOYALTY_ENABLED', false),
    ],
    'display_currencies' => ['SAR', 'AED', 'KWD', 'BHD', 'OMR', 'QAR', 'USD', 'EUR', 'GBP', 'EGP'],
    'display_exchange_rates' => [
        'provider_url' => 'https://open.er-api.com/v6/latest/SAR',
        'source' => 'exchange-rate-api-open-access',
        'max_age_hours' => 30,
    ],
    'proof' => [
        // Shown on the homepage only while the orders table holds no completed
        // order at all. They are the audited figures from the Salla export
        // (docs/decisions/2026-08-09-salla-history-import-design.md); once the
        // import has run, the database counts are authoritative.
        'fallback' => [
            'customers_served' => 8877,
            'completed_orders' => 29161,
        ],
    ],
    'support' => [
        'whatsapp_url' => 'https://wa.me/966537998099',
        'email' => 'info@arab-ut.com',
    ],
    'seo' => [
        // The image WhatsApp, X, and Facebook show when the store is shared.
        // It must be at least 300x200 or scrapers fall back to a tiny icon;
        // 1200x630 is the ideal. The header logo (96x68) is far too small.
        'share_image' => '/images/store/share/store-card.webp',
        // Used as the schema.org organisation logo, where a small square is fine.
        'logo' => '/images/arabut-logo-header.webp',
    ],
    'socials' => [
        'x' => 'https://x.com/fut_fi',
        'instagram' => 'https://www.instagram.com/arabutcoins/',
    ],
    'simple_pages' => [
        'privacy', 'returns', 'warranty',
        'ea_backup_codes', 'terms',
    ],
    'payments' => [
        ['name' => 'Mada', 'image_url' => '/images/store/payments/mada.png', 'width' => 120, 'height' => 41],
        ['name' => 'Visa', 'image_url' => '/images/store/payments/visa.png', 'width' => 120, 'height' => 39],
        ['name' => 'Mastercard', 'image_url' => '/images/store/payments/mastercard.png', 'width' => 120, 'height' => 75],
        ['name' => 'Apple Pay', 'image_url' => '/images/store/payments/apple-pay.png', 'width' => 120, 'height' => 50],
    ],
];
