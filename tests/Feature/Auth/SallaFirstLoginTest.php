<?php

use App\Models\EmailLoginCode;
use App\Models\User;
use App\Notifications\EmailLoginCodeNotification;
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
        // The code itself is never stored.
        ->and($pending->code_hash)->not->toContain('0');
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

    // Read the code the way the customer does - out of the mail.
    $code = null;
    Notification::assertSentTo($user, EmailLoginCodeNotification::class, function ($notification) use (&$code) {
        $body = collect($notification->toMail($notification)->introLines)->implode(' ');
        preg_match('/\d{6}/', $body, $matches);
        $code = $matches[0] ?? null;

        return true;
    });

    expect($code)->not->toBeNull();

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

    // Six codes an hour; the seventh attempt still lands the customer on the
    // code screen, because the code already sent is still live.
    foreach (range(1, 8) as $attempt) {
        $this->post('/login', ['email' => 'imported@example.test', 'password' => 'anything'])
            ->assertRedirect('/login/code');
    }

    $sent = 0;
    Notification::assertSentTo($user, EmailLoginCodeNotification::class, function () use (&$sent) {
        $sent++;

        return true;
    });

    expect($sent)->toBeLessThanOrEqual(6);
});
