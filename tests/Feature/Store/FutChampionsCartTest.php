<?php

use App\Enums\ServiceType;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\FulfillmentAttachment;
use App\Models\ServicePriceSchedule;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
});

function futCartCredentials(string $platform = 'playstation', ?string $store = null): array
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

function futCartImage(): UploadedFile
{
    return new UploadedFile(
        public_path('images/store/payments/mada.png'),
        'fut-squad.png',
        null,
        UPLOAD_ERR_OK,
        true,
    );
}

function validFutCartPayload(array $overrides = []): array
{
    $platform = $overrides['platform'] ?? 'playstation';
    $store = $overrides['pcStore'] ?? null;

    return array_replace([
        'scheduleVersion' => '1',
        'platform' => $platform,
        'rank' => '3',
        'urgent' => '0',
        'matchesPlayed' => '0',
        'credentials' => futCartCredentials($platform, $store),
        'squadImage' => futCartImage(),
    ], $overrides);
}

function postFutCart(array $payload, string $key = 'fut-request-1')
{
    return test()->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => $key,
    ])->post('/cart/items/fut-champions', $payload);
}

it('prices all six FUT ranks and the urgent surcharge only on the server', function (int $rank, bool $urgent, int $expected) {
    postFutCart(validFutCartPayload([
        'rank' => $rank,
        'urgent' => $urgent,
        'matchesPlayed' => 9,
    ]), "rank-{$rank}-".($urgent ? 'urgent' : 'standard'))
        ->assertCreated();

    $item = CartItem::query()->sole();

    expect($item->unit_price_halalah)->toBe($expected)
        ->and($item->total_halalah)->toBe($expected)
        ->and($item->configuration)->toMatchArray([
            'service_type' => 'fut_champions',
            'rank' => $rank,
            'urgent' => $urgent,
            'matches_played' => 9,
            'price_version' => 1,
        ]);
})->with([
    'Rank 1' => [1, false, 22_000],
    'Rank 2' => [2, false, 19_000],
    'Rank 3' => [3, false, 17_000],
    'Rank 4' => [4, false, 15_000],
    'Rank 5' => [5, false, 13_000],
    'Rank 6' => [6, false, 10_000],
    'urgent Rank 6' => [6, true, 14_000],
]);

it('accepts every approved platform credential shape at the same rank price', function (array $overrides, string $expectedPlatform, ?string $expectedStore) {
    $response = postFutCart(validFutCartPayload($overrides), 'platform-'.Str::lower((string) Str::ulid()))
        ->assertCreated()
        ->assertJsonStructure(['data' => ['cartItemId', 'cartCount', 'cartUrl']]);

    $item = CartItem::query()->sole();
    $secret = $item->secret;
    $attachment = $item->squadImage;
    $serialized = strtolower(json_encode($response->json(), JSON_THROW_ON_ERROR));

    expect($item->unit_price_halalah)->toBe(17_000)
        ->and($item->configuration['platform'])->toBe($expectedPlatform)
        ->and($item->configuration['pc_store'])->toBe($expectedStore)
        ->and($secret->encrypted_payload['platform'])->toBe($expectedPlatform)
        ->and($attachment)->not->toBeNull()
        ->and($serialized)->not->toContain('password', 'backup', 'email', 'path', 'squad');
})->with([
    'PlayStation' => [[], 'playstation', null],
    'PC EA app' => [[
        'platform' => 'pc',
        'pcStore' => 'ea_app',
        'credentials' => futCartCredentials('pc', 'ea_app'),
    ], 'pc', 'ea_app'],
    'PC Steam' => [[
        'platform' => 'pc',
        'pcStore' => 'steam',
        'credentials' => futCartCredentials('pc', 'steam'),
    ], 'pc', 'steam'],
]);

it('creates an authenticated customer cart as well as guest carts', function () {
    $user = User::factory()->create();

    $this->actingAs($user);
    postFutCart(validFutCartPayload(), 'authenticated-fut')->assertCreated();

    expect(CartItem::query()->sole()->cart->user_id)->toBe($user->id);
});

it('supports the localized FUT multipart endpoint and returns the localized cart URL', function () {
    $this->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => 'localized-fut',
    ])->post('/en/cart/items/fut-champions', validFutCartPayload())
        ->assertCreated()
        ->assertJsonPath('data.cartUrl', '/en/cart');
});

it('rejects invalid conditional fields and missing images before creating a cart item', function (array $payload) {
    postFutCart($payload, 'invalid-'.Str::lower((string) Str::ulid()))->assertUnprocessable();

    expect(CartItem::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'Xbox' => fn () => validFutCartPayload(['platform' => 'xbox']),
    'missing image' => fn () => array_diff_key(validFutCartPayload(), ['squadImage' => true]),
    'PlayStation with PC store' => fn () => validFutCartPayload(['pcStore' => 'steam']),
    'EA app with Steam fields' => fn () => validFutCartPayload([
        'platform' => 'pc',
        'pcStore' => 'ea_app',
        'credentials' => futCartCredentials('pc', 'steam'),
    ]),
    'unknown field' => fn () => [...validFutCartPayload(), 'price' => 1],
]);

it('fails closed for inactive or stale FUT pricing schedules', function (array $scheduleChange) {
    ServicePriceSchedule::query()
        ->where('service_type', ServiceType::FutChampions)
        ->update($scheduleChange);

    postFutCart(validFutCartPayload(), 'unavailable-fut-'.Str::lower((string) Str::ulid()))
        ->assertUnprocessable();

    expect(CartItem::query()->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'inactive' => [['is_active' => false]],
    'stale page version' => [['version' => 2]],
]);

it('replays an identical FUT request and conflicts on changed input', function () {
    $first = postFutCart(validFutCartPayload(), 'replay-fut')->assertCreated()->json();
    $replay = postFutCart(validFutCartPayload(), 'replay-fut')->assertCreated()->json();

    expect($replay)->toBe($first)
        ->and(CartItem::query()->count())->toBe(1)
        ->and(Storage::disk('local')->allFiles())->toHaveCount(1);

    postFutCart(validFutCartPayload(['rank' => 4]), 'replay-fut')->assertConflict();

    expect(CartItem::query()->count())->toBe(1);
});

it('stores FUT secrets only as ciphertext', function () {
    postFutCart(validFutCartPayload(), 'ciphertext-fut')->assertCreated();

    $ciphertext = (string) DB::table('cart_item_secrets')->value('encrypted_payload');

    expect($ciphertext)->not->toContain('player@example.com', 'PS Secret', '12345678', 'A1B2C3');
});

it('projects only a safe immutable FUT summary into the cart', function () {
    postFutCart(validFutCartPayload([
        'platform' => 'pc',
        'pcStore' => 'steam',
        'rank' => 3,
        'urgent' => true,
        'matchesPlayed' => 4,
        'credentials' => futCartCredentials('pc', 'steam'),
    ]), 'safe-fut-projection')->assertCreated();

    $response = $this->get('/en/cart')->assertOk();
    $response->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.configuration.service_type', 'fut_champions')
        ->where('cart.items.0.configuration.platform', 'pc')
        ->where('cart.items.0.configuration.pc_launcher', 'steam')
        ->where('cart.items.0.configuration.target_rank', 3)
        ->where('cart.items.0.configuration.urgent', true)
        ->where('cart.items.0.configuration.matches_played', 4)
        ->where('cart.items.0.configuration.schedule_version', 1)
        ->where('cart.items.0.fulfillment.credentialsReady', true)
        ->where('cart.items.0.fulfillment.squadImagePresent', true)
        ->where('cart.items.0.credentials', null)
        ->where('cart.items.0.credentialsUrl', null));

    $serialized = strtolower($response->getContent());
    expect($serialized)->not->toContain(
        'player@example.com',
        'ea secret',
        'steam secret',
        '12345678',
        'fulfillment/squad-images',
    );
});

it('removes FUT secrets and the private squad image with the owned cart line', function () {
    postFutCart(validFutCartPayload(), 'delete-fut-fulfillment')->assertCreated();

    $item = CartItem::query()->sole();
    $attachment = FulfillmentAttachment::query()->sole();
    $path = $attachment->path;
    Storage::disk('local')->assertExists($path);

    $this->deleteJson('/cart/items/'.$item->public_id)
        ->assertOk()
        ->assertJsonPath('data.cartCount', 0);

    expect(CartItem::query()->count())->toBe(0)
        ->and(CartItemSecret::query()->count())->toBe(0)
        ->and(FulfillmentAttachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('adding the same FUT Champions variant twice returns 409 already_in_cart', function () {
    postFutCart(validFutCartPayload(), 'fut-duplicate-1')->assertCreated();

    $second = postFutCart(validFutCartPayload(), 'fut-duplicate-2');

    $second->assertConflict()
        ->assertJsonPath('error.code', 'already_in_cart')
        ->assertJsonPath('error.message', trans('store.cart.already_in_cart'))
        ->assertJsonPath('error.cartUrl', '/cart');
    expect($second->headers->get('Cache-Control'))->toContain('no-store')
        ->and(CartItem::count())->toBe(1);
});

test('adding another FUT Champions platform variant creates a second line', function () {
    postFutCart(validFutCartPayload(), 'fut-platform-1')->assertCreated();

    $pcPayload = validFutCartPayload([
        'platform' => 'pc',
        'pcStore' => 'steam',
        'credentials' => futCartCredentials('pc', 'steam'),
    ]);

    postFutCart($pcPayload, 'fut-platform-2')
        ->assertCreated()
        ->assertJsonPath('data.cartCount', 2);

    expect(CartItem::count())->toBe(2);
});
