<?php

use App\Services\AI\EstimateAgentRunCost;
use App\ValueObjects\AI\AgentUsage;
use Tests\TestCase;

uses(TestCase::class);

test('cost estimator computes exact USD cost for canonical fixture', function () {
    $estimator = app(EstimateAgentRunCost::class);

    $usage = new AgentUsage(
        inputTokens: 1000,
        cachedInputTokens: 200,
        cacheWriteTokens: 100,
        outputTokens: 300,
        reasoningTokens: 80,
        totalTokens: 1300,
    );

    $cost = $estimator->for($usage);

    // 700 * 0.20 + 200 * 0.02 + 100 * 0.25 + 300 * 1.20 = 140 + 4 + 25 + 360 = 529 => 0.00052900
    expect($cost)->toBe('0.00052900');
});

test('cost estimator reflects independent rate mutations across all categories', function () {
    $estimator = app(EstimateAgentRunCost::class);

    $usage = new AgentUsage(
        inputTokens: 1000,
        cachedInputTokens: 200,
        cacheWriteTokens: 100,
        outputTokens: 300,
        reasoningTokens: 80,
        totalTokens: 1300,
    );

    // Double input rate: 0.20 -> 0.40 (uncached 700 goes from 140 to 280, total 529 -> 669)
    config()->set('ai-assistant.pricing.input_per_million', '0.40');
    expect($estimator->for($usage))->toBe('0.00066900');
    config()->set('ai-assistant.pricing.input_per_million', '0.20');

    // Double cached input rate: 0.02 -> 0.04 (cached 200 goes from 4 to 8, total 529 -> 533)
    config()->set('ai-assistant.pricing.cached_input_per_million', '0.04');
    expect($estimator->for($usage))->toBe('0.00053300');
    config()->set('ai-assistant.pricing.cached_input_per_million', '0.02');

    // Double cache write rate: 0.25 -> 0.50 (cache write 100 goes from 25 to 50, total 529 -> 554)
    config()->set('ai-assistant.pricing.cache_write_per_million', '0.50');
    expect($estimator->for($usage))->toBe('0.00055400');
    config()->set('ai-assistant.pricing.cache_write_per_million', '0.25');

    // Double output rate: 1.20 -> 2.40 (output 300 goes from 360 to 720, total 529 -> 889)
    config()->set('ai-assistant.pricing.output_per_million', '2.40');
    expect($estimator->for($usage))->toBe('0.00088900');
    config()->set('ai-assistant.pricing.output_per_million', '1.20');
});

test('reasoning tokens are never added to cost accounting', function () {
    $estimator = app(EstimateAgentRunCost::class);

    $usage1 = new AgentUsage(
        inputTokens: 1000,
        cachedInputTokens: 200,
        cacheWriteTokens: 100,
        outputTokens: 300,
        reasoningTokens: 0,
        totalTokens: 1300,
    );

    $usage2 = new AgentUsage(
        inputTokens: 1000,
        cachedInputTokens: 200,
        cacheWriteTokens: 100,
        outputTokens: 300,
        reasoningTokens: 250,
        totalTokens: 1300,
    );

    expect($estimator->for($usage1))->toBe('0.00052900')
        ->and($estimator->for($usage2))->toBe('0.00052900');
});

test('uncached input clamps to zero when cached and write tokens exceed total input', function () {
    $estimator = app(EstimateAgentRunCost::class);

    $usage = new AgentUsage(
        inputTokens: 100,
        cachedInputTokens: 100,
        cacheWriteTokens: 50,
        outputTokens: 0,
        reasoningTokens: 0,
        totalTokens: 150,
    );

    // uncachedInput = max(0, 100 - 100 - 50) = 0
    // usd = (0 * 0.20 + 100 * 0.02 + 50 * 0.25 + 0) / 1_000_000 = (2 + 12.5) / 1_000_000 = 14.5 / 1_000_000 = 0.00001450
    expect($estimator->for($usage))->toBe('0.00001450');
});
