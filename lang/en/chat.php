<?php

return [
    'conversation_closed' => 'This conversation is closed. Start a new conversation to continue.',
    'conversation_not_found' => 'The requested conversation was not found.',
    'handoff_requires_login' => 'Please log in to contact support.',
    'assistant_resumed' => 'Nawaf is back to help — reply here any time.',
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

    'support' => [
        'defaultSubject' => 'Customer support request',
    ],

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
                'message' => 'I want coins on PlayStation',
            ],
            'pc' => [
                'label' => 'PC',
                'message' => 'I want coins on PC',
            ],
            'normal' => [
                'label' => 'Normal',
                'message' => 'I want :amount coins on :platform, normal delivery',
            ],
            'fast' => [
                'label' => 'Fast',
                'message' => 'I want :amount coins on :platform, fast delivery',
            ],
            // A chip's message is sent as the customer's next message, and a
            // turn reads that message alone. "PlayStation" on its own arrives
            // with no service attached and the funnel dead-ends, so every chip
            // restates everything chosen so far.
            'quantities' => [
                100000 => [
                    'label' => '100K',
                    'amount' => '100k',
                    'message' => 'I want 100k coins on :platform',
                ],
                500000 => [
                    'label' => '500K',
                    'amount' => '500k',
                    'message' => 'I want 500k coins on :platform',
                ],
                1000000 => [
                    'label' => '1M',
                    'amount' => '1m',
                    'message' => 'I want 1m coins on :platform',
                ],
                2000000 => [
                    'label' => '2M',
                    'amount' => '2m',
                    'message' => 'I want 2m coins on :platform',
                ],
                5000000 => [
                    'label' => '5M',
                    'amount' => '5m',
                    'message' => 'I want 5m coins on :platform',
                ],
            ],
        ],
        'rivals' => [
            'current_prompt' => 'Which division are you in now?',
            'target_prompt' => 'Which division do you want to reach?',
            'current' => [
                '7' => ['label' => 'Division 7', 'message' => 'I am in division 7'],
                '6' => ['label' => 'Division 6', 'message' => 'I am in division 6'],
                '5' => ['label' => 'Division 5', 'message' => 'I am in division 5'],
                '4' => ['label' => 'Division 4', 'message' => 'I am in division 4'],
                '3' => ['label' => 'Division 3', 'message' => 'I am in division 3'],
                '2' => ['label' => 'Division 2', 'message' => 'I am in division 2'],
                '1' => ['label' => 'Division 1', 'message' => 'I am in division 1'],
            ],
            // The message carries the whole route: a turn only ever sees the
            // latest message, so a target alone would arrive with no start.
            'target' => [
                'division' => [
                    'label' => 'Division :to',
                    'message' => 'from division :from to division :to',
                ],
                'elite' => [
                    'label' => 'Elite',
                    'message' => 'from division :from to Elite',
                ],
            ],
        ],
        'fut_champions' => [
            'rank_prompt' => 'Which rank do you want to reach?',
            'urgency_prompt' => 'Normal or urgent?',
            'normal' => [
                'label' => 'Normal',
                'message' => 'I want FUT Champions rank :rank, normal',
            ],
            'urgent' => [
                'label' => 'Urgent',
                'message' => 'I want FUT Champions rank :rank, urgent',
            ],
            'ranks' => [
                6 => [
                    'label' => 'Rank 6',
                    'message' => 'I want FUT Champions rank 6',
                ],
                5 => [
                    'label' => 'Rank 5',
                    'message' => 'I want FUT Champions rank 5',
                ],
                4 => [
                    'label' => 'Rank 4',
                    'message' => 'I want FUT Champions rank 4',
                ],
                3 => [
                    'label' => 'Rank 3',
                    'message' => 'I want FUT Champions rank 3',
                ],
                2 => [
                    'label' => 'Rank 2',
                    'message' => 'I want FUT Champions rank 2',
                ],
                1 => [
                    'label' => 'Rank 1',
                    'message' => 'I want FUT Champions rank 1',
                ],
            ],
        ],
    ],
];
