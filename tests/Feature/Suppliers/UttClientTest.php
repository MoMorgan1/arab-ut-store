<?php

use App\Enums\OrderStatus;
use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\SupplierGuard;
use App\Suppliers\Translation\SupplierStateTranslator;
use App\Suppliers\UttClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function uttUnavailable(callable $call): ?SupplierUnavailable
{
    try {
        $call();
    } catch (SupplierUnavailable $exception) {
        return $exception;
    }

    return null;
}

beforeEach(function (): void {
    config()->set('services.suppliers.utt.base_url', 'https://utt.example.test/api');
    config()->set('services.suppliers.utt.api_key', 'utt-key');
    Http::preventStrayRequests();
});

test('observe reads the order and maps it into the fft payload shape', function () {
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['order' => [
            'idOrder' => 'utt-42',
            'statusOrder' => 'COMPLETED',
            'amountProcessed' => 5000,
            'amountTotal' => 2500,
            'platform' => 'ps5',
            'endCoins' => 100,
        ]]),
    ]);

    $observation = app(UttClient::class)->observe('utt-42');

    expect($observation->supplier)->toBe(Supplier::Utt)
        ->and($observation->supplierOrderId)->toBe('utt-42')
        ->and($observation->payload['status'])->toBe('finished')
        ->and($observation->payload['amount'])->toBe(5)
        ->and($observation->payload['amountOrdered'])->toBe(2.5)
        ->and($observation->payload['platform'])->toBe('PS5')
        ->and($observation->payload['coinsCustomerAccount'])->toBe(100)
        ->and($observation->payload['_supplier'])->toBe('UTT')
        ->and($observation->payload['_uttStatusOrder'])->toBe('COMPLETED')
        ->and($observation->payload['_uttIdOrder'])->toBe('utt-42');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://utt.example.test/api/getOrder'
        && $request->data()['apiKey'] === 'utt-key'
        && $request->data()['idOrder'] === 'utt-42');
});

test('observe translates utt interruption states into fft equivalents', function (string $statusOrder, string $expectedStatus, string $expectedAccountCheck, string $expectedEconomyState) {
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['order' => ['statusOrder' => $statusOrder]]),
    ]);

    $payload = app(UttClient::class)->observe('utt-42')->payload;

    expect($payload['status'])->toBe($expectedStatus)
        ->and($payload['accountCheck'])->toBe($expectedAccountCheck)
        ->and($payload['economyState'])->toBe($expectedEconomyState);
})->with([
    'wrong backup code' => ['BACKUP CODE WRONG', 'interrupted', 'wrongBA', ''],
    'ready to resume' => ['READY TO RESUME', 'stopped', '', 'deactivated'],
    'transferring' => ['TRANSFERRING', 'entered', '', 'transfersInProgress'],
    'logging' => ['LOGGING', 'entered', 'entered', ''],
    'system softban' => ['BUYING SOFTBAN', 'entered', '', 'tempbanCooldown'],
    'bad proxy' => ['BAD PROXY', 'interrupted', '', 'FailedProxyConnectionError'],
    'unknown status passes through, not spun' => ['SOMETHING NEW', 'SOMETHING NEW', '', ''],
]);

test('an unknown utt status fails closed through the translator instead of spinning', function () {
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['order' => [
            'idOrder' => 'utt-42',
            'statusOrder' => 'SOMETHING NEW',
        ]]),
    ]);

    $observation = app(UttClient::class)->observe('utt-42');

    $translated = (new SupplierStateTranslator)->translate(
        $observation,
        OrderStatus::WaitingForCustomer,
        null,
    );

    expect($observation->payload['status'])->toBe('SOMETHING NEW')
        ->and($observation->payload['_uttStatusOrder'])->toBe('SOMETHING NEW')
        ->and($translated->supported)->toBeFalse()
        ->and($translated->status)->toBe(OrderStatus::WaitingForCustomer)
        ->and($translated->holdReason)->toBeNull()
        ->and($translated->allowedActions)->toBe([])
        ->and($translated->observedState)->toBe('something new');
});

test('observe rejects the order not found trap without counting it against the circuit', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 1);
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['error' => 'Order not found']),
    ]);

    $failure = uttUnavailable(fn () => app(UttClient::class)->observe('utt-42'));

    expect($failure)->not->toBeNull()
        ->and($failure->reason)->toBe('order_missing')
        ->and($failure->httpStatus)->toBe(200)
        ->and(app(SupplierGuard::class)->availableAt(Supplier::Utt))
        ->toBeNull();
});

test('a supplier fault in a JSON error body counts against the circuit', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 1);
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['error' => 'Invalid API key']),
    ]);

    expect(uttUnavailable(fn () => app(UttClient::class)->observe('utt-42'))?->reason)
        ->toBe('order_missing')
        ->and(app(SupplierGuard::class)->availableAt(Supplier::Utt))
        ->not->toBeNull();
});

test('a supplier fault in an action error body counts against the circuit', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 1);
    Http::fake([
        'https://utt.example.test/api/startOrder' => Http::response(['error' => 'Site under maintenance']),
    ]);

    $result = app(UttClient::class)->resume('utt-42');

    expect($result->accepted)->toBeFalse()
        ->and(app(SupplierGuard::class)->availableAt(Supplier::Utt))
        ->not->toBeNull();
});

test('an html 2xx body is unavailable, not accepted, and counts against the circuit', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 1);
    Http::fake([
        'https://utt.example.test/api/startOrder' => Http::response('<html>Bad gateway</html>', 200),
    ]);

    expect(uttUnavailable(fn () => app(UttClient::class)->resume('utt-42'))?->reason)
        ->toBe('invalid_body')
        ->and(app(SupplierGuard::class)->availableAt(Supplier::Utt))
        ->not->toBeNull();
});

test('a plain text 2xx action body still counts as accepted', function () {
    Http::fake([
        'https://utt.example.test/api/startOrder' => Http::response('OK', 200, ['Content-Type' => 'text/plain']),
    ]);

    $result = app(UttClient::class)->resume('utt-42');

    expect($result->accepted)->toBeTrue()
        ->and($result->payload)->toBe([]);
});

test('a connection failure becomes an unavailable exception', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(uttUnavailable(fn () => app(UttClient::class)->observe('utt-42'))?->reason)
        ->toBe('connection');
});

test('five consecutive failures open the circuit and the sixth call fails fast', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 5);
    Http::fake(['https://utt.example.test/api/getOrder' => Http::response('down', 500)]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(uttUnavailable(fn () => app(UttClient::class)->observe('utt-42'))?->reason)
            ->toBe('http_status');
    }

    expect(uttUnavailable(fn () => app(UttClient::class)->observe('utt-42'))?->reason)
        ->toBe('circuit_open');

    Http::assertSentCount(5);
});

test('correct credentials carries the current order forward for omitted fields', function () {
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['order' => [
            'emailAccount' => 'old@example.com',
            'passwordAccount' => 'old-pass',
            'amountTotal' => 12500,
            'platform' => 'PS4',
            'publicSaleStocks' => 'available',
            'nameAccount' => 'Player One',
            'backupCodes' => 'old-code',
        ]]),
        'https://utt.example.test/api/editOrderPublic' => Http::response(['success' => true]),
    ]);

    $result = app(UttClient::class)->correctCredentials('utt-42', [
        'user' => 'new@example.com',
        'platform' => 'XBOX',
        'ba' => 'code-1',
        'ba3' => 'code-3',
    ]);

    expect($result->accepted)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://utt.example.test/api/getOrder');

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== 'https://utt.example.test/api/editOrderPublic') {
            return false;
        }

        $data = $request->data();

        return $data['apiKey'] === 'utt-key'
            && $data['idOrder'] === 'utt-42'
            && $data['email'] === 'new@example.com'
            && $data['password'] === 'old-pass'
            && (int) $data['amountOrder'] === 12500
            && $data['platform'] === 'xbox'
            && $data['publicSale'] === 'available'
            && $data['name'] === 'Player One'
            && $data['backupCodes'] === 'code-1,code-3';
    });
});

test('an edit error body is not accepted', function () {
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['order' => []]),
        'https://utt.example.test/api/editOrderPublic' => Http::response(['error' => 'nope']),
    ]);

    $result = app(UttClient::class)->correctCredentials('utt-42', ['user' => 'new@example.com']);

    expect($result->accepted)->toBeFalse()
        ->and($result->code)->toBeNull();
});

test('a failed edit never leaks the submitted password', function () {
    Http::fake([
        'https://utt.example.test/api/getOrder' => Http::response(['order' => []]),
        'https://utt.example.test/api/editOrderPublic' => Http::response('down', 500),
    ]);

    $failure = uttUnavailable(fn () => app(UttClient::class)->correctCredentials('utt-42', [
        'user' => 'new@example.com',
        'pass' => 'secret-pass',
    ]));

    expect($failure)->not->toBeNull()
        ->and($failure->getMessage())->not->toContain('secret-pass')
        ->and((string) $failure)->not->toContain('secret-pass');
});

test('resume posts to start order', function () {
    Http::fake([
        'https://utt.example.test/api/startOrder' => Http::response(['success' => true]),
    ]);

    $result = app(UttClient::class)->resume('utt-42');

    expect($result->accepted)->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://utt.example.test/api/startOrder'
        && $request->data()['idOrder'] === 'utt-42'
        && $request->data()['apiKey'] === 'utt-key');
});

test('retry challenge is not supported', function () {
    Http::fake();

    expect(fn () => app(UttClient::class)->retryChallenge('utt-42', 'SBC-abc'))
        ->toThrow(LogicException::class);

    Http::assertNothingSent();
});

test('missing or unsafe configuration fails closed before the network', function (string $field, mixed $value) {
    config()->set("services.suppliers.utt.{$field}", $value);
    Http::fake();

    expect(fn () => app(UttClient::class)->observe('utt-42'))
        ->toThrow(SupplierNotConfigured::class);

    Http::assertNothingSent();
})->with([
    'missing base url' => ['base_url', null],
    'insecure base url' => ['base_url', 'http://utt.example.test/api'],
    'base url with credentials' => ['base_url', 'https://user:pass@utt.example.test/api'],
    'blank api key' => ['api_key', ''],
]);
