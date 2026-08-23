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
        ],
        'sbc' => [
            'title' => 'SBC Challenges',
            'subtitle' => 'We complete the challenge and supply the coins',
        ],
        'rivals' => [
            'title' => 'Division Rivals',
            'subtitle' => 'We play and promote you to the division you want',
        ],
        'fut_champions' => [
            'title' => 'FUT Champions',
            'subtitle' => 'From Rank 6 to Rank 1',
        ],
    ],
];
