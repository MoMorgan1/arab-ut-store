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
        'prompt_version' => 'support-v1',
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
        ->and($runtime->promptVersion())->toBe('support-v1')
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

test('runtime config rejects invalid values without exposing them', function (string $key, mixed $value, string $method) {
    config()->set($key, $value);

    expect(fn () => app(AgentRuntimeConfig::class)->{$method}())
        ->toThrow(AgentConfigurationException::class, 'Assistant runtime configuration is invalid.');
})->with([
    'enabled must be boolean' => ['ai-assistant.enabled', 'yes', 'enabled'],
    'test users must be unique positive integers' => ['ai-assistant.test_user_ids', [7, 7], 'testUserIds'],
    'provider must be known' => ['ai-assistant.provider', 'unexpected', 'provider'],
    'model is fixed' => ['ai-assistant.model', 'unexpected', 'model'],
    'prompt version is fixed' => ['ai-assistant.prompt_version', 'unexpected', 'promptVersion'],
    'debounce is bounded' => ['ai-assistant.turn_debounce_ms', 99, 'turnDebounceMilliseconds'],
    'context is bounded' => ['ai-assistant.max_context_messages', 25, 'maxContextMessages'],
    'output is bounded' => ['ai-assistant.max_output_tokens', 501, 'maxOutputTokens'],
    'response length is bounded' => ['ai-assistant.max_response_characters', 4001, 'maxResponseCharacters'],
    'reasoning effort is fixed' => ['ai-assistant.reasoning_effort', 'high', 'reasoningEffort'],
    'connection timeout is bounded' => ['ai-assistant.connect_timeout_seconds', 0, 'connectTimeoutSeconds'],
    'stream timeout is bounded' => ['ai-assistant.stream_read_timeout_seconds', 11, 'streamReadTimeoutSeconds'],
    'request timeout is bounded' => ['ai-assistant.request_timeout_seconds', 61, 'requestTimeoutSeconds'],
    'owner rate limit is bounded' => ['ai-assistant.turn_rate_limit_per_minute', 0, 'turnRateLimitPerMinute'],
    'ip rate limit is bounded' => ['ai-assistant.turn_ip_rate_limit_per_minute', 301, 'turnIpRateLimitPerMinute'],
    'attempt count is fixed' => ['ai-assistant.max_attempts', 2, 'maxAttempts'],
    'retry cap is bounded' => ['ai-assistant.retry_after_cap_ms', 2001, 'retryAfterCapMilliseconds'],
    'stale turn time is bounded' => ['ai-assistant.stale_turn_seconds', 59, 'staleTurnSeconds'],
    'fake delay is bounded' => ['ai-assistant.fake_delta_delay_ms', 2001, 'fakeDeltaDelayMilliseconds'],
    'pricing version must be present' => ['ai-assistant.pricing.version', '', 'pricingVersion'],
    'input pricing must be nonnegative decimal' => ['ai-assistant.pricing.input_per_million', '-0.01', 'inputRatePerMillion'],
    'cached input pricing must be nonnegative decimal' => ['ai-assistant.pricing.cached_input_per_million', '-0.01', 'cachedInputRatePerMillion'],
    'cache write pricing must be nonnegative decimal' => ['ai-assistant.pricing.cache_write_per_million', '-0.01', 'cacheWriteRatePerMillion'],
    'output pricing must be nonnegative decimal' => ['ai-assistant.pricing.output_per_million', '-0.01', 'outputRatePerMillion'],
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
