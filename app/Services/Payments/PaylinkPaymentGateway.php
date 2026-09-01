<?php

namespace App\Services\Payments;

use App\Contracts\Payments\PaymentGateway;
use App\Exceptions\Payments\PaymentConfigurationException;
use App\Exceptions\Payments\PaymentGatewayException;
use App\Payments\PaymentInvoice;
use App\Payments\PaymentInvoiceRequest;
use App\Payments\RefundResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

final class PaylinkPaymentGateway implements PaymentGateway
{
    private const MERCHANT_TOKEN_TTL_SECONDS = 104400;

    private const ALLOWED_LOOKUP_KEYS = ['cr', 'freelancer', 'mobile', 'email', 'accountNo'];

    public function createInvoice(PaymentInvoiceRequest $request): PaymentInvoice
    {
        $products = array_map(static fn (array $product): array => array_filter([
            'title' => $product['title'],
            'price' => $product['priceHalalah'] / 100,
            'qty' => $product['quantity'],
            'description' => $product['description'] ?? null,
            'isDigital' => true,
        ], static fn (mixed $value): bool => $value !== null), $request->products);

        $response = $this->sendWithMerchantAuth(fn (string $token): Response => $this->request()
            ->withToken($token)
            ->post($this->baseUrl().'/api/addInvoice', array_filter([
                'orderNumber' => $request->orderNumber,
                'amount' => $request->amountHalalah / 100,
                'callBackUrl' => $request->callbackUrl,
                'cancelUrl' => $request->cancelUrl,
                'clientName' => $request->clientName,
                'clientEmail' => $request->clientEmail,
                'clientMobile' => $request->clientMobile,
                'currency' => 'SAR',
                'products' => $products,
                'displayPending' => true,
                'note' => 'Arab UT order '.$request->orderNumber,
            ], static fn (mixed $value): bool => $value !== null)));

        return $this->parseInvoice($this->json($response));
    }

    public function getInvoice(string $transactionNo): PaymentInvoice
    {
        $this->assertIdentifier($transactionNo);
        $response = $this->sendWithMerchantAuth(fn (string $token): Response => $this->safeReadRequest()
            ->withToken($token)
            ->get($this->baseUrl().'/api/getInvoice/'.$transactionNo));

        return $this->parseInvoice($this->json($response));
    }

    public function cancelInvoice(string $transactionNo): void
    {
        $this->assertIdentifier($transactionNo);
        $response = $this->sendWithMerchantAuth(fn (string $token): Response => $this->request()
            ->withToken($token)
            ->post($this->baseUrl().'/api/cancelInvoice', [
                'transactionNo' => $transactionNo,
            ]));
        $body = $this->json($response);

        if (($body['success'] ?? null) !== true) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }
    }

    public function refund(string $orderNumber, string $reason): RefundResult
    {
        $this->assertIdentifier($orderNumber);
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new PaymentGatewayException('The Paylink refund request is invalid.');
        }

        [$profileNo, $apiKey, $lookupKey, $lookupValue] = $this->partnerConfiguration();

        $url = sprintf(
            '%s/rest/partner/v2/merchant/%s/%s/refund',
            $this->baseUrl(),
            rawurlencode($lookupKey),
            rawurlencode($lookupValue),
        );
        $response = $this->sendWithPartnerAuth(fn (string $token): Response => $this->request()
            ->withToken($token)
            ->post($url, [
                'orderNumber' => $orderNumber,
                'refundReason' => $reason,
            ]));
        $body = $this->json($response);

        try {
            return new RefundResult(
                providerRefundId: $this->stringValue($body['id'] ?? null),
                orderNumber: $this->stringValue($body['orderNumber'] ?? null),
                amountHalalah: $this->halalah($body['amount'] ?? null),
                currency: $this->stringValue($body['currency'] ?? null),
                reason: $this->stringValue($body['refundReason'] ?? null),
                createdAtMilliseconds: $this->positiveInteger($body['createDatetime'] ?? null),
            );
        } catch (InvalidArgumentException|PaymentGatewayException) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }
    }

    public function clearTokenCache(): void
    {
        try {
            [$apiId] = $this->merchantConfiguration();
            Cache::forget($this->tokenCacheKey('merchant:'.hash('sha256', $apiId)));
        } catch (PaymentConfigurationException) {
            // Intentionally ignored when merchant configuration is missing.
        }

        try {
            [$profileNo] = $this->partnerConfiguration();
            Cache::forget($this->tokenCacheKey('partner:'.hash('sha256', $profileNo)));
        } catch (PaymentConfigurationException) {
            // Intentionally ignored when partner configuration is missing.
        }
    }

    /** @param callable(string $token): Response $callback */
    private function sendWithMerchantAuth(callable $callback): Response
    {
        [$apiId, $secretKey] = $this->merchantConfiguration();
        $scope = 'merchant:'.hash('sha256', $apiId);
        $credentials = ['apiId' => $apiId, 'secretKey' => $secretKey, 'persistToken' => true];
        $endpoint = '/api/auth';

        $token = $this->token($scope, $endpoint, $credentials);
        $response = $this->send(fn (): Response => $callback($token));

        if ($response->status() === 401 || $response->status() === 403) {
            $freshToken = $this->token($scope, $endpoint, $credentials, forceFresh: true);
            $response = $this->send(fn (): Response => $callback($freshToken));

            if ($response->status() === 401 || $response->status() === 403) {
                Cache::forget($this->tokenCacheKey($scope));
            }
        }

        return $response;
    }

    /** @param callable(string $token): Response $callback */
    private function sendWithPartnerAuth(callable $callback): Response
    {
        [$profileNo, $apiKey] = [$this->partnerConfiguration()[0], $this->partnerConfiguration()[1]];
        $scope = 'partner:'.hash('sha256', $profileNo);
        $credentials = ['profileNo' => $profileNo, 'apiKey' => $apiKey, 'persistToken' => true];
        $endpoint = '/api/partner/auth';

        $token = $this->token($scope, $endpoint, $credentials);
        $response = $this->send(fn (): Response => $callback($token));

        if ($response->status() === 401 || $response->status() === 403) {
            $freshToken = $this->token($scope, $endpoint, $credentials, forceFresh: true);
            $response = $this->send(fn (): Response => $callback($freshToken));

            if ($response->status() === 401 || $response->status() === 403) {
                Cache::forget($this->tokenCacheKey($scope));
            }
        }

        return $response;
    }

    /** @param array<string, string|bool> $credentials */
    private function token(string $scope, string $endpoint, array $credentials, bool $forceFresh = false): string
    {
        $cacheKey = $this->tokenCacheKey($scope);

        if ($forceFresh) {
            Cache::forget($cacheKey);
        }

        return Cache::remember($cacheKey, self::MERCHANT_TOKEN_TTL_SECONDS, function () use ($endpoint, $credentials): string {
            $response = $this->send(fn (): Response => $this->request()->post($this->baseUrl().$endpoint, $credentials));
            $body = $this->json($response);
            $token = $body['id_token'] ?? null;

            if (! is_string($token) || trim($token) === '' || strlen($token) > 8192) {
                throw new PaymentGatewayException('Paylink returned an invalid response.');
            }

            return $token;
        });
    }

    private function tokenCacheKey(string $scope): string
    {
        return 'paylink:token:'.$this->environment().':'.$scope;
    }

    private function request(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(12);
    }

    private function safeReadRequest(): PendingRequest
    {
        return $this->request()
            ->retry(2, 150, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            }, throw: false);
    }

    /** @param callable(): Response $send */
    private function send(callable $send): Response
    {
        try {
            return $send();
        } catch (ConnectionException|RequestException) {
            throw new PaymentGatewayException('Paylink is temporarily unavailable.');
        }
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        if (! $response->successful()) {
            throw new PaymentGatewayException('Paylink is temporarily unavailable.');
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }

        return $body;
    }

    /** @param array<string, mixed> $body */
    private function parseInvoice(array $body): PaymentInvoice
    {
        $gatewayOrder = $body['gatewayOrderRequest'] ?? null;
        $receipt = $body['paymentReceipt'] ?? null;

        if (($body['success'] ?? null) !== true || ! is_array($gatewayOrder)) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }

        $status = match (strtolower($this->stringValue($body['orderStatus'] ?? null))) {
            'pending' => 'pending',
            'paid' => 'paid',
            'canceled', 'cancelled' => 'cancelled',
            default => throw new PaymentGatewayException('Paylink returned an invalid response.'),
        };

        $paymentUrl = $body['url'] ?? null;
        $paymentMethod = is_array($receipt) ? ($receipt['paymentMethod'] ?? null) : null;

        try {
            return new PaymentInvoice(
                transactionNo: $this->stringValue($body['transactionNo'] ?? null),
                orderNumber: $this->stringValue($gatewayOrder['orderNumber'] ?? null),
                amountHalalah: $this->halalah($body['amount'] ?? null),
                currency: strtoupper($this->stringValue($gatewayOrder['currency'] ?? 'SAR')),
                status: $status,
                paymentUrl: is_string($paymentUrl) && $paymentUrl !== '' ? $paymentUrl : null,
                paymentMethod: is_string($paymentMethod) && $paymentMethod !== '' ? $paymentMethod : null,
            );
        } catch (InvalidArgumentException|PaymentGatewayException) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }
    }

    /** @return array{string, string} */
    private function merchantConfiguration(): array
    {
        $apiId = config('services.paylink.api_id');
        $secretKey = config('services.paylink.secret_key');

        if (! is_string($apiId) || trim($apiId) === '' || ! is_string($secretKey) || trim($secretKey) === '') {
            throw new PaymentConfigurationException('Paylink merchant payment is not configured.');
        }

        return [$apiId, $secretKey];
    }

    /** @return array{string, string, string, string} */
    private function partnerConfiguration(): array
    {
        $profileNo = config('services.paylink.partner_profile_no');
        $apiKey = config('services.paylink.partner_api_key');
        $lookupKey = config('services.paylink.merchant_lookup_key');
        $lookupValue = config('services.paylink.merchant_lookup_value');

        if (! is_string($profileNo) || trim($profileNo) === ''
            || ! is_string($apiKey) || trim($apiKey) === ''
            || ! is_string($lookupKey) || ! in_array($lookupKey, self::ALLOWED_LOOKUP_KEYS, true)
            || ! is_string($lookupValue) || trim($lookupValue) === '') {
            throw new PaymentConfigurationException('Paylink refunds are not configured.');
        }

        return [$profileNo, $apiKey, $lookupKey, $lookupValue];
    }

    private function baseUrl(): string
    {
        return match ($this->environment()) {
            'test' => 'https://restpilot.paylink.sa',
            'production' => 'https://restapi.paylink.sa',
            default => throw new PaymentConfigurationException('The Paylink environment is invalid.'),
        };
    }

    private function environment(): string
    {
        $environment = config('services.paylink.environment');

        if (! is_string($environment) || ! in_array($environment, ['test', 'production'], true)) {
            throw new PaymentConfigurationException('The Paylink environment is invalid.');
        }

        return $environment;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $identifier) !== 1) {
            throw new PaymentGatewayException('The Paylink identifier is invalid.');
        }
    }

    private function stringValue(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || trim((string) $value) === '') {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }

        return (string) $value;
    }

    private function positiveInteger(mixed $value): int
    {
        if ((! is_int($value) && (! is_string($value) || preg_match('/\A[0-9]+\z/D', $value) !== 1))
            || (int) $value < 1) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }

        return (int) $value;
    }

    private function halalah(mixed $amount): int
    {
        if (! is_int($amount) && ! is_float($amount) && ! is_string($amount)) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }

        if (! is_numeric($amount) || (float) $amount < 0 || ! is_finite((float) $amount)) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }

        $halalah = (int) round((float) $amount * 100);

        if (abs(((float) $amount * 100) - $halalah) > 0.00001) {
            throw new PaymentGatewayException('Paylink returned an invalid response.');
        }

        return $halalah;
    }
}
