<?php

namespace App\Actions\Cart;

use App\Enums\Platform;
use App\Enums\ProductAuthority;
use App\Enums\ServiceType;
use App\Exceptions\IdempotencyConflict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\IdempotencyKey;
use App\Models\Product;
use App\Models\ProductVariant;
use App\ValueObjects\Cart\CartOwner;
use App\ValueObjects\Cart\ManualServiceCredentials;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class ManualServiceCartSupport
{
    public function claim(CartOwner $owner, string $key, string $scope, string $hash): IdempotencyKey
    {
        $scoped = $scope.':'.$owner->idempotencyScope();
        DB::table('idempotency_keys')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'key' => $key,
            'scope' => $scoped,
            'request_hash' => $hash,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);
        $claim = IdempotencyKey::query()->where('key', $key)->lockForUpdate()->firstOrFail();

        if ($claim->scope !== $scoped || ! hash_equals((string) $claim->request_hash, $hash)) {
            throw new IdempotencyConflict;
        }

        return $claim;
    }

    /** @return array{status: int, body: array<string, mixed>}|null */
    public function replay(IdempotencyKey $claim): ?array
    {
        if ($claim->response_status === null || $claim->response_body === null) {
            return null;
        }

        try {
            $body = json_decode((string) $claim->response_body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new IdempotencyConflict;
        }

        if (! is_array($body)) {
            throw new IdempotencyConflict;
        }

        return ['status' => (int) $claim->response_status, 'body' => $body];
    }

    /** @param array<string, mixed> $validated */
    public function credentials(array $validated): ManualServiceCredentials
    {
        $credentials = ['platform' => $validated['platform'], ...$validated['credentials']];

        if ($validated['platform'] === Platform::Pc->value) {
            $credentials['pc_store'] = $validated['pcStore'];
        }

        return ManualServiceCredentials::fromValidated($credentials);
    }

    public function eligibleVariant(ServiceType $service, Platform $platform): ProductVariant
    {
        $sku = match ([$service, $platform]) {
            [ServiceType::FutChampions, Platform::PlayStation] => 'MANUAL_FUT_CHAMPIONS_PLAYSTATION',
            [ServiceType::FutChampions, Platform::Pc] => 'MANUAL_FUT_CHAMPIONS_PC',
            [ServiceType::Rivals, Platform::PlayStation] => 'MANUAL_RIVALS_PLAYSTATION',
            [ServiceType::Rivals, Platform::Pc] => 'MANUAL_RIVALS_PC',
            default => throw new DomainException('The manual-service platform is unavailable.'),
        };
        $variant = ProductVariant::query()
            ->where('sku', $sku)
            ->where('service_type', $service)
            ->where('platform', $platform)
            ->where('authority', ProductAuthority::Manual)
            ->where('is_active', true)
            ->whereHas('product', fn ($query) => $query
                ->where('service_type', $service)
                ->where('authority', ProductAuthority::Manual)
                ->where('is_visible', true)
                ->whereNull('archived_at'))
            ->with('product')
            ->lockForUpdate()
            ->first();

        if (! $variant instanceof ProductVariant || ! $variant->product instanceof Product) {
            throw new DomainException('The manual-service platform is unavailable.');
        }

        return $variant;
    }

    /** @return array<string, mixed> */
    public function responseBody(Cart $cart, CartItem $item, string $locale): array
    {
        $localized = $locale === 'en';

        return ['data' => [
            'cartItemId' => $item->public_id,
            'cartCount' => $cart->items()->count(),
            'cartUrl' => route(
                $localized ? 'localized.store.cart' : 'store.cart',
                $localized ? ['locale' => 'en'] : [],
                absolute: false,
            ),
        ]];
    }

    /** @param array<string, mixed> $body */
    public function complete(IdempotencyKey $claim, array $body): void
    {
        $claim->forceFill([
            'response_status' => 201,
            'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
        ])->save();
    }
}
