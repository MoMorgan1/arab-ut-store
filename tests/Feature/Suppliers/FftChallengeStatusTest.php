<?php

use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierUnavailable;
use App\Suppliers\FftClient;
use App\Suppliers\UttClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function fftChallengeUnavailable(callable $call): ?SupplierUnavailable
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

test('observeChallenges posts to sbcStatusBulkAPI with bare lowercase ids', function () {
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([
            '1803b7a6-0000-0000-0000-00000064265f' => [
                'sbcStatus' => 'finished',
            ],
        ]),
    ]);

    $result = app(FftClient::class)->observeChallenges(['SBC-1803B7A6-0000-0000-0000-00000064265F']);

    expect($result)->toBeArray();

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://fft.example.test/sbcStatusBulkAPI'
            && $request->method() === 'POST'
            && $data['sbcIDs'] === ['1803b7a6-0000-0000-0000-00000064265f']
            && $data['apiUser'] === 'store-fft'
            && $data['apiKey'] === 'fft-key';
    });
});

test('observeChallenges parses realistic response into map keyed by id with both counter pairs', function () {
    $realisticPayload = [
        '1803b7a6-0000-0000-0000-00000064265f' => [
            'challengesDone' => 7,
            'totalChallenges' => 7,
            'challengesSubmitted' => 14,
            'timesSolved' => 2,
            'timesToSolve' => 2,
            'sbcStatus' => 'finished',
            'costCoins' => 546650,
            'setId' => 702,
            'sbcSolveID' => '1803b7a6-0000-0000-0000-00000064265f',
            'account' => 'customer@example.com',
            'created' => '2026-04-02 17:56:00',
            'cached' => 0,
        ],
    ];

    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response($realisticPayload),
    ]);

    $result = app(FftClient::class)->observeChallenges(['1803b7a6-0000-0000-0000-00000064265f']);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('1803b7a6-0000-0000-0000-00000064265f')
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['challengesDone'])->toBe(7)
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['totalChallenges'])->toBe(7)
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['timesSolved'])->toBe(2)
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['timesToSolve'])->toBe(2)
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['challengesSubmitted'])->toBe(14)
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['sbcStatus'])->toBe('finished')
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['costCoins'])->toBe(546650)
        ->and($result['1803b7a6-0000-0000-0000-00000064265f']['setId'])->toBe(702);
});

test('a 404 with plain-text notFound body throws supplier unavailable with http_status reason', function () {
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response('notFound', 404, ['Content-Type' => 'text/plain']),
    ]);

    $failure = fftChallengeUnavailable(fn () => app(FftClient::class)->observeChallenges(['1803b7a6-0000-0000-0000-00000064265f']));

    expect($failure)->not->toBeNull()
        ->and($failure->supplier)->toBe(Supplier::Fft)
        ->and($failure->httpStatus)->toBe(404)
        ->and($failure->reason)->toBe('http_status');
});

test('a connection failure throws supplier unavailable with connection reason', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    $failure = fftChallengeUnavailable(fn () => app(FftClient::class)->observeChallenges(['1803b7a6-0000-0000-0000-00000064265f']));

    expect($failure)->not->toBeNull()
        ->and($failure->supplier)->toBe(Supplier::Fft)
        ->and($failure->reason)->toBe('connection');
});

test('a non-array 200 body throws supplier unavailable with invalid_body reason', function () {
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response('OK', 200, ['Content-Type' => 'text/plain']),
    ]);

    $failure = fftChallengeUnavailable(fn () => app(FftClient::class)->observeChallenges(['1803b7a6-0000-0000-0000-00000064265f']));

    expect($failure)->not->toBeNull()
        ->and($failure->supplier)->toBe(Supplier::Fft)
        ->and($failure->reason)->toBe('invalid_body');
});

test('an id absent from the response yields a map without that key and does not throw', function () {
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response([
            '1803b7a6-0000-0000-0000-000000000001' => [
                'sbcStatus' => 'finished',
            ],
        ]),
    ]);

    $result = app(FftClient::class)->observeChallenges([
        '1803b7a6-0000-0000-0000-000000000001',
        '1803b7a6-0000-0000-0000-000000000002',
    ]);

    expect($result)->toHaveKey('1803b7a6-0000-0000-0000-000000000001')
        ->and($result)->not->toHaveKey('1803b7a6-0000-0000-0000-000000000002');
});

test('five consecutive failures open the circuit through observeChallenges', function () {
    config()->set('services.suppliers.circuit_failure_threshold', 5);
    Http::fake([
        'https://fft.example.test/sbcStatusBulkAPI' => Http::response('down', 500),
    ]);

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $failure = fftChallengeUnavailable(fn () => app(FftClient::class)->observeChallenges(['1803b7a6-0000-0000-0000-00000064265f']));
        expect($failure?->reason)->toBe('http_status');
    }

    $circuitFailure = fftChallengeUnavailable(fn () => app(FftClient::class)->observeChallenges(['1803b7a6-0000-0000-0000-00000064265f']));
    expect($circuitFailure?->reason)->toBe('circuit_open');

    Http::assertSentCount(5);
});

test('UttClient observeChallenges throws LogicException', function () {
    expect(fn () => app(UttClient::class)->observeChallenges(['1803b7a6-0000-0000-0000-00000064265f']))
        ->toThrow(LogicException::class);
});

test('observeChallenges throws LogicException when normalised id list is empty', function () {
    expect(fn () => app(FftClient::class)->observeChallenges([]))
        ->toThrow(LogicException::class);

    expect(fn () => app(FftClient::class)->observeChallenges(['invalid-id', 'SBC-']))
        ->toThrow(LogicException::class);

    Http::assertNothingSent();
});
