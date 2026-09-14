<?php

use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\FftClient;
use App\Suppliers\SupplierCallProfile;
use App\Suppliers\SupplierGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fftUnavailable(callable $call): ?SupplierUnavailable
{
    try {
        $call();
    } catch (SupplierUnavailable $exception) {
        return $exception;
    }

    return null;
}

beforeEach(function (): void {
    config()->set('services.suppliers.fft.base_url', 'https://fft.example.test');
    config()->set('services.suppliers.fft.api_user', 'store-fft');
    config()->set('services.suppliers.fft.api_key', 'fft-key');
    Http::preventStrayRequests();
});

test('observe posts the tracker payload and flags external order ids', function () {
    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response(['status' => 'entered']),
    ]);

    $observation = app(FftClient::class)->observe('0f8fad5b-d9cb-469f-a165-70867728950e');

    expect($observation->supplier)->toBe(Supplier::Fft)
        ->and($observation->supplierOrderId)->toBe('0f8fad5b-d9cb-469f-a165-70867728950e')
        ->and($observation->payload)->toBe(['status' => 'entered']);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://fft.example.test/orderStatusAPI'
            && $request->method() === 'POST'
            && $data['orderID'] === '0f8fad5b-d9cb-469f-a165-70867728950e'
            && $data['apiUser'] === 'store-fft'
            && $data['apiKey'] === 'fft-key'
            && $data['externalID'] === 1
            && $data['isMotherID'] === 0;
    });
});

test('observe can address the suppliers own internal id', function () {
    Http::fake(['https://fft.example.test/orderStatusAPI' => Http::response([])]);

    app(FftClient::class)->observe('1234567890', false);

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('externalID', $request->data())
        && ! array_key_exists('isMotherID', $request->data()));
});

test('a non-2xx answer becomes an unavailable exception with the http status', function () {
    Http::fake(['https://fft.example.test/orderStatusAPI' => Http::response('upstream down', 503)]);

    $failure = fftUnavailable(fn () => app(FftClient::class)->observe('1234567890'));

    expect($failure)->not->toBeNull()
        ->and($failure->supplier)->toBe(Supplier::Fft)
        ->and($failure->supplierOrderId)->toBe('1234567890')
        ->and($failure->httpStatus)->toBe(503)
        ->and($failure->reason)->toBe('http_status');
});

test('a connection failure becomes an unavailable exception', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fftUnavailable(fn () => app(FftClient::class)->observe('1234567890'))?->reason)
        ->toBe('connection');
});

test('an invalid observe body is rejected', function () {
    Http::fake([
        'https://fft.example.test/orderStatusAPI' => Http::response('OK', 200, ['Content-Type' => 'text/plain']),
    ]);

    expect(fftUnavailable(fn () => app(FftClient::class)->observe('1234567890'))?->reason)
        ->toBe('invalid_body');
});

test('correct credentials sends only the fields it was given and continues by default', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'ok']),
    ]);

    $result = app(FftClient::class)->correctCredentials('AUT-1001', [
        'user' => 'new@example.com',
        'pass' => 'secret-pass',
        'ba' => 'backup-1',
        'ba3' => 'backup-3',
        'limit' => 8,
    ]);

    expect($result->accepted)->toBeTrue()
        ->and($result->code)->toBe('ok');

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://fft.example.test/correctCredentialsAPI'
            && $data['orderID'] === 'AUT-1001'
            && $data['continue'] === 1
            && $data['externalOrderID'] === 1
            && $data['user'] === 'new@example.com'
            && $data['pass'] === 'secret-pass'
            && $data['ba'] === 'backup-1'
            && $data['ba3'] === 'backup-3'
            && $data['limit'] === 8
            && ! array_key_exists('ba2', $data)
            && ! array_key_exists('platform', $data);
    });
});

test('correct credentials does not flag internal ids as external and honours an explicit continue', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'ok']),
    ]);

    app(FftClient::class)->correctCredentials('0f8fad5b-d9cb-469f-a165-70867728950e', ['continue' => false]);

    Http::assertSent(fn (Request $request): bool => ! array_key_exists('externalOrderID', $request->data())
        && $request->data()['continue'] === 0);
});

test('resume sends just the continue payload', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['success' => true]),
    ]);

    $result = app(FftClient::class)->resume('AUT-1001');

    expect($result->accepted)->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();
        ksort($data);

        return $request->url() === 'https://fft.example.test/correctCredentialsAPI'
            && $data === [
                'apiKey' => 'fft-key',
                'apiUser' => 'store-fft',
                'continue' => 1,
                'externalOrderID' => 1,
                'orderID' => 'AUT-1001',
            ];
    });
});

test('an error body is not accepted even with http 200', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['error' => 'bad credentials']),
    ]);

    $result = app(FftClient::class)->resume('1234567890');

    expect($result->accepted)->toBeFalse()
        ->and($result->code)->toBeNull()
        ->and($result->payload)->toBe(['error' => 'bad credentials']);
});

test('a failed outcome is not accepted', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response(['outcome' => 'FAILED']),
    ]);

    expect(app(FftClient::class)->resume('1234567890')->accepted)->toBeFalse();
});

test('an empty 2xx body counts as accepted', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response('', 200),
    ]);

    $result = app(FftClient::class)->resume('1234567890');

    expect($result->accepted)->toBeTrue()
        ->and($result->payload)->toBe([]);
});

test('a plain text 2xx body counts as accepted', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response('OK', 200, ['Content-Type' => 'text/plain']),
    ]);

    $result = app(FftClient::class)->resume('1234567890');

    expect($result->accepted)->toBeTrue()
        ->and($result->payload)->toBe([]);
});

test('an html 2xx body is unavailable, not accepted, and counts against the circuit', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 1);
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response('<html>Bad gateway</html>', 200),
    ]);

    expect(fftUnavailable(fn () => app(FftClient::class)->resume('1234567890'))?->reason)
        ->toBe('invalid_body')
        ->and(app(SupplierGuard::class)->availableAt(Supplier::Fft))
        ->not->toBeNull();
});

test('five consecutive failures open the circuit and the sixth call fails fast', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 5);
    Http::fake(['https://fft.example.test/orderStatusAPI' => Http::response('down', 500)]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        expect(fftUnavailable(fn () => app(FftClient::class)->observe('AUT-1001'))?->reason)
            ->toBe('http_status');
    }

    expect(fftUnavailable(fn () => app(FftClient::class)->observe('AUT-1001'))?->reason)
        ->toBe('circuit_open');

    Http::assertSentCount(5);
});

test('a failed action never leaks the submitted password', function () {
    Http::fake([
        'https://fft.example.test/correctCredentialsAPI' => Http::response('down', 500),
    ]);

    $failure = fftUnavailable(fn () => app(FftClient::class)->correctCredentials('AUT-1001', [
        'user' => 'new@example.com',
        'pass' => 'secret-pass',
    ]));

    expect($failure)->not->toBeNull()
        ->and($failure->getMessage())->not->toContain('secret-pass')
        ->and((string) $failure)->not->toContain('secret-pass');
});

test('retry challenge strips the sbc prefix and sends a single element array', function () {
    Http::fake(['https://fft.example.test/retrySBCAPI' => Http::response(['success' => true])]);

    $result = app(FftClient::class)->retryChallenge('1234567890', 'SBC-0f8fad5b-d9cb-469f-a165-70867728950e');

    expect($result->accepted)->toBeTrue();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://fft.example.test/retrySBCAPI'
            && $data['sbcSolveID'] === ['0f8fad5b-d9cb-469f-a165-70867728950e']
            && $data['apiUser'] === 'store-fft'
            && $data['apiKey'] === 'fft-key';
    });
});

test('missing or unsafe configuration fails closed before the network', function (string $field, mixed $value) {
    config()->set("services.suppliers.fft.{$field}", $value);
    Http::fake();

    expect(fn () => app(FftClient::class)->observe('1234567890'))
        ->toThrow(SupplierNotConfigured::class);

    Http::assertNothingSent();
})->with([
    'missing base url' => ['base_url', null],
    'insecure base url' => ['base_url', 'http://fft.example.test'],
    'base url with credentials' => ['base_url', 'https://user:pass@fft.example.test'],
    'missing api user' => ['api_user', null],
    'blank api key' => ['api_key', ''],
]);

test('call profiles keep the house timeouts', function () {
    expect(SupplierCallProfile::Polling->connectTimeoutSeconds())->toBe(3)
        ->and(SupplierCallProfile::Polling->timeoutSeconds())->toBe(5)
        ->and(SupplierCallProfile::Action->connectTimeoutSeconds())->toBe(5)
        ->and(SupplierCallProfile::Action->timeoutSeconds())->toBe(12);
});
