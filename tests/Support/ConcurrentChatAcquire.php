<?php

use App\Actions\Chat\CreateOrGetActiveConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/ConcurrentChatReadinessBarrier.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $ownerType = $argv[1] ?? '';
    $ownerIdentifier = $argv[2] ?? '';
    $owner = $ownerType === 'user'
        ? ChatOwner::user((int) $ownerIdentifier)
        : ChatOwner::guest($ownerIdentifier);
    $request = Request::create('/chat/conversations', 'POST');
    $request->setLaravelSession(new Store('concurrent-chat-acquire', new ArraySessionHandler(120)));

    if ($ownerType === 'user') {
        $request->setUserResolver(fn (): ?User => User::find((int) $ownerIdentifier));
    }

    waitForConcurrentChatRelease($argv[3] ?? '', $argv[4] ?? '');

    echo $app->make(CreateOrGetActiveConversation::class)->execute($owner, $request, 'ar')->public_id;
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat acquisition failed.');

    exit(1);
}
