<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\PersistCartItemCredentials;
use App\Actions\Cart\ResolveCartOwner;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CartItemCredentialsRequest;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\Services\Catalog\CoinsCatalogReader;
use App\ValueObjects\Cart\CartOwner;
use App\ValueObjects\Cart\ManualServiceCredentials;
use DateTimeImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class CartItemCredentialsController extends Controller
{
    public function show(
        Request $request,
        string $cartItem,
        ResolveCartOwner $resolveCartOwner,
    ): JsonResponse {
        $secret = $this->ownedSecret($cartItem, $resolveCartOwner->forRequest($request));

        if ($this->isManual($secret)) {
            $manual = $this->manualCredentials($secret);

            abort_if($manual === null, Response::HTTP_NOT_FOUND);

            return response()->json(['data' => $manual]);
        }

        $credentials = $this->credentials($secret);

        abort_if($credentials === null, Response::HTTP_NOT_FOUND);

        return response()->json(['data' => [
            'eaEmail' => $credentials['ea_email'],
            'eaPassword' => $credentials['ea_password'],
            'backupCodes' => $credentials['backup_codes'],
            'currentBalance' => $credentials['current_balance'],
            'companionMarketOpen' => $credentials['companion_market_open'],
            'policyAccepted' => $credentials['policy_accepted'],
        ]]);
    }

    public function update(
        CartItemCredentialsRequest $request,
        string $cartItem,
        ResolveCartOwner $resolveCartOwner,
        PersistCartItemCredentials $persistCredentials,
    ): Response {
        DB::transaction(function () use ($request, $cartItem, $resolveCartOwner, $persistCredentials): void {
            $secret = $this->ownedSecret(
                $cartItem,
                $resolveCartOwner->forRequest($request),
                lock: true,
            );

            if ($this->isManual($secret)) {
                $persistCredentials->replaceManual(
                    $secret,
                    $this->validatedManualUpdate($secret, $request->validated()),
                );

                return;
            }

            $persistCredentials->replace(
                $secret,
                $this->validatedUpdate($secret, $request->validated()),
            );
        }, attempts: 3);

        return response()->noContent();
    }

    private function ownedSecret(string $publicId, CartOwner $owner, bool $lock = false): CartItemSecret
    {
        $ownedCartIds = Cart::query();
        $ownedCartIds->activeForOwner($owner);

        $query = CartItemSecret::query()
            ->whereHas('cartItem', fn (Builder $item): Builder => $item
                ->where('public_id', $publicId)
                ->whereIn('cart_id', $ownedCartIds->select('id')));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function isManual(CartItemSecret $secret): bool
    {
        $serviceType = $secret->cartItem->productVariant->service_type;

        return in_array($serviceType, [ServiceType::Rivals, ServiceType::FutChampions], true);
    }

    /**
     * The manual shape read from the secret payload the add path wrote.
     * Fields the platform does not use come back empty; the platform and
     * launcher come from the stored line and are never editable here.
     *
     * @return array{platform: string, launcher: string|null, eaEmail: string, eaPassword: string, eaCodes: array{string, string, string}, playstationEmail: string, playstationPassword: string, playstationCodes: array{string, string, string}, steamUsername: string, steamPassword: string}|null
     */
    private function manualCredentials(CartItemSecret $secret): ?array
    {
        $payload = $secret->encrypted_payload;
        $configuration = $secret->cartItem->configuration;

        if (! is_array($payload) || ! is_array($configuration)) {
            return null;
        }

        $platform = $configuration['platform'] ?? null;
        $store = $configuration['pc_store'] ?? null;

        if (($payload['platform'] ?? null) !== $platform
            || ($platform === 'pc' && ($payload['pc_store'] ?? null) !== $store)
            || ($platform === 'playstation' && array_key_exists('pc_store', $payload))) {
            return null;
        }

        if ($platform === 'playstation') {
            if (! isset($payload['playstation_email'], $payload['playstation_password'], $payload['ea_backup_codes'], $payload['playstation_backup_codes'])
                || ! is_string($payload['playstation_email'])
                || ! is_string($payload['playstation_password'])
                || ! $this->validEaCodes($payload['ea_backup_codes'])
                || ! $this->validPlayStationCodes($payload['playstation_backup_codes'])) {
                return null;
            }

            /** @var array{string, string, string} $eaCodes */
            $eaCodes = array_values($payload['ea_backup_codes']);
            /** @var array{string, string, string} $playStationCodes */
            $playStationCodes = array_values($payload['playstation_backup_codes']);

            return [
                'platform' => 'playstation',
                'launcher' => null,
                'eaEmail' => '',
                'eaPassword' => '',
                'eaCodes' => $eaCodes,
                'playstationEmail' => $payload['playstation_email'],
                'playstationPassword' => $payload['playstation_password'],
                'playstationCodes' => $playStationCodes,
                'steamUsername' => '',
                'steamPassword' => '',
            ];
        }

        if ($platform === 'pc' && in_array($store, ['ea_app', 'steam'], true)) {
            if (! isset($payload['ea_email'], $payload['ea_password'], $payload['ea_backup_codes'])
                || ! is_string($payload['ea_email'])
                || ! is_string($payload['ea_password'])
                || ! $this->validEaCodes($payload['ea_backup_codes'])) {
                return null;
            }

            if ($store === 'steam'
                && (! isset($payload['steam_username'], $payload['steam_password'])
                    || ! is_string($payload['steam_username'])
                    || ! is_string($payload['steam_password']))) {
                return null;
            }

            /** @var array{string, string, string} $eaCodes */
            $eaCodes = array_values($payload['ea_backup_codes']);

            return [
                'platform' => 'pc',
                'launcher' => $store,
                'eaEmail' => $payload['ea_email'],
                'eaPassword' => $payload['ea_password'],
                'eaCodes' => $eaCodes,
                'playstationEmail' => '',
                'playstationPassword' => '',
                'playstationCodes' => ['', '', ''],
                'steamUsername' => $store === 'steam' ? $payload['steam_username'] : '',
                'steamPassword' => $store === 'steam' ? $payload['steam_password'] : '',
            ];
        }

        return null;
    }

    private function validEaCodes(mixed $codes): bool
    {
        return is_array($codes)
            && count($codes) === 3
            && array_filter(
                $codes,
                fn (mixed $code): bool => ! is_string($code)
                    || preg_match('/\A[0-9]{8}\z/D', $code) !== 1,
            ) === []
            && count(array_unique($codes)) === 3;
    }

    private function validPlayStationCodes(mixed $codes): bool
    {
        return is_array($codes)
            && count($codes) === 3
            && array_filter(
                $codes,
                fn (mixed $code): bool => ! is_string($code)
                    || preg_match('/\A[A-Z0-9]{6}\z/D', $code) !== 1,
            ) === []
            && count(array_unique($codes)) === 3;
    }

    /** @return array{ea_email: string, ea_password: string, backup_codes: array{string, string, string}, current_balance: int|null, companion_market_open: bool, policy_accepted: bool}|null */
    private function credentials(CartItemSecret $secret): ?array
    {
        $payload = $secret->encrypted_payload;
        $allowedKeys = [
            'ea_email',
            'ea_password',
            'backup_codes',
            'current_balance',
            'companion_market_open',
            'policy_version',
            'policy_accepted_at',
        ];

        if (! is_array($payload)
            || array_diff(array_keys($payload), $allowedKeys) !== []
            || ! is_string($payload['ea_email'])
            || ! is_string($payload['ea_password'])
            || ! is_array($payload['backup_codes'])
            || count($payload['backup_codes']) !== 3
            || array_filter(
                $payload['backup_codes'],
                fn (mixed $code): bool => ! is_string($code)
                    || preg_match('/\A[0-9]{8}\z/D', $code) !== 1,
            ) !== []
            || count(array_unique($payload['backup_codes'])) !== 3) {
            return null;
        }

        $hasFulfillmentMetadata = array_key_exists('companion_market_open', $payload)
            || array_key_exists('policy_version', $payload)
            || array_key_exists('policy_accepted_at', $payload)
            || array_key_exists('current_balance', $payload);

        if ($hasFulfillmentMetadata && (! isset($payload['companion_market_open'])
            || $payload['companion_market_open'] !== true
            || ($payload['policy_version'] ?? null) !== PersistCartItemCredentials::POLICY_VERSION
            || ! $this->validAcceptedAt($payload['policy_accepted_at'] ?? null)
            || (array_key_exists('current_balance', $payload)
                && (! is_int($payload['current_balance'])
                    || $payload['current_balance'] < 0
                    || $payload['current_balance'] > 100_000_000)))) {
            return null;
        }

        /** @var array{string, string, string} $codes */
        $codes = array_values($payload['backup_codes']);

        return [
            'ea_email' => $payload['ea_email'],
            'ea_password' => $payload['ea_password'],
            'backup_codes' => $codes,
            'current_balance' => isset($payload['current_balance']) ? (int) $payload['current_balance'] : null,
            'companion_market_open' => $hasFulfillmentMetadata,
            'policy_accepted' => $hasFulfillmentMetadata,
        ];
    }

    private function validAcceptedAt(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $date = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $value);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    /** @param array<string, mixed> $validated
     * @return array<string, mixed>
     */
    private function validatedUpdate(CartItemSecret $secret, array $validated): array
    {
        $configuration = $secret->cartItem->configuration;
        $isCoins = is_array($configuration)
            && ($configuration['service_type'] ?? null) === 'coins';
        $fulfillmentKeys = [
            'current_balance',
            'companion_market_open',
            'policy_accepted',
        ];

        if (! $isCoins) {
            if (array_intersect(array_keys($validated), $fulfillmentKeys) !== []) {
                $this->failUpdate('request');
            }

            return $validated;
        }

        $requiresBalance = ($configuration['platform'] ?? null) === 'playstation'
            && ($configuration['delivery'] ?? null) === 'fast'
            && app(CoinsCatalogReader::class)->requiresCurrentBalance();
        $hasBalance = array_key_exists('current_balance', $validated);

        if (($validated['companion_market_open'] ?? null) !== true
            || ($validated['policy_accepted'] ?? null) !== true
            || $requiresBalance !== $hasBalance) {
            $this->failUpdate('credentials');
        }

        return $validated;
    }

    /**
     * Rebuilds the validated edit through the stored platform/launcher, so
     * the value object sees exactly the shape the add path validated and
     * the masked summary stays in sync.
     *
     * @param  array<string, mixed>  $validated
     */
    private function validatedManualUpdate(CartItemSecret $secret, array $validated): ManualServiceCredentials
    {
        $configuration = $secret->cartItem->configuration;
        $platform = is_array($configuration) ? ($configuration['platform'] ?? null) : null;
        $store = is_array($configuration) ? ($configuration['pc_store'] ?? null) : null;

        try {
            $combined = ['platform' => $platform, ...$validated];

            if ($platform === 'pc') {
                $combined['pc_store'] = $store;
            }

            return ManualServiceCredentials::fromValidated($combined);
        } catch (DomainException) {
            $this->failUpdate('credentials');
        }
    }

    private function failUpdate(string $field): never
    {
        throw new HttpResponseException(
            response()->json([
                'message' => trans('store.cart.validation_error'),
                'errors' => [$field => [trans('store.cart.validation_error')]],
            ], Response::HTTP_UNPROCESSABLE_ENTITY)->header('Cache-Control', 'no-store'),
        );
    }
}
