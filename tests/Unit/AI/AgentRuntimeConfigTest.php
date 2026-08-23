<?php

use App\Enums\AI\AgentErrorCode;
use App\Exceptions\AI\AgentConfigurationException;
use App\Support\AI\AgentRuntimeConfig;
use Tests\TestCase;

uses(TestCase::class);

test('runtime config returns each validated configured value', function () {
    config()->set('ai-assistant', [
        'enabled' => true,
        'rollout' => 'public',
        'test_user_ids' => [7, 11],
        'provider' => 'fake',
        'model' => 'gpt-5.6-luna',
        'prompt_version' => 'support-v6',
        'turn_debounce_ms' => 100,
        'max_context_messages' => 12,
        'max_output_tokens' => 300,
        'max_response_characters' => 2000,
        'reasoning_effort' => 'low',
        'connect_timeout_seconds' => 3,
        'stream_read_timeout_seconds' => 2,
        'request_timeout_seconds' => 10,
        'turn_rate_limit_per_minute' => 5,
        'turn_ip_rate_limit_per_minute' => 10,
        'max_attempts' => 3,
        'retry_after_cap_ms' => 1000,
        'stale_turn_seconds' => 60,
        'fake_delta_delay_ms' => 0,
        'pricing' => [
            'version' => 'test-pricing-v1',
            'input_per_million' => '0.20',
            'cached_input_per_million' => '0.02',
            'cache_write_per_million' => '0.25',
            'output_per_million' => '1.20',
        ],
    ]);

    $runtime = app(AgentRuntimeConfig::class);

    expect($runtime->enabled())->toBeTrue()
        ->and($runtime->rollout()->value)->toBe('public')
        ->and($runtime->testUserIds())->toBe([7, 11])
        ->and($runtime->provider()->value)->toBe('fake')
        ->and($runtime->model())->toBe('gpt-5.6-luna')
        ->and($runtime->promptVersion())->toBe('support-v6')
        ->and($runtime->turnDebounceMilliseconds())->toBe(100)
        ->and($runtime->maxContextMessages())->toBe(12)
        ->and($runtime->maxOutputTokens())->toBe(300)
        ->and($runtime->maxResponseCharacters())->toBe(2000)
        ->and($runtime->reasoningEffort())->toBe('low')
        ->and($runtime->connectTimeoutSeconds())->toBe(3)
        ->and($runtime->streamReadTimeoutSeconds())->toBe(2)
        ->and($runtime->requestTimeoutSeconds())->toBe(10)
        ->and($runtime->turnRateLimitPerMinute())->toBe(5)
        ->and($runtime->turnIpRateLimitPerMinute())->toBe(10)
        ->and($runtime->maxAttempts())->toBe(3)
        ->and($runtime->retryAfterCapMilliseconds())->toBe(1000)
        ->and($runtime->staleTurnSeconds())->toBe(60)
        ->and($runtime->fakeDeltaDelayMilliseconds())->toBe(0)
        ->and($runtime->pricingVersion())->toBe('test-pricing-v1')
        ->and($runtime->inputRatePerMillion())->toBe('0.20')
        ->and($runtime->cachedInputRatePerMillion())->toBe('0.02')
        ->and($runtime->cacheWriteRatePerMillion())->toBe('0.25')
        ->and($runtime->outputRatePerMillion())->toBe('1.20');
});

test('runtime config rejects invalid domains without exposing them', function (string $key, mixed $value, string $method) {
    config()->set($key, $value);

    expect(fn () => app(AgentRuntimeConfig::class)->{$method}())
        ->toThrow(AgentConfigurationException::class, 'Assistant runtime configuration is invalid.');
})->with([
    'enabled must be boolean' => ['ai-assistant.enabled', 'yes', 'enabled'],
    'test users must be unique positive integers' => ['ai-assistant.test_user_ids', [7, 7], 'testUserIds'],
    'provider must be known' => ['ai-assistant.provider', 'unexpected', 'provider'],
    'model is fixed' => ['ai-assistant.model', 'unexpected', 'model'],
    'prompt version is fixed' => ['ai-assistant.prompt_version', 'unexpected', 'promptVersion'],
    'reasoning effort is fixed' => ['ai-assistant.reasoning_effort', 'high', 'reasoningEffort'],
    'pricing version must be present' => ['ai-assistant.pricing.version', '', 'pricingVersion'],
    'input pricing must be nonnegative decimal' => ['ai-assistant.pricing.input_per_million', '-0.01', 'inputRatePerMillion'],
    'cached input pricing must be nonnegative decimal' => ['ai-assistant.pricing.cached_input_per_million', '-0.01', 'cachedInputRatePerMillion'],
    'cache write pricing must be nonnegative decimal' => ['ai-assistant.pricing.cache_write_per_million', '-0.01', 'cacheWriteRatePerMillion'],
    'output pricing must be nonnegative decimal' => ['ai-assistant.pricing.output_per_million', '-0.01', 'outputRatePerMillion'],
]);

test('runtime config rejects values immediately outside every numeric boundary', function (string $key, int $value, string $method) {
    config()->set($key, $value);

    expect(fn () => app(AgentRuntimeConfig::class)->{$method}())
        ->toThrow(AgentConfigurationException::class);
})->with([
    'debounce below minimum' => ['ai-assistant.turn_debounce_ms', 99, 'turnDebounceMilliseconds'],
    'debounce above maximum' => ['ai-assistant.turn_debounce_ms', 5001, 'turnDebounceMilliseconds'],
    'context below minimum' => ['ai-assistant.max_context_messages', 0, 'maxContextMessages'],
    'context above maximum' => ['ai-assistant.max_context_messages', 25, 'maxContextMessages'],
    'output below minimum' => ['ai-assistant.max_output_tokens', 0, 'maxOutputTokens'],
    'output above maximum' => ['ai-assistant.max_output_tokens', 1001, 'maxOutputTokens'],
    'response length below minimum' => ['ai-assistant.max_response_characters', 0, 'maxResponseCharacters'],
    'response length above maximum' => ['ai-assistant.max_response_characters', 4001, 'maxResponseCharacters'],
    'connection timeout below minimum' => ['ai-assistant.connect_timeout_seconds', 0, 'connectTimeoutSeconds'],
    'connection timeout above maximum' => ['ai-assistant.connect_timeout_seconds', 11, 'connectTimeoutSeconds'],
    'stream timeout below minimum' => ['ai-assistant.stream_read_timeout_seconds', 0, 'streamReadTimeoutSeconds'],
    'stream timeout above maximum' => ['ai-assistant.stream_read_timeout_seconds', 11, 'streamReadTimeoutSeconds'],
    'request timeout below minimum' => ['ai-assistant.request_timeout_seconds', 0, 'requestTimeoutSeconds'],
    'request timeout above maximum' => ['ai-assistant.request_timeout_seconds', 61, 'requestTimeoutSeconds'],
    'owner rate limit below minimum' => ['ai-assistant.turn_rate_limit_per_minute', 0, 'turnRateLimitPerMinute'],
    'owner rate limit above maximum' => ['ai-assistant.turn_rate_limit_per_minute', 121, 'turnRateLimitPerMinute'],
    'ip rate limit below minimum' => ['ai-assistant.turn_ip_rate_limit_per_minute', 0, 'turnIpRateLimitPerMinute'],
    'ip rate limit above maximum' => ['ai-assistant.turn_ip_rate_limit_per_minute', 301, 'turnIpRateLimitPerMinute'],
    'attempt count below fixed value' => ['ai-assistant.max_attempts', 2, 'maxAttempts'],
    'attempt count above fixed value' => ['ai-assistant.max_attempts', 4, 'maxAttempts'],
    'retry cap below minimum' => ['ai-assistant.retry_after_cap_ms', -1, 'retryAfterCapMilliseconds'],
    'retry cap above maximum' => ['ai-assistant.retry_after_cap_ms', 2001, 'retryAfterCapMilliseconds'],
    'stale turn time below minimum' => ['ai-assistant.stale_turn_seconds', 59, 'staleTurnSeconds'],
    'stale turn time above maximum' => ['ai-assistant.stale_turn_seconds', 3601, 'staleTurnSeconds'],
    'fake delay below minimum' => ['ai-assistant.fake_delta_delay_ms', -1, 'fakeDeltaDelayMilliseconds'],
    'fake delay above maximum' => ['ai-assistant.fake_delta_delay_ms', 2001, 'fakeDeltaDelayMilliseconds'],
]);

test('environment config accepts only canonical runtime values', function (string $environmentKey, string $rawValue, string $method, mixed $expected) {
    $runtime = runtimeConfigLoadedFromEnvironment($environmentKey, $rawValue);

    expect($runtime->{$method}())->toBe($expected);
})->with([
    'enabled true' => ['AI_ASSISTANT_ENABLED', 'true', 'enabled', true],
    'enabled false' => ['AI_ASSISTANT_ENABLED', 'false', 'enabled', false],
    'empty tester allowlist' => ['AI_ASSISTANT_TEST_USER_IDS', '', 'testUserIds', []],
    'tester allowlist' => ['AI_ASSISTANT_TEST_USER_IDS', '7,11', 'testUserIds', [7, 11]],
    'integer string' => ['AI_TURN_DEBOUNCE_MS', '100', 'turnDebounceMilliseconds', 100],
]);

test('environment config preserves malformed values for fail closed validation', function (string $environmentKey, string $rawValue, string $method) {
    $runtime = runtimeConfigLoadedFromEnvironment($environmentKey, $rawValue);

    try {
        $runtime->{$method}();
    } catch (AgentConfigurationException $exception) {
        expect($exception->errorCode)->toBe(AgentErrorCode::ConfigurationInvalid)
            ->and($exception->getMessage())->toBe('Assistant runtime configuration is invalid.')
            ->and($exception->getMessage())->not->toContain($rawValue);

        return;
    }

    $this->fail('Expected malformed environment configuration to fail closed.');
})->with([
    'malformed enabled flag' => ['AI_ASSISTANT_ENABLED', 'tru', 'enabled'],
    'malformed tester token' => ['AI_ASSISTANT_TEST_USER_IDS', '7oops', 'testUserIds'],
    'zero tester token' => ['AI_ASSISTANT_TEST_USER_IDS', '0', 'testUserIds'],
    'negative tester token' => ['AI_ASSISTANT_TEST_USER_IDS', '-7', 'testUserIds'],
    'duplicate tester token' => ['AI_ASSISTANT_TEST_USER_IDS', '7,7', 'testUserIds'],
    'malformed numeric value' => ['AI_TURN_DEBOUNCE_MS', '100oops', 'turnDebounceMilliseconds'],
]);

test('runtime config rejects an empty provider with the configuration invalid code', function () {
    config()->set('ai-assistant.provider', '');

    try {
        app(AgentRuntimeConfig::class)->provider();
    } catch (AgentConfigurationException $exception) {
        expect($exception->errorCode)->toBe(AgentErrorCode::ConfigurationInvalid);

        return;
    }

    $this->fail('Expected an empty provider to throw AgentConfigurationException.');
});

test('runtime config rejects invalid timeout and rate-limit relationships', function (string $key, mixed $value, string $method) {
    config()->set('ai-assistant.request_timeout_seconds', 5);
    config()->set('ai-assistant.turn_rate_limit_per_minute', 10);
    config()->set($key, $value);

    expect(fn () => app(AgentRuntimeConfig::class)->{$method}())
        ->toThrow(AgentConfigurationException::class);
})->with([
    'connect timeout cannot exceed request timeout' => ['ai-assistant.connect_timeout_seconds', 6, 'connectTimeoutSeconds'],
    'stream timeout cannot exceed request timeout' => ['ai-assistant.stream_read_timeout_seconds', 6, 'streamReadTimeoutSeconds'],
    'ip rate limit cannot be below owner rate limit' => ['ai-assistant.turn_ip_rate_limit_per_minute', 9, 'turnIpRateLimitPerMinute'],
]);

function runtimeConfigLoadedFromEnvironment(string $environmentKey, string $rawValue): AgentRuntimeConfig
{
    $managedKeys = [
        'AI_ASSISTANT_ENABLED',
        'AI_ASSISTANT_ROLLOUT',
        'AI_ASSISTANT_TEST_USER_IDS',
        'AI_MODEL_PROVIDER',
        'AI_MODEL',
        'AI_TURN_DEBOUNCE_MS',
        'AI_MAX_CONTEXT_MESSAGES',
        'AI_MAX_OUTPUT_TOKENS',
        'AI_MAX_RESPONSE_CHARACTERS',
        'AI_REASONING_EFFORT',
        'AI_CONNECT_TIMEOUT_SECONDS',
        'AI_STREAM_READ_TIMEOUT_SECONDS',
        'AI_REQUEST_TIMEOUT_SECONDS',
        'AI_TURN_RATE_LIMIT_PER_MINUTE',
        'AI_TURN_IP_RATE_LIMIT_PER_MINUTE',
        'AI_MAX_ATTEMPTS',
        'AI_RETRY_AFTER_CAP_MS',
        'AI_STALE_TURN_SECONDS',
        'AI_FAKE_DELTA_DELAY_MS',
    ];

    $saved = [];

    foreach ($managedKeys as $managedKey) {
        $saved[$managedKey] = getenv($managedKey) === false
            ? null
            : (string) getenv($managedKey);

        putenv($managedKey);
        unset($_ENV[$managedKey], $_SERVER[$managedKey]);
    }

    putenv("{$environmentKey}={$rawValue}");
    $_ENV[$environmentKey] = $rawValue;
    $_SERVER[$environmentKey] = $rawValue;

    try {
        config()->set('ai-assistant', require config_path('ai-assistant.php'));
    } finally {
        foreach ($managedKeys as $managedKey) {
            if ($saved[$managedKey] !== null) {
                $restored = $saved[$managedKey];
                putenv("{$managedKey}={$restored}");
                $_ENV[$managedKey] = $restored;
                $_SERVER[$managedKey] = $restored;

                continue;
            }

            putenv($managedKey);
            unset($_ENV[$managedKey], $_SERVER[$managedKey]);
        }
    }

    return app(AgentRuntimeConfig::class);
}
