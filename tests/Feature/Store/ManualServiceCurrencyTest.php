<?php

use App\Models\ExchangeRate;
use Inertia\Testing\AssertableInertia as Assert;

it('presents manual-service prices in the session display currency', function () {
    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'USD',
        'rate' => '0.26666667',
        'source' => 'exchange-rate-api-open-access',
        'fetched_at' => now(),
    ]);
    Http::preventStrayRequests();

    $this->withSession(['display_currency' => 'USD'])
        ->get('/fut-champions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manualService.active', true)
            ->where('manualService.pricing.currency', 'USD')
            ->where('manualService.pricing.rankOptions', [
                ['rank' => 1, 'price' => ['amountMinor' => 5_867, 'currency' => 'USD']],
                ['rank' => 2, 'price' => ['amountMinor' => 5_067, 'currency' => 'USD']],
                ['rank' => 3, 'price' => ['amountMinor' => 4_533, 'currency' => 'USD']],
                ['rank' => 4, 'price' => ['amountMinor' => 4_000, 'currency' => 'USD']],
                ['rank' => 5, 'price' => ['amountMinor' => 3_467, 'currency' => 'USD']],
                ['rank' => 6, 'price' => ['amountMinor' => 2_667, 'currency' => 'USD']],
            ])
            ->where('manualService.pricing.urgentSurcharge', ['amountMinor' => 1_067, 'currency' => 'USD']));

    $this->withSession(['display_currency' => 'USD'])
        ->get('/rivals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manualService.pricing.currency', 'USD')
            ->where('manualService.pricing.stepOptions.0.price', ['amountMinor' => 2_933, 'currency' => 'USD'])
            ->where('manualService.pricing.stepOptions.6.price', ['amountMinor' => 4_533, 'currency' => 'USD']));
});

it('fails manual-service pricing closed when no fresh foreign display rate exists', function () {
    Http::preventStrayRequests();

    $this->withSession(['display_currency' => 'USD'])
        ->get('/fut-champions')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manualService.active', true)
            ->where('manualService.pricing', null));

    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'EUR',
        'rate' => '0.25000000',
        'source' => 'exchange-rate-api-open-access',
        'fetched_at' => now()->subHours(30),
    ]);

    $this->withSession(['display_currency' => 'EUR'])
        ->get('/rivals')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('manualService.active', true)
            ->where('manualService.pricing', null));
});

it('keeps manual-service checkout authority in SAR while displaying converted prices', function () {
    ExchangeRate::create([
        'base_currency' => 'SAR',
        'quote_currency' => 'USD',
        'rate' => '0.26666667',
        'source' => 'exchange-rate-api-open-access',
        'fetched_at' => now(),
    ]);

    $response = $this->withSession(['display_currency' => 'USD'])
        ->get('/en/rivals');

    $response->assertOk();

    expect(config('store.checkout_currency'))->toBe('SAR');
});
