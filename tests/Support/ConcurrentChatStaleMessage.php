<?php

use App\Actions\Chat\CreateChatMessage;
use App\Exceptions\Chat\ChatConversationWriteRejected;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;

if (! is_array($arguments) || count($arguments) < 7) {
    fwrite(STDERR, 'Concurrent stale chat message worker requires six arguments.');

    exit(2);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$failureStage = 'setup';

try {
    config()->set('chat.demo_assistant', true);
    $conversation = ChatConversation::query()->findOrFail((int) $arguments[1]);
    $owner = match ((string) $arguments[2]) {
        'user' => ChatOwner::user((int) $arguments[3]),
        'guest' => ChatOwner::guest((string) $arguments[3]),
        default => throw new InvalidArgumentException('Unsupported stale message owner type.'),
    };
    $clientMessageId = (string) $arguments[4];
    $readyPath = (string) $arguments[5];
    $releasePath = (string) $arguments[6];
    awaitConcurrentStaleChatMessageRelease($readyPath, $releasePath);
    $failureStage = 'message action';
    $created = $app->make(CreateChatMessage::class)->execute(
        $conversation,
        'Stale concurrent message content',
        $clientMessageId,
        $owner,
    );

    echo json_encode([
        'outcome' => 'created',
        'customerPublicId' => $created['message']->public_id,
        'replyPublicId' => $created['demoReply']?->public_id,
    ], JSON_THROW_ON_ERROR);
} catch (ChatConversationWriteRejected $exception) {
    echo json_encode(['outcome' => $exception->errorCode()], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, "Concurrent stale chat message worker failed during {$failureStage}.");

    exit(1);
}

function awaitConcurrentStaleChatMessageRelease(string $readyPath, string $releasePath): void
{
    if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal concurrent stale chat message worker readiness.');
    }

    $deadline = microtime(true) + 20;

    while (! file_exists($releasePath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting to release concurrent stale chat message worker.');
        }

        usleep(25_000);
    }
}
