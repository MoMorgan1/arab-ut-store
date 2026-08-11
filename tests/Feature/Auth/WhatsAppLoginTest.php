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
        && $request['to'] === '+201001234567'
    );
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
    ])->assertOk()->assertJsonPath('data.redirectUrl', route('dashboard', absolute: false));

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

test('unknown unverified and inactive phones receive the same safe response without a provider call', function (array $attributes) {
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
