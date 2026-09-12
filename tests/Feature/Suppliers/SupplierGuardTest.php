<?php

use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

function guardFailureReason(callable $call): ?string
{
    try {
        $call();
    } catch (SupplierUnavailable $exception) {
        return $exception->reason;
    }

    return null;
}

test('the rate limiter fails closed at the per-minute allowance and frees up after a minute', function () {
    config()->set('services.suppliers.rate_limit_per_minute', 2);

    $guard = app(SupplierGuard::class);

    $guard->ensureAvailable(Supplier::Fft, 'order-1');
    $guard->ensureAvailable(Supplier::Fft, 'order-1');

    expect(guardFailureReason(fn () => $guard->ensureAvailable(Supplier::Fft, 'order-1')))
        ->toBe('rate_limited');

    $this->travel(61)->seconds();

    $guard->ensureAvailable(Supplier::Fft, 'order-1');
});

test('the circuit opens after the failure threshold and closes after the cooldown', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 2);
    config()->set('services.suppliers.circuit_cooldown_seconds', 60);

    $guard = app(SupplierGuard::class);

    $guard->recordFailure(Supplier::Fft);
    expect($guard->availableAt(Supplier::Fft))->toBeNull();

    $guard->recordFailure(Supplier::Fft);
    expect($guard->availableAt(Supplier::Fft))->not->toBeNull();

    expect(guardFailureReason(fn () => $guard->ensureAvailable(Supplier::Fft, 'order-1')))
        ->toBe('circuit_open');

    $this->travel(61)->seconds();

    expect($guard->availableAt(Supplier::Fft))->toBeNull();

    $guard->ensureAvailable(Supplier::Fft, 'order-1');
});

test('a retry-after answer opens the circuit immediately for that long', function () {
    $guard = app(SupplierGuard::class);

    $guard->recordFailure(Supplier::Utt, '120');

    expect($guard->availableAt(Supplier::Utt))->not->toBeNull()
        ->and(guardFailureReason(fn () => $guard->ensureAvailable(Supplier::Utt, 'order-1')))
        ->toBe('circuit_open');

    $this->travel(61)->seconds();
    expect($guard->availableAt(Supplier::Utt))->not->toBeNull();

    $this->travel(60)->seconds();
    expect($guard->availableAt(Supplier::Utt))->toBeNull();
});

test('an http-date retry-after opens the circuit', function () {
    $guard = app(SupplierGuard::class);

    $guard->recordFailure(Supplier::Utt, CarbonImmutable::now()->addSeconds(120)->toRfc7231String());

    expect($guard->availableAt(Supplier::Utt))->not->toBeNull()
        ->and(guardFailureReason(fn () => $guard->ensureAvailable(Supplier::Utt, 'order-1')))
        ->toBe('circuit_open');
});

test('a retry-after date in the past is ignored and falls back to the failure count', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 5);

    $guard = app(SupplierGuard::class);

    $guard->recordFailure(Supplier::Utt, 'Sat, 01 Jan 2000 00:00:00 GMT');

    expect($guard->availableAt(Supplier::Utt))->toBeNull()
        ->and(Cache::get('supplier:'.Supplier::Utt->value.':failures'))->toBe(1);
});

test('failures spread across the failure window still open the circuit', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 5);

    $guard = app(SupplierGuard::class);

    for ($failure = 0; $failure < 4; $failure++) {
        $guard->recordFailure(Supplier::Fft);
        $this->travel(90)->seconds();
    }

    expect($guard->availableAt(Supplier::Fft))->toBeNull();

    $guard->recordFailure(Supplier::Fft);

    expect($guard->availableAt(Supplier::Fft))->not->toBeNull();
});

test('a successful call clears accumulated failures', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 2);

    $guard = app(SupplierGuard::class);

    $guard->recordFailure(Supplier::Fft);
    $guard->recordSuccess(Supplier::Fft);
    $guard->recordFailure(Supplier::Fft);

    expect($guard->availableAt(Supplier::Fft))->toBeNull();

    $guard->ensureAvailable(Supplier::Fft, 'order-1');
});

test('guard state is tracked per supplier', function () {
    config()->set('services.suppliers.rate_limit_per_minute', 1);

    $guard = app(SupplierGuard::class);

    $guard->ensureAvailable(Supplier::Fft, 'order-1');
    $guard->ensureAvailable(Supplier::Utt, 'order-2');

    expect(guardFailureReason(fn () => $guard->ensureAvailable(Supplier::Fft, 'order-1')))
        ->toBe('rate_limited');
});
