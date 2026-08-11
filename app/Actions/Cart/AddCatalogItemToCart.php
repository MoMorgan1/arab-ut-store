<?php

namespace App\Actions\Cart;

use App\Enums\ServiceType;
use App\Exceptions\IdempotencyConflict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\IdempotencyKey;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Security\CatalogCartFingerprint;
use App\ValueObjects\Cart\CartOwner;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class AddCatalogItemToCart
{
    private const SCOPE = 'catalog-cart';

    public function __construct(private AcquireActiveCart $acquireActiveCart) {}

    /** @return array{status: int, body: array<string, mixed>} */
    public function execute(
        CartOwner $owner,
        string $variantPublicId,
        string $idempotencyKey,
        string $locale,
    ): array {
        return DB::transaction(fn (): array => $this->store(
            $owner,
            $variantPublicId,
            $idempotencyKey,
            $locale,
        ), attempts: 3);
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function store(
        CartOwner $owner,
        string $variantPublicId,
        string $idempotencyKey,
        string $locale,
    ): array {
        $scope = self::SCOPE.':'.$owner->idempotencyScope();
        $hash = CatalogCartFingerprint::generate($owner->databaseKey(), $variantPublicId, (string) config('app.key'));
        $claim = $this->claim($idempotencyKey, $scope, $hash);

        if ($claim->response_status !== null && $claim->response_body !== null) {
            return $this->replay($claim);
        }

        $variant = $this->eligibleVariant($variantPublicId);
        $price = $this->effectivePrice($variant);
        $cart = $this->acquireActiveCart->execute($owner);
        $item = $this->createItem($cart, $variant, $price);
        $body = $this->responseBody($cart, $item, $locale);
        $this->completeClaim($claim, $body);

        return ['status' => 201, 'body' => $body];
    }

    private function eligibleVariant(string $publicId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->where('public_id', $publicId)
            ->where('is_active', true)
            ->where('service_type', '!=', ServiceType::Coins)
            ->whereHas('product', fn ($query) => $query
                ->where('is_visible', true)
                ->whereNull('archived_at'))
            ->with('product')
            ->lockForUpdate()
            ->first();

        if (! $variant instanceof ProductVariant || ! $variant->product instanceof Product
            || $variant->product->service_type === ServiceType::Coins
            || $variant->product->service_type !== $variant->service_type) {
            throw new DomainException('The catalog variant is unavailable.');
        }

        return $variant;
    }

    private function effectivePrice(ProductVariant $variant): int
    {
        $salePrice = $variant->getAttribute('sale_price_halalah');
        $price = is_int($salePrice) ? $salePrice : (int) $variant->getAttribute('price_halalah');

        if ($price <= 0) {
            throw new DomainException('The catalog variant price is unavailable.');
        }

        return $price;
    }

    private function createItem(Cart $cart, ProductVariant $variant, int $price): CartItem
    {
        return $cart->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price_halalah' => $price,
            'total_halalah' => $price,
            'configuration' => [
                'service_type' => $variant->service_type->value,
                'platform' => $variant->platform->value,
                'market' => $variant->market->value,
                'quoted_at' => now()->utc()->toIso8601String(),
                'price_version' => (int) $variant->getAttribute('price_version'),
            ],
        ]);
    }

    private function claim(string $key, string $scope, string $hash): IdempotencyKey
    {
        DB::table('idempotency_keys')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'key' => $key,
            'scope' => $scope,
            'request_hash' => $hash,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);

        $claim = IdempotencyKey::where('key', $key)->lockForUpdate()->firstOrFail();

        if ($claim->scope !== $scope || ! hash_equals((string) $claim->request_hash, $hash)) {
            throw new IdempotencyConflict;
        }

        return $claim;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function replay(IdempotencyKey $claim): array
    {
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

    /** @return array<string, mixed> */
    private function responseBody(Cart $cart, CartItem $item, string $locale): array
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
    private function completeClaim(IdempotencyKey $claim, array $body): void
    {
        $claim->forceFill([
            'response_status' => 201,
            'response_body' => json_encode($body, JSON_THROW_ON_ERROR),
        ])->save();
    }
}
