<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\PersistCartItemCredentials;
use App\Actions\Cart\ResolveCartOwner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CartItemCredentialsRequest;
use App\Models\Cart;
use App\Models\CartItemSecret;
use App\ValueObjects\Cart\CartOwner;
use DateTimeImmutable;
use DateTimeInterface;
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
            && ($configuration['delivery'] ?? null) === 'fast';
        $hasBalance = array_key_exists('current_balance', $validated);

        if (($validated['companion_market_open'] ?? null) !== true
            || ($validated['policy_accepted'] ?? null) !== true
            || $requiresBalance !== $hasBalance) {
            $this->failUpdate('credentials');
        }

        return $validated;
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
