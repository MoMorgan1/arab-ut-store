<?php

use App\Models\EmailLoginCode;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\EmailLoginCodeNotification;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An account exactly as `ImportSallaCustomers` leaves one: no password, no
 * verified email, and - for most of the cohort - no phone either.
 */
function importedSallaCustomer(array $overrides = []): User
{
    $user = User::factory()->create([
        'email' => 'imported@example.test',
        'first_name' => 'Fahad',
        ...$overrides,
    ]);

    $user->forceFill([
        'password' => null,
        'email_verified_at' => null,
        'phone' => null,
        'phone_verified_at' => null,
        'is_active' => true,
    ])->save();

    return $user;
}

/** Reads the code the way the customer does: out of the mail. */
function sallaCodeFromMail(User $user, bool $last = false): string
{
    $codes = [];

    Notification::assertSentTo($user, EmailLoginCodeNotification::class, function ($notification) use (&$codes) {
        $body = collect($notification->toMail($notification)->introLines)->implode(' ');

        if (preg_match('/\d{6}/', $body, $matches) === 1) {
            $codes[] = $matches[0];
        }

        return true;
    });

    expect($codes)->not->toBeEmpty();

    return $last ? (string) end($codes) : (string) $codes[0];
}

beforeEach(function (): void {
    Notification::fake();
    RateLimiter::clear('email-login-code:'.sha1('imported@example.test'));
});

test('an imported customer typing any password is sent a code instead of being turned away', function (): void {
    $user = importedSallaCustomer();

    // Whatever they type is wrong, because nothing would have been right.
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
        ->assertRedirect('/login/code');

    Notification::assertSentTo($user, EmailLoginCodeNotification::class);

    $pending = EmailLoginCode::query()->sole();
    expect($pending->email)->toBe('imported@example.test')
        ->and($pending->user_id)->toBe($user->id)
        ->and($pending->verified_at)->toBeNull()
        // The code itself is never stored - only a hash of it, so a copy of
        // this table is not a list of live logins.
        ->and($pending->code_hash)->toStartWith('$2y$')
        ->and($pending->code_hash)->not->toMatch('/^\d{6}$/');
});

test('the code screen names the inbox without reading out the address', function (): void {
    importedSallaCustomer();

    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);

    $this->get('/login/code')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/login-code')
            ->where('maskedEmail', 'im******@example.test')
            ->has('authUi')
            ->has('authRoutes'));
});

test('a correct code signs them in and finishes the verification the import never did', function (): void {
    $user = importedSallaCustomer();

    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);

    $code = sallaCodeFromMail($user);

    $this->post('/login/code', ['code' => $code])->assertRedirect();

    $this->assertAuthenticatedAs($user->fresh());

    expect($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(EmailLoginCode::query()->sole()->verified_at)->not->toBeNull();
});

test('a wrong code is refused, and five wrong ones close that code for good', function (): void {
    $user = importedSallaCustomer();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);

    foreach (range(1, 5) as $attempt) {
        $this->post('/login/code', ['code' => '000000'])
            ->assertSessionHasErrors('code');
    }

    $this->assertGuest();
    expect(EmailLoginCode::query()->sole()->attempts)->toBe(5);

    // Even the right code is dead once the attempts are spent.
    $pending = EmailLoginCode::query()->sole();
    $pending->forceFill(['code_hash' => Hash::make('123456')])->save();

    $this->post('/login/code', ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('the recovery page sends the same code rather than pretending to send a reset', function (): void {
    $user = importedSallaCustomer();

    $this->post('/forgot-password', ['email' => 'imported@example.test'])
        ->assertRedirect('/login/code');

    Notification::assertSentTo($user, EmailLoginCodeNotification::class);
});

test('an account with a password is left alone', function (): void {
    $user = User::factory()->create([
        'email' => 'normal@example.test',
        'password' => Hash::make('CorrectHorse!42'),
        'email_verified_at' => now(),
        'is_active' => true,
    ]);

    $this->post('/login', ['email' => 'normal@example.test', 'password' => 'wrong-one'])
        ->assertSessionHasErrors();

    $this->assertGuest();
    expect(EmailLoginCode::query()->count())->toBe(0);
    Notification::assertNothingSent();

    $this->post('/login', ['email' => 'normal@example.test', 'password' => 'CorrectHorse!42']);
    $this->assertAuthenticatedAs($user->fresh());
});

test('a deactivated account is not offered a way in', function (): void {
    $user = importedSallaCustomer();
    $user->forceFill(['is_active' => false])->save();

    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
        ->assertSessionHasErrors();

    expect(EmailLoginCode::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

// "an account Google evicted a password from is not offered a code" sets a
// verified address AND a social link, because that is the state the Google
// claim really leaves. It therefore cannot prove either exclusion on its own:
// drop one predicate and it still passes. These two isolate them.

test('a verified address alone keeps an account out of the cohort', function (): void {
    $user = importedSallaCustomer();
    $user->forceFill(['email_verified_at' => now()])->save();

    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
        ->assertSessionHasErrors();

    expect(EmailLoginCode::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a linked social account alone keeps an account out of the cohort', function (): void {
    $user = importedSallaCustomer();
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'linked-google-id',
    ]);

    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
        ->assertSessionHasErrors();

    expect(EmailLoginCode::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a live code dies the moment the address is verified another way', function (): void {
    $user = importedSallaCustomer();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);
    $code = sallaCodeFromMail($user);

    // The customer reached the account by a door this code knows nothing
    // about - the phone sign-in - and proved the address from inside. They
    // have left the cohort, and whoever holds the mailed code must not be able
    // to walk in behind them.
    $user->forceFill(['email_verified_at' => now()])->save();

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a resend with the hour spent says so instead of promising a code', function (): void {
    $user = importedSallaCustomer();
    $this->withoutMiddleware(ThrottleRequests::class);
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);

    // Spend the rest of the hour, a minute apart so every one of them sends.
    foreach (range(1, 5) as $attempt) {
        $this->travel(2)->minutes();
        $this->post('/login/code/resend');
    }

    // Past the last code's ten minutes, so there is nothing live left to read.
    $this->travel(15)->minutes();

    // "A code is on its way" here would be the same lie the whole change
    // exists to remove: an inbox watched for an hour that stays empty.
    $this->travel(2)->minutes();
    $this->post('/login/code/resend')->assertSessionHasErrors('code');
});

test('the queued code never travels in plain text', function (): void {
    // The code is hashed in `email_login_codes` so it is never written down.
    // A queued notification writes it down anyway - serialized into `jobs`,
    // and into `failed_jobs` for good if the mail host refuses it - unless the
    // notification says it must be encrypted first.
    expect(new EmailLoginCodeNotification('123456', 'ar'))
        ->toBeInstanceOf(ShouldBeEncrypted::class);
});

test('an unknown address learns nothing', function (): void {
    $this->post('/login', ['email' => 'stranger@example.test', 'password' => 'anything'])
        ->assertSessionHasErrors();

    expect(EmailLoginCode::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('the code screen is not reachable without a login attempt', function (): void {
    $this->get('/login/code')->assertRedirect('/login');
    $this->post('/login/code', ['code' => '123456'])->assertRedirect('/login');
});

test('one mailbox cannot be flooded', function (): void {
    $user = importedSallaCustomer();

    // Without this the login route's own throttle answers 429 first and the
    // mailbox limit never gets tested; it is the second limit that is under
    // test here, not the first.
    $this->withoutMiddleware(ThrottleRequests::class);

    // Eight attempts inside the same minute. The cooldown collapses them into
    // one mail, and every one of them still lands on the code screen, because
    // the code already sent is live and saying anything else would send the
    // customer looking for a second one.
    //
    // Exactly one, not "at most six": a ceiling nothing approaches is a test
    // that passes whatever the cooldown does. The hourly cap is a different
    // limit and is pinned separately, a minute apart, further down.
    foreach (range(1, 8) as $attempt) {
        $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
            ->assertRedirect('/login/code');
    }

    $sent = 0;
    Notification::assertSentTo($user, EmailLoginCodeNotification::class, function () use (&$sent) {
        $sent++;

        return true;
    });

    expect($sent)->toBe(1);
});

test('a code dies when the customer changes the address it was sent to', function (): void {
    $user = importedSallaCustomer();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);
    $code = sallaCodeFromMail($user);

    // Whoever reads the old mailbox holds a code for an address this account
    // no longer has.
    $user->forceFill(['email' => 'moved@example.test'])->save();

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a code dies once the account has a password of its own', function (): void {
    $user = importedSallaCustomer();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);
    $code = sallaCodeFromMail($user);

    $user->forceFill(['password' => Hash::make('ChosenLater!42')])->save();

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('a code dies once Google has claimed the account', function (): void {
    $user = importedSallaCustomer();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);
    $code = sallaCodeFromMail($user);

    // The claim path verifies the address and nulls the password, so the
    // password check alone would still pass. The social link is what says the
    // owner arrived by another road.
    $user->forceFill(['email_verified_at' => now()])->save();
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'claimer-google-id',
    ]);

    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');
    $this->assertGuest();
});

test('an account Google evicted a password from is not offered a code', function (): void {
    // Exactly the state GoogleAuthenticationController leaves behind when it
    // claims an account somebody pre-registered with an address they do not
    // own: password nulled to evict them, address now verified.
    $user = User::factory()->create([
        'email' => 'claimed@example.test',
        'email_verified_at' => now(),
        'is_active' => true,
    ]);
    $user->forceFill(['password' => null])->save();
    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_user_id' => 'owner-google-id',
    ]);

    // Otherwise the evicted attacker keeps a button that mails the real owner.
    $this->post('/login', ['email' => 'claimed@example.test', 'password' => 'attacker-known'])
        ->assertSessionHasErrors();

    expect(EmailLoginCode::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('a new code retires the one it replaces', function (): void {
    $user = importedSallaCustomer();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);
    $first = sallaCodeFromMail($user);

    // Past the one-minute cooldown, ask again.
    $this->travel(2)->minutes();
    $this->post('/login/code/resend');
    $second = sallaCodeFromMail($user, last: true);

    // The replaced row is retired outright, not merely outranked. Selecting
    // "the newest unverified row" would fall back to it the moment the newest
    // one was stamped, and a code the customer replaced would open the account
    // again minutes later.
    //
    // Asserted on the rows rather than by submitting the old code, because two
    // six-digit draws collide once in nine hundred thousand runs and a test
    // that fails that often is a test nobody believes. `Hash::check` says
    // which row holds which code, so the retirement is pinned either way.
    $rows = EmailLoginCode::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2)
        ->and(Hash::check($first, $rows[0]->code_hash))->toBeTrue()
        ->and($rows[0]->expires_at->isFuture())->toBeFalse()
        ->and(Hash::check($second, $rows[1]->code_hash))->toBeTrue()
        ->and($rows[1]->expires_at->isFuture())->toBeTrue();

    $this->post('/login/code', ['code' => $second])->assertRedirect();
    $this->assertAuthenticatedAs($user->fresh());
});

test('a declined resend does not spend the hour, and an exhausted hour says so', function (): void {
    $user = importedSallaCustomer();
    $this->withoutMiddleware(ThrottleRequests::class);

    // Six inside one minute: the first sends, the other five are declined by
    // the cooldown. Charging for those declines is how somebody who knows an
    // address empties the allowance in seconds.
    foreach (range(1, 6) as $attempt) {
        $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
            ->assertRedirect('/login/code');
    }

    $sent = 0;
    Notification::assertSentTo($user, EmailLoginCodeNotification::class, function () use (&$sent) {
        $sent++;

        return true;
    });
    expect($sent)->toBe(1);

    // Five more real codes, a minute apart, spend the rest of the hour.
    foreach (range(1, 5) as $attempt) {
        $this->travel(2)->minutes();
        $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
            ->assertRedirect('/login/code');
    }

    // The seventh is refused out loud rather than answered with a code screen
    // and a code that never comes.
    $this->travel(2)->minutes();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
        ->assertSessionHasErrors('email');
});

test('a failed code submission leaves no copy of the code in the session', function (): void {
    $user = importedSallaCustomer();
    $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything']);
    $code = sallaCodeFromMail($user);

    // The address moves under the open screen, so a valid code fails.
    $user->forceFill(['email' => 'moved@example.test'])->save();
    $this->post('/login/code', ['code' => $code])->assertSessionHasErrors('code');

    // The flashed old input is where a live credential would otherwise sit in
    // plain text, beside the hash written so it never would.
    $old = session('_old_input') ?? [];
    expect($old)->not->toHaveKey('code');
    expect(json_encode($old, JSON_THROW_ON_ERROR))->not->toContain($code);
});
