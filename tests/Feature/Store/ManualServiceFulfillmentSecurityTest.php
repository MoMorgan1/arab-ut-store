<?php

use App\Actions\Cart\DeleteCartItemFulfillment;
use App\Actions\Cart\PersistManualServiceFulfillment;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\FulfillmentAttachment;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use App\ValueObjects\Cart\ManualServiceCredentials;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function playStationManualCredentials(array $overrides = []): array
{
    return array_replace([
        'platform' => 'playstation',
        'playstation_email' => ' Player@Example.COM ',
        'playstation_password' => '  PS Secret كلمة مرور  ',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'playstation_backup_codes' => ['a1b2c3', 'D4E5F6', 'z9y8x7'],
    ], $overrides);
}

function eaAppManualCredentials(array $overrides = []): array
{
    return array_replace([
        'platform' => 'pc',
        'pc_store' => 'ea_app',
        'ea_email' => ' Player@Example.COM ',
        'ea_password' => " \tEA Secret كلمة مرور\n ",
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
    ], $overrides);
}

function steamManualCredentials(array $overrides = []): array
{
    return array_replace([
        'platform' => 'pc',
        'pc_store' => 'steam',
        'ea_email' => ' Player@Example.COM ',
        'ea_password' => 'EA Secret',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'steam_username' => '  SteamPlayer  ',
        'steam_password' => " \tSteam Secret كلمة مرور\n ",
    ], $overrides);
}

function manualServiceCartItem(): CartItem
{
    $variant = ProductVariant::query()->where('sku', 'MANUAL_FUT_CHAMPIONS_PLAYSTATION')->sole();
    $cart = Cart::create([
        'session_key' => 'manual-'.Str::lower((string) Str::ulid()),
        'status' => 'active',
        'currency' => 'SAR',
    ]);

    return $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 22_000,
        'total_halalah' => 22_000,
        'configuration' => ['service_type' => 'fut_champions'],
    ]);
}

function manualServiceImage(string $format = 'png', string $clientName = 'my-private-squad.png'): UploadedFile
{
    $path = match ($format) {
        'png' => public_path('images/store/payments/mada.png'),
        'webp' => public_path('images/store/navigation/logo-champions-80.webp'),
    };

    return new UploadedFile($path, $clientName, null, UPLOAD_ERR_OK, true);
}

it('normalizes the PlayStation credential shape without accepting EA login details', function () {
    $credentials = ManualServiceCredentials::fromValidated(playStationManualCredentials());

    expect($credentials->payload())->toBe([
        'platform' => 'playstation',
        'playstation_email' => 'player@example.com',
        'playstation_password' => '  PS Secret كلمة مرور  ',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'playstation_backup_codes' => ['A1B2C3', 'D4E5F6', 'Z9Y8X7'],
    ])->and($credentials->maskedSummary())->toBe([
        'platform' => 'playstation',
        'pc_store' => null,
        'has_ea_password' => false,
        'has_playstation_password' => true,
        'has_steam_password' => false,
        'ea_backup_code_count' => 3,
        'playstation_backup_code_count' => 3,
    ]);
});

it('normalizes the EA app credential shape while preserving password bytes', function () {
    $credentials = ManualServiceCredentials::fromValidated(eaAppManualCredentials());

    expect($credentials->payload())->toBe([
        'platform' => 'pc',
        'pc_store' => 'ea_app',
        'ea_email' => 'player@example.com',
        'ea_password' => " \tEA Secret كلمة مرور\n ",
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
    ])->and($credentials->maskedSummary())->toBe([
        'platform' => 'pc',
        'pc_store' => 'ea_app',
        'has_ea_password' => true,
        'has_playstation_password' => false,
        'has_steam_password' => false,
        'ea_backup_code_count' => 3,
        'playstation_backup_code_count' => 0,
    ]);
});

it('normalizes the Steam credential shape without requiring Steam Guard codes', function () {
    $credentials = ManualServiceCredentials::fromValidated(steamManualCredentials());

    expect($credentials->payload())->toBe([
        'platform' => 'pc',
        'pc_store' => 'steam',
        'ea_email' => 'player@example.com',
        'ea_password' => 'EA Secret',
        'ea_backup_codes' => ['12345678', '23456789', '34567890'],
        'steam_username' => 'SteamPlayer',
        'steam_password' => " \tSteam Secret كلمة مرور\n ",
    ])->and($credentials->maskedSummary()['has_steam_password'])->toBeTrue();
});

it('rejects unknown, missing, forbidden, or malformed credential fields', function (array $input) {
    ManualServiceCredentials::fromValidated($input);
})->with([
    'unknown field' => fn () => [...eaAppManualCredentials(), 'steam_guard_code' => '123456'],
    'missing platform' => fn () => array_diff_key(eaAppManualCredentials(), ['platform' => true]),
    'unknown platform' => fn () => eaAppManualCredentials(['platform' => 'xbox']),
    'PlayStation EA email forbidden' => fn () => [...playStationManualCredentials(), 'ea_email' => 'player@example.com'],
    'PlayStation store forbidden' => fn () => [...playStationManualCredentials(), 'pc_store' => 'steam'],
    'EA app Steam password forbidden' => fn () => [...eaAppManualCredentials(), 'steam_password' => 'secret'],
    'Steam username missing' => fn () => array_diff_key(steamManualCredentials(), ['steam_username' => true]),
    'unknown PC store' => fn () => eaAppManualCredentials(['pc_store' => 'epic']),
    'invalid email' => fn () => eaAppManualCredentials(['ea_email' => 'not-an-email']),
    'empty password' => fn () => eaAppManualCredentials(['ea_password' => '']),
    'EA code wrong length' => fn () => eaAppManualCredentials(['ea_backup_codes' => ['1234567', '23456789', '34567890']]),
    'EA code nonnumeric' => fn () => eaAppManualCredentials(['ea_backup_codes' => ['1234567A', '23456789', '34567890']]),
    'duplicate EA code' => fn () => eaAppManualCredentials(['ea_backup_codes' => ['12345678', '12345678', '34567890']]),
    'PlayStation code wrong length' => fn () => playStationManualCredentials(['playstation_backup_codes' => ['A1B2C', 'D4E5F6', 'Z9Y8X7']]),
    'PlayStation code non-English' => fn () => playStationManualCredentials(['playstation_backup_codes' => ['ع1B2C3', 'D4E5F6', 'Z9Y8X7']]),
    'duplicate PlayStation code after normalization' => fn () => playStationManualCredentials(['playstation_backup_codes' => ['a1b2c3', 'A1B2C3', 'Z9Y8X7']]),
])->throws(DomainException::class);

it('enforces exactly one fulfillment attachment owner and one squad image per item', function () {
    $cartItem = manualServiceCartItem();
    $orderItem = OrderItem::factory()->create();
    $base = [
        'kind' => 'squad_image',
        'disk' => 'local',
        'mime_type' => 'image/png',
        'bytes' => 100,
        'sha256' => str_repeat('a', 64),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    expect(fn () => DB::table('fulfillment_attachments')->insert([
        ...$base,
        'public_id' => (string) Str::ulid(),
        'path' => 'fulfillment/squad-images/neither.png',
    ]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('fulfillment_attachments')->insert([
            ...$base,
            'public_id' => (string) Str::ulid(),
            'cart_item_id' => $cartItem->id,
            'order_item_id' => $orderItem->id,
            'path' => 'fulfillment/squad-images/both.png',
        ]))->toThrow(QueryException::class);

    $cartAttachment = FulfillmentAttachment::create([
        ...$base,
        'cart_item_id' => $cartItem->id,
        'path' => 'fulfillment/squad-images/cart.png',
    ]);
    $orderAttachment = FulfillmentAttachment::create([
        ...$base,
        'order_item_id' => $orderItem->id,
        'path' => 'fulfillment/squad-images/order.png',
    ]);

    expect($cartItem->fresh()->squadImage->is($cartAttachment))->toBeTrue()
        ->and($orderItem->fresh()->squadImage->is($orderAttachment))->toBeTrue()
        ->and(fn () => FulfillmentAttachment::create([
            ...$base,
            'cart_item_id' => $cartItem->id,
            'path' => 'fulfillment/squad-images/duplicate.png',
        ]))->toThrow(QueryException::class);
});

it('encrypts credentials and stores a validated squad image on the private disk', function (string $format, string $mime) {
    Storage::fake('local');
    $cartItem = manualServiceCartItem();
    $credentials = ManualServiceCredentials::fromValidated(steamManualCredentials());

    app(PersistManualServiceFulfillment::class)->execute(
        $cartItem,
        $credentials,
        manualServiceImage($format, 'customer-secret-squad-name.'.$format),
    );

    $secret = $cartItem->fresh()->secret;
    $attachment = $cartItem->fresh()->squadImage;
    $rawCiphertext = DB::table('cart_item_secrets')->where('id', $secret->id)->value('encrypted_payload');

    expect($secret->encrypted_payload)->toBe($credentials->payload())
        ->and($secret->masked_summary)->toBe($credentials->maskedSummary())
        ->and($rawCiphertext)->not->toContain('Player@Example.COM', 'EA Secret', 'Steam Secret', '12345678')
        ->and($attachment->disk)->toBe('local')
        ->and($attachment->kind)->toBe('squad_image')
        ->and($attachment->mime_type)->toBe($mime)
        ->and($attachment->bytes)->toBeGreaterThan(0)
        ->and($attachment->sha256)->toMatch('/^[a-f0-9]{64}$/')
        ->and($attachment->path)->toMatch('/^fulfillment\/squad-images\/[0-9A-HJKMNP-TV-Z]{26}\.(png|webp)$/')
        ->and($attachment->path)->not->toContain('customer-secret-squad-name');

    Storage::disk('local')->assertExists($attachment->path);
})->with([
    'PNG' => ['png', 'image/png'],
    'WebP' => ['webp', 'image/webp'],
]);

it('rejects spoofed, unsupported, empty, and oversized squad images', function (UploadedFile $file) {
    Storage::fake('local');

    expect(fn () => app(PersistManualServiceFulfillment::class)->execute(
        manualServiceCartItem(),
        ManualServiceCredentials::fromValidated(eaAppManualCredentials()),
        $file,
    ))->toThrow(DomainException::class)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with([
    'spoofed PNG' => fn () => UploadedFile::fake()->createWithContent('squad.png', 'not an image'),
    'GIF content' => fn () => UploadedFile::fake()->createWithContent('squad.gif', 'GIF89a'.str_repeat("\0", 100)),
    'empty file' => fn () => UploadedFile::fake()->createWithContent('squad.png', ''),
    'oversized file' => fn () => UploadedFile::fake()->create('squad.png', 5_121, 'image/png'),
]);

it('deletes a newly written image when secret persistence fails', function () {
    Storage::fake('local');
    $cartItem = manualServiceCartItem();
    $existing = new CartItemSecret(['cart_item_id' => $cartItem->id]);
    $existing->forceFill(['masked_summary' => ['existing' => true]]);
    $existing->encrypted_payload = ['existing' => true];
    $existing->save();

    expect(fn () => app(PersistManualServiceFulfillment::class)->execute(
        $cartItem,
        ManualServiceCredentials::fromValidated(eaAppManualCredentials()),
        manualServiceImage(),
    ))->toThrow(QueryException::class)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(FulfillmentAttachment::query()->count())->toBe(0);
});

it('removes the private file and fulfillment rows when a cart item is deleted', function () {
    Storage::fake('local');
    $cartItem = manualServiceCartItem();
    app(PersistManualServiceFulfillment::class)->execute(
        $cartItem,
        ManualServiceCredentials::fromValidated(playStationManualCredentials()),
        manualServiceImage(),
    );
    $path = $cartItem->fresh()->squadImage->path;

    app(DeleteCartItemFulfillment::class)->execute($cartItem);

    Storage::disk('local')->assertMissing($path);
    expect($cartItem->fresh()->secret)->toBeNull()
        ->and($cartItem->fresh()->squadImage)->toBeNull();
});
