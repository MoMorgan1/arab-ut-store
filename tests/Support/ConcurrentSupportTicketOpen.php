<?php

use App\Actions\Support\OpenSupportTicket;
use App\Models\ChatConversation;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/ConcurrentChatReadinessBarrier.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $conversation = ChatConversation::query()->findOrFail((int) ($argv[1] ?? 0));
    $user = User::query()->findOrFail((int) ($argv[2] ?? 0));

    waitForConcurrentChatRelease($argv[3] ?? '', $argv[4] ?? '');

    $ticket = $app->make(OpenSupportTicket::class)->execute($conversation, $user, openedVia: 'concurrent_test');

    echo json_encode([
        'ticketId' => $ticket->id,
        'publicId' => $ticket->public_id,
        'ticketNumber' => $ticket->ticket_number,
        'status' => $ticket->status->value,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, 'Concurrent support ticket open failed: '.$e->getMessage());

    exit(1);
}
