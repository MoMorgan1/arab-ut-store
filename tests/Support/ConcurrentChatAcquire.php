<?php

use App\Actions\Chat\CreateOrGetActiveConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $owner = $argv[1] === 'user'
        ? ChatOwner::user((int) $argv[2])
        : ChatOwner::guest((string) $argv[2]);
    $session = new Store('concurrent-chat-acquire', new ArraySessionHandler(120));
    $session->start();
    $request = Request::create('/chat/conversations', 'POST');
    $request->setLaravelSession($session);
    $conversation = $app->make(CreateOrGetActiveConversation::class)->execute($owner, $request);
} catch (Throwable) {
    fwrite(STDERR, 'Concurrent chat acquisition failed.');

    exit(1);
}

echo $conversation->public_id;
