<?php

return [
    'conversation_closed' => 'This conversation is closed. Start a new conversation to continue.',
    'validation_error' => 'The submitted chat data is invalid.',
    'rate_limited' => 'Too many chat requests. Please try again shortly.',
    'unavailable' => 'Chat is temporarily unavailable. Please try again.',
    'provider_connection_failed' => 'Unable to connect to the assistant service. Please try again.',
    'provider_timeout' => 'The assistant took too long to respond. Please try again.',
    'provider_server_error' => 'The assistant service encountered a temporary error. Please try again.',
    'provider_incomplete' => 'The response was interrupted. Please try again.',
    'stream_terminated' => 'The response stream was interrupted. Please try again.',
    'stale_turn_recovered' => 'The previous request timed out and was recovered. You can retry now.',
    'sensitive_content_blocked' => 'Your message could not be processed because it may contain sensitive information or credentials. Please send your message again without passwords, verification codes, card numbers, or confidential data.',
    'configuration_invalid' => 'The assistant service is temporarily misconfigured. Please contact support.',
    'invalid_agent_request' => 'The request could not be processed. Please try again with a different message.',
    'provider_authentication_failed' => 'The assistant service is currently unavailable. Please try again later.',
    'provider_permission_denied' => 'The assistant service is currently unavailable. Please try again later.',
    'provider_request_rejected' => 'The request was rejected by the service. Please try again with a different message.',
    'provider_malformed' => 'The assistant received an unexpected response format. Please try again.',
    'provider_terminal_failure' => 'The assistant is unable to process this request. Please try again later.',
    'cancelled' => 'The request was cancelled.',

    'cards' => [
        'cta' => 'Order now',
        'coins' => [
            'title' => 'FC Coins',
            'subtitle' => 'Pick your platform and amount to see the price',
            'options' => [
                'platform' => 'Platform',
                'delivery' => 'Delivery',
                'quantity' => 'Quantity',
            ],
            'platforms' => [
                'playstation' => 'PlayStation',
                'xbox' => 'Xbox',
                'pc' => 'PC',
            ],
            'deliveries' => [
                'normal' => 'Normal',
                'fast' => 'Fast',
            ],
            'quantity_value' => ':count Coins',
        ],
        'sbc' => [
            'title' => 'SBC Challenges',
            'subtitle' => 'We complete the challenge and supply the coins',
        ],
        'rivals' => [
            'title' => 'Division Rivals',
            'subtitle' => 'We play and promote you to the division you want',
            'options' => [
                'current_division' => 'Current division',
                'target_division' => 'Target division',
            ],
            'division_value' => 'Division :division',
            'elite' => 'Elite',
        ],
        'fut_champions' => [
            'title' => 'FUT Champions',
            'subtitle' => 'From Rank 6 to Rank 1',
            'options' => [
                'rank' => 'Rank',
                'urgent' => 'Speed',
            ],
            'rank_value' => 'Rank :rank',
            'urgent_value' => 'Urgent',
            'normal_value' => 'Standard',
        ],
    ],
    'choices' => [
        'service' => [
            'prompt' => 'Which service do you want?',
            'coins' => [
                'label' => 'Coins',
                'message' => 'I want coins',
            ],
            'rivals' => [
                'label' => 'Division Rivals',
                'message' => 'I want Rivals',
            ],
            'fut_champions' => [
                'label' => 'FUT Champions',
                'message' => 'I want FUT Champions',
            ],
            'sbc' => [
                'label' => 'SBC challenges',
                'message' => 'I want SBC challenges',
            ],
        ],
        'coins' => [
            'platform_prompt' => 'Which platform?',
            'quantity_prompt' => 'How many coins?',
            'delivery_prompt' => 'Which delivery speed?',
            'playstation' => [
                'label' => 'PlayStation',
                'message' => 'PlayStation',
            ],
            'pc' => [
                'label' => 'PC',
                'message' => 'PC',
            ],
            'normal' => [
                'label' => 'Normal',
                'message' => 'normal delivery',
            ],
            'fast' => [
                'label' => 'Fast',
                'message' => 'fast delivery',
            ],
            'quantities' => [
                100000 => [
                    'label' => '100K',
                    'message' => '100k coins',
                ],
                500000 => [
                    'label' => '500K',
                    'message' => '500k coins',
                ],
                1000000 => [
                    'label' => '1M',
                    'message' => '1m coins',
                ],
                2000000 => [
                    'label' => '2M',
                    'message' => 'two million coins',
                ],
                5000000 => [
                    'label' => '5M',
                    'message' => 'five million coins',
                ],
            ],
        ],
        'fut_champions' => [
            'rank_prompt' => 'Which rank do you want to reach?',
            'urgency_prompt' => 'Normal or urgent?',
            'normal' => [
                'label' => 'Normal',
                'message' => 'normal',
            ],
            'urgent' => [
                'label' => 'Urgent',
                'message' => 'urgent',
            ],
            'ranks' => [
                6 => [
                    'label' => 'Rank 6',
                    'message' => 'rank 6',
                ],
                5 => [
                    'label' => 'Rank 5',
                    'message' => 'rank 5',
                ],
                4 => [
                    'label' => 'Rank 4',
                    'message' => 'rank 4',
                ],
                3 => [
                    'label' => 'Rank 3',
                    'message' => 'rank 3',
                ],
                2 => [
                    'label' => 'Rank 2',
                    'message' => 'rank 2',
                ],
                1 => [
                    'label' => 'Rank 1',
                    'message' => 'rank 1',
                ],
            ],
        ],
    ],
];
