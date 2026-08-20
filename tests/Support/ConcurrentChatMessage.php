<?php

use App\Actions\Chat\CreateChatMessage;
use App\Models\ChatConversation;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    config()->set('chat.demo_assistant', true);
    $conversation = ChatConversation::query()->findOrFail((int) $argv[1]);
    $created = $app->make(CreateChatMessage::class)->execute(
        $conversation,
        'Concurrent message content',
        (string) $argv[2],
    );
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat message failed.');

    exit(1);
}

echo json_encode([
    'customerPublicId' => $created['message']->public_id,
    'replyPublicId' => $created['demoReply']?->public_id,
], JSON_THROW_ON_ERROR);
