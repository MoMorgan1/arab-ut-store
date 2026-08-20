<?php

use App\Actions\Chat\CreateChatMessage;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/ConcurrentChatReadinessBarrier.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $conversationId = (int) ($argv[1] ?? 0);
    $clientMessageId = $argv[2] ?? '';
    $lifecycleBarrierKey = $argv[3] ?? null;
    $conversation = ChatConversation::query()->findOrFail($conversationId);
    $owner = $conversation->user_id !== null
        ? ChatOwner::user((int) $conversation->user_id)
        : ChatOwner::guest((string) $conversation->guest_key);

    config()->set('chat.demo_assistant', true);

    if (is_string($lifecycleBarrierKey) && $lifecycleBarrierKey !== '') {
        DB::table('chat_lifecycle_barriers')->insert([
            'race_key' => $lifecycleBarrierKey,
            'sender_ready_at' => now(),
        ]);

        waitForLifecycleMutationCommit($lifecycleBarrierKey);
    }

    waitForConcurrentChatRelease($argv[4] ?? '', $argv[5] ?? '');

    $result = $app->make(CreateChatMessage::class)->execute(
        $conversation,
        'Concurrent duplicate message.',
        $clientMessageId,
        $owner,
    );

    echo json_encode([
        'status' => 'sent',
        'customerPublicId' => $result['message']->public_id,
        'replyPublicId' => $result['demoReply']?->public_id,
    ], JSON_THROW_ON_ERROR);
} catch (ConflictHttpException) {
    echo json_encode(['status' => 'conversation_closed'], JSON_THROW_ON_ERROR);
} catch (ModelNotFoundException) {
    echo json_encode(['status' => 'conversation_not_found'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat message failed.');

    exit(1);
}

function waitForLifecycleMutationCommit(string $barrierKey): void
{
    foreach (range(1, 100) as $_) {
        $committed = DB::table('chat_lifecycle_barriers')
            ->where('race_key', $barrierKey)
            ->whereNotNull('lifecycle_committed_at')
            ->exists();

        if ($committed) {
            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException('Timed out waiting for the chat lifecycle barrier.');
}
