<?php

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/ConcurrentChatReadinessBarrier.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $conversation = ChatConversation::query()->findOrFail((int) ($argv[1] ?? 0));
    $owner = $conversation->user_id !== null
        ? ChatOwner::user((int) $conversation->user_id)
        : ChatOwner::guest((string) $conversation->guest_key);

    config()->set('ai-assistant.turn_debounce_ms', 100);
    waitForConcurrentChatRelease($argv[2] ?? '', $argv[3] ?? '');
    $claim = $app->make(CreateOrRecoverAgentTurn::class)->execute($conversation, $owner);

    echo json_encode([
        'publicId' => $claim->turn?->public_id,
        'shouldStart' => $claim->shouldStart,
        'hasPendingMessages' => $claim->hasPendingMessages,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent agent turn claim failed.');

    exit(1);
}
