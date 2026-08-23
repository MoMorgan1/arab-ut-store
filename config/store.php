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
    'display_currencies' => ['SAR', 'AED', 'KWD', 'BHD', 'OMR', 'QAR', 'USD', 'EUR', 'GBP'],
    'display_exchange_rates' => [
        'provider_url' => 'https://open.er-api.com/v6/latest/SAR',
        'source' => 'exchange-rate-api-open-access',
        'max_age_hours' => 30,
    ],
    'support' => [
        'whatsapp_url' => 'https://wa.me/966537998099',
        'email' => 'info@arab-ut.com',
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
