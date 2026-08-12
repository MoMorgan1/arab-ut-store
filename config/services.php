<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'n8n' => [
        'reviews_url' => env('N8N_REVIEWS_URL'),
        'catalog_key' => env('N8N_CATALOG_KEY'),
        'catalog_secret' => env('N8N_CATALOG_SECRET'),
        'sbc_catalog_key' => env('N8N_SBC_CATALOG_KEY'),
        'sbc_catalog_secret' => env('N8N_SBC_CATALOG_SECRET'),
        'pricing_key' => env('N8N_PRICING_KEY'),
        'pricing_secret' => env('N8N_PRICING_SECRET'),
        'sbc_pricing_read_key' => env('N8N_SBC_PRICING_READ_KEY'),
        'sbc_pricing_read_secret' => env('N8N_SBC_PRICING_READ_SECRET'),
        'catalog_media_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('N8N_CATALOG_MEDIA_HOSTS', '')),
        ))),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'whapi' => [
        'base_url' => env('WHAPI_BASE_URL', 'https://gate.whapi.cloud'),
        'token' => env('WHAPI_TOKEN'),
    ],

];
