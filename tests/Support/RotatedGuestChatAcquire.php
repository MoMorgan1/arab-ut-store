<?php

use App\Actions\Chat\CreateOrGetActiveConversation;
use App\Actions\Chat\ResolveChatOwner;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $rawToken = $argv[1] ?? '';
    $currentAppKey = $argv[2] ?? '';
    $previousAppKey = $argv[3] ?? '';
    $activePublicId = $argv[4] ?? '';

    config()->set('app.key', $currentAppKey);
    config()->set('app.previous_keys', [$previousAppKey]);

    $session = new Store('rotated-guest-chat-acquire', new ArraySessionHandler(120));
    $session->start();
    $session->put(ResolveChatOwner::SESSION_KEY, $rawToken);
    $session->put(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY, $activePublicId);

    $request = Request::create('/chat/conversations', 'POST');
    $request->setLaravelSession($session);
    $owner = $app->make(ResolveChatOwner::class)->forRequest($request);
    $conversation = $app->make(CreateOrGetActiveConversation::class)->execute($owner, $request, 'ar');

    echo json_encode([
        'publicId' => $conversation->public_id,
        'guestKey' => $owner->guestKey(),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable) {
    fwrite(STDERR, 'Rotated guest chat acquisition failed.');

    exit(1);
}
