<?php

use App\Actions\Chat\RestartChatConversation;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $conversation = ChatConversation::query()->findOrFail((int) ($argv[1] ?? 0));
    $barrierKey = $argv[2] ?? null;
    $request = Request::create('/chat/conversations/restart', 'POST');
    $request->setLaravelSession(new Store('concurrent-chat-restart', new ArraySessionHandler(120)));

    if (is_string($barrierKey) && $barrierKey !== '') {
        waitForStaleSendLookup($barrierKey);
    }

    $replacement = $app->make(RestartChatConversation::class)->execute(
        ChatOwner::user((int) $conversation->user_id),
        $request,
        null,
    );

    if (is_string($barrierKey) && $barrierKey !== '') {
        DB::table('chat_restart_barriers')->where('race_key', $barrierKey)->update([
            'restart_committed_at' => now(),
        ]);
    }

    echo json_encode([
        'status' => 'restarted',
        'conversationPublicId' => $replacement->public_id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat restart failed.');

    exit(1);
}

function waitForStaleSendLookup(string $barrierKey): void
{
    foreach (range(1, 100) as $_) {
        $ready = DB::table('chat_restart_barriers')->where('race_key', $barrierKey)->exists();

        if ($ready) {
            return;
        }

        usleep(100_000);
    }

    throw new RuntimeException('Timed out waiting for the stale send lookup.');
}
