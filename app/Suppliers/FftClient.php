<?php

namespace App\Suppliers;

use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The futtransfer (FFT) supplier.
 *
 * Keeps the tracker's wire format: JSON bodies, an apiUser/apiKey pair, and a
 * UUID order id that means "this is an external order" when it is not one of
 * FFT's own internal ids.
 */
final class FftClient implements SupplierClient
{
    /** @var list<string> */
    private const CREDENTIAL_FIELDS = ['user', 'pass', 'ba', 'ba2', 'ba3', 'ba4', 'ba5', 'platform', 'persona', 'limit', 'sortMode'];

    public function __construct(private readonly SupplierGuard $guard) {}

    public function supplier(): Supplier
    {
        return Supplier::Fft;
    }

    public function observe(string $supplierOrderId, bool $isExternalId = true): RawSupplierObservation
    {
        $config = $this->configuration();

        $payload = [
            'orderID' => $supplierOrderId,
            'apiUser' => $config['api_user'],
            'apiKey' => $config['api_key'],
        ];

        if ($isExternalId) {
            $payload['externalID'] = 1;
            $payload['isMotherID'] = 0;
        }

        $response = $this->request($config, $supplierOrderId, '/orderStatusAPI', $payload, SupplierCallProfile::Polling);
        $data = $response->json();

        if (! is_array($data)) {
            $this->guard->recordFailure(Supplier::Fft);

            throw new SupplierUnavailable(Supplier::Fft, $supplierOrderId, $response->status(), 'invalid_body');
        }

        $this->guard->recordSuccess(Supplier::Fft);

        return new RawSupplierObservation(Supplier::Fft, $supplierOrderId, $data, CarbonImmutable::now());
    }

    public function correctCredentials(string $supplierOrderId, array $credentials): SupplierActionResult
    {
        $config = $this->configuration();
        $payload = $this->actionPayload($config, $supplierOrderId);

        foreach (self::CREDENTIAL_FIELDS as $field) {
            $value = $credentials[$field] ?? null;

            if ((is_string($value) && $value !== '') || is_int($value)) {
                $payload[$field] = $value;
            }
        }

        if (array_key_exists('continue', $credentials)) {
            $payload['continue'] = (int) $credentials['continue'];
        }

        $response = $this->request($config, $supplierOrderId, '/correctCredentialsAPI', $payload, SupplierCallProfile::Action);

        return $this->actionResult($supplierOrderId, $response);
    }

    public function resume(string $supplierOrderId): SupplierActionResult
    {
        $config = $this->configuration();

        $response = $this->request($config, $supplierOrderId, '/correctCredentialsAPI', $this->actionPayload($config, $supplierOrderId), SupplierCallProfile::Action);

        return $this->actionResult($supplierOrderId, $response);
    }

    public function retryChallenge(string $supplierOrderId, string $challengeId): SupplierActionResult
    {
        $config = $this->configuration();
        $bareId = preg_replace('/^SBC-/i', '', $challengeId) ?? $challengeId;

        $payload = [
            'sbcSolveID' => [$bareId],
            'apiUser' => $config['api_user'],
            'apiKey' => $config['api_key'],
        ];

        $response = $this->request($config, $supplierOrderId, '/retrySBCAPI', $payload, SupplierCallProfile::Action);

        return $this->actionResult($supplierOrderId, $response);
    }

    /**
     * @param  array{base_url: string, api_user: string, api_key: string}  $config
     * @return array<string, mixed>
     */
    private function actionPayload(array $config, string $supplierOrderId): array
    {
        $payload = [
            'orderID' => $supplierOrderId,
            'apiUser' => $config['api_user'],
            'apiKey' => $config['api_key'],
            'continue' => 1,
        ];

        if (! $this->isInternalId($supplierOrderId)) {
            $payload['externalOrderID'] = 1;
        }

        return $payload;
    }

    /**
     * @param  array{base_url: string, api_user: string, api_key: string}  $config
     * @param  array<string, mixed>  $payload
     */
    private function request(array $config, string $supplierOrderId, string $path, array $payload, SupplierCallProfile $profile): Response
    {
        $this->guard->ensureAvailable(Supplier::Fft, $supplierOrderId);

        try {
            $response = Http::timeout($profile->timeoutSeconds())
                ->connectTimeout($profile->connectTimeoutSeconds())
                ->acceptJson()
                ->post($config['base_url'].$path, $payload);
        } catch (ConnectionException $exception) {
            $this->guard->recordFailure(Supplier::Fft);

            throw new SupplierUnavailable(Supplier::Fft, $supplierOrderId, null, 'connection', $exception);
        }

        if (! $response->successful()) {
            $this->guard->recordFailure(Supplier::Fft, $response->header('Retry-After'));

            throw new SupplierUnavailable(Supplier::Fft, $supplierOrderId, $response->status(), 'http_status');
        }

        return $response;
    }

    private function actionResult(string $supplierOrderId, Response $response): SupplierActionResult
    {
        $body = trim($response->body());

        if ($body !== '' && str_starts_with($body, '<')) {
            // An HTML 2xx is a proxy error page, a WAF challenge or a
            // maintenance splash, not an answer from the supplier: we do not
            // know whether the action was seen, so the honest answer is
            // "unavailable" and it counts against the circuit.
            $this->guard->recordFailure(Supplier::Fft);

            throw new SupplierUnavailable(Supplier::Fft, $supplierOrderId, $response->status(), 'invalid_body');
        }

        $data = $response->json();

        if (! is_array($data)) {
            // A 2xx with an empty or plain-text body is the tracker's success shape.
            $this->guard->recordSuccess(Supplier::Fft);

            return new SupplierActionResult(true, null, []);
        }

        $this->guard->recordSuccess(Supplier::Fft);

        $outcome = strtolower((string) ($data['outcome'] ?? $data['status'] ?? ''));

        $accepted = ! isset($data['error']) && ! in_array($outcome, ['error', 'failed', 'failure'], true);

        return new SupplierActionResult($accepted, $this->codeFrom($data), $data);
    }

    /** @param array<string, mixed> $data */
    private function codeFrom(array $data): ?string
    {
        foreach (['code', 'outcome', 'status'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    private function isInternalId(string $supplierOrderId): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $supplierOrderId) === 1;
    }

    /** @return array{base_url: string, api_user: string, api_key: string} */
    private function configuration(): array
    {
        $baseUrl = config('services.suppliers.fft.base_url');
        $apiUser = config('services.suppliers.fft.api_user');
        $apiKey = config('services.suppliers.fft.api_key');

        if (! is_string($baseUrl) || ! $this->isSecureBaseUrl($baseUrl)) {
            throw new SupplierNotConfigured(Supplier::Fft, 'base_url');
        }

        if (! is_string($apiUser) || trim($apiUser) === '') {
            throw new SupplierNotConfigured(Supplier::Fft, 'api_user');
        }

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new SupplierNotConfigured(Supplier::Fft, 'api_key');
        }

        return [
            'base_url' => rtrim($baseUrl, '/'),
            'api_user' => $apiUser,
            'api_key' => $apiKey,
        ];
    }

    private function isSecureBaseUrl(string $baseUrl): bool
    {
        $parts = parse_url($baseUrl);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? '') !== ''
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }
}
