<?php

use App\Actions\Chat\RestartChatConversation;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $conversation = ChatConversation::query()->findOrFail((int) ($argv[1] ?? 0));
    $request = Request::create('/chat/conversations/restart', 'POST');
    $request->setLaravelSession(new Store('concurrent-chat-restart', new ArraySessionHandler(120)));

    $replacement = $app->make(RestartChatConversation::class)->execute(
        ChatOwner::user((int) $conversation->user_id),
        $request,
        null,
    );

    echo json_encode([
        'status' => 'restarted',
        'conversationPublicId' => $replacement->public_id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat restart failed.');

    exit(1);
}
