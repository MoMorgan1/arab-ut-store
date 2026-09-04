<?php

use App\Actions\Checkout\PlaceOrder;
use App\Enums\ServiceType;
use App\Models\CartItem;
use App\Models\ServicePriceSchedule;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
});

function rivalsCartCredentials(string $platform = 'playstation', ?string $store = null): array
{
    if ($platform === 'playstation') {
        return [
            'playstation_email' => 'player@example.com',
            'playstation_password' => 'PS Secret',
            'ea_backup_codes' => ['12345678', '23456789', '34567890'],
            'playstation_backup_codes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
        ];
    }

    $credentials = [
        'ea_email' => 'player@example.com',
        'ea_password' => 'EA Secret',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
    ];

    return $store === 'steam'
        ? [...$credentials, 'steam_username' => 'SteamPlayer', 'steam_password' => 'Steam Secret']
        : $credentials;
}

function rivalsCartImage(): UploadedFile
{
    return new UploadedFile(
        public_path('images/store/navigation/logo-champions-80.webp'),
        'rivals-squad.webp',
        null,
        UPLOAD_ERR_OK,
        true,
    );
}

function validRivalsCartPayload(array $overrides = []): array
{
    $platform = $overrides['platform'] ?? 'playstation';
    $store = $overrides['pcStore'] ?? null;

    return array_replace([
        'scheduleVersion' => '1',
        'platform' => $platform,
        'currentDivision' => '5',
        'targetDivision' => 'elite',
        'credentials' => rivalsCartCredentials($platform, $store),
        'squadImage' => rivalsCartImage(),
    ], $overrides);
}

function postRivalsCart(array $payload, string $key = 'rivals-request-1')
{
    return test()->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => $key,
    ])->post('/cart/items/rivals', $payload);
}

it('prices every adjacent Rivals edge and valid multi-step routes', function (string $from, string $to, int $expected) {
    postRivalsCart(validRivalsCartPayload([
        'currentDivision' => $from,
        'targetDivision' => $to,
    ]), "rivals-{$from}-{$to}")->assertCreated();

    $item = CartItem::query()->sole();

    expect($item->unit_price_halalah)->toBe($expected)
        ->and($item->configuration)->toMatchArray([
            'service_type' => 'rivals',
            'current_division' => $from,
            'target_division' => $to,
            'price_version' => 1,
        ]);
})->with([
    '7 to 6' => ['7', '6', 11_000],
    '6 to 5' => ['6', '5', 12_000],
    '5 to 4' => ['5', '4', 13_000],
    '4 to 3' => ['4', '3', 14_000],
    '3 to 2' => ['3', '2', 15_000],
    '2 to 1' => ['2', '1', 16_000],
    '1 to Elite' => ['1', 'elite', 17_000],
    '5 to Elite' => ['5', 'elite', 75_000],
    '7 to Elite' => ['7', 'elite', 98_000],
]);

it('accepts PlayStation and PC Steam Rivals orders with private fulfillment data', function (array $overrides, string $platform) {
    $response = postRivalsCart(validRivalsCartPayload($overrides), 'rivals-platform-'.Str::lower((string) Str::ulid()))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['cartItemId', 'cartCount', 'cartUrl']]);

    $item = CartItem::query()->sole();
    $serialized = strtolower(json_encode($response->json(), JSON_THROW_ON_ERROR));

    expect($item->configuration['platform'])->toBe($platform)
        ->and($item->secret->encrypted_payload['platform'])->toBe($platform)
        ->and($item->squadImage)->not->toBeNull()
        ->and($serialized)->not->toContain('password', 'email', 'code', 'path');
})->with([
    'PlayStation' => [[], 'playstation'],
    'PC Steam' => [[
        'platform' => 'pc',
        'pcStore' => 'steam',
        'credentials' => rivalsCartCredentials('pc', 'steam'),
    ], 'pc'],
]);

it('rejects same, lower, unknown, and urgent Rivals requests', function (array $overrides) {
    postRivalsCart(validRivalsCartPayload($overrides), 'invalid-rivals-'.Str::lower((string) Str::ulid()))
        ->assertUnprocessable();

    expect(CartItem::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'same division' => [['currentDivision' => '5', 'targetDivision' => '5']],
    'lower target' => [['currentDivision' => '3', 'targetDivision' => '4']],
    'unknown division' => [['currentDivision' => 'bronze']],
    'urgent forbidden' => [['urgent' => true]],
]);

it('fails closed for inactive or stale Rivals schedules', function (array $scheduleChange) {
    ServicePriceSchedule::query()->where('service_type', ServiceType::Rivals)->update($scheduleChange);

    postRivalsCart(validRivalsCartPayload(), 'unavailable-rivals-'.Str::lower((string) Str::ulid()))
        ->assertUnprocessable();

    expect(CartItem::query()->count())->toBe(0);
})->with([
    'inactive' => [['is_active' => false]],
    'stale page version' => [['version' => 2]],
]);

it('replays identical Rivals requests and conflicts on a changed target', function () {
    $first = postRivalsCart(validRivalsCartPayload(), 'replay-rivals')->assertCreated()->json();
    $replay = postRivalsCart(validRivalsCartPayload(), 'replay-rivals')->assertCreated()->json();

    expect($replay)->toBe($first)
        ->and(CartItem::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

    postRivalsCart(validRivalsCartPayload(['targetDivision' => '1']), 'replay-rivals')->assertConflict();
});

it('supports the localized Rivals multipart endpoint', function () {
    $this->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => 'localized-rivals',
    ])->post('/en/cart/items/rivals', validRivalsCartPayload())
        ->assertCreated()
        ->assertJsonPath('data.cartUrl', '/en/cart');
});

it('projects only a safe immutable Rivals route into the cart', function () {
    postRivalsCart(validRivalsCartPayload([
        'currentDivision' => '5',
        'targetDivision' => 'elite',
    ]), 'safe-rivals-projection')->assertCreated();

    $item = CartItem::query()->sole();

    $response = $this->get('/cart')->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.configuration.service_type', 'rivals')
        ->where('cart.items.0.configuration.from_division', '5')
        ->where('cart.items.0.configuration.to_division', 'elite')
        ->where('cart.items.0.configuration.schedule_version', 1)
        ->where('cart.items.0.fulfillment.credentialsReady', true)
        ->where('cart.items.0.fulfillment.squadImagePresent', true)
        ->where('cart.items.0.credentials', null)
        ->where('cart.items.0.credentialsKind', 'manual')
        ->where('cart.items.0.credentialsUrl', "/cart/items/{$item->public_id}/credentials")
        ->missing('cart.items.0.configuration.urgent'));

    expect(strtolower($response->getContent()))->not->toContain(
        'player@example.com',
        'ps secret',
        '12345678',
        'a1b2c3',
        'fulfillment/squad-images',
    );
});

/** Puts weekly matches on sale at a known price. */
function priceRivalsWeeklyMatches(int $priceHalalah = 9_000, int $includedWins = 8): void
{
    $schedule = ServicePriceSchedule::query()->where('service_type', ServiceType::Rivals)->sole();

    $schedule->forceFill([
        'configuration' => [
            ...(array) $schedule->configuration,
            'weeklyMatches' => ['priceHalalah' => $priceHalalah, 'includedWins' => $includedWins],
        ],
    ])->save();
}

test('a week of matches is sold at its own price and carries no divisions', function (): void {
    // Weekly matches promote nothing, so a division stored against one would be
    // a claim the service does not make - and the price is flat, not a sum of
    // ladder steps.
    priceRivalsWeeklyMatches();

    $payload = validRivalsCartPayload(['mode' => 'weekly_matches']);
    unset($payload['currentDivision'], $payload['targetDivision']);

    $this->post('/cart/items/rivals', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated();

    $item = CartItem::query()->sole();

    expect($item->total_halalah)->toBe(9_000)
        ->and($item->configuration['mode'])->toBe('weekly_matches')
        ->and($item->configuration['current_division'])->toBeNull()
        ->and($item->configuration['target_division'])->toBeNull()
        // Frozen with the purchase, so changing the setting later never rewrites
        // what an existing order was promised.
        ->and($item->configuration['included_wins'])->toBe(8);
});

test('a division sent with a week of matches is refused', function (): void {
    priceRivalsWeeklyMatches();

    $this->post(
        '/cart/items/rivals',
        validRivalsCartPayload(['mode' => 'weekly_matches']),
        ['Idempotency-Key' => (string) Str::uuid()],
    )->assertUnprocessable();

    expect(CartItem::query()->count())->toBe(0);
});

test('weekly matches cannot be bought before an admin prices them', function (): void {
    // The storefront hides the option until it is priced. A request arriving
    // anyway must not fall back to some default price.
    $payload = validRivalsCartPayload(['mode' => 'weekly_matches']);
    unset($payload['currentDivision'], $payload['targetDivision']);

    $this->post('/cart/items/rivals', $payload, ['Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable();

    expect(CartItem::query()->count())->toBe(0);
});

test('a promotion and a week of matches are not confused for the same request', function (): void {
    // Both carry the same credentials and the same image. Without the mode in
    // the fingerprint the second would be replayed as the first, and the
    // customer would be handed the wrong service.
    priceRivalsWeeklyMatches();

    $key = (string) Str::uuid();

    $this->post('/cart/items/rivals', validRivalsCartPayload(), ['Idempotency-Key' => $key])
        ->assertCreated();

    $weekly = validRivalsCartPayload(['mode' => 'weekly_matches']);
    unset($weekly['currentDivision'], $weekly['targetDivision']);

    $this->post('/cart/items/rivals', $weekly, ['Idempotency-Key' => $key])
        ->assertStatus(409);
});

it('places an order for a Rivals item added the way the storefront adds it', function (string $mode, int $expectedHalalah) {
    // The checkout fixtures hand-build a cart item configuration. That is how a
    // mismatch between what AddRivalsToCart writes and what PlaceOrder accepts
    // could sit here fully green: nothing walked the real path from the button
    // to the order.
    $user = User::factory()->create([
        'phone' => '+966500000001',
        'phone_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $payload = validRivalsCartPayload(['mode' => $mode]);

    if ($mode === 'weekly_matches') {
        priceRivalsWeeklyMatches();
        unset($payload['currentDivision'], $payload['targetDivision']);
    }

    postRivalsCart($payload, "rivals-checkout-{$mode}")->assertCreated();

    $result = app(PlaceOrder::class)->execute($user, 'ar', "rivals-place-{$mode}");

    // The week is a flat price, the promotion is the ladder summed. Checkout
    // re-prices from the server, so asserting the total here is what proves it
    // priced the mode the customer actually chose.
    expect($result->order->items()->count())->toBe(1)
        ->and($result->order->items()->sole()->service_type)->toBe(ServiceType::Rivals)
        ->and($result->order->total_halalah)->toBe($expectedHalalah);
})->with([
    'a promotion up the ladder' => ['promotion', 75_000],
    'a week of matches' => ['weekly_matches', 9_000],
]);

it('shows the week of matches and its win count on the cart page', function () {
    // The line has no divisions to show, so without these the customer reads
    // "Division Rivals" and a price, and pays without ever seeing which of the
    // two services they picked.
    priceRivalsWeeklyMatches();

    $payload = validRivalsCartPayload(['mode' => 'weekly_matches']);
    unset($payload['currentDivision'], $payload['targetDivision']);

    postRivalsCart($payload, 'rivals-cart-page')->assertCreated();

    $this->get('/cart')->assertInertia(
        fn (Assert $page) => $page
            ->where('cart.items.0.configuration.weekly_matches', true)
            ->where('cart.items.0.configuration.included_wins', 8)
            ->missing('cart.items.0.configuration.from_division'),
    );
});

test('adding the same Rivals variant twice returns 409 already_in_cart', function () {
    postRivalsCart(validRivalsCartPayload(), 'rivals-duplicate-1')->assertCreated();

    $second = postRivalsCart(validRivalsCartPayload(), 'rivals-duplicate-2');

    $second->assertConflict()
        ->assertJsonPath('error.code', 'already_in_cart')
        ->assertJsonPath('error.message', trans('store.cart.already_in_cart'))
        ->assertJsonPath('error.cartUrl', '/cart');
    expect($second->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(1);
});

test('adding another Rivals platform variant creates a second line', function () {
    postRivalsCart(validRivalsCartPayload(), 'rivals-platform-1')->assertCreated();

    $pcPayload = validRivalsCartPayload([
        'platform' => 'pc',
        'pcStore' => 'steam',
        'credentials' => rivalsCartCredentials('pc', 'steam'),
    ]);

    postRivalsCart($pcPayload, 'rivals-platform-2')
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 2);

    expect(CartItem::count())->toBe(2);
});

test('replacing a Rivals line removes the old line and keeps the count', function () {
    postRivalsCart(validRivalsCartPayload(), 'rivals-replace-first')->assertCreated();
    $old = CartItem::query()->sole();

    $replacement = validRivalsCartPayload(['targetDivision' => '1']);
    unset($replacement['squadImage']);
    $replacement['replaceCartItemId'] = $old->public_id;

    postRivalsCart($replacement, 'rivals-replace-second')
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 1);

    expect(CartItem::count())->toBe(1)
        ->and(CartItem::withRemoved()->count())->toBe(2)
        ->and($old->fresh()->removed_at)->not->toBeNull()
        ->and(CartItem::sole()->configuration['target_division'])->toBe('1')
        ->and(CartItem::sole()->squadImage)->not->toBeNull();
});

test('replacing a Rivals line that is not on the owner cart is refused', function () {
    $owner = User::factory()->create();
    $this->actingAs($owner);
    postRivalsCart(validRivalsCartPayload(), 'rivals-replace-owner')->assertCreated();
    $foreign = CartItem::query()->sole();

    $this->actingAs(User::factory()->create());

    $replacement = validRivalsCartPayload();
    unset($replacement['squadImage']);
    $replacement['replaceCartItemId'] = $foreign->public_id;

    postRivalsCart($replacement, 'rivals-replace-foreign')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'replaced_item_unavailable');

    expect($foreign->fresh()->removed_at)->toBeNull();
});
