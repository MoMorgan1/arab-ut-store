<?php

namespace App\Suppliers;

use App\Enums\Supplier;
use App\Suppliers\Exceptions\SupplierNotConfigured;
use App\Suppliers\Exceptions\SupplierUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The UTT (utautotransfer) supplier.
 *
 * UTT speaks form-encoded bodies and its own status vocabulary; reads are
 * normalised into the FFT payload shape so the layer above only knows one
 * order model. UTT has no challenge solver, so retryChallenge is impossible
 * by contract.
 */
final class UttClient implements SupplierClient
{
    /**
     * UTT answers HTTP 200 with a JSON error body for both order-level
     * problems and supplier-side ones. The tracker never classified the
     * wording (every JSON error becomes "order not found",
     * api-handlers.php:285-293 and :638-641) and the probe that would have
     * captured a wrong-key answer (.probe/utt-ref.php) has no recorded
     * output, so these are narrow semantic classes rather than invented
     * codes: only bodies about the key, maintenance or throttling count
     * against the circuit. Everything else is a business rejection.
     *
     * @var list<string>
     */
    private const SUPPLIER_FAULT_PATTERNS = [
        'api key',
        'apikey',
        'unauthorized',
        'forbidden',
        'maintenance',
        'rate limit',
        'too many requests',
    ];

    public function __construct(private readonly SupplierGuard $guard) {}

    public function supplier(): Supplier
    {
        return Supplier::Utt;
    }

    public function observe(string $supplierOrderId, bool $isExternalId = true): RawSupplierObservation
    {
        $order = $this->fetchOrder($supplierOrderId);

        return new RawSupplierObservation(
            Supplier::Utt,
            $supplierOrderId,
            $this->mapUttToFftFormat($order),
            CarbonImmutable::now(),
        );
    }

    public function correctCredentials(string $supplierOrderId, array $credentials): SupplierActionResult
    {
        $config = $this->configuration();

        // UTT's editOrderPublic is a full replace: it needs every field, so
        // the current order is fetched first and used as the fallback. This
        // read is part of a customer action, so it uses the action profile.
        $current = $this->fetchOrder($supplierOrderId, SupplierCallProfile::Action);

        $payload = array_filter([
            'apiKey' => $config['api_key'],
            'idOrder' => $supplierOrderId,
            'email' => $this->filledString($credentials, 'user') ?? ($current['emailAccount'] ?? ''),
            'password' => $this->filledString($credentials, 'pass') ?? ($current['passwordAccount'] ?? ''),
            'amountOrder' => $current['amountTotal'] ?? 0,
            'platform' => strtolower($this->filledString($credentials, 'platform') ?? (string) ($current['platform'] ?? 'ps')),
            'publicSale' => $current['publicSaleStocks'] ?? '',
            'name' => $current['nameAccount'] ?? '',
            'backupCodes' => $this->backupCodes($credentials) ?? ($current['backupCodes'] ?? ''),
        ], static fn (mixed $value): bool => $value !== '' && $value !== null);

        $response = $this->request($config, $supplierOrderId, '/editOrderPublic', $payload, SupplierCallProfile::Action);

        return $this->actionResult($supplierOrderId, $response);
    }

    public function resume(string $supplierOrderId): SupplierActionResult
    {
        $config = $this->configuration();

        $response = $this->request($config, $supplierOrderId, '/startOrder', [
            'apiKey' => $config['api_key'],
            'idOrder' => $supplierOrderId,
        ], SupplierCallProfile::Action);

        return $this->actionResult($supplierOrderId, $response);
    }

    public function retryChallenge(string $supplierOrderId, string $challengeId): SupplierActionResult
    {
        throw new LogicException('Supplier [utt] does not support challenge retries.');
    }

    /**
     * Fetch the raw UTT order, rejecting the 200-with-error-body trap.
     *
     * @return array<string, mixed>
     */
    private function fetchOrder(string $supplierOrderId, SupplierCallProfile $profile = SupplierCallProfile::Polling): array
    {
        $config = $this->configuration();

        $response = $this->request($config, $supplierOrderId, '/getOrder', [
            'apiKey' => $config['api_key'],
            'idOrder' => $supplierOrderId,
        ], $profile);

        $data = $response->json();

        if (! is_array($data)) {
            $this->guard->recordFailure(Supplier::Utt);

            throw new SupplierUnavailable(Supplier::Utt, $supplierOrderId, $response->status(), 'invalid_body');
        }

        $order = $data['order'] ?? null;

        if (! is_array($order)) {
            // A stale or wrong id is UTT answering correctly, so it must not
            // count against the circuit for every other UTT job. A JSON error
            // that blames the supplier itself (bad key, maintenance,
            // throttling) does.
            if ($this->isSupplierFault($data)) {
                $this->guard->recordFailure(Supplier::Utt);
            } else {
                $this->guard->recordSuccess(Supplier::Utt);
            }

            throw new SupplierUnavailable(Supplier::Utt, $supplierOrderId, $response->status(), 'order_missing');
        }

        $this->guard->recordSuccess(Supplier::Utt);

        return $order;
    }

    /**
     * @param  array{base_url: string, api_key: string}  $config
     * @param  array<string, mixed>  $payload
     */
    private function request(array $config, string $supplierOrderId, string $path, array $payload, SupplierCallProfile $profile): Response
    {
        $this->guard->ensureAvailable(Supplier::Utt, $supplierOrderId);

        try {
            $response = Http::asForm()
                ->timeout($profile->timeoutSeconds())
                ->connectTimeout($profile->connectTimeoutSeconds())
                ->acceptJson()
                ->post($config['base_url'].$path, $payload);
        } catch (ConnectionException $exception) {
            $this->guard->recordFailure(Supplier::Utt);

            throw new SupplierUnavailable(Supplier::Utt, $supplierOrderId, null, 'connection', $exception);
        }

        if (! $response->successful()) {
            $this->guard->recordFailure(Supplier::Utt, $response->header('Retry-After'));

            throw new SupplierUnavailable(Supplier::Utt, $supplierOrderId, $response->status(), 'http_status');
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
            $this->guard->recordFailure(Supplier::Utt);

            throw new SupplierUnavailable(Supplier::Utt, $supplierOrderId, $response->status(), 'invalid_body');
        }

        $data = $response->json();

        if (! is_array($data)) {
            // A 2xx with an empty or plain-text body is the tracker's success shape.
            $this->guard->recordSuccess(Supplier::Utt);

            return new SupplierActionResult(true, null, []);
        }

        $accepted = empty($data['error']);

        if (! $accepted && $this->isSupplierFault($data)) {
            $this->guard->recordFailure(Supplier::Utt);
        } else {
            $this->guard->recordSuccess(Supplier::Utt);
        }

        return new SupplierActionResult($accepted, $this->codeFrom($data), $data);
    }

    /** @param array<string, mixed> $data */
    private function isSupplierFault(array $data): bool
    {
        $error = $data['error'] ?? $data['message'] ?? null;

        if (! is_string($error) || trim($error) === '') {
            return false;
        }

        $error = strtolower($error);

        foreach (self::SUPPLIER_FAULT_PATTERNS as $pattern) {
            if (str_contains($error, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $data */
    private function codeFrom(array $data): ?string
    {
        foreach (['code', 'status'] as $key) {
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

    /** @param array<string, mixed> $credentials */
    private function filledString(array $credentials, string $key): ?string
    {
        $value = $credentials[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    /** @param array<string, mixed> $credentials */
    private function backupCodes(array $credentials): ?string
    {
        $first = $this->filledString($credentials, 'ba');

        if ($first === null) {
            return null;
        }

        $codes = array_filter([
            $first,
            $this->filledString($credentials, 'ba2'),
            $this->filledString($credentials, 'ba3'),
            $this->filledString($credentials, 'ba4'),
            $this->filledString($credentials, 'ba5'),
        ]);

        return implode(',', $codes);
    }

    /**
     * Port of the tracker's mapUTTtoFFTFormat(), so callers never branch on
     * the supplier when reading an order.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function mapUttToFftFormat(array $order): array
    {
        $statusOrder = strtoupper(trim((string) ($order['statusOrder'] ?? '')));

        $statusMap = [
            'COMPLETED' => 'finished',
            'STOPPED' => 'stopped',
            'STOPPING' => 'stopped',
        ];

        $activeStatuses = [
            'ENTERED', 'READY', 'STARTING', 'LOGGING',
            'TRANSFERRING', 'CHANGING SENDER',
        ];

        $fftStatus = $statusMap[$statusOrder] ?? 'entered';

        if (in_array($statusOrder, $activeStatuses, true)) {
            $fftStatus = 'entered';
        }

        $customerErrors = [
            'BACKUP CODE WRONG' => 'wrongBA',
            'WRONG DETAILS' => 'wrongUserPass',
            'WRONG PLATFORM' => 'wrongConsole',
            'CUSTOMER NOT ENOUGH COINS' => 'notEnoughCoins',
            'NO TRANSFER ACCESS' => 'noTM',
            'NO CLUB' => 'noClub',
            'NEW CLUB' => 'noClub',
            'TRANSFER LIST IS FULL' => 'tlFull',
            'HAS UNASSIGNED ITEMS' => 'unassignedItemsPresent',
            'ERROR CAPTCHA' => 'captcha',
            'WATCHLIST IS FULL' => 'tlFull',
            'WRONG GAUTH' => 'wrongBA',
            'GAUTH WRONG' => 'wrongBA',
            'GAUTH UNAVAILABLE' => 'wrongBA',
            'CODE SENT' => 'wrongBA',
            'CODE SENT EMAIL' => 'wrongBA',
            'CODE SENT EMAIL1' => 'wrongBA',
            'LOGGED IN' => 'active session',
            'LOGIN FAILED' => 'loginFailed',
            'GENERAL LOGIN ERROR' => 'loginFailed',
            'LOGIN UNAVAILABLE' => 'loginFailed',
        ];

        $systemInfoMap = [
            'BUYING SOFTBAN' => 'tempbanCooldown',
            'LISTING SOFTBAN' => 'tempbanCooldown',
            'SEARCH SOFTBAN' => 'tempbanCooldown',
            'DAILY LIMIT REACHED' => 'dailyReceiverLimit',
            'EA SERVERS SLOW' => 'calcErrorMaintenance',
            'GENERAL ERROR' => 'calcErrorMaintenance',
            'NO SENDER AVAILABLE' => 'noSuitableSender',
            'BALANCE NOT ENOUGH' => 'insufficientFunds',
            'CHANGE SENDER NOT ENOUGH COINS' => 'noSuitableSender',
            'DISABLE BAN' => 'LoginFailedDeviceBan',
            'WRITE SUPPORT' => 'LoginFailedDeviceBan',
        ];

        $connectionErrors = [
            'BAD COOKIE' => 'FailedProxyConnectionError',
            'BAD PROXY' => 'FailedProxyConnectionError',
            'LOGIN FAILED PROXY' => 'FailedProxyConnectionError',
        ];

        $accountCheck = '';
        $economyState = '';

        if (isset($customerErrors[$statusOrder])) {
            $fftStatus = 'interrupted';
            $accountCheck = $customerErrors[$statusOrder];
        } elseif (isset($systemInfoMap[$statusOrder])) {
            $fftStatus = 'entered';
            $economyState = $systemInfoMap[$statusOrder];
        } elseif (isset($connectionErrors[$statusOrder])) {
            $fftStatus = 'interrupted';
            $economyState = $connectionErrors[$statusOrder];
        } elseif ($statusOrder === 'READY TO RESUME') {
            $fftStatus = 'stopped';
            $economyState = 'deactivated';
        }

        if ($statusOrder === 'TRANSFERRING') {
            $economyState = 'transfersInProgress';
        } elseif ($statusOrder === 'LOGGING') {
            $accountCheck = 'entered';
        }

        $amountProcessed = (int) ($order['amountProcessed'] ?? 0);
        $amountTotal = (int) ($order['amountTotal'] ?? 0);

        return [
            'status' => $fftStatus,
            'amount' => $amountProcessed / 1000,
            'amountOrdered' => $amountTotal / 1000,
            'accountCheck' => $accountCheck,
            'economyState' => $economyState,
            'platform' => strtoupper((string) ($order['platform'] ?? '')),
            'coinsCustomerAccount' => $order['endCoins'] ?? -1,
            'simplifiedStatus' => '',
            'accountCheckLong' => '',
            'economyStateLong' => '',
            '_supplier' => 'UTT',
            '_uttStatusOrder' => $statusOrder,
            '_uttIdOrder' => $order['idOrder'] ?? null,
        ];
    }

    /** @return array{base_url: string, api_key: string} */
    private function configuration(): array
    {
        $baseUrl = config('services.suppliers.utt.base_url');
        $apiKey = config('services.suppliers.utt.api_key');

        if (! is_string($baseUrl) || ! $this->isSecureBaseUrl($baseUrl)) {
            throw new SupplierNotConfigured(Supplier::Utt, 'base_url');
        }

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new SupplierNotConfigured(Supplier::Utt, 'api_key');
        }

        return [
            'base_url' => rtrim($baseUrl, '/'),
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
