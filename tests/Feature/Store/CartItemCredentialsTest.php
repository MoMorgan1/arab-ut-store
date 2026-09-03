<?php

use App\Models\CartItem;
use App\Models\User;
use App\Security\FutChampionsCartFingerprint;
use App\Security\RivalsCartFingerprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
});

function manualLineCredentials(string $platform = 'playstation', ?string $store = null): array
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

function manualLineImage(): UploadedFile
{
    return new UploadedFile(
        public_path('images/store/payments/mada.png'),
        'manual-squad.png',
        null,
        UPLOAD_ERR_OK,
        true,
    );
}

function postManualFutLine(array $overrides = [], string $key = 'manual-credentials-first'): TestResponse
{
    $platform = $overrides['platform'] ?? 'playstation';
    $store = $overrides['pcStore'] ?? null;

    return test()->withHeaders([
        'Accept' => 'application/json',
        'Idempotency-Key' => $key,
    ])->post('/cart/items/fut-champions', array_replace([
        'scheduleVersion' => '1',
        'platform' => $platform,
        'rank' => '3',
        'urgent' => '0',
        'matchesPlayed' => '0',
        'credentials' => manualLineCredentials($platform, $store),
        'squadImage' => manualLineImage(),
    ], $overrides));
}

test('a manual show returns the stored fields for the owner only', function () {
    postManualFutLine()->assertCreated();

    $item = CartItem::query()->sole();

    $this->getJson("/cart/items/{$item->public_id}/credentials")
        ->assertOk()
        ->assertJson(['data' => [
            'platform' => 'playstation',
            'launcher' => null,
            'eaEmail' => '',
            'eaPassword' => '',
            'eaCodes' => ['12345678', '23456789', '34567890'],
            'playstationEmail' => 'player@example.com',
            'playstationPassword' => 'PS Secret',
            'playstationCodes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
            'steamUsername' => '',
            'steamPassword' => '',
        ]]);

    $this->actingAs(User::factory()->create());

    $this->getJson("/cart/items/{$item->public_id}/credentials")->assertNotFound();
});

test('a manual show returns the steam shape for a pc steam line', function () {
    postManualFutLine([
        'platform' => 'pc',
        'pcStore' => 'steam',
        'credentials' => manualLineCredentials('pc', 'steam'),
    ], 'manual-credentials-steam')->assertCreated();

    $item = CartItem::query()->sole();

    $this->getJson("/cart/items/{$item->public_id}/credentials")
        ->assertOk()
        ->assertJson(['data' => [
            'platform' => 'pc',
            'launcher' => 'steam',
            'eaEmail' => 'player@example.com',
            'steamUsername' => 'SteamPlayer',
            'playstationCodes' => ['', '', ''],
        ]]);
});

test('a manual update persists and the cart page shows credentialsReady true', function () {
    postManualFutLine()->assertCreated();

    $item = CartItem::query()->sole();

    $this->patchJson("/cart/items/{$item->public_id}/credentials", [
        'playstation_email' => 'edited@example.com',
        'playstation_password' => 'Edited Secret',
        'ea_backup_codes' => ['11111111', '22222222', '33333333'],
        'playstation_backup_codes' => ['ABC123', 'DEF456', 'GHI789'],
    ])->assertNoContent();

    $this->getJson("/cart/items/{$item->public_id}/credentials")
        ->assertOk()
        ->assertJsonPath('data.playstationEmail', 'edited@example.com')
        ->assertJsonPath('data.playstationPassword', 'Edited Secret')
        ->assertJsonPath('data.eaCodes', ['11111111', '22222222', '33333333']);

    // The masked summary was rebuilt through the value object, so the cart
    // still reads the line as ready.
    expect($item->fresh()->secret->masked_summary)->toMatchArray([
        'platform' => 'playstation',
        'has_playstation_password' => true,
        'ea_backup_code_count' => 3,
        'playstation_backup_code_count' => 3,
    ]);

    $this->get('/cart')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('cart.items.0.fulfillment.credentialsReady', true)
        ->where('cart.items.0.credentialsKind', 'manual'));
});

test('a manual update by another owner is refused', function () {
    postManualFutLine()->assertCreated();

    $item = CartItem::query()->sole();

    $this->actingAs(User::factory()->create());

    $this->patchJson("/cart/items/{$item->public_id}/credentials", [
        'playstation_email' => 'intruder@example.com',
        'playstation_password' => 'Intruder Secret',
        'ea_backup_codes' => ['11111111', '22222222', '33333333'],
        'playstation_backup_codes' => ['ABC123', 'DEF456', 'GHI789'],
    ])->assertNotFound();

    expect($item->fresh()->secret->encrypted_payload['playstation_email'])->toBe('player@example.com');
});

test('a manual update refuses unknown fields and a moved platform', function (array $payload) {
    postManualFutLine()->assertCreated();

    $item = CartItem::query()->sole();

    $this->patchJson("/cart/items/{$item->public_id}/credentials", $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('request');

    expect($item->fresh()->secret->encrypted_payload['playstation_email'])->toBe('player@example.com');
})->with([
    'platform is not editable' => fn () => [
        'platform' => 'pc',
        'playstation_email' => 'player@example.com',
        'playstation_password' => 'PS Secret',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'playstation_backup_codes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
    ],
    'pc fields do not belong on a playstation line' => fn () => [
        'playstation_email' => 'player@example.com',
        'playstation_password' => 'PS Secret',
        'ea_email' => 'player@example.com',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'playstation_backup_codes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
    ],
]);

test('the manual cart fingerprints include the replaced line id', function () {
    $image = manualLineImage();

    $rivals = [
        'scheduleVersion' => 1,
        'platform' => 'playstation',
        'mode' => 'promotion',
        'currentDivision' => '5',
        'targetDivision' => 'elite',
        'credentials' => manualLineCredentials(),
        'squadImage' => $image,
    ];
    $fut = [
        'scheduleVersion' => 1,
        'platform' => 'playstation',
        'rank' => 3,
        'urgent' => false,
        'matchesPlayed' => 0,
        'credentials' => manualLineCredentials(),
        'squadImage' => $image,
    ];

    expect(RivalsCartFingerprint::generate('user:17', $rivals, 'synthetic-application-key'))
        ->not->toBe(RivalsCartFingerprint::generate(
            'user:17',
            [...$rivals, 'replaceCartItemId' => '01K00000000000000000000000'],
            'synthetic-application-key',
        ))
        ->and(FutChampionsCartFingerprint::generate('user:17', $fut, 'synthetic-application-key'))
        ->not->toBe(FutChampionsCartFingerprint::generate(
            'user:17',
            [...$fut, 'replaceCartItemId' => '01K00000000000000000000000'],
            'synthetic-application-key',
        ));
});
