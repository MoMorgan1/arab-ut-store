<?php

namespace App\Suppliers;

use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Protects suppliers from us and us from suppliers.
 *
 * Two independent guards: a per-minute rate limiter so a sweep tick cannot
 * hammer a supplier, and a circuit breaker so a supplier that keeps failing
 * is left alone for a cooldown instead of being retried into the ground.
 *
 * Both guards fail closed: throwing is the only outcome when a supplier
 * cannot be called.
 */
final class SupplierGuard
{
    public function ensureAvailable(Supplier $supplier, string $supplierOrderId): void
    {
        if ($this->openUntil($supplier) !== null) {
            throw new SupplierUnavailable($supplier, $supplierOrderId, null, 'circuit_open');
        }

        $key = $this->rateLimitKey($supplier);

        if (RateLimiter::tooManyAttempts($key, $this->rateLimitPerMinute())) {
            throw new SupplierUnavailable($supplier, $supplierOrderId, null, 'rate_limited');
        }

        RateLimiter::hit($key, 60);
    }

    public function recordSuccess(Supplier $supplier): void
    {
        Cache::forget($this->failureKey($supplier));
        Cache::forget($this->circuitKey($supplier));
    }

    /**
     * Record a failed call. A supplier telling us to back off opens the
     * circuit immediately for that many seconds, regardless of the count.
     */
    public function recordFailure(Supplier $supplier, ?string $retryAfter = null): void
    {
        $retryAfterSeconds = $this->retryAfterSeconds($retryAfter);

        if ($retryAfterSeconds > 0) {
            $this->openCircuit($supplier, $retryAfterSeconds);

            return;
        }

        $failures = (int) Cache::get($this->failureKey($supplier), 0) + 1;

        if ($failures >= $this->failureThreshold()) {
            $this->openCircuit($supplier, $this->cooldownSeconds());

            return;
        }

        // The count lives in its own window, longer than the cooldown, so a
        // supplier failing once every few minutes still reaches the threshold.
        Cache::put($this->failureKey($supplier), $failures, $this->failureWindowSeconds());
    }

    public function availableAt(Supplier $supplier): ?CarbonImmutable
    {
        return $this->openUntil($supplier);
    }

    private function openCircuit(Supplier $supplier, int $cooldownSeconds): void
    {
        Cache::put(
            $this->circuitKey($supplier),
            CarbonImmutable::now()->addSeconds($cooldownSeconds)->getTimestamp(),
            $cooldownSeconds,
        );
        Cache::forget($this->failureKey($supplier));
    }

    private function openUntil(Supplier $supplier): ?CarbonImmutable
    {
        $value = Cache::get($this->circuitKey($supplier));

        if (! is_numeric($value)) {
            return null;
        }

        $openUntil = CarbonImmutable::createFromTimestampUTC((int) $value);

        return $openUntil->isFuture() ? $openUntil : null;
    }

    private function rateLimitPerMinute(): int
    {
        return max(1, (int) config('services.suppliers.rate_limit_per_minute', 120));
    }

    private function failureThreshold(): int
    {
        return max(1, (int) config('services.suppliers.circuit_failure_threshold', 5));
    }

    private function cooldownSeconds(): int
    {
        return max(1, (int) config('services.suppliers.circuit_cooldown_seconds', 60));
    }

    private function failureWindowSeconds(): int
    {
        return max(1, (int) config('services.suppliers.circuit_failure_window_seconds', 600));
    }

    /**
     * Retry-After arrives either as seconds or as an HTTP date. A date in the
     * past means "no delay", so it falls through to the failure count.
     */
    private function retryAfterSeconds(?string $retryAfter): int
    {
        if ($retryAfter === null || trim($retryAfter) === '') {
            return 0;
        }

        if (is_numeric($retryAfter)) {
            return max(0, (int) $retryAfter);
        }

        $timestamp = strtotime($retryAfter);

        if ($timestamp === false) {
            return 0;
        }

        return max(0, $timestamp - CarbonImmutable::now()->getTimestamp());
    }

    private function rateLimitKey(Supplier $supplier): string
    {
        return 'supplier:'.$supplier->value;
    }

    private function failureKey(Supplier $supplier): string
    {
        return 'supplier:'.$supplier->value.':failures';
    }

    private function circuitKey(Supplier $supplier): string
    {
        return 'supplier:'.$supplier->value.':circuit';
    }
}
