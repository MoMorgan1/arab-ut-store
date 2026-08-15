<?php

use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.whapi', [
        'base_url' => 'https://gate.whapi.test',
        'token' => 'synthetic-whapi-token',
    ]);
});

test('an existing verified phone receives a short-lived hashed WhatsApp login code', function () {
    $user = User::factory()->create([
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });

    $this->postJson(route('auth.whatsapp.send'), ['phone' => '+201001234567'])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => ['sent' => true]]);

    $verification = PhoneVerification::query()->sole();
    expect($verification->user_id)->toBe($user->id)
        ->and($verification->phone)->toBe('+201001234567')
        ->and($verification->attempts)->toBe(0)
        ->and($verification->expires_at?->isFuture())->toBeTrue()
        ->and($verification->verified_at)->toBeNull()
        ->and($sentCode)->toMatch('/\A[0-9]{6}\z/')
        ->and(Hash::check((string) $sentCode, $verification->code_hash))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gate.whapi.test/messages/text'
        && $request->hasHeader('Authorization', 'Bearer synthetic-whapi-token')
        && $request['to'] === '201001234567'
        && $request['body'] === "رمز عرب التيميت: {$sentCode}"
    );
});

test('a new verified WhatsApp number continues to registration and becomes the account phone', function () {
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });

    $this->postJson(route('auth.whatsapp.send'), ['phone' => '+201001234567'])
        ->assertOk()
        ->assertExactJson(['data' => ['sent' => true]]);

    $verification = PhoneVerification::query()->sole();
    expect($verification->user_id)->toBeNull()
        ->and($sentCode)->toMatch('/\A[0-9]{6}\z/');

    $verifyResponse = $this->postJson(route('auth.whatsapp.verify'), [
        'phone' => '+201001234567',
        'code' => $sentCode,
    ]);

    $verifyResponse->assertOk()
        ->assertJsonPath('data.redirectUrl', route('register', absolute: false))
        ->assertSessionHas('auth.verified_registration_phone.phone', '+201001234567');

    $this->assertGuest();

    $this->post(route('register.store'), [
        'first_name' => 'New',
        'last_name' => 'Customer',
        'email' => 'new-phone@example.test',
        'password' => 'StrongPassword!12',
        'password_confirmation' => 'StrongPassword!12',
    ])->assertRedirect('/my-account');

    $user = User::query()->where('email', 'new-phone@example.test')->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->phone)->toBe('+201001234567')
        ->and($user->phone_verified_at)->not->toBeNull();
    expect(session()->has('auth.verified_registration_phone'))->toBeFalse();
});

test('a new English WhatsApp number receives concise copy and continues to localized registration', function () {
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });

    $this->postJson(route('localized.auth.whatsapp.send', ['locale' => 'en']), [
        'phone' => '+966501112233',
    ])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request['body'] === "Arab UT code: {$sentCode}");

    $this->postJson(route('localized.auth.whatsapp.verify', ['locale' => 'en']), [
        'phone' => '+966501112233',
        'code' => $sentCode,
    ])->assertOk()
        ->assertJsonPath('data.redirectUrl', route('localized.register', ['locale' => 'en'], absolute: false));
});

test('registration fails safely if a newly verified phone is claimed in another session', function () {
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });

    $this->postJson(route('auth.whatsapp.send'), ['phone' => '+201001234567'])->assertOk();
    $this->postJson(route('auth.whatsapp.verify'), [
        'phone' => '+201001234567',
        'code' => $sentCode,
    ])->assertOk();

    User::factory()->create([
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);

    $this->post(route('register.store'), [
        'first_name' => 'Conflicting',
        'last_name' => 'Customer',
        'email' => 'phone-conflict@example.test',
        'password' => 'StrongPassword!12',
        'password_confirmation' => 'StrongPassword!12',
    ])->assertSessionHasErrors('phone');

    $this->assertGuest();
    $this->assertDatabaseMissing('users', ['email' => 'phone-conflict@example.test']);
    expect(session()->get('auth.verified_registration_phone.phone'))->toBe('+201001234567');
});

test('the matching WhatsApp code is consumed once and signs in the phone owner', function () {
    $user = User::factory()->create([
        'phone' => '+966501234567',
        'phone_verified_at' => now(),
    ]);
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });

    $this->postJson(route('auth.whatsapp.send'), ['phone' => $user->phone])->assertOk();
    $this->postJson(route('auth.whatsapp.verify'), [
        'phone' => $user->phone,
        'code' => $sentCode,
    ])->assertOk()->assertJsonPath('data.redirectUrl', '/my-account');

    $this->assertAuthenticatedAs($user);
    expect(PhoneVerification::query()->sole()->verified_at)->not->toBeNull();

    auth()->logout();
    $this->postJson(route('auth.whatsapp.verify'), [
        'phone' => $user->phone,
        'code' => $sentCode,
    ])->assertUnprocessable();
    $this->assertGuest();
});

test('five incorrect attempts are persisted and exhaust the code', function () {
    $user = User::factory()->create([
        'phone' => '+966551234567',
        'phone_verified_at' => now(),
    ]);
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });
    $this->postJson(route('auth.whatsapp.send'), ['phone' => $user->phone])->assertOk();

    foreach (range(1, 5) as $_attempt) {
        $this->postJson(route('auth.whatsapp.verify'), [
            'phone' => $user->phone,
            'code' => '000000',
        ])->assertUnprocessable();
    }

    expect(PhoneVerification::query()->sole()->attempts)->toBe(5);
    $this->postJson(route('auth.whatsapp.verify'), [
        'phone' => $user->phone,
        'code' => $sentCode,
    ])->assertUnprocessable();
    $this->assertGuest();
});

test('unverified and inactive account phones receive the same safe response without a provider call', function (array $attributes) {
    User::factory()->create($attributes);
    Http::fake();

    $this->postJson(route('auth.whatsapp.send'), ['phone' => $attributes['phone']])
        ->assertOk()
        ->assertExactJson(['data' => ['sent' => true]]);

    expect(PhoneVerification::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'unverified' => [[
        'phone' => '+971501234567',
        'phone_verified_at' => null,
        'is_active' => true,
    ]],
    'inactive' => [[
        'phone' => '+96550123456',
        'phone_verified_at' => now(),
        'is_active' => false,
    ]],
]);

test('invalid phone and code payloads fail closed without reaching Whapi', function () {
    Http::fake();

    $this->postJson(route('auth.whatsapp.send'), ['phone' => '01001234567'])
        ->assertUnprocessable();
    $this->postJson(route('auth.whatsapp.verify'), [
        'phone' => '+201001234567',
        'code' => '12345',
    ])->assertUnprocessable();

    Http::assertNothingSent();
});

test('an ambiguous Whapi failure never resends the same login code automatically', function () {
    $user = User::factory()->create([
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);
    Http::fake(['https://gate.whapi.test/messages/text' => Http::response(['error' => 'temporary'], 503)]);

    $this->postJson(route('auth.whatsapp.send'), ['phone' => $user->phone])
        ->assertServiceUnavailable();

    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://gate.whapi.test/messages/text'))
        ->toHaveCount(1)
        ->and(PhoneVerification::count())->toBe(0);
});

test('an unsafe Whapi base URL fails closed without exposing the token', function () {
    $user = User::factory()->create([
        'phone' => '+201001234567',
        'phone_verified_at' => now(),
    ]);
    config()->set('services.whapi.base_url', 'http://example.test/steal');
    Http::fake();

    $this->postJson(route('auth.whatsapp.send'), ['phone' => $user->phone])
        ->assertServiceUnavailable();

    Http::assertNothingSent();
    expect(PhoneVerification::count())->toBe(0);
});
