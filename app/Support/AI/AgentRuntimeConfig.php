<?php

namespace App\Support\AI;

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentProvider;
use App\Enums\AI\AgentRollout;
use App\Exceptions\AI\AgentConfigurationException;

final class AgentRuntimeConfig
{
    public function enabled(): bool
    {
        $enabled = $this->value('enabled');

        if (! is_bool($enabled)) {
            $this->invalid();
        }

        return $enabled;
    }

    public function rollout(): AgentRollout
    {
        $rollout = $this->value('rollout');

        return is_string($rollout)
            ? AgentRollout::tryFrom($rollout) ?? AgentRollout::Disabled
            : AgentRollout::Disabled;
    }

    /** @return list<int> */
    public function testUserIds(): array
    {
        $userIds = $this->value('test_user_ids');

        if (! is_array($userIds)) {
            $this->invalid();
        }

        foreach ($userIds as $userId) {
            if (! is_int($userId) || $userId < 1) {
                $this->invalid();
            }
        }

        if (count($userIds) !== count(array_unique($userIds, SORT_REGULAR))) {
            $this->invalid();
        }

        return array_values($userIds);
    }

    public function provider(): AgentProvider
    {
        $provider = $this->value('provider');

        if (! is_string($provider)) {
            $this->invalid();
        }

        return AgentProvider::tryFrom($provider) ?? $this->invalid();
    }

    public function model(): string
    {
        return $this->fixedString('model', 'gpt-5.6-luna');
    }

    public function promptVersion(): string
    {
        return $this->fixedString('prompt_version', 'support-v1');
    }

    public function turnDebounceMilliseconds(): int
    {
        return $this->integerInRange('turn_debounce_ms', 100, 5000);
    }

    public function maxContextMessages(): int
    {
        return $this->integerInRange('max_context_messages', 1, 24);
    }

    public function maxOutputTokens(): int
    {
        return $this->integerInRange('max_output_tokens', 1, 500);
    }

    public function maxResponseCharacters(): int
    {
        return $this->integerInRange('max_response_characters', 1, 4000);
    }

    public function reasoningEffort(): string
    {
        return $this->fixedString('reasoning_effort', 'low');
    }

    public function connectTimeoutSeconds(): int
    {
        $timeout = $this->integerInRange('connect_timeout_seconds', 1, 10);

        if ($timeout > $this->requestTimeoutSeconds()) {
            $this->invalid();
        }

        return $timeout;
    }

    public function streamReadTimeoutSeconds(): int
    {
        $timeout = $this->integerInRange('stream_read_timeout_seconds', 1, 10);

        if ($timeout > $this->requestTimeoutSeconds()) {
            $this->invalid();
        }

        return $timeout;
    }

    public function requestTimeoutSeconds(): int
    {
        return $this->integerInRange('request_timeout_seconds', 1, 60);
    }

    public function turnRateLimitPerMinute(): int
    {
        return $this->integerInRange('turn_rate_limit_per_minute', 1, 120);
    }

    public function turnIpRateLimitPerMinute(): int
    {
        $limit = $this->integerInRange('turn_ip_rate_limit_per_minute', 1, 300);

        if ($limit < $this->turnRateLimitPerMinute()) {
            $this->invalid();
        }

        return $limit;
    }

    public function maxAttempts(): int
    {
        $attempts = $this->value('max_attempts');

        if ($attempts !== 3) {
            $this->invalid();
        }

        return $attempts;
    }

    public function retryAfterCapMilliseconds(): int
    {
        return $this->integerInRange('retry_after_cap_ms', 0, 2000);
    }

    public function staleTurnSeconds(): int
    {
        return $this->integerInRange('stale_turn_seconds', 60, 3600);
    }

    public function fakeDeltaDelayMilliseconds(): int
    {
        return $this->integerInRange('fake_delta_delay_ms', 0, 2000);
    }

    public function pricingVersion(): string
    {
        $version = $this->value('pricing.version');

        if (! is_string($version) || trim($version) === '') {
            $this->invalid();
        }

        return $version;
    }

    public function inputRatePerMillion(): string
    {
        return $this->nonNegativeDecimal('pricing.input_per_million');
    }

    public function cachedInputRatePerMillion(): string
    {
        return $this->nonNegativeDecimal('pricing.cached_input_per_million');
    }

    public function cacheWriteRatePerMillion(): string
    {
        return $this->nonNegativeDecimal('pricing.cache_write_per_million');
    }

    public function outputRatePerMillion(): string
    {
        return $this->nonNegativeDecimal('pricing.output_per_million');
    }

    private function fixedString(string $key, string $expected): string
    {
        $value = $this->value($key);

        if ($value !== $expected) {
            $this->invalid();
        }

        return $value;
    }

    private function integerInRange(string $key, int $minimum, int $maximum): int
    {
        $value = $this->value($key);

        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            $this->invalid();
        }

        return $value;
    }

    private function nonNegativeDecimal(string $key): string
    {
        $value = $this->value($key);

        if (! is_string($value) || preg_match('/\A\d+(?:\.\d+)?\z/D', $value) !== 1) {
            $this->invalid();
        }

        return $value;
    }

    private function value(string $key): mixed
    {
        return config("ai-assistant.{$key}");
    }

    private function invalid(): never
    {
        throw new AgentConfigurationException(AgentErrorCode::ConfigurationInvalid);
    }
}
