<?php

namespace App\Services\AI;

use App\Support\AI\AgentRuntimeConfig;
use App\ValueObjects\AI\AgentDeadline;
use Generator;
use Illuminate\Http\Client\Response;
use RuntimeException;

final readonly class DeadlineAwareStreamReader
{
    public function __construct(
        private AgentRuntimeConfig $config,
    ) {}

    /**
     * @return Generator<int, string, mixed, void>
     */
    public function chunks(Response $response, AgentDeadline $deadline): Generator
    {
        $body = $response->toPsrResponse()->getBody();
        $resource = $body->detach();

        if (! is_resource($resource)) {
            $content = (string) $response->body();
            if ($content !== '') {
                $deadline->throwIfExpired();
                yield $content;
            }

            return;
        }

        try {
            while (! feof($resource)) {
                $deadline->throwIfExpired();

                $remainingSeconds = max(0.001, $deadline->remainingMilliseconds() / 1000.0);
                $readTimeoutSeconds = min(
                    (float) $this->config->streamReadTimeoutSeconds(),
                    $remainingSeconds,
                );

                $seconds = (int) floor($readTimeoutSeconds);
                $microseconds = (int) round(($readTimeoutSeconds - $seconds) * 1_000_000);

                stream_set_timeout($resource, $seconds, $microseconds);

                $chunk = fread($resource, 8192);

                $meta = stream_get_meta_data($resource);

                if ($chunk === false || $chunk === '') {
                    if ($meta['timed_out']) {
                        throw new RuntimeException('stream_timed_out');
                    }

                    break;
                }

                yield $chunk;
            }
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }
    }
}
