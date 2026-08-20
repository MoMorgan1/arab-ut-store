<?php

use App\Actions\Chat\CreateChatMessage;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$arguments = $_SERVER['argv'] ?? null;

if (! is_array($arguments) || count($arguments) < 5) {
    fwrite(STDERR, 'Concurrent chat message worker requires four arguments.');

    exit(2);
}

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$failureStage = 'setup';

try {
    config()->set('chat.demo_assistant', true);
    $clientMessageId = (string) $arguments[2];
    $readyPath = (string) $arguments[3];
    $releasePath = (string) $arguments[4];
    ChatMessage::creating(static function (ChatMessage $message) use (
        &$failureStage,
        $clientMessageId,
        $readyPath,
        $releasePath,
    ): void {
        if ($message->client_message_id !== $clientMessageId) {
            return;
        }

        $failureStage = 'barrier';
        awaitConcurrentChatMessageRelease($readyPath, $releasePath);
        $failureStage = 'message insert';
    });
    $failureStage = 'conversation lookup';
    $conversation = ChatConversation::query()->findOrFail((int) $arguments[1]);
    $failureStage = 'message action';
    $created = $app->make(CreateChatMessage::class)->execute(
        $conversation,
        'Concurrent message content',
        $clientMessageId,
    );
} catch (Throwable) {
    fwrite(STDERR, "Concurrent chat message worker failed during {$failureStage}.");

    exit(1);
}

echo json_encode([
    'customerPublicId' => $created['message']->public_id,
    'replyPublicId' => $created['demoReply']?->public_id,
], JSON_THROW_ON_ERROR);

function awaitConcurrentChatMessageRelease(string $readyPath, string $releasePath): void
{
    if (file_put_contents($readyPath, 'ready', LOCK_EX) === false) {
        throw new RuntimeException('Unable to signal concurrent chat message worker readiness.');
    }

    $deadline = microtime(true) + 20;

    while (! file_exists($releasePath)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting to release concurrent chat message worker.');
        }

        usleep(25_000);
    }
}
