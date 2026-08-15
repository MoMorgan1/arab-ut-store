<?php

use App\Models\User;
use App\Models\UserIdentityChange;
use App\Notifications\EmailChangedNotification;
use App\Notifications\PendingEmailChangeNotification;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    config()->set('services.whapi', [
        'base_url' => 'https://gate.whapi.test',
        'token' => 'synthetic-whapi-token',
    ]);
});

test('the bilingual profile page exposes only editable identity state', function (
    string $path,
    string $locale,
): void {
    $user = User::factory()->create([
        'first_name' => 'Mohamed',
        'last_name' => 'Player',
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)->get($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertInertia(fn ($page) => $page
            ->component('account/profile')
            ->where('locale', $locale)
            ->where('profile.firstName', 'Mohamed')
            ->where('profile.lastName', 'Player')
            ->where('profile.email.value', $user->email)
            ->where('profile.email.verified', true)
            ->where('profile.phone.value', '+201001234567')
            ->where('profile.phone.verified', true)
            ->where('profile.preferredLocale', 'ar')
            ->where('profile.displayCurrency', 'SAR')
            ->where('accountNavigation', fn ($items): bool => collect($items)->pluck('key')->all() === [
                'overview', 'orders', 'wallet', 'profile',
            ])
            ->missing('profile.password'));

    expect($response->inertiaPage()['encryptHistory'] ?? false)->toBeTrue();
})->with([
    'Arabic profile' => ['/my-account/profile', 'ar'],
    'English profile' => ['/en/my-account/profile', 'en'],
]);

test('names and preferences update without changing verified contact identities', function (): void {
    $user = User::factory()->create([
        'email' => 'current@example.test',
        'phone' => '+201001234567',
        'email_verified_at' => now(),
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->patch('/my-account/profile', [
        'first_name' => 'Updated',
        'last_name' => 'Customer',
        'preferred_locale' => 'en',
        'display_currency' => 'AED',
        'email' => 'typo@example.test',
        'phone' => '+966501112233',
    ])->assertRedirect('/my-account/profile');

    $user->refresh();
    expect($user->first_name)->toBe('Updated')
        ->and($user->last_name)->toBe('Customer')
        ->and($user->preferred_locale)->toBe('en')
        ->and($user->display_currency)->toBe('AED')
        ->and($user->email)->toBe('current@example.test')
        ->and($user->phone)->toBe('+201001234567');
});

test('email changes stay encrypted and pending until the new address opens its one-time link', function (): void {
    Notification::fake();
    $user = User::factory()->create([
        'email' => 'old@example.test',
        'email_verified_at' => now(),
    ]);
    $verificationUrl = null;

    $this->actingAs($user)->post('/my-account/profile/email', [
        'email' => 'new@example.test',
    ])->assertRedirect('/my-account/profile');

    $change = UserIdentityChange::query()->sole();
    $raw = DB::table('user_identity_changes')->where('id', $change->id)->sole();

    expect($user->fresh()->email)->toBe('old@example.test')
        ->and($change->candidate_value)->toBe('new@example.test')
        ->and($raw->candidate_value)->not->toContain('new@example.test')
        ->and($raw->verification_hash)->not->toBeNull()
        ->and($raw->verification_hash)->not->toContain('new@example.test');

    Notification::assertSentOnDemand(
        PendingEmailChangeNotification::class,
        function (PendingEmailChangeNotification $notification, array $channels, object $notifiable) use (&$verificationUrl): bool {
            $verificationUrl = $notification->verificationUrl;

            return $channels === ['mail']
                && data_get($notifiable, 'routes.mail') === 'new@example.test';
        },
    );

    expect($verificationUrl)->toBeString();
    $this->get((string) $verificationUrl)->assertRedirect('/my-account/profile');

    $user->refresh();
    expect($user->email)->toBe('new@example.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($change->fresh()->consumed_at)->not->toBeNull();
    Notification::assertSentOnDemand(
        EmailChangedNotification::class,
        fn (EmailChangedNotification $_notification, array $channels, object $notifiable): bool => $channels === ['mail']
            && data_get($notifiable, 'routes.mail') === 'old@example.test',
    );

    $this->get((string) $verificationUrl)->assertSessionHasErrors('email');
});

test('email candidates can be staged without asking for the current password', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'owner@example.test']);
    User::factory()->create(['email' => 'taken@example.test']);

    $this->actingAs($user)->post('/my-account/profile/email', [
        'email' => 'taken@example.test',
    ])->assertSessionHasErrors('email');

    $this->post('/my-account/profile/email', [
        'email' => 'free@example.test',
    ])->assertRedirect('/my-account/profile');

    expect(UserIdentityChange::count())->toBe(1);
});

test('phone changes use a bounded hashed OTP and swap atomically after proof', function (): void {
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });
    $user = User::factory()->create([
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $this->actingAs($user)->postJson('/my-account/profile/phone', [
        'phone' => '+966501112233',
    ])->assertOk()->assertExactJson(['data' => ['sent' => true]]);

    $change = UserIdentityChange::query()->sole();
    $raw = DB::table('user_identity_changes')->where('id', $change->id)->sole();
    expect($user->fresh()->phone)->toBe('+201001234567')
        ->and($change->candidate_value)->toBe('+966501112233')
        ->and($raw->candidate_value)->not->toContain('+966501112233')
        ->and($sentCode)->toMatch('/\A[0-9]{6}\z/')
        ->and(Hash::check((string) $sentCode, $change->verification_hash))->toBeTrue();

    foreach (range(1, 4) as $_attempt) {
        $this->postJson('/my-account/profile/phone/confirm', ['code' => '000000'])
            ->assertUnprocessable();
    }
    expect($change->fresh()->attempts)->toBe(4);

    $this->postJson('/my-account/profile/phone/confirm', ['code' => $sentCode])
        ->assertOk()->assertExactJson(['data' => ['verified' => true]]);

    $user->refresh();
    expect($user->phone)->toBe('+966501112233')
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($change->fresh()->attempts)->toBe(5)
        ->and($change->fresh()->consumed_at)->not->toBeNull();

    $this->postJson('/my-account/profile/phone/confirm', ['code' => $sentCode])
        ->assertUnprocessable();
});

test('phone resend cooldown avoids duplicate delivery and expired codes fail closed', function (): void {
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });
    $user = User::factory()->create();
    $payload = ['phone' => '+966501112233'];

    $this->actingAs($user)->postJson('/my-account/profile/phone', $payload)->assertOk();
    $this->postJson('/my-account/profile/phone', $payload)->assertOk();
    Http::assertSentCount(1);

    UserIdentityChange::query()->sole()->forceFill(['expires_at' => now()->subSecond()])->save();
    $this->postJson('/my-account/profile/phone/confirm', ['code' => $sentCode])
        ->assertUnprocessable();
    expect($user->fresh()->phone)->toBeNull();
});

test('contact changes are available to password accounts without a current-password field', function (): void {
    Http::fake(fn () => Http::response(['sent' => true]));
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/my-account/profile/phone', [
        'phone' => '+966501112233',
    ])->assertOk();
});

test('identity change delivery is rate limited by the signed in customer', function (): void {
    Http::fake(fn () => Http::response(['sent' => true]));
    $user = User::factory()->create();

    foreach (['+966501112231', '+966501112232', '+966501112233'] as $phone) {
        $this->actingAs($user)->postJson('/my-account/profile/phone', [
            'phone' => $phone,
        ])->assertOk();
    }

    $this->postJson('/my-account/profile/phone', [
        'phone' => '+966501112234',
    ])->assertTooManyRequests();
    Http::assertSentCount(3);
});
