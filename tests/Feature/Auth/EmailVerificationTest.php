<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::emailVerification());
});

test('the verify-email prompt resolves in both locales (404 regression)', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/verify-email')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/verify-email')
            ->where('authPage', 'verify_email')
            ->where('locale', 'ar'));

    $this->actingAs($user)->get('/en/verify-email')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/verify-email')
            ->where('authPage', 'verify_email')
            ->where('locale', 'en'));
});

test('verified customers hitting the prompt are redirected onward in both locales', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/verify-email')->assertRedirect('/dashboard');
    $this->actingAs($user)->get('/en/verify-email')->assertRedirect('/dashboard');
});

test('resending dispatches an Arabic verification email and is rate limited', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['preferred_locale' => 'ar']);

    foreach (range(1, 3) as $attempt) {
        $this->actingAs($user)->post('/verify-email/send')
            ->assertRedirect()
            ->assertSessionHas('status', 'verification-link-sent');
    }

    $this->actingAs($user)->post('/verify-email/send')->assertStatus(429);

    Notification::assertSentTimes(VerifyEmailNotification::class, 3);
    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $path = (string) parse_url((string) $mail->actionUrl, PHP_URL_PATH);

        expect($mail->subject)->toBe('وثّق بريد حسابك في عرب التيميت')
            ->and($path)->toStartWith('/verify-email/');

        return true;
    });
});

test('english recipients receive the locale-prefixed verification link', function () {
    Notification::fake();
    $user = User::factory()->unverified()->create(['preferred_locale' => 'en']);

    $this->actingAs($user)->post('/en/verify-email/send')->assertRedirect();

    Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $path = (string) parse_url((string) $mail->actionUrl, PHP_URL_PATH);

        expect($mail->subject)->toBe('Verify your Arab UT account email')
            ->and($path)->toStartWith('/en/verify-email/');

        return true;
    });
});

test('clicking a valid verification link marks the account verified', function () {
    config(['app.url' => 'http://localhost']);

    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1((string) $user->email)],
    );

    $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('the localized english verification link resolves and marks the account verified', function () {
    config(['app.url' => 'http://localhost']);

    $user = User::factory()->unverified()->create(['preferred_locale' => 'en']);

    $url = URL::temporarySignedRoute(
        'localized.verification.verify',
        now()->addMinutes(60),
        ['locale' => 'en', 'id' => $user->id, 'hash' => sha1((string) $user->email)],
    );

    expect((string) parse_url($url, PHP_URL_PATH))->toStartWith('/en/verify-email/');

    $this->actingAs($user)->get($url)->assertRedirect('/dashboard?verified=1');

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('a customer without an email never receives verification mail and nothing throws', function () {
    Notification::fake();
    $user = User::factory()->withoutEmail()->create();

    $this->actingAs($user)->get('/my-account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('account/overview')
            ->where('auth.user.email', null)
            ->where('auth.user.email_verified_at', null));

    $this->actingAs($user)->post('/verify-email/send')
        ->assertRedirect()
        ->assertSessionHas('status', 'verification-link-sent');

    Notification::assertNothingSent();
});

test('registration dispatches a verification email to the new customer', function () {
    Notification::fake();

    $this->post('/register', [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => 'verify-me@example.test',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect('/my-account');

    $user = User::query()->where('email', 'verify-me@example.test')->firstOrFail();

    Notification::assertSentTo($user, VerifyEmailNotification::class);
    expect($user->email_verified_at)->toBeNull();
});

test('an unverified customer can still log in, browse the account, and reach checkout', function () {
    $user = User::factory()->unverified()->create();

    $this->post('/login', ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect('/my-account');
    $this->assertAuthenticated();

    $this->actingAs($user)->get('/my-account')->assertOk();

    // Reaches the checkout endpoint itself: the controller's request
    // validation answers, not a 403 or a redirect to email verification.
    $this->actingAs($user)
        ->postJson('/checkout/paylink', [], ['Idempotency-Key' => 'unverified-checkout-1'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['expected_total']);
});
