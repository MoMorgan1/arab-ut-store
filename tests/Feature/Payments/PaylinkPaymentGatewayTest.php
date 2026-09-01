<?php

use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Payments\PaymentInvoiceRequest;
use App\Services\Payments\PaylinkPaymentGateway;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.paylink', [
        'environment' => 'test',
        'api_id' => 'APP_ID_TEST_ONLY',
        'secret_key' => 'test-secret-key',
        'webhook_token' => 'test-webhook-token',
        'partner_profile_no' => '19039481',
        'partner_api_key' => 'test-partner-api-key',
        'merchant_lookup_key' => 'accountNo',
        'merchant_lookup_value' => '123456',
    ]);

    Cache::flush();
    Http::preventStrayRequests();
});

test('it creates a hosted Paylink invoice with exact SAR and digital product data', function () {
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => Http::response([
            'id_token' => 'merchant-token',
        ]),
        'https://restpilot.paylink.sa/api/addInvoice' => Http::response(paylinkInvoiceFixture()),
    ]);

    $invoice = app(PaylinkPaymentGateway::class)->createInvoice(paylinkInvoiceRequest());

    expect($invoice->transactionNo)->toBe('1716194603030')
        ->and($invoice->orderNumber)->toBe('AUT-01HXYZ')
        ->and($invoice->amountHalalah)->toBe(12500)
        ->and($invoice->currency)->toBe('SAR')
        ->and($invoice->status)->toBe('pending')
        ->and($invoice->paymentUrl)->toBe('https://payment.paylink.sa/pay/info/1716194603030')
        ->and($invoice->paymentMethod)->toBeNull();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://restpilot.paylink.sa/api/auth'
        && $request->method() === 'POST'
        && $request->data() === [
            'apiId' => 'APP_ID_TEST_ONLY',
            'secretKey' => 'test-secret-key',
            'persistToken' => true,
        ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://restpilot.paylink.sa/api/addInvoice'
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer merchant-token')
        && $request['orderNumber'] === 'AUT-01HXYZ'
        && $request['amount'] === 125
        && $request['currency'] === 'SAR'
        && $request['clientMobile'] === '+966501234567'
        && $request['products'] === [[
            'title' => 'SBC Sithole',
            'price' => 125,
            'qty' => 1,
            'description' => 'Arab UT digital service',
            'isDigital' => true,
        ]]
        && ! array_key_exists('supportedCardBrands', $request->data())
        && ! str_contains(json_encode($request->data(), JSON_THROW_ON_ERROR), 'ea-password'));
});

test('it caches the merchant token and maps a paid invoice from server verification', function () {
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'merchant-token']),
        'https://restpilot.paylink.sa/api/getInvoice/*' => Http::response(paylinkInvoiceFixture([
            'orderStatus' => 'Paid',
            'paymentReceipt' => [
                'receiptUrl' => 'https://payment.paylink.sa/receipt/1716194603030',
                'passcode' => 'not-persisted',
                'paymentMethod' => 'MADA',
                'paymentDate' => '2026-08-14T05:00:00Z',
                'bankCardNumber' => '1234',
            ],
        ])),
    ]);

    $gateway = app(PaylinkPaymentGateway::class);
    $first = $gateway->getInvoice('1716194603030');
    $second = $gateway->getInvoice('1716194603030');

    expect($first->status)->toBe('paid')
        ->and($first->paymentMethod)->toBe('MADA')
        ->and($second)->toEqual($first);

    Http::assertSentCount(3);
    expect(Http::recorded(fn (Request $request): bool => $request->url() === 'https://restpilot.paylink.sa/api/auth'))
        ->toHaveCount(1);
});

test('it cancels a pending invoice through the documented endpoint', function () {
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'merchant-token']),
        'https://restpilot.paylink.sa/api/cancelInvoice' => Http::response(['success' => true]),
    ]);

    app(PaylinkPaymentGateway::class)->cancelInvoice('1716194603030');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://restpilot.paylink.sa/api/cancelInvoice'
        && $request->method() === 'POST'
        && $request->data() === ['transactionNo' => '1716194603030']);
});

test('it refunds through the separately authenticated Paylink partner boundary', function () {
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response([
            'id' => 237,
            'orderNumber' => 'AUT-01HXYZ',
            'amount' => 125.0,
            'currency' => 'SAR',
            'refundReason' => 'Customer request.',
            'createDatetime' => 1786683600000,
        ]),
    ]);

    $refund = app(PaylinkPaymentGateway::class)->refund('AUT-01HXYZ', 'Customer request.');

    expect($refund->providerRefundId)->toBe('237')
        ->and($refund->orderNumber)->toBe('AUT-01HXYZ')
        ->and($refund->amountHalalah)->toBe(12500)
        ->and($refund->currency)->toBe('SAR');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://restpilot.paylink.sa/api/partner/auth'
        && $request->data() === [
            'profileNo' => '19039481',
            'apiKey' => 'test-partner-api-key',
            'persistToken' => true,
        ]);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund'
        && $request->hasHeader('Authorization', 'Bearer partner-token')
        && $request->data() === [
            'orderNumber' => 'AUT-01HXYZ',
            'refundReason' => 'Customer request.',
        ]);
});

test('missing merchant configuration fails closed before any network request', function (string $field) {
    config()->set("services.paylink.{$field}", null);
    Http::fake();

    expect(fn () => app(PaylinkPaymentGateway::class)->getInvoice('1716194603030'))
        ->toThrow(PaymentConfigurationException::class, 'Paylink merchant payment is not configured.');

    Http::assertNothingSent();
})->with(['api_id', 'secret_key']);

test('missing partner configuration fails refunds closed without affecting merchant payments', function (string $field) {
    config()->set("services.paylink.{$field}", null);
    Http::fake();

    expect(fn () => app(PaylinkPaymentGateway::class)->refund('AUT-01HXYZ', 'Customer request.'))
        ->toThrow(PaymentConfigurationException::class, 'Paylink refunds are not configured.');

    Http::assertNothingSent();
})->with(['partner_profile_no', 'partner_api_key', 'merchant_lookup_value']);

test('invalid environments and unsafe identifiers fail before a request is sent', function (callable $operation) {
    Http::fake();

    expect(fn () => $operation(app(PaylinkPaymentGateway::class)))
        ->toThrow(PaymentConfigurationException::class);

    Http::assertNothingSent();
})->with([
    'invalid environment' => [function (PaylinkPaymentGateway $gateway): void {
        config()->set('services.paylink.environment', 'staging');
        $gateway->getInvoice('1716194603030');
    }],
    'unsafe merchant lookup key' => [function (PaylinkPaymentGateway $gateway): void {
        config()->set('services.paylink.merchant_lookup_key', '../email');
        $gateway->refund('AUT-01HXYZ', 'Customer request.');
    }],
]);

test('malformed or unsuccessful provider responses are rejected with a sanitized error', function (string $endpoint, array $response, callable $operation) {
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'merchant-token']),
        "https://restpilot.paylink.sa/{$endpoint}" => Http::response($response),
    ]);

    expect(fn () => $operation(app(PaylinkPaymentGateway::class)))
        ->toThrow(PaymentGatewayException::class, 'Paylink returned an invalid response.');
})->with([
    'missing transaction' => ['api/addInvoice', paylinkInvoiceFixture(['transactionNo' => null]), fn (PaylinkPaymentGateway $gateway) => $gateway->createInvoice(paylinkInvoiceRequest())],
    'unsafe payment URL' => ['api/addInvoice', paylinkInvoiceFixture(['url' => 'https://example.test/collect']), fn (PaylinkPaymentGateway $gateway) => $gateway->createInvoice(paylinkInvoiceRequest())],
    'unsupported status' => ['api/getInvoice/*', paylinkInvoiceFixture(['orderStatus' => 'REFUNDED']), fn (PaylinkPaymentGateway $gateway) => $gateway->getInvoice('1716194603030')],
    'cancel unsuccessful' => ['api/cancelInvoice', ['success' => false], fn (PaylinkPaymentGateway $gateway) => $gateway->cancelInvoice('1716194603030')],
]);

test('authentication and transport failures never expose provider details or credentials', function (string $failure) {
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => $failure === 'connection'
            ? Http::failedConnection('provider-secret-detail')
            : Http::response(['detail' => 'provider-secret-detail'], 503),
    ]);

    try {
        app(PaylinkPaymentGateway::class)->getInvoice('1716194603030');
        $this->fail('Expected Paylink to fail closed.');
    } catch (PaymentGatewayException $exception) {
        expect($exception->getMessage())->toBe('Paylink is temporarily unavailable.')
            ->and($exception->getMessage())->not->toContain('test-secret-key')
            ->and($exception->getMessage())->not->toContain('provider-secret-detail');
    }
})->with([
    'upstream error' => ['response'],
    'connection failure' => ['connection'],
]);

test('invoice creation is never retried after an ambiguous upstream failure', function () {
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'merchant-token']),
        'https://restpilot.paylink.sa/api/addInvoice' => Http::response(['detail' => 'temporary'], 503),
    ]);

    expect(fn () => app(PaylinkPaymentGateway::class)->createInvoice(paylinkInvoiceRequest()))
        ->toThrow(PaymentGatewayException::class, 'Paylink is temporarily unavailable.');

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/addInvoice')))
        ->toHaveCount(1);
});

test('refund creation is never retried after an ambiguous upstream failure', function () {
    Http::fake([
        'https://restpilot.paylink.sa/api/partner/auth' => Http::response(['id_token' => 'partner-token']),
        'https://restpilot.paylink.sa/rest/partner/v2/merchant/accountNo/123456/refund' => Http::response(['detail' => 'temporary'], 503),
    ]);

    expect(fn () => app(PaylinkPaymentGateway::class)->refund('AUT-01HXYZ', 'Customer request.'))
        ->toThrow(PaymentGatewayException::class, 'Paylink is temporarily unavailable.');

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/refund')))
        ->toHaveCount(1);
});

test('a 401 or 403 unauthorized response clears the cached token and retries once with a fresh token', function (int $statusCode) {
    $tokenCalls = 0;
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => function () use (&$tokenCalls) {
            $tokenCalls++;

            return Http::response(['id_token' => "merchant-token-{$tokenCalls}"]);
        },
        'https://restpilot.paylink.sa/api/getInvoice/*' => Http::sequence()
            ->push(['error' => 'Unauthorized'], $statusCode)
            ->push(paylinkInvoiceFixture()),
    ]);

    $invoice = app(PaylinkPaymentGateway::class)->getInvoice('1716194603030');

    expect($invoice->transactionNo)->toBe('1716194603030')
        ->and($tokenCalls)->toBe(2);

    $authRequests = Http::recorded(fn (Request $request): bool => $request->url() === 'https://restpilot.paylink.sa/api/auth');
    expect($authRequests)->toHaveCount(2);

    $getInvoiceRequests = Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/getInvoice/'))->values();
    expect($getInvoiceRequests)->toHaveCount(2)
        ->and($getInvoiceRequests->get(0)[0]->hasHeader('Authorization', 'Bearer merchant-token-1'))->toBeTrue()
        ->and($getInvoiceRequests->get(1)[0]->hasHeader('Authorization', 'Bearer merchant-token-2'))->toBeTrue();
})->with([401, 403]);

test('consecutive 401 unauthorized responses evict the cached token and fail closed', function () {
    Http::fake([
        'https://restpilot.paylink.sa/api/auth' => Http::response(['id_token' => 'invalid-token']),
        'https://restpilot.paylink.sa/api/getInvoice/*' => Http::response(['error' => 'Unauthorized'], 401),
    ]);

    expect(fn () => app(PaylinkPaymentGateway::class)->getInvoice('1716194603030'))
        ->toThrow(PaymentGatewayException::class, 'Paylink is temporarily unavailable.');

    $cacheKey = 'paylink:token:test:merchant:'.hash('sha256', 'APP_ID_TEST_ONLY');
    expect(Cache::has($cacheKey))->toBeFalse();
});

test('payments:clear-paylink-tokens artisan command clears cached merchant and partner tokens', function () {
    $merchantKey = 'paylink:token:test:merchant:'.hash('sha256', 'APP_ID_TEST_ONLY');
    $partnerKey = 'paylink:token:test:partner:'.hash('sha256', '19039481');

    Cache::put($merchantKey, 'cached-merchant-token', 1000);
    Cache::put($partnerKey, 'cached-partner-token', 1000);

    expect(Cache::has($merchantKey))->toBeTrue()
        ->and(Cache::has($partnerKey))->toBeTrue();

    $this->artisan('payments:clear-paylink-tokens')
        ->expectsOutput('Paylink authentication token cache cleared.')
        ->assertSuccessful();

    expect(Cache::has($merchantKey))->toBeFalse()
        ->and(Cache::has($partnerKey))->toBeFalse();
});

function paylinkInvoiceRequest(): PaymentInvoiceRequest
{
    return new PaymentInvoiceRequest(
        orderNumber: 'AUT-01HXYZ',
        amountHalalah: 12500,
        callbackUrl: 'https://store.arab-ut.com/payments/paylink/callback',
        cancelUrl: 'https://store.arab-ut.com/payments/paylink/cancel',
        clientName: 'Mohamed Ali',
        clientEmail: 'mohamed@example.com',
        clientMobile: '+966501234567',
        products: [[
            'title' => 'SBC Sithole',
            'priceHalalah' => 12500,
            'quantity' => 1,
            'description' => 'Arab UT digital service',
        ]],
    );
}

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function paylinkInvoiceFixture(array $overrides = []): array
{
    return array_replace([
        'gatewayOrderRequest' => [
            'amount' => 125.0,
            'orderNumber' => 'AUT-01HXYZ',
            'callBackUrl' => 'https://store.arab-ut.com/payments/paylink/callback',
            'clientEmail' => 'mohamed@example.com',
            'clientName' => 'Mohamed Ali',
            'clientMobile' => '+966501234567',
            'cancelUrl' => 'https://store.arab-ut.com/payments/paylink/cancel',
            'products' => [],
            'currency' => 'SAR',
        ],
        'amount' => 125.0,
        'transactionNo' => '1716194603030',
        'orderStatus' => 'PENDING',
        'paymentErrors' => null,
        'url' => 'https://payment.paylink.sa/pay/info/1716194603030',
        'qrUrl' => 'https://restpilot.paylink.sa/openApi/loadOrderQRCode/1716194603030',
        'mobileUrl' => 'https://payment.paylink.sa/pay/frame/1716194603030',
        'checkUrl' => 'https://restpilot.paylink.sa/api/getInvoice/1716194603030',
        'success' => true,
        'digitalOrder' => true,
        'foreignCurrencyRate' => null,
        'paymentReceipt' => null,
        'metadata' => null,
    ], $overrides);
}
