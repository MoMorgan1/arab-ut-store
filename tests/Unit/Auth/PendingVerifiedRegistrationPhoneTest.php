<?php

use App\Actions\Auth\PendingVerifiedRegistrationPhone;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Tests\TestCase;

uses(TestCase::class);

function pendingRegistrationPhoneRequest(): Request
{
    $session = new Store('pending-registration-phone-test', new ArraySessionHandler(120));
    $session->start();
    $request = Request::create('/');
    $request->setLaravelSession($session);

    return $request;
}

/** @param array<string, mixed> $payload */
function seedPendingRegistrationPhone(Request $request, array $payload): void
{
    $request->session()->put(PendingVerifiedRegistrationPhone::SESSION_KEY, $payload);
}

test('a freshly verified phone resolves within the ttl', function (): void {
    $request = pendingRegistrationPhoneRequest();
    seedPendingRegistrationPhone($request, [
        'phone' => '+201001234567',
        'verified_at' => now()->timestamp,
    ]);

    $phone = app(PendingVerifiedRegistrationPhone::class)->current($request);

    expect($phone?->value())->toBe('+201001234567')
        ->and($request->session()->has(PendingVerifiedRegistrationPhone::SESSION_KEY))->toBeTrue();
});

test('a phone verified thirty-one minutes ago is refused and forgotten', function (): void {
    $request = pendingRegistrationPhoneRequest();
    seedPendingRegistrationPhone($request, [
        'phone' => '+201001234567',
        'verified_at' => now()->subMinutes(31)->timestamp,
    ]);

    $phone = app(PendingVerifiedRegistrationPhone::class)->current($request);

    expect($phone)->toBeNull()
        ->and($request->session()->has(PendingVerifiedRegistrationPhone::SESSION_KEY))->toBeFalse();
});

test('a payload without verified_at is stale, refused and forgotten', function (): void {
    $request = pendingRegistrationPhoneRequest();
    seedPendingRegistrationPhone($request, ['phone' => '+201001234567']);

    $phone = app(PendingVerifiedRegistrationPhone::class)->current($request);

    expect($phone)->toBeNull()
        ->and($request->session()->has(PendingVerifiedRegistrationPhone::SESSION_KEY))->toBeFalse();
});
