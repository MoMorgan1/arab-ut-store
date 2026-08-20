<?php

use App\Actions\Chat\ClaimGuestChatConversations;
use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $guestKey = $argv[1] ?? '';
    $user = User::query()->findOrFail((int) ($argv[2] ?? 0));
    $activePublicId = $argv[3] ?? null;
    $lockWaitTimeout = (int) ($argv[4] ?? 0);

    if ($lockWaitTimeout > 0) {
        DB::statement("SET SESSION innodb_lock_wait_timeout = {$lockWaitTimeout}");
    }

    $app->make(ClaimGuestChatConversations::class)->execute(
        [ChatOwner::guest($guestKey)],
        $user,
        is_string($activePublicId) && $activePublicId !== '' ? $activePublicId : null,
    );

    $conversation = ChatConversation::query()->where('public_id', $activePublicId)->firstOrFail();

    echo json_encode([
        'status' => 'claimed',
        'userId' => $conversation->user_id,
        'guestKey' => $conversation->guest_key,
    ], JSON_THROW_ON_ERROR);
} catch (QueryException $exception) {
    if ((int) ($exception->errorInfo[1] ?? 0) === 1205) {
        echo json_encode(['status' => 'lock_wait_timeout'], JSON_THROW_ON_ERROR);

        exit(0);
    }

    fwrite(STDERR, 'Concurrent chat claim query failed.');

    exit(1);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat claim failed.');

    exit(1);
}
