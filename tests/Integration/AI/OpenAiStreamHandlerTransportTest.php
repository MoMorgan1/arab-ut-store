<?php

use App\Enums\AI\AgentModelEventType;
use App\Services\AI\OpenAiResponsesAgentModel;
use App\Support\AI\AgentRuntimeConfig;
use App\Support\AI\SystemMonotonicClock;
use App\ValueObjects\AI\AgentDeadline;
use App\ValueObjects\AI\AgentModelRequest;
use Symfony\Component\Process\Process;
use Tests\TestCase;

uses(TestCase::class);

test('real stream handler transport streams over loopback', function () {
    $hasWrappers = in_array('http', stream_get_wrappers(), true);
    $allowUrlFopen = (bool) ini_get('allow_url_fopen');

    if (! $hasWrappers || ! $allowUrlFopen) {
        if (getenv('CI') === 'true' || getenv('CI') === '1') {
            $this->fail('Configured CI PHP lacks stream-handler support.');
        }

        $this->markTestSkipped('Configured local PHP lacks stream-handler support.');
    }

    $port = 8996;
    $serverProcess = new Process([
        PHP_BINARY,
        '-S',
        "127.0.0.1:{$port}",
        __DIR__.'/../../Fixtures/AI/streaming-provider.php',
    ]);

    $serverProcess->start();

    $ready = false;
    $start = microtime(true);
    while (microtime(true) - $start < 3.0) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
        if (is_resource($conn)) {
            fclose($conn);
            $ready = true;
            break;
        }
        usleep(50000);
    }

    if (! $ready) {
        $serverProcess->stop();
        $this->fail('Streaming provider fixture failed to start.');
    }

    try {
        config()->set('services.openai.base_url', "http://127.0.0.1:{$port}");
        config()->set('services.openai.key', 'loopback-test-key');

        $request = new AgentModelRequest(
            model: 'gpt-5.6-luna',
            instructions: 'Loopback instruction.',
            messages: [['role' => 'user', 'content' => 'Hello']],
            safetyIdentifier: str_repeat('b', 64),
            maxOutputTokens: 500,
            reasoningEffort: 'low',
            locale: 'en',
        );

        $deadline = AgentDeadline::afterSeconds(
            app(SystemMonotonicClock::class),
            app(AgentRuntimeConfig::class)->requestTimeoutSeconds(),
        );

        $model = app(OpenAiResponsesAgentModel::class);
        $events = iterator_to_array($model->stream($request, $deadline));

        expect($events)->toHaveCount(3)
            ->and($events[0]->type)->toBe(AgentModelEventType::Delta)
            ->and($events[0]->delta)->toBe('Hello ')
            ->and($events[1]->type)->toBe(AgentModelEventType::Delta)
            ->and($events[1]->delta)->toBe('World')
            ->and($events[2]->type)->toBe(AgentModelEventType::Completed)
            ->and($events[2]->usage->totalTokens)->toBe(150)
            ->and($events[2]->providerResponseId)->toBe('resp_loopback_01');
    } finally {
        $serverProcess->stop();
    }
});
