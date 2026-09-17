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
        'order_paid_url' => env('N8N_ORDER_PAID_URL'),
        'order_paid_key' => env('N8N_ORDER_PAID_KEY'),
        'order_paid_secret' => env('N8N_ORDER_PAID_SECRET'),
        'catalog_key' => env('N8N_CATALOG_KEY'),
        'catalog_secret' => env('N8N_CATALOG_SECRET'),
        'sbc_catalog_key' => env('N8N_SBC_CATALOG_KEY'),
        'sbc_catalog_secret' => env('N8N_SBC_CATALOG_SECRET'),
        'pricing_key' => env('N8N_PRICING_KEY'),
        'pricing_secret' => env('N8N_PRICING_SECRET'),
        'sbc_pricing_read_key' => env('N8N_SBC_PRICING_READ_KEY'),
        'sbc_pricing_read_secret' => env('N8N_SBC_PRICING_READ_SECRET'),
        'fulfillment_key' => env('N8N_FULFILLMENT_KEY'),
        'fulfillment_secret' => env('N8N_FULFILLMENT_SECRET'),
        'solve_challenge_url' => env('N8N_SOLVE_CHALLENGE_URL'),
        'solve_challenge_key' => env('N8N_SOLVE_CHALLENGE_KEY'),
        'solve_challenge_secret' => env('N8N_SOLVE_CHALLENGE_SECRET'),
        // After this many failed delivery attempts an order-paid event is
        // retired as failed for manual requeue instead of being retried
        // forever. A literal rather than an env entry, so .env.example keeps
        // its one-to-one parity with this file.
        'order_paid_max_attempts' => 10,
        'challenge_ready_max_attempts' => 10,
        'catalog_media_hosts' => array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('N8N_CATALOG_MEDIA_HOSTS', '')),
        ))),
    ],

    // The external fulfillment suppliers, reached through App\Suppliers. The
    // rate limit and circuit breaker values are literals rather than env
    // entries, so only real credentials live in the environment.
    'suppliers' => [
        'rate_limit_per_minute' => 120,
        'circuit_failure_threshold' => 5,
        'circuit_cooldown_seconds' => 60,
        'circuit_failure_window_seconds' => 600,

        // The scheduled read loop's cadences. Literals rather than env entries,
        // so only real credentials live in the environment. The fast band is
        // for orders a customer is watching (last_viewed_at fresh), the slow
        // band for everything else; the ceiling caps the failure backoff.
        'poll' => [
            'attention_window_seconds' => 180,
            'attention_cadence_seconds' => 25,
            'background_cadence_seconds' => 180,
            'backoff_ceiling_seconds' => 600,
            'lease_seconds' => 30,
            'deadline_seconds' => 50,

            // How long a measured observation gap is kept
            // (`fulfillment_observation_gaps`, pruned daily by
            // `fulfillment:prune-observation-gaps`).
            //
            // Fourteen days because the question the table answers is
            // answered by weeks, not months: D3's own plan asked for "a week
            // of numbers", and two weeks gives that twice over while bounding
            // the table. One open job polled on the background cadence writes
            // about 480 rows a day, so the window is roughly seven thousand
            // rows per job a supplier is working on - a size shared hosting
            // does not notice, which a year of the same rows would be.
            'gap_retention_days' => 14,
        ],

        // The silence alarm's thresholds. Literals for the same reason as the
        // cadences above, and none of them is a number a customer ever feels:
        // they decide when an operator is told that paid work has stopped.
        'alarm' => [
            // A paid automated item with no placement row is only silent once
            // every ordinary retry has had its turn. The outbox publisher runs
            // every minute and backs off to at most an hour, so a quarter of an
            // hour is well past a transient failure and well short of a
            // customer wondering where their order went.
            'unplaced_after_minutes' => 15,
            // The same silence, when the store has already written down why it
            // cannot send the order: no applied pricing run to budget against,
            // a platform no supplier serves, an EA account purged before the
            // request left. The publisher backs off one, two then four minutes,
            // so its attempts land at roughly zero, one and three minutes: by
            // five it has refused three times for the same reason and the
            // fourth is two minutes away. Waiting the full quarter hour to hear
            // the same answer a fourth time only delays the person who has to
            // do something about it.
            'blocked_after_minutes' => 5,
            // Fruitless supplier reads in a row. The poller backs off from its
            // band cadence to a ten-minute ceiling, so six of them is the better
            // part of an hour in which no read has landed at all.
            'silent_after_failures' => 6,
            // How far back a NEW alarm may be opened. An alarm that is already
            // open stays open however old it gets; this only stops the first run
            // after a deploy from opening one for every automated item the store
            // has ever sold through a pipeline that predates this table.
            'raise_window_hours' => 48,

            // D3b's phase cadence table: the longest a placed job in each
            // delivery phase may go without a reading landing before an
            // operator is told it has stopped moving. Minutes. The key 'none'
            // is a job carrying no phase at all.
            //
            // Every entry is deliberately unset, and unset means that phase is
            // not watched - never that everything in it is stalled. The only
            // honest source for these numbers is measured per-phase
            // observation gaps, and on 2026-09-17 there were none to measure:
            // D3a's instrumentation is a single Log::info line per tick and
            // production runs at LOG_LEVEL=warning, so not one tick has ever
            // been written, and nothing durable records an observation gap
            // either - `fulfillment_jobs.observed_at` is one column that is
            // overwritten on every read, not a history.
            //
            // Setting a number here turns the alarm on for that phase, so it
            // is a decision, not a default. A guessed threshold would mail
            // Mohamed about healthy orders until he stopped reading the mail.
            'stalled_after_minutes' => [
                'coins' => null,
                'challenge' => null,
                'none' => null,
            ],
        ],

        'fft' => [
            'base_url' => env('SUPPLIER_FFT_BASE_URL', 'https://futtransfer.top'),
            'api_user' => env('SUPPLIER_FFT_API_USER'),
            'api_key' => env('SUPPLIER_FFT_API_KEY'),
        ],

        'utt' => [
            'base_url' => env('SUPPLIER_UTT_BASE_URL', 'https://utautotransfer.com/api'),
            'api_key' => env('SUPPLIER_UTT_API_KEY'),
        ],
    ],

    'orders' => [
        // Cancelled orders that never captured money are permanently deleted
        // this many hours after cancellation - not at the moment of
        // cancelling, because a customer can still be mid-redirect at Paylink
        // when a checkout expires, and a late webhook must find the order it
        // is looking for. A literal rather than an env entry, so .env.example
        // keeps its one-to-one parity with this file.
        'purge_cancelled_grace_hours' => 24,
    ],

    // Analytics vendor ids. All three are public by nature (they ship in
    // page source); an empty value switches that vendor off entirely.
    // Tracking is on by default and a visitor opts out from the privacy
    // page. See docs/decisions/2026-09-02-analytics-tracking-design.md.
    'analytics' => [
        'ga4_measurement_id' => env('ANALYTICS_GA4_MEASUREMENT_ID'),
        'meta_pixel_id' => env('ANALYTICS_META_PIXEL_ID'),
        'tiktok_pixel_id' => env('ANALYTICS_TIKTOK_PIXEL_ID'),
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

    'paylink' => [
        'environment' => env('PAYLINK_ENV', 'test'),
        'api_id' => env('PAYLINK_API_ID'),
        'secret_key' => env('PAYLINK_SECRET_KEY'),
        'webhook_token' => env('PAYLINK_WEBHOOK_TOKEN'),
        'partner_profile_no' => env('PAYLINK_PARTNER_PROFILE_NO'),
        'partner_api_key' => env('PAYLINK_PARTNER_API_KEY'),
        'merchant_lookup_key' => env('PAYLINK_MERCHANT_LOOKUP_KEY', 'accountNo'),
        'merchant_lookup_value' => env('PAYLINK_MERCHANT_LOOKUP_VALUE'),
    ],

    'openai' => [
        'base_url' => 'https://api.openai.com/v1',
        'key' => env('OPENAI_API_KEY', ''),
    ],

];
