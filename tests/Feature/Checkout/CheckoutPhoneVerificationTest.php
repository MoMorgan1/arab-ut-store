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

test('an authenticated customer can verify a new checkout phone through Whapi', function () {
    $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);
    $sentCode = null;
    Http::fake(function (Request $request) use (&$sentCode) {
        preg_match('/\b([0-9]{6})\b/', (string) $request['body'], $matches);
        $sentCode = $matches[1] ?? null;

        return Http::response(['sent' => true]);
    });

    $this->actingAs($user)->postJson('/checkout/phone/code', [
        'phone' => '+201001234567',
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => ['sent' => true]]);

    $verification = PhoneVerification::query()->sole();
    expect($verification->user_id)->toBe($user->id)
        ->and($verification->phone)->toBe('+201001234567')
        ->and($sentCode)->toMatch('/\A[0-9]{6}\z/')
        ->and(Hash::check((string) $sentCode, $verification->code_hash))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://gate.whapi.test/messages/text'
        && $request['to'] === '+201001234567'
        && str_contains((string) $request['body'], 'رمز توثيق رقمك لإتمام الدفع في عرب التيميت'));

    $this->actingAs($user)->postJson('/checkout/phone/verify', [
        'phone' => '+201001234567',
        'code' => $sentCode,
    ])->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => ['verified' => true]]);

    $user->refresh();
    expect($user->phone)->toBe('+201001234567')
        ->and($user->phone_verified_at)->not->toBeNull()
        ->and($verification->fresh()?->verified_at)->not->toBeNull();
});

test('checkout phone verification rejects guests reused phones and wrong codes', function () {
    $owner = User::factory()->create([
        'phone' => '+966501234567',
        'phone_verified_at' => now(),
    ]);
    $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);
    Http::fake();

    $this->postJson('/checkout/phone/code', ['phone' => '+201001234567'])
        ->assertUnauthorized()
        ->assertHeader('Cache-Control', 'no-store, private');

    $this->actingAs($user)->postJson('/checkout/phone/code', [
        'phone' => $owner->phone,
    ])->assertUnprocessable()->assertJsonPath('error.code', 'phone_unavailable');

    expect(PhoneVerification::query()->count())->toBe(0);
    Http::assertNothingSent();

    PhoneVerification::create([
        'user_id' => $user->id,
        'phone' => '+201001234567',
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(5),
        'verified_at' => null,
    ]);

    $this->actingAs($user)->postJson('/checkout/phone/verify', [
        'phone' => '+201001234567',
        'code' => '000000',
    ])->assertUnprocessable()->assertJsonPath('error.code', 'phone_code_invalid');

    expect($user->fresh()?->phone_verified_at)->toBeNull();
});
