<?php

use App\Actions\Chat\CreateChatMessage;
use App\Models\ChatConversation;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/ConcurrentChatReadinessBarrier.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $conversationId = (int) ($argv[1] ?? 0);
    $clientMessageId = $argv[2] ?? '';
    $barrierKey = $argv[3] ?? null;
    $conversation = ChatConversation::query()->findOrFail($conversationId);

    config()->set('chat.demo_assistant', true);

    if (is_string($barrierKey) && $barrierKey !== '') {
        DB::table('chat_restart_barriers')->insert([
            'race_key' => $barrierKey,
            'sender_ready_at' => now(),
        ]);

        waitForRestartCommit($barrierKey);
    }

    waitForConcurrentChatRelease($argv[4] ?? '', $argv[5] ?? '');

    $result = $app->make(CreateChatMessage::class)->execute(
        $conversation,
        'Concurrent duplicate message.',
        $clientMessageId,
    );

    echo json_encode([
        'status' => 'sent',
        'customerPublicId' => $result['message']->public_id,
        'replyPublicId' => $result['demoReply']?->public_id,
    ], JSON_THROW_ON_ERROR);
} catch (ConflictHttpException) {
    echo json_encode(['status' => 'conversation_closed'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat message failed.');

    exit(1);
}

function waitForRestartCommit(string $barrierKey): void
{
    foreach (range(1, 100) as $_) {
        $committed = DB::table('chat_restart_barriers')
            ->where('race_key', $barrierKey)
            ->whereNotNull('restart_committed_at')
            ->exists();

        if ($committed) {
            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException('Timed out waiting for the restart barrier.');
}
