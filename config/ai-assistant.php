<?php

$testUserIds = array_values(array_unique(array_filter(
    array_map('intval', explode(',', (string) env('AI_ASSISTANT_TEST_USER_IDS', ''))),
    static fn (int $id): bool => $id > 0,
)));

return [
    'enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),
    'rollout' => trim((string) env('AI_ASSISTANT_ROLLOUT', 'disabled')),
    'test_user_ids' => $testUserIds,
    'provider' => trim((string) env('AI_MODEL_PROVIDER', '')),
    'model' => (string) env('AI_MODEL', 'gpt-5.6-luna'),
    'prompt_version' => 'support-v1',
    'turn_debounce_ms' => (int) env('AI_TURN_DEBOUNCE_MS', 1500),
    'max_context_messages' => (int) env('AI_MAX_CONTEXT_MESSAGES', 24),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 500),
    'max_response_characters' => (int) env('AI_MAX_RESPONSE_CHARACTERS', 4000),
    'reasoning_effort' => (string) env('AI_REASONING_EFFORT', 'low'),
    'connect_timeout_seconds' => (int) env('AI_CONNECT_TIMEOUT_SECONDS', 5),
    'stream_read_timeout_seconds' => (int) env('AI_STREAM_READ_TIMEOUT_SECONDS', 2),
    'request_timeout_seconds' => (int) env('AI_REQUEST_TIMEOUT_SECONDS', 45),
    'turn_rate_limit_per_minute' => (int) env('AI_TURN_RATE_LIMIT_PER_MINUTE', 6),
    'turn_ip_rate_limit_per_minute' => (int) env('AI_TURN_IP_RATE_LIMIT_PER_MINUTE', 20),
    'max_attempts' => (int) env('AI_MAX_ATTEMPTS', 3),
    'retry_after_cap_ms' => (int) env('AI_RETRY_AFTER_CAP_MS', 2000),
    'stale_turn_seconds' => (int) env('AI_STALE_TURN_SECONDS', 120),
    'fake_delta_delay_ms' => (int) env('AI_FAKE_DELTA_DELAY_MS', 350),
    'pricing' => [
        'version' => 'openai-gpt-5.6-luna-2026-08-21',
        'input_per_million' => '0.20',
        'cached_input_per_million' => '0.02',
        'cache_write_per_million' => '0.25',
        'output_per_million' => '1.20',
    ],
];
