<?php

use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentModelEventType;
use App\Exceptions\AI\AgentDeadlineExceeded;
use App\Services\AI\DeadlineAwareStreamReader;
use App\Services\AI\OpenAiResponsesAgentModel;
use App\Services\AI\OpenAiSseDecoder;
use App\Services\AI\OpenAiStreamHandlerStack;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Tests\Support\AI\FakeMonotonicClock;

function validAgentModelRequest(): AgentModelRequest
{
    return new AgentModelRequest(
        model: 'gpt-5.6-luna',
        instructions: 'Verified support instructions.',
        messages: [['role' => 'user', 'content' => 'Ù…Ø±Ø­Ø¨Ù‹Ø§']],
        safetyIdentifier: str_repeat('a', 64),
        maxOutputTokens: 500,
        reasoningEffort: 'low',
        locale: 'ar',
    );
}

test('matched Http fake is recorded through the custom base handler', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(
            "data: {\"type\":\"response.output_text.delta\",\"delta\":\"Ù…Ø±Ø­Ø¨Ù‹Ø§\"}\n\n".
            "data: {\"type\":\"response.completed\",\"response\":{\"id\":\"resp_fixture_01\",\"usage\":{\"input_tokens\":1000,\"input_tokens_details\":{\"cached_tokens\":200,\"cache_write_tokens\":100},\"output_tokens\":300,\"output_tokens_details\":{\"reasoning_tokens\":80},\"total_tokens\":1300}}}\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);
    config()->set('services.openai.key', 'unit-test-key-not-a-real-secret');

    $request = validAgentModelRequest();
    $clock = new FakeMonotonicClock;
    $deadline = AgentDeadline::afterSeconds(
        $clock,
        app(AgentRuntimeConfig::class)->requestTimeoutSeconds(),
    );
    $events = iterator_to_array(
        app(OpenAiResponsesAgentModel::class)->stream($request, $deadline),
    );

    Http::assertSent(function (Request $sent): bool {
        $data = $sent->data();
        $expectedKeys = [
            'model',
            'instructions',
            'input',
            'store',
            'stream',
            'reasoning',
            'max_output_tokens',
            'safety_identifier',
        ];
        $actualKeys = array_keys($data);
        sort($expectedKeys);
        sort($actualKeys);

        return $sent->url() === 'https://api.openai.com/v1/responses'
            && $actualKeys === $expectedKeys
            && $sent['model'] === 'gpt-5.6-luna'
            && $sent['instructions'] === 'Verified support instructions.'
            && $sent['input'] === [['role' => 'user', 'content' => 'Ù…Ø±Ø­Ø¨Ù‹Ø§']]
            && $sent['store'] === false
            && $sent['stream'] === true
            && $sent['reasoning'] === ['effort' => 'low']
            && $sent['max_output_tokens'] === 500
            && $sent['safety_identifier'] === str_repeat('a', 64);
    });

    expect($events)->toHaveCount(2)
        ->and($events[0]->delta)->toBe('Ù…Ø±Ø­Ø¨Ù‹Ø§')
        ->and($events[1]->usage->inputTokens)->toBe(1000)
        ->and($events[1]->usage->cachedInputTokens)->toBe(200)
        ->and($events[1]->usage->cacheWriteTokens)->toBe(100)
        ->and($events[1]->usage->outputTokens)->toBe(300)
        ->and($events[1]->usage->reasoningTokens)->toBe(80)
        ->and($events[1]->usage->totalTokens)->toBe(1300)
        ->and($events[1]->providerResponseId)->toBe('resp_fixture_01');
});

test('preventStrayRequests blocks an unmatched URL through the custom base handler', function () {
    Http::preventStrayRequests();
    config()->set('services.openai.key', 'unit-test-key-not-a-real-secret');
    $clock = new FakeMonotonicClock;
    $deadline = AgentDeadline::afterSeconds(
        $clock,
        app(AgentRuntimeConfig::class)->requestTimeoutSeconds(),
    );

    expect(fn () => iterator_to_array(
        app(OpenAiResponsesAgentModel::class)->stream(
            validAgentModelRequest(),
            $deadline,
        ),
    ))->toThrow(StrayRequestException::class);
});

test('continuous nonterminal events cannot overrun the monotonic total deadline', function () {
    config()->set('ai-assistant.request_timeout_seconds', 5);
    $events = implode("\n\n", array_fill(
        0,
        20,
        'data: {"type":"response.in_progress"}',
    ))."\n\n";
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(
            $events,
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);
    config()->set('services.openai.key', 'unit-test-key-not-a-real-secret');
    $clock = FakeMonotonicClock::advancingByMilliseconds(500);
    $deadline = AgentDeadline::afterSeconds(
        $clock,
        app(AgentRuntimeConfig::class)->requestTimeoutSeconds(),
    );

    expect(fn () => iterator_to_array(
        app(OpenAiResponsesAgentModel::class)->stream(validAgentModelRequest(), $deadline),
    ))->toThrow(AgentDeadlineExceeded::class)
        ->and($clock->elapsedMilliseconds())->toBeLessThanOrEqual(5500);
});

test('missing api key fails with ConfigurationInvalid event without throwing', function () {
    config()->set('services.openai.key', '');
    $clock = new FakeMonotonicClock;
    $deadline = AgentDeadline::afterSeconds($clock, 30);

    $events = iterator_to_array(
        app(OpenAiResponsesAgentModel::class)->stream(validAgentModelRequest(), $deadline),
    );

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(AgentModelEventType::Failed)
        ->and($events[0]->errorCode)->toBe(AgentErrorCode::ConfigurationInvalid);
});

test('invalid request parameters fail with InvalidAgentRequest event', function (AgentModelRequest $invalidRequest) {
    config()->set('services.openai.key', 'unit-test-key');
    $clock = new FakeMonotonicClock;
    $deadline = AgentDeadline::afterSeconds($clock, 30);

    $events = iterator_to_array(
        app(OpenAiResponsesAgentModel::class)->stream($invalidRequest, $deadline),
    );

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(AgentModelEventType::Failed)
        ->and($events[0]->errorCode)->toBe(AgentErrorCode::InvalidAgentRequest);
})->with([
    'wrong model' => fn () => new AgentModelRequest('gpt-4o', 'inst', [['role' => 'user', 'content' => 'hi']], str_repeat('a', 64), 500, 'low', 'en'),
    'wrong reasoning' => fn () => new AgentModelRequest('gpt-5.6-luna', 'inst', [['role' => 'user', 'content' => 'hi']], str_repeat('a', 64), 500, 'high', 'en'),
    'negative tokens' => fn () => new AgentModelRequest('gpt-5.6-luna', 'inst', [['role' => 'user', 'content' => 'hi']], str_repeat('a', 64), 0, 'low', 'en'),
    'excess tokens' => fn () => new AgentModelRequest('gpt-5.6-luna', 'inst', [['role' => 'user', 'content' => 'hi']], str_repeat('a', 64), 1001, 'low', 'en'),
    'invalid safety id' => fn () => new AgentModelRequest('gpt-5.6-luna', 'inst', [['role' => 'user', 'content' => 'hi']], 'short-id', 500, 'low', 'en'),
    'empty messages' => fn () => new AgentModelRequest('gpt-5.6-luna', 'inst', [], str_repeat('a', 64), 500, 'low', 'en'),
]);

test('http error status codes map exhaustively to typed failure events', function (int $status, AgentErrorCode $expectedCode) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(['error' => 'fail'], $status),
    ]);
    config()->set('services.openai.key', 'unit-test-key');
    $clock = new FakeMonotonicClock;
    $deadline = AgentDeadline::afterSeconds($clock, 30);

    $events = iterator_to_array(
        app(OpenAiResponsesAgentModel::class)->stream(validAgentModelRequest(), $deadline),
    );

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(AgentModelEventType::Failed)
        ->and($events[0]->errorCode)->toBe($expectedCode);
})->with([
    '400 rejected' => [400, AgentErrorCode::ProviderRequestRejected],
    '401 auth' => [401, AgentErrorCode::ProviderAuthenticationFailed],
    '403 permission' => [403, AgentErrorCode::ProviderPermissionDenied],
    '404 rejected' => [404, AgentErrorCode::ProviderRequestRejected],
    '409 rejected' => [409, AgentErrorCode::ProviderRequestRejected],
    '422 rejected' => [422, AgentErrorCode::ProviderRequestRejected],
    '429 rate limited' => [429, AgentErrorCode::RateLimited],
    '500 server' => [500, AgentErrorCode::ProviderServerError],
    '503 server' => [503, AgentErrorCode::ProviderServerError],
    '418 unlisted' => [418, AgentErrorCode::ProviderRequestRejected],
]);

test('rate limit parses valid delta seconds, future http date, invalid, and past dates', function (?string $retryAfterHeader, int $expectedRetryAfterMs) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(
            ['error' => 'rate_limited'],
            429,
            $retryAfterHeader !== null ? ['Retry-After' => $retryAfterHeader] : [],
        ),
    ]);
    config()->set('services.openai.key', 'unit-test-key');

    $fixedWallClock = fn (): DateTimeImmutable => new DateTimeImmutable('2026-08-21 12:00:00', new DateTimeZone('UTC'));

    $adapter = new OpenAiResponsesAgentModel(
        app(AgentRuntimeConfig::class),
        app(OpenAiStreamHandlerStack::class),
        app(DeadlineAwareStreamReader::class),
        app(OpenAiSseDecoder::class),
        $fixedWallClock,
    );

    $clock = new FakeMonotonicClock;
    $deadline = AgentDeadline::afterSeconds($clock, 30);
    $events = iterator_to_array($adapter->stream(validAgentModelRequest(), $deadline));

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(AgentModelEventType::Failed)
        ->and($events[0]->errorCode)->toBe(AgentErrorCode::RateLimited)
        ->and($events[0]->retryAfterMilliseconds)->toBe($expectedRetryAfterMs);
})->with([
    'valid 5 seconds' => ['5', 5000],
    'valid 0 seconds' => ['0', 0],
    'invalid string' => ['invalid-delta', 0],
    'absent header' => [null, 0],
    'future HTTP date (10s ahead)' => ['Fri, 21 Aug 2026 12:00:10 GMT', 10000],
    'past HTTP date (10s behind)' => ['Fri, 21 Aug 2026 11:59:50 GMT', 0],
]);

test('provider events map correctly across terminal, error, and malformed cases', function (string $ssePayload, AgentErrorCode $expectedCode) {
    Http::fake([
        'https://api.openai.com/v1/responses' => Http::response(
            $ssePayload,
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);
    config()->set('services.openai.key', 'unit-test-key');
    $clock = new FakeMonotonicClock;
    $deadline = AgentDeadline::afterSeconds($clock, 30);

    $events = iterator_to_array(
        app(OpenAiResponsesAgentModel::class)->stream(validAgentModelRequest(), $deadline),
    );

    expect($events)->toHaveCount(1)
        ->and($events[0]->type)->toBe(AgentModelEventType::Failed)
        ->and($events[0]->errorCode)->toBe($expectedCode);
})->with([
    'response.failed' => ["data: {\"type\":\"response.failed\"}\n\n", AgentErrorCode::ProviderTerminalFailure],
    'response.incomplete' => ["data: {\"type\":\"response.incomplete\"}\n\n", AgentErrorCode::ProviderIncomplete],
    'error rate_limit' => ["data: {\"type\":\"error\",\"error\":{\"code\":\"rate_limit_exceeded\"}}\n\n", AgentErrorCode::RateLimited],
    'error server' => ["data: {\"type\":\"error\",\"error\":{\"code\":\"server_error\"}}\n\n", AgentErrorCode::ProviderServerError],
    'error auth' => ["data: {\"type\":\"error\",\"error\":{\"code\":\"invalid_api_key\"}}\n\n", AgentErrorCode::ProviderAuthenticationFailed],
    'error permission' => ["data: {\"type\":\"error\",\"error\":{\"code\":\"permission_denied\"}}\n\n", AgentErrorCode::ProviderPermissionDenied],
    'error invalid_request' => ["data: {\"type\":\"error\",\"error\":{\"code\":\"invalid_request_error\"}}\n\n", AgentErrorCode::ProviderRequestRejected],
    'error unknown' => ["data: {\"type\":\"error\",\"error\":{\"code\":\"custom_unrecognized_error\"}}\n\n", AgentErrorCode::ProviderTerminalFailure],
    'malformed json' => ["data: {malformed-json\n\n", AgentErrorCode::ProviderMalformed],
    'malformed usage structure' => ["data: {\"type\":\"response.completed\",\"response\":{\"id\":\"r1\",\"usage\":\"invalid\"}}\n\n", AgentErrorCode::ProviderMalformed],
    'eof without terminal' => ["data: {\"type\":\"response.in_progress\"}\n\n", AgentErrorCode::ProviderIncomplete],
]);
