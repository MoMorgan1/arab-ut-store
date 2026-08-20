<?php

use App\Actions\Chat\CreateChatMessage;
use App\Models\ChatConversation;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $conversationId = (int) ($argv[1] ?? 0);
    $clientMessageId = $argv[2] ?? '';
    $conversation = ChatConversation::query()->findOrFail($conversationId);

    config()->set('chat.demo_assistant', true);

    $result = $app->make(CreateChatMessage::class)->execute(
        $conversation,
        'Concurrent duplicate message.',
        $clientMessageId,
    );

    echo json_encode([
        'customerPublicId' => $result['message']->public_id,
        'replyPublicId' => $result['demoReply']?->public_id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat message failed.');

    exit(1);
}
