<?php

namespace App\Services\AI;

use App\Contracts\AI\AgentModel;
use App\Enums\AI\AgentErrorCode;
use App\Enums\AI\AgentModelEventType;
use App\Exceptions\AI\AgentDeadlineExceeded;
use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentModelRequest;
use App\ValueObjects\AI\AgentUsage;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Generator;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class OpenAiResponsesAgentModel implements AgentModel
{
    public function __construct(
        private AgentRuntimeConfig $config,
        private OpenAiStreamHandlerStack $streamHandlerStack,
        private DeadlineAwareStreamReader $streamReader,
        private OpenAiSseDecoder $decoder,
        private ?Closure $wallClock = null,
    ) {}

    /**
     * @return Generator<int, AgentModelEvent, mixed, void>
     */
    public function stream(AgentModelRequest $request, AgentDeadline $deadline): Generator
    {
        $deadline->throwIfExpired();

        $apiKey = (string) config('services.openai.key', '');
        if (trim($apiKey) === '') {
            yield AgentModelEvent::failed(AgentErrorCode::ConfigurationInvalid, null);

            return;
        }

        if ($request->model !== $this->config->model()
            || $request->reasoningEffort !== $this->config->reasoningEffort()
            || $request->maxOutputTokens < 1
            || $request->maxOutputTokens > $this->config->maxOutputTokens()
            || preg_match('/\A[0-9a-f]{64}\z/D', $request->safetyIdentifier) !== 1
            || $request->messages === []
            || count($request->messages) > $this->config->maxContextMessages()
        ) {
            yield AgentModelEvent::failed(AgentErrorCode::InvalidAgentRequest, null);

            return;
        }

        $payload = [
            'model' => $request->model,
            'instructions' => $request->instructions,
            'input' => $request->messages,
            'store' => false,
            'stream' => true,
            'reasoning' => ['effort' => $request->reasoningEffort],
            'max_output_tokens' => $request->maxOutputTokens,
            'safety_identifier' => $request->safetyIdentifier,
        ];

        $deadline->throwIfExpired();
        $remainingSeconds = max(0.001, $deadline->remainingMilliseconds() / 1000.0);
        $baseUrl = (string) config('services.openai.base_url', 'https://api.openai.com/v1');

        $pendingRequest = Http::baseUrl($baseUrl)
            ->withToken($apiKey)
            ->acceptJson();

        $pendingRequest->setHandler($this->streamHandlerStack->make());

        try {
            $response = $pendingRequest
                ->withOptions([
                    'stream' => true,
                    // Connect, per-read, and total budgets are distinct Guzzle
                    // options; each is capped by the remaining turn deadline.
                    'connect_timeout' => min(
                        (float) $this->config->connectTimeoutSeconds(),
                        $remainingSeconds,
                    ),
                    'read_timeout' => min(
                        (float) $this->config->streamReadTimeoutSeconds(),
                        $remainingSeconds,
                    ),
                    'timeout' => min(
                        (float) $this->config->requestTimeoutSeconds(),
                        $remainingSeconds,
                    ),
                ])
                ->send('POST', '/responses', ['json' => $payload]);
        } catch (AgentDeadlineExceeded $e) {
            throw $e;
        } catch (StrayRequestException $e) {
            throw $e;
        } catch (ConnectionException|ConnectException) {
            yield AgentModelEvent::failed(AgentErrorCode::ProviderConnectionFailed, null);

            return;
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout')) {
                yield AgentModelEvent::failed(AgentErrorCode::ProviderTimeout, null);

                return;
            }

            yield AgentModelEvent::failed(AgentErrorCode::ProviderConnectionFailed, null);

            return;
        }

        $deadline->throwIfExpired();
        $status = $response->status();

        if ($status !== 200) {
            $errorCode = match (true) {
                $status === 400, $status === 404, $status === 409, $status === 422 => AgentErrorCode::ProviderRequestRejected,
                $status === 401 => AgentErrorCode::ProviderAuthenticationFailed,
                $status === 403 => AgentErrorCode::ProviderPermissionDenied,
                $status === 429 => AgentErrorCode::RateLimited,
                $status >= 500 && $status <= 599 => AgentErrorCode::ProviderServerError,
                default => AgentErrorCode::ProviderRequestRejected,
            };

            $retryAfterMs = null;
            if ($status === 429) {
                $retryAfterHeader = $response->header('Retry-After');
                $retryAfterMs = $this->parseRetryAfter($retryAfterHeader);
            }

            yield AgentModelEvent::failed($errorCode, $retryAfterMs);

            return;
        }

        $this->decoder->reset();

        try {
            foreach ($this->streamReader->chunks($response, $deadline) as $chunk) {
                $deadline->throwIfExpired();

                foreach ($this->decoder->push($chunk) as $providerEvent) {
                    $deadline->throwIfExpired();
                    $mapped = $this->mapProviderEvent($providerEvent);

                    if ($mapped !== null) {
                        yield $mapped;

                        if ($mapped->type === AgentModelEventType::Completed || $mapped->type === AgentModelEventType::Failed) {
                            return;
                        }
                    }
                }
            }
        } catch (AgentDeadlineExceeded $e) {
            throw $e;
        } catch (Throwable $e) {
            if ($e->getMessage() === 'stream_timed_out') {
                yield AgentModelEvent::failed(AgentErrorCode::ProviderTimeout, null);

                return;
            }

            yield AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);

            return;
        }

        yield AgentModelEvent::failed(AgentErrorCode::ProviderIncomplete, null);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function mapProviderEvent(array $event): ?AgentModelEvent
    {
        if (isset($event['__malformed__'])) {
            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        $type = $event['type'] ?? null;
        if (! is_string($type)) {
            if (isset($event['error']) && is_array($event['error'])) {
                return $this->mapErrorEvent($event['error']);
            }

            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        return match ($type) {
            'response.output_text.delta' => $this->mapDeltaEvent($event),
            'response.completed' => $this->mapCompletedEvent($event),
            'response.failed' => AgentModelEvent::failed(AgentErrorCode::ProviderTerminalFailure, null),
            'response.incomplete' => AgentModelEvent::failed(AgentErrorCode::ProviderIncomplete, null),
            'error' => $this->mapErrorEvent($event['error'] ?? $event),
            'done' => null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function mapDeltaEvent(array $event): ?AgentModelEvent
    {
        $delta = $event['delta'] ?? null;
        if (! is_string($delta)) {
            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        if ($delta === '') {
            return null;
        }

        return AgentModelEvent::delta($delta);
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function mapCompletedEvent(array $event): AgentModelEvent
    {
        $response = $event['response'] ?? null;
        if (! is_array($response)) {
            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        $id = $response['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        $usage = $response['usage'] ?? null;
        if (! is_array($usage)) {
            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        $inputTokens = $usage['input_tokens'] ?? null;
        $outputTokens = $usage['output_tokens'] ?? null;
        $totalTokens = $usage['total_tokens'] ?? null;

        if (! is_int($inputTokens) || ! is_int($outputTokens) || ! is_int($totalTokens)
            || $inputTokens < 0 || $outputTokens < 0 || $totalTokens < 0) {
            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        $inputDetails = $usage['input_tokens_details'] ?? [];
        $outputDetails = $usage['output_tokens_details'] ?? [];

        $cachedTokens = is_array($inputDetails) ? ($inputDetails['cached_tokens'] ?? 0) : 0;
        $cacheWriteTokens = is_array($inputDetails) ? ($inputDetails['cache_write_tokens'] ?? 0) : 0;
        $reasoningTokens = is_array($outputDetails) ? ($outputDetails['reasoning_tokens'] ?? 0) : 0;

        if (! is_int($cachedTokens) || ! is_int($cacheWriteTokens) || ! is_int($reasoningTokens)
            || $cachedTokens < 0 || $cacheWriteTokens < 0 || $reasoningTokens < 0) {
            return AgentModelEvent::failed(AgentErrorCode::ProviderMalformed, null);
        }

        $agentUsage = new AgentUsage(
            inputTokens: $inputTokens,
            cachedInputTokens: $cachedTokens,
            cacheWriteTokens: $cacheWriteTokens,
            outputTokens: $outputTokens,
            reasoningTokens: $reasoningTokens,
            totalTokens: $totalTokens,
        );

        return AgentModelEvent::completed($agentUsage, $id);
    }

    private function mapErrorEvent(mixed $error): AgentModelEvent
    {
        if (! is_array($error)) {
            return AgentModelEvent::failed(AgentErrorCode::ProviderTerminalFailure, null);
        }

        $code = strtolower((string) ($error['code'] ?? $error['type'] ?? ''));

        $mappedCode = match (true) {
            str_contains($code, 'rate_limit') || str_contains($code, 'insufficient_quota') => AgentErrorCode::RateLimited,
            str_contains($code, 'server_error') || str_contains($code, 'internal_error') => AgentErrorCode::ProviderServerError,
            str_contains($code, 'auth') || str_contains($code, 'invalid_api_key') || str_contains($code, 'unauthorized') => AgentErrorCode::ProviderAuthenticationFailed,
            str_contains($code, 'permission') || str_contains($code, 'forbidden') || str_contains($code, 'access_denied') => AgentErrorCode::ProviderPermissionDenied,
            str_contains($code, 'invalid_request') || str_contains($code, 'bad_request') => AgentErrorCode::ProviderRequestRejected,
            default => AgentErrorCode::ProviderTerminalFailure,
        };

        return AgentModelEvent::failed($mappedCode, null);
    }

    private function parseRetryAfter(?string $header): int
    {
        if ($header === null || trim($header) === '') {
            return 0;
        }

        $header = trim($header);

        if (preg_match('/\A\d+\z/D', $header) === 1) {
            $seconds = (int) $header;

            return max(0, $seconds * 1000);
        }

        try {
            $targetDate = new DateTimeImmutable($header, new DateTimeZone('UTC'));
            $now = $this->wallClockNow();

            $diffSeconds = $targetDate->getTimestamp() - $now->getTimestamp();

            return $diffSeconds > 0 ? $diffSeconds * 1000 : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    private function wallClockNow(): DateTimeImmutable
    {
        if ($this->wallClock !== null) {
            $result = ($this->wallClock)();
            if ($result instanceof DateTimeImmutable) {
                return $result;
            }
            if ($result instanceof DateTimeInterface) {
                return DateTimeImmutable::createFromInterface($result);
            }
        }

        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
