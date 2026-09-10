<?php

declare(strict_types=1);

use App\Http\Middleware\SetChatLocale;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    config()->set('chat.enabled', true);
});

test('the endpoint returns the service prices as json', function () {
    $response = $this->getJson(route('chat.service-prices'));

    $response->assertOk()->assertJsonStructure(['prices']);
});

test('it is unavailable when chat is disabled', function () {
    config()->set('chat.enabled', false);

    $this->getJson(route('chat.service-prices'))->assertNotFound();
});

test('prices stay out of the page render query budget', function () {
    // The storefront enforces per-page query budgets, which is why prices are
    // fetched on demand rather than shared with every Inertia response.
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->forUser($user)->create();
    ChatMessage::factory()->for($conversation, 'conversation')->create();

    DB::enableQueryLog();

    $this->actingAs($user)
        ->getJson(route('chat.conversations.show', ['conversation' => $conversation->public_id]))
        ->assertOk();

    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(6);
});

test('the route resolves the chat locale like every other chat route', function () {
    // BuildServicePriceLabels reads app()->getLocale() for the SBC shelf, so
    // this route must run SetChatLocale or the labels follow ambient state.
    $route = app('router')->getRoutes()->getByName('chat.service-prices');

    expect($route->gatherMiddleware())->toContain(SetChatLocale::class);
});
