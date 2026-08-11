<?php

use App\Actions\Cart\AcquireActiveCart;
use App\Actions\Cart\ClaimGuestCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\ProductVariant;
use App\Models\User;
use App\ValueObjects\Cart\CartOwner;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
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
    $action->execute([CartOwner::guest($guestHmac)], $user);
    $action->execute([CartOwner::guest($guestHmac)], $user);

    expect(Cart::query()->where('active_owner_key', "user:{$user->id}")->count())->toBe(1)
        ->and(Cart::query()->where('active_owner_key', "guest:{$guestHmac}")->count())->toBe(0)
        ->and(CartItem::query()->whereKey($item->id)->count())->toBe(1)
        ->and($item->fresh()->cart_id)->toBe($guestCart->id);
});

test('a real claim failure logs the user back out, rolls back, retains the token, and can be retried', function () {
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $this->markTestSkipped('This failure injection uses a SQLite trigger.');
    }

    $user = User::factory()->create();
    $rawToken = str_repeat('e', 64);
    $guestHmac = guestClaimHmac($rawToken);
    $guestCart = guestClaimCart($guestHmac);
    $userCart = guestClaimCart(null, $user);
    $guestItem = guestClaimItem($guestCart, 'Rollback guest secret sentinel');
    $userItem = guestClaimItem($userCart, 'Rollback user secret sentinel');

    DB::unprepared(<<<SQL
        CREATE TRIGGER fail_guest_cart_claim
        BEFORE DELETE ON carts
        WHEN OLD.id = {$guestCart->id}
        BEGIN
            SELECT RAISE(ABORT, 'injected guest claim failure');
        END
        SQL);

    $this->withoutExceptionHandling();

    try {
        $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
            ->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->fail('The injected claim failure was not propagated.');
    } catch (QueryException $exception) {
        expect($exception->getMessage())->toContain('injected guest claim failure');
    }

    $this->assertGuest();
    expect(session()->get(ResolveCartOwner::SESSION_KEY))->toBe($rawToken)
        ->and($guestCart->fresh()->active_owner_key)->toBe("guest:{$guestHmac}")
        ->and($userCart->fresh()->active_owner_key)->toBe("user:{$user->id}")
        ->and($guestItem->fresh()->cart_id)->toBe($guestCart->id)
        ->and($userItem->fresh()->cart_id)->toBe($userCart->id);

    DB::unprepared('DROP TRIGGER fail_guest_cart_claim');

    $response = $this->withSession([ResolveCartOwner::SESSION_KEY => $rawToken])
        ->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

    $response->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    $response->assertSessionMissing(ResolveCartOwner::SESSION_KEY);
    expect(Cart::query()->whereKey($guestCart->id)->exists())->toBeFalse()
        ->and($guestItem->fresh()->cart_id)->toBe($userCart->id)
        ->and($userItem->fresh()->cart_id)->toBe($userCart->id);
});

test('login claims every current and previous key cart in one root transaction', function () {
    $user = User::factory()->create();
    $oldApplicationKey = 'base64:'.base64_encode(str_repeat('o', 32));
    $newApplicationKey = 'base64:'.base64_encode(str_repeat('n', 32));
    $rawToken = bin2hex(random_bytes(32));
    $oldHmac = hash_hmac('sha256', $rawToken, $oldApplicationKey);
    $newHmac = hash_hmac('sha256', $rawToken, $newApplicationKey);
    $oldCart = guestClaimCart($oldHmac);
    $newCart = guestClaimCart($newHmac);
    $oldItem = guestClaimItem($oldCart, 'Old-key claim sentinel');
    $newItem = guestClaimItem($newCart, 'New-key claim sentinel');
    $session = new Store('guest-claim-rotation', new ArraySessionHandler(120));
    $session->start();
    $session->put(ResolveCartOwner::SESSION_KEY, $rawToken);
    $request = Request::create('/login');
    $request->setLaravelSession($session);
    config()->set('app.key', $newApplicationKey);
    config()->set('app.previous_keys', [$oldApplicationKey]);

    $owners = app(ResolveCartOwner::class)->existingGuestCandidatesForRequest($request);

    expect(array_map(fn (CartOwner $owner): string => $owner->sessionKey() ?? '', $owners))
        ->toBe([$newHmac, $oldHmac])
        ->and($oldCart->fresh()->active_owner_key)->toBe("guest:{$oldHmac}")
        ->and($newCart->fresh()->active_owner_key)->toBe("guest:{$newHmac}");

    app(ClaimGuestCart::class)->execute($owners, $user);

    $claimedCart = Cart::query()->where('active_owner_key', "user:{$user->id}")->sole();
    expect($claimedCart->items()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$oldItem->id, $newItem->id])->sort()->values()->all())
        ->and(Cart::query()->whereIn('active_owner_key', ["guest:{$oldHmac}", "guest:{$newHmac}"])->count())
        ->toBe(0);
});

test('a claimed guest identity cannot recreate an orphan guest cart', function () {
    $user = User::factory()->create();
    $guestHmac = guestClaimHmac(str_repeat('1', 64));
    $owner = CartOwner::guest($guestHmac);

    app(ClaimGuestCart::class)->execute([$owner], $user);
    $cart = app(AcquireActiveCart::class)->execute($owner);

    expect($cart->user_id)->toBe($user->id)
        ->and($cart->session_key)->toBeNull()
        ->and($cart->active_owner_key)->toBe("user:{$user->id}")
        ->and(Cart::query()->where('active_owner_key', "guest:{$guestHmac}")->count())->toBe(0)
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $guestHmac)->value('user_id'))
        ->toBe($user->id);
});

test('a guest identity claimed by one user fails closed for another user', function () {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();
    $guestHmac = guestClaimHmac(str_repeat('2', 64));
    $owner = CartOwner::guest($guestHmac);
    $cart = guestClaimCart($guestHmac);

    app(ClaimGuestCart::class)->execute([$owner], $firstUser);

    expect(fn () => app(ClaimGuestCart::class)->execute([$owner], $secondUser))
        ->toThrow(RuntimeException::class, 'The guest cart has already been claimed.')
        ->and($cart->fresh()->active_owner_key)->toBe("user:{$firstUser->id}")
        ->and(DB::table('guest_cart_claims')->where('guest_session_hmac', $guestHmac)->value('user_id'))
        ->toBe($firstUser->id)
        ->and(Cart::query()->where('active_owner_key', "user:{$secondUser->id}")->count())->toBe(0);
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
        'masked_summary' => ['has_password' => true, 'backup_code_count' => 3],
        'retained_until' => null,
    ]);
    $secret->encrypted_payload = [
        'ea_email' => 'claim@example.test',
        'ea_password' => $secretSentinel,
        'backup_codes' => ['10000001', '10000002', '10000003'],
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
