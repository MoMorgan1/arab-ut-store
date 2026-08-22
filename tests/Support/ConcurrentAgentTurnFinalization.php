<?php

use App\Actions\AI\FinalizeAgentTurn;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\ValueObjects\AI\AgentModelEvent;
use App\ValueObjects\AI\AgentUsage;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/ConcurrentChatReadinessBarrier.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $turn = AgentTurn::query()->findOrFail((int) ($argv[1] ?? 0));
    $run = AgentRun::query()->findOrFail((int) ($argv[2] ?? 0));
    $readyPath = $argv[3] ?? '';
    $releasePath = $argv[4] ?? '';
    $text = $argv[5] ?? 'Finalized assistant text.';

    waitForConcurrentChatRelease($readyPath, $releasePath);

    $event = AgentModelEvent::completed(
        new AgentUsage(10, 0, 0, 10, 0, 20),
        'resp_concurrent_finalization',
    );

    $message = $app->make(FinalizeAgentTurn::class)->execute(
        $turn,
        $run,
        $text,
        $event,
        150,
    );

    echo json_encode([
        'messageId' => $message->id,
        'content' => $message->content,
        'turnStatus' => $turn->fresh()->status->value,
        'assistantMessageId' => $turn->fresh()->assistant_message_id,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, 'Concurrent agent turn finalization failed: '.$e->getMessage());

    exit(1);
}
