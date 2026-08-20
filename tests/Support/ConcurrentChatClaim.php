<?php

use App\Actions\Chat\ClaimGuestChatConversations;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $guestKey = $argv[1] ?? '';
    $user = User::query()->findOrFail((int) ($argv[2] ?? 0));
    $activePublicId = $argv[3] ?? null;
    $readyPath = $argv[4] ?? '';

    if ($readyPath === '' || ! touch($readyPath)) {
        throw new RuntimeException('Unable to signal chat claim readiness.');
    }

    $app->make(ClaimGuestChatConversations::class)->execute(
        [ChatOwner::guest($guestKey)],
        $user,
        is_string($activePublicId) && $activePublicId !== '' ? $activePublicId : null,
    );

    echo json_encode(['status' => 'claimed'], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat claim failed.');

    exit(1);
}
