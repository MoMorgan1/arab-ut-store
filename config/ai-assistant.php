<?php

return [
    'enabled' => env('AI_ASSISTANT_ENABLED', false),
    'rollout' => env('AI_ASSISTANT_ROLLOUT', 'disabled'),
    'test_user_ids' => env('AI_ASSISTANT_TEST_USER_IDS', ''),
    'provider' => env('AI_MODEL_PROVIDER', ''),
    'model' => env('AI_MODEL', 'gpt-5.6-luna'),
    'prompt_version' => 'support-v8',

    // Number of approved knowledge topics injected per turn. 0 disables
    // grounding and returns the assistant to prompt-only answers.
    'knowledge_max_topics' => (int) env('AI_ASSISTANT_KNOWLEDGE_MAX_TOPICS', 3),
    'turn_debounce_ms' => env('AI_TURN_DEBOUNCE_MS', 1500),
    'max_context_messages' => env('AI_MAX_CONTEXT_MESSAGES', 24),
    'max_output_tokens' => env('AI_MAX_OUTPUT_TOKENS', 1000),
    'max_response_characters' => env('AI_MAX_RESPONSE_CHARACTERS', 4000),
    'reasoning_effort' => env('AI_REASONING_EFFORT', 'low'),
    'connect_timeout_seconds' => env('AI_CONNECT_TIMEOUT_SECONDS', 5),
    'stream_read_timeout_seconds' => env('AI_STREAM_READ_TIMEOUT_SECONDS', 2),
    'request_timeout_seconds' => env('AI_REQUEST_TIMEOUT_SECONDS', 30),
    'turn_rate_limit_per_minute' => env('AI_TURN_RATE_LIMIT_PER_MINUTE', 6),
    'turn_ip_rate_limit_per_minute' => env('AI_TURN_IP_RATE_LIMIT_PER_MINUTE', 20),
    'max_attempts' => env('AI_MAX_ATTEMPTS', 3),
    'retry_after_cap_ms' => env('AI_RETRY_AFTER_CAP_MS', 2000),
    'stale_turn_seconds' => env('AI_STALE_TURN_SECONDS', 60),
    'fake_delta_delay_ms' => env('AI_FAKE_DELTA_DELAY_MS', 350),
    'pricing' => [
        'version' => 'openai-gpt-5.6-luna-2026-08-21',
        'input_per_million' => '0.20',
        'cached_input_per_million' => '0.02',
        'cache_write_per_million' => '0.25',
        'output_per_million' => '1.20',
    ],
];
