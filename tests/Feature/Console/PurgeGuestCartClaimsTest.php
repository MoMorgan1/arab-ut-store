<?php

use App\Actions\Cart\AcquireActiveCart;
use App\Actions\Cart\ClaimGuestCart;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\Models\ProductVariant;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

test('the purge removes only expired claim markers without exposing identities or deleting cart data', function () {
    config()->set('coins.cart.guest_claim_retention_hours', 24);
    $expiredOwner = CartOwner::guest(hash('sha256', 'expired-unclaimed-marker'));
    $freshOwner = CartOwner::guest(hash('sha256', 'fresh-unclaimed-marker'));
    $expiredCart = app(AcquireActiveCart::class)->execute($expiredOwner);
    app(AcquireActiveCart::class)->execute($freshOwner);
    $item = $expiredCart->items()->create([
        'product_variant_id' => ProductVariant::factory()->create()->id,
        'quantity' => 1,
        'unit_price_halalah' => 5_000,
        'total_halalah' => 5_000,
        'configuration' => ['service_type' => 'coins'],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 5],
        'retained_until' => now()->addDay(),
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'claim-retention@example.test',
        'ea_password' => 'Claim Retention Sentinel',
        'backup_codes' => ['85000001', '85000002', '85000003', '85000004', '85000005'],
    ];
    $secret->save();
    $ciphertext = (string) DB::table('cart_item_secrets')
        ->where('cart_item_id', $item->id)
        ->value('encrypted_payload');
    DB::table('guest_cart_claims')
        ->where('guest_session_hmac', $expiredOwner->sessionKey())
        ->update(['updated_at' => now()->subHours(25)]);

    $this->artisan('guest-cart-claims:purge')
        ->expectsOutputToContain('Purged 1 expired guest cart claim marker(s).')
        ->doesntExpectOutput($expiredOwner->sessionKey())
        ->assertSuccessful();

    expect(DB::table('guest_cart_claims')->where('guest_session_hmac', $expiredOwner->sessionKey())->exists())
        ->toBeFalse()
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $freshOwner->sessionKey())->exists())
        ->toBeTrue()
        ->and($expiredCart->fresh())->not->toBeNull()
        ->and($item->fresh()->cart_id)->toBe($expiredCart->id)
        ->and((string) DB::table('cart_item_secrets')->where('cart_item_id', $item->id)->value('encrypted_payload'))
        ->toBe($ciphertext);
});

test('purged unclaimed and claimed identities recover with safe post-retention ownership', function () {
    config()->set('coins.cart.guest_claim_retention_hours', 24);
    $unclaimedOwner = CartOwner::guest(hash('sha256', 'recover-unclaimed-marker'));
    $unclaimedCart = app(AcquireActiveCart::class)->execute($unclaimedOwner);
    $claimedOwner = CartOwner::guest(hash('sha256', 'expire-claimed-marker'));
    $user = User::factory()->create();
    $userCart = Cart::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    app(ClaimGuestCart::class)->execute([$claimedOwner], $user);
    DB::table('guest_cart_claims')
        ->whereIn('guest_session_hmac', [$unclaimedOwner->sessionKey(), $claimedOwner->sessionKey()])
        ->update(['updated_at' => now()->subHours(25)]);

    $this->artisan('guest-cart-claims:purge')->assertSuccessful();

    $recoveredCart = app(AcquireActiveCart::class)->execute($unclaimedOwner);
    $postRetentionCart = app(AcquireActiveCart::class)->execute($claimedOwner);

    expect($recoveredCart->is($unclaimedCart))->toBeTrue()
        ->and($postRetentionCart->user_id)->toBeNull()
        ->and($postRetentionCart->session_key)->toBe($claimedOwner->sessionKey())
        ->and($userCart->fresh()->active_owner_key)->toBe("user:{$user->id}")
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $unclaimedOwner->sessionKey())->value('user_id'))
        ->toBeNull()
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $claimedOwner->sessionKey())->value('user_id'))
        ->toBeNull();
});

test('a claimed identity within retention still routes stale additions to the user cart', function () {
    config()->set('coins.cart.guest_claim_retention_hours', 24);
    $owner = CartOwner::guest(hash('sha256', 'retained-claimed-marker'));
    $user = User::factory()->create();
    $userCart = Cart::query()->create([
        'user_id' => $user->id,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
    app(ClaimGuestCart::class)->execute([$owner], $user);

    $this->artisan('guest-cart-claims:purge')->assertSuccessful();
    $staleAddCart = app(AcquireActiveCart::class)->execute($owner);

    expect($staleAddCart->is($userCart))->toBeTrue()
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $owner->sessionKey())->value('user_id'))
        ->toBe($user->id)
        ->and(Cart::query()->where('active_owner_key', 'guest:'.$owner->sessionKey())->count())->toBe(0);
});

test('guest claim retention covers session and cart secret retention and purge is scheduled hourly', function () {
    $minimumHours = max(
        24,
        (int) ceil(((int) config('session.lifetime')) / 60),
        (int) config('coins.cart.secret_retention_hours'),
    );

    expect(config('coins.cart.guest_claim_retention_hours'))->toBeGreaterThanOrEqual($minimumHours);

    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'guest-cart-claims:purge'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
