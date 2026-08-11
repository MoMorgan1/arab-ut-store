<?php

use App\Models\ExchangeRate;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

function openAccessPayload(array $rates, string $baseCode = 'SAR'): string
{
    $tokens = [];

    foreach ($rates as $currency => $rate) {
        $tokens[] = sprintf('"%s":%s', $currency, $rate);
    }

    return sprintf(
        '{"result":"success","provider":"https://www.exchangerate-api.com","base_code":"%s","rates":{%s}}',
        $baseCode,
        implode(',', $tokens),
    );
}

test('the refresh command atomically stores every configured foreign display rate', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://open.er-api.com/v6/latest/SAR' => Http::response(
            openAccessPayload([
                'SAR' => '1',
                'USD' => '0.266666666',
                'EUR' => '0.228123455',
                'GBP' => '0.196',
            ]),
            200,
            ['Content-Type' => 'application/json'],
        ),
    ]);

    $this->artisan('currency:refresh-display-rates')->assertSuccessful();

    expect(ExchangeRate::query()->orderBy('quote_currency')->get()->mapWithKeys(
        fn (ExchangeRate $rate): array => [$rate->quote_currency => $rate->rate],
    )->all())->toBe([
        'EUR' => '0.22812346',
        'GBP' => '0.19600000',
        'USD' => '0.26666667',
    ]);
});

test('an incomplete provider response leaves every prior rate unchanged', function () {
    foreach (['USD' => '0.26000000', 'EUR' => '0.22000000', 'GBP' => '0.19000000'] as $currency => $rate) {
        ExchangeRate::create([
            'base_currency' => 'SAR',
            'quote_currency' => $currency,
            'rate' => $rate,
            'source' => 'previous',
            'fetched_at' => now()->subDay(),
        ]);
    }

    Http::fake([
        'https://open.er-api.com/v6/latest/SAR' => Http::response(
            openAccessPayload(['SAR' => '1', 'USD' => '0.27', 'EUR' => '0.23']),
        ),
    ]);

    $this->artisan('currency:refresh-display-rates')->assertFailed();

    expect(ExchangeRate::query()->pluck('source')->unique()->all())->toBe(['previous']);
});

test('duplicate configured currency keys leave every prior rate unchanged', function () {
    foreach (['USD' => '0.26000000', 'EUR' => '0.22000000', 'GBP' => '0.19000000'] as $currency => $rate) {
        ExchangeRate::create([
            'base_currency' => 'SAR',
            'quote_currency' => $currency,
            'rate' => $rate,
            'source' => 'previous',
            'fetched_at' => now()->subDay(),
        ]);
    }

    Http::fake([
        'https://open.er-api.com/v6/latest/SAR' => Http::response(
            '{"result":"success","base_code":"SAR","rates":{"SAR":1,"USD":0.26,"USD":0.27,"EUR":0.23,"GBP":0.20}}',
        ),
    ]);

    $this->artisan('currency:refresh-display-rates')->assertFailed();

    expect(ExchangeRate::query()->orderBy('quote_currency')->get()->mapWithKeys(
        fn (ExchangeRate $rate): array => [$rate->quote_currency => [$rate->rate, $rate->source]],
    )->all())->toBe([
        'EUR' => ['0.22000000', 'previous'],
        'GBP' => ['0.19000000', 'previous'],
        'USD' => ['0.26000000', 'previous'],
    ]);
});

test('provider transport and contract failures return failure without writing rates', function (
    int $status,
    string $payload,
) {
    Http::fake([
        'https://open.er-api.com/v6/latest/SAR' => Http::response($payload, $status),
    ]);

    $this->artisan('currency:refresh-display-rates')->assertFailed();

    expect(ExchangeRate::query()->count())->toBe(0);
})->with([
    'HTTP failure' => [503, '{"result":"error","error-type":"unavailable"}'],
    'provider error' => [200, '{"result":"error","error-type":"unknown-code"}'],
    'wrong base' => [200, openAccessPayload(['USD' => '1', 'EUR' => '0.9', 'GBP' => '0.8'], 'USD')],
    'malformed decimal' => [200, openAccessPayload(['SAR' => '1', 'USD' => '2e-1', 'EUR' => '0.2', 'GBP' => '0.1'])],
]);

test('the display rate refresh command is registered on one daily schedule', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'currency:refresh-display-rates'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 0 * * *')
        ->and($events->first()->command)->toContain('currency:refresh-display-rates');
});
