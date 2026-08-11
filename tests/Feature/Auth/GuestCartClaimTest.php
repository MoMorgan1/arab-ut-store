<?php

use App\Actions\Cart\ClaimGuestCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

test('localized login converts a guest-only cart without changing its items or encrypted secret', function () {
    $user = User::factory()->create();
    $rawToken = str_repeat('a', 64);
    $guestCart = guestClaimCart(guestClaimHmac($rawToken));
    $item = guestClaimItem($guestCart, 'Guest-only secret sentinel');
    $ciphertext = guestClaimCiphertext($item);

    $response = $this->withSession([
        ResolveCartOwner::SESSION_KEY => $rawToken,
        'url.intended' => '/en/cart',
    ])->post(route('localized.login.store', ['locale' => 'en']), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/en/cart');
    $this->assertAuthenticatedAs($user);
    $response->assertSessionMissing(ResolveCartOwner::SESSION_KEY);

    $claimedCart = $guestCart->fresh();

    expect($claimedCart)->not->toBeNull()
        ->and($claimedCart->user_id)->toBe($user->id)
        ->and($claimedCart->session_key)->toBeNull()
        ->and($claimedCart->active_owner_key)->toBe("user:{$user->id}")
        ->and($item->fresh()->cart_id)->toBe($guestCart->id)
        ->and(guestClaimCiphertext($item))->toBe($ciphertext);
});

test('localized registration claims the guest cart through the same successful login event', function () {
    $rawToken = str_repeat('b', 64);
    $guestCart = guestClaimCart(guestClaimHmac($rawToken));
    guestClaimItem($guestCart, 'Registration secret sentinel');

    $response = $this->withSession([
        ResolveCartOwner::SESSION_KEY => $rawToken,
        'url.intended' => '/en/cart',
    ])->post(route('localized.register.store', ['locale' => 'en']), [
        'first_name' => 'Guest',
        'last_name' => 'Owner',
        'email' => 'guest-owner@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->where('email', 'guest-owner@example.test')->sole();

    $response->assertRedirect('/en/cart');
    $this->assertAuthenticatedAs($user);
    $response->assertSessionMissing(ResolveCartOwner::SESSION_KEY);

    expect($guestCart->fresh()->user_id)->toBe($user->id)
        ->and(Cart::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1);
});

test('login merges guest items into an existing user cart without selecting or changing secrets', function () {
    $user = User::factory()->create();
    $rawToken = str_repeat('c', 64);
    $guestCart = guestClaimCart(guestClaimHmac($rawToken));
    $userCart = guestClaimCart(null, $user);
    $guestItem = guestClaimItem($guestCart, 'Merge guest secret sentinel');
    $userItem = guestClaimItem($userCart, 'Merge user secret sentinel');
    $guestCiphertext = guestClaimCiphertext($guestItem);
    $userCiphertext = guestClaimCiphertext($userItem);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $response = $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $claimQueries = DB::getQueryLog();
    DB::disableQueryLog();
    $querySql = strtolower(implode("\n", array_column($claimQueries, 'query')));

    $response->assertRedirect('/dashboard');
    $response->assertSessionMissing(ResolveCartOwner::SESSION_KEY);

    expect(Cart::query()->whereKey($guestCart->id)->exists())->toBeFalse()
        ->and(Cart::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1)
        ->and($guestItem->fresh()->cart_id)->toBe($userCart->id)
        ->and($userItem->fresh()->cart_id)->toBe($userCart->id)
        ->and(guestClaimCiphertext($guestItem))->toBe($guestCiphertext)
        ->and(guestClaimCiphertext($userItem))->toBe($userCiphertext)
        ->and($querySql)->not->toContain('encrypted_payload')
        ->and($querySql)->not->toContain('cart_item_secrets')
        ->and($querySql)->not->toContain('secret_access_logs');
});

test('repeating a claim is an idempotent no-op', function () {
    $user = User::factory()->create();
    $guestHmac = guestClaimHmac(str_repeat('d', 64));
    $guestCart = guestClaimCart($guestHmac);
    $item = guestClaimItem($guestCart, 'Repeated claim secret sentinel');

    $action = app(ClaimGuestCart::class);
    $action->execute($guestHmac, $user);
    $action->execute($guestHmac, $user);

    expect(Cart::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1)
        ->and(Cart::query()->where('active_owner_key', "guest:{$guestHmac}")->count())->toBe(0)
        ->and(CartItem::query()->whereKey($item->id)->count())->toBe(1)
        ->and($item->fresh()->cart_id)->toBe($guestCart->id);
});

test('a failed merge rolls back ownership and retains the guest token for retry', function () {
    $user = User::factory()->create();
    $rawToken = str_repeat('e', 64);
    $guestHmac = guestClaimHmac($rawToken);
    $guestCart = guestClaimCart($guestHmac);
    $userCart = guestClaimCart(null, $user);
    $guestItem = guestClaimItem($guestCart, 'Rollback guest secret sentinel');
    $userItem = guestClaimItem($userCart, 'Rollback user secret sentinel');

    try {
        DB::transaction(function () use ($rawToken, $user): void {
            $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
                ->post(route('login.store'), [
                    'email' => $user->email,
                    'password' => 'password',
                ])
                ->assertRedirect('/dashboard');

            throw new RuntimeException('synthetic outer transaction failure');
        });

        $this->fail('The synthetic outer transaction failure was not propagated.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('synthetic outer transaction failure');
    }

    expect(session()->get(ResolveCartOwner::SESSION_KEY))->toBe($rawToken)
        ->and($guestCart->fresh()->active_owner_key)->toBe("guest:{$guestHmac}")
        ->and($userCart->fresh()->active_owner_key)->toBe("user:{$user->id}")
        ->and($guestItem->fresh()->cart_id)->toBe($guestCart->id)
        ->and($userItem->fresh()->cart_id)->toBe($userCart->id);
});

test('login cannot claim a cart belonging to another guest session', function () {
    $user = User::factory()->create();
    $cartOwnerToken = str_repeat('f', 64);
    $otherSessionToken = str_repeat('0', 64);
    $guestHmac = guestClaimHmac($cartOwnerToken);
    $guestCart = guestClaimCart($guestHmac);
    $item = guestClaimItem($guestCart, 'Other session secret sentinel');

    $response = $this->withSession([ResolveCartOwner::SESSION_KEY => $otherSessionToken])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertRedirect('/dashboard');
    $response->assertSessionMissing(ResolveCartOwner::SESSION_KEY);

    expect($guestCart->fresh()->active_owner_key)->toBe("guest:{$guestHmac}")
        ->and($guestCart->fresh()->user_id)->toBeNull()
        ->and($item->fresh()->cart_id)->toBe($guestCart->id)
        ->and(Cart::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(0);
});

function guestClaimHmac(string $rawToken): string
{
    return hash_hmac('sha256', $rawToken, (string) config('app.key'));
}

function guestClaimCart(?string $guestHmac = null, ?User $user = null): Cart
{
    return Cart::query()->create([
        'user_id' => $user?->id,
        'session_key' => $guestHmac,
        'status' => 'active',
        'currency' => 'SAR',
    ]);
}

function guestClaimItem(Cart $cart, string $secretSentinel): CartItem
{
    $variant = ProductVariant::factory()->create();
    $item = $cart->items()->create([
        'product_variant_id' => $variant->id,
        'quantity' => 1,
        'unit_price_halalah' => 5_000,
        'total_halalah' => 5_000,
        'configuration' => ['service_type' => 'coins'],
    ]);
    $secret = new CartItemSecret([
        'cart_item_id' => $item->id,
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 5],
        'retained_until' => now()->addHour(),
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'claim@example.test',
        'ea_password' => $secretSentinel,
        'backup_codes' => ['10000001', '10000002', '10000003', '10000004', '10000005'],
    ];
    $secret->save();

    return $item;
}

function guestClaimCiphertext(CartItem $item): string
{
    return (string) DB::table('cart_item_secrets')
        ->where('cart_item_id', $item->id)
        ->value('encrypted_payload');
}
