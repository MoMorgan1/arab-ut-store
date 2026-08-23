<?php

use App\Enums\AI\AgentRunStatus;
use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureAdminMfa;
use App\Models\AgentRun;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Laravel\Fortify\Fortify;

afterEach(function (): void {
    Carbon::setTestNow();
});

test('guests and nonprivileged accounts cannot access admin conversations list', function (): void {
    $this->get('/admin/conversations')->assertRedirect('/en/login');

    foreach ([UserRole::Customer, UserRole::ServiceAccount] as $role) {
        $account = User::factory()->create(['role' => $role]);
        $this->actingAs($account)->get('/admin/conversations')->assertForbidden();
    }

    $inactiveStaff = adminConversationsActor(UserRole::Staff);
    $inactiveStaff->forceFill(['is_active' => false])->save();
    $this->actingAs($inactiveStaff)->get('/admin/conversations')->assertForbidden();
});

test('staff users are forbidden from the conversations index', function (): void {
    $staff = adminConversationsActor(UserRole::Staff);

    $this->actingAs($staff)->get('/admin/conversations')->assertForbidden();
});

test('unconfirmed MFA admin users are redirected to MFA setup', function (): void {
    $admin = adminConversationsActor(UserRole::Admin);
    $admin->forceFill(['two_factor_confirmed_at' => null])->save();

    $this->actingAs($admin)->get('/admin/conversations')->assertRedirect('/admin/settings');
});

test('the conversations route requires EnsureAdminMfa and can:chat.view middleware', function (): void {
    $route = Route::getRoutes()->getByName('admin.conversations');

    expect($route)->not->toBeNull()
        ->and($route?->gatherMiddleware())->toContain(EnsureAdminMfa::class)
        ->and($route?->gatherMiddleware())->toContain('can:chat.view');
});

test('an admin can load the conversations index and sees a seeded conversation', function (string $path): void {
    $admin = adminConversationsActor(UserRole::Admin);
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Fahad',
        'last_name' => 'Al-Otaibi',
    ]);

    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'status' => ChatConversationStatus::Open,
        'locale' => 'ar',
        'last_message_at' => now(),
    ]);

    ChatMessage::factory()->customer()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Hello, I need help with my coin order.',
    ]);

    $this->actingAs($admin)
        ->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/conversations/index', false)
            ->where('auth', null)
            ->where('locale', 'en')
            ->where('direction', 'ltr')
            ->has('rows', 1)
            ->where('rows.0.publicId', (string) $conversation->public_id)
            ->where('rows.0.status', 'open')
            ->where('rows.0.locale', 'ar')
            ->where('rows.0.ownerType', 'customer')
            ->where('rows.0.customerName', 'Fahad Al-Otaibi')
            ->where('rows.0.messageCount', 1)
            ->has('pagination')
            ->has('filters')
            ->has('filterOptions'));
})->with([
    'Canonical Admin' => ['/admin/conversations'],
    'English Admin' => ['/en/admin/conversations'],
]);

test('admin navigation includes conversations between customers and products', function (string $path, array $expectedUrls): void {
    $actor = adminConversationsActor(UserRole::Admin);

    $this->actingAs($actor)
        ->get($path)
        ->assertOk()
        // Compare the whole list, not a fixed number of indexes: an
        // enumeration silently stops checking whatever it does not reach, so
        // a nav entry added at the end went unasserted.
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where(
                'adminNavigation',
                fn (Collection $navigation): bool => $navigation
                    ->pluck('url')
                    ->all() === $expectedUrls,
            ));
})->with([
    'Canonical family' => ['/admin/conversations', ['/admin', '/admin/orders', '/admin/customers', '/admin/conversations', '/admin/marketing/coupons', '/admin/products', '/admin/marketing/loyalty', '/admin/settings']],
    'Localized family' => ['/en/admin/conversations', ['/en/admin', '/en/admin/orders', '/en/admin/customers', '/en/admin/conversations', '/en/admin/marketing/coupons', '/en/admin/products', '/en/admin/marketing/loyalty', '/en/admin/settings']],
]);

test('status filter returns only open conversations when status=open', function (): void {
    $admin = adminConversationsActor(UserRole::Admin);

    $openConv = ChatConversation::factory()->open()->create();
    $closedConv = ChatConversation::factory()->closed(ChatConversationCloseReason::Inactive, now()->subHour())->create();

    $response = $this->actingAs($admin)->get('/admin/conversations?status=open');
    $response->assertOk();

    $rows = $response->original->getData()['page']['props']['rows'];
    $publicIds = array_column($rows, 'publicId');

    expect($publicIds)->toContain((string) $openConv->public_id)
        ->and($publicIds)->not->toContain((string) $closedConv->public_id);
});

test('owner=guest filter returns only guest conversations', function (): void {
    $admin = adminConversationsActor(UserRole::Admin);

    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $customerConv = ChatConversation::factory()->forUser($customer)->create();
    $guestConv = ChatConversation::factory()->forGuest('test-guest-secret-key-12345')->create();

    $response = $this->actingAs($admin)->get('/admin/conversations?owner=guest');
    $response->assertOk();

    $rows = $response->original->getData()['page']['props']['rows'];
    $publicIds = array_column($rows, 'publicId');

    expect($publicIds)->toContain((string) $guestConv->public_id)
        ->and($publicIds)->not->toContain((string) $customerConv->public_id);
});

test('searching a known public_id returns exactly that conversation', function (): void {
    $admin = adminConversationsActor(UserRole::Admin);

    $targetConv = ChatConversation::factory()->create();
    $otherConv = ChatConversation::factory()->create();

    $response = $this->actingAs($admin)->get('/admin/conversations?q='.(string) $targetConv->public_id);
    $response->assertOk();

    $rows = $response->original->getData()['page']['props']['rows'];
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['publicId'])->toBe((string) $targetConv->public_id);
});

test('the detail page returns the messages in ascending order and agent turns', function (): void {
    $admin = adminConversationsActor(UserRole::Admin);

    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'first_name' => 'Sultan',
        'last_name' => 'Al-Ghamdi',
    ]);

    $conversation = ChatConversation::factory()->forUser($customer)->create([
        'status' => ChatConversationStatus::Open,
        'locale' => 'ar',
    ]);

    $msg1 = ChatMessage::factory()->customer()->create([
        'conversation_id' => $conversation->id,
        'content' => 'First message from customer',
        'created_at' => now()->subMinutes(5),
    ]);

    $msg2 = ChatMessage::factory()->assistant()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Second message from assistant',
        'reply_to_message_id' => $msg1->id,
        'created_at' => now()->subMinutes(4),
    ]);

    $msg3 = ChatMessage::factory()->customer()->create([
        'conversation_id' => $conversation->id,
        'content' => 'Third message from customer',
        'created_at' => now()->subMinutes(3),
    ]);

    $turn = AgentTurn::factory()->create([
        'conversation_id' => $conversation->id,
        'status' => AgentTurnStatus::Completed,
        'first_customer_message_id' => $msg1->id,
        'last_customer_message_id' => $msg1->id,
        'assistant_message_id' => $msg2->id,
        'prompt_version' => 'v1.0.0',
    ]);

    AgentRun::factory()->create([
        'agent_turn_id' => $turn->id,
        'attempt_number' => 1,
        'status' => AgentRunStatus::Completed,
        'model' => 'gemini-2.5-flash',
        'latency_ms' => 450,
        'input_tokens' => 120,
        'output_tokens' => 45,
    ]);

    $response = $this->actingAs($admin)->get("/admin/conversations/{$conversation->public_id}");
    $response->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/conversations/show', false)
            ->where('conversation.publicId', (string) $conversation->public_id)
            ->where('conversation.status', 'open')
            ->where('conversation.customerName', 'Sultan Al-Ghamdi')
            ->has('messages', 3)
            ->where('messages.0.publicId', (string) $msg1->public_id)
            ->where('messages.0.content', 'First message from customer')
            ->where('messages.0.senderType', 'customer')
            ->where('messages.1.publicId', (string) $msg2->public_id)
            ->where('messages.1.content', 'Second message from assistant')
            ->where('messages.1.senderType', 'assistant')
            ->where('messages.2.publicId', (string) $msg3->public_id)
            ->where('messages.2.content', 'Third message from customer')
            ->where('messages.2.senderType', 'customer')
            ->has('turns', 1)
            ->where('turns.0.publicId', (string) $turn->public_id)
            ->where('turns.0.status', 'completed')
            ->where('turns.0.promptVersion', 'v1.0.0')
            ->where('turns.0.model', 'gemini-2.5-flash')
            ->where('turns.0.latencyMs', 450)
            ->where('turns.0.inputTokens', 120)
            ->where('turns.0.outputTokens', 45));
});

test('an unknown publicId returns 404', function (): void {
    $admin = adminConversationsActor(UserRole::Admin);

    $this->actingAs($admin)
        ->get('/admin/conversations/01HZZZZZZZZZZZZZZZZZZZZZZZ')
        ->assertNotFound();
});

test('guest_key never appears anywhere in the index or detail Inertia payload', function (): void {
    $admin = adminConversationsActor(UserRole::Admin);
    $guestSecret = 'GUEST_SECRET_KEY_NEVER_LEAK_1234567890abcdef';

    $guestConv = ChatConversation::factory()->forGuest($guestSecret)->create([
        'status' => ChatConversationStatus::Open,
    ]);

    ChatMessage::factory()->customer()->create([
        'conversation_id' => $guestConv->id,
        'content' => 'Guest message content',
    ]);

    // Test index response
    $indexResponse = $this->actingAs($admin)->get('/admin/conversations');
    $indexResponse->assertOk();
    $indexContent = $indexResponse->getContent();
    $indexProps = json_encode($indexResponse->original->getData()['page']['props']);

    expect($indexContent)->not->toContain($guestSecret)
        ->and($indexProps)->not->toContain($guestSecret);

    // Test detail response
    $detailResponse = $this->actingAs($admin)->get("/admin/conversations/{$guestConv->public_id}");
    $detailResponse->assertOk();
    $detailContent = $detailResponse->getContent();
    $detailProps = json_encode($detailResponse->original->getData()['page']['props']);

    expect($detailContent)->not->toContain($guestSecret)
        ->and($detailProps)->not->toContain($guestSecret);
});

function adminConversationsActor(UserRole $role, string $locale = 'en'): User
{
    $actor = User::factory()->create([
        'role' => $role,
        'preferred_locale' => $locale,
        'password' => 'SecurePassword!12',
    ]);
    $actor->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('ADMINCONVERSATIONSTOTPSECRET'),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $actor;
}
