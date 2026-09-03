<?php

namespace App\Actions\Cart;

use App\Enums\ServiceType;
use App\Exceptions\IdempotencyConflict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\IdempotencyKey;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Security\SbcCartFingerprint;
use App\ValueObjects\Cart\CartOwner;
use App\ValueObjects\Pricing\SbcCompletionPricing;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class AddSbcToCart
{
    private const SCOPE = 'sbc-cart';

    public function __construct(
        private AcquireActiveCart $acquireActiveCart,
        private AssertVariantNotInCart $assertVariantNotInCart,
        private PersistCartItemCredentials $persistCredentials,
    ) {}

    /** @param array<string, mixed> $validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function execute(CartOwner $owner, array $validated, string $key, string $locale): array
    {
        return DB::transaction(
            fn (): array => $this->store($owner, $validated, $key, $locale),
            attempts: 3,
        );
    }

    /** @param array<string, mixed> $validated
     * @return array{status: int, body: array<string, mixed>}
     */
    private function store(CartOwner $owner, array $validated, string $key, string $locale): array
    {
        $scope = self::SCOPE.':'.$owner->idempotencyScope();
        $hash = SbcCartFingerprint::generate($owner->databaseKey(), $validated, (string) config('app.key'));
        $claim = $this->claim($key, $scope, $hash);

        if ($claim->response_status !== null && $claim->response_body !== null) {
            return $this->replay($claim);
        }

        $variant = $this->eligibleVariant((string) $validated['variantId']);
        $completionCount = (int) $validated['completionCount'];
        $pricing = SbcCompletionPricing::fromConfiguration(
            $variant->effectivePricingConfiguration(),
            $this->effectivePrice($variant),
            requireDeclared: false,
        );
        $price = $pricing->tierTotal($completionCount);

        if ($price === null) {
            throw new DomainException('The selected SBC completion tier is unavailable.');
        }

        $cart = $this->acquireActiveCart->execute($owner);
        $this->assertVariantNotInCart->execute($cart, $variant);
        $item = $this->createItem($cart, $variant, $price, $completionCount);
        $this->persistCredentials->execute($item, $validated['credentials']);
        $body = $this->responseBody($cart, $item, $locale);
        $this->completeClaim($claim, $body);

        return ['status' => 201, 'body' => $body];
    }

    private function eligibleVariant(string $publicId): ProductVariant
    {
        $variant = ProductVariant::query()
            ->where('public_id', $publicId)
            ->where('service_type', ServiceType::Sbc)
            ->where('is_active', true)
            ->whereHas('product', function (Builder $query): void {
                $query->where('service_type', ServiceType::Sbc);
                Product::applyStorefrontVisible($query);
            })
            ->with('product')
            ->lockForUpdate()
            ->first();

        if (! $variant instanceof ProductVariant || ! $variant->product instanceof Product) {
            throw new DomainException('The SBC variant is unavailable.');
        }

        if ((int) $variant->getAttribute('price_version') < 1) {
            throw new DomainException('The SBC variant price version is unavailable.');
        }

        return $variant;
    }

    private function effectivePrice(ProductVariant $variant): int
    {
        $price = $variant->effectivePriceHalalah();

        if ($price <= 0) {
            throw new DomainException('The SBC variant price is unavailable.');
        }

        return $price;
    }

    private function createItem(
        Cart $cart,
        ProductVariant $variant,
        int $price,
        int $completionCount,
    ): CartItem {
        return $cart->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price_halalah' => $price,
            'total_halalah' => $price,
            'configuration' => [
                'service_type' => ServiceType::Sbc->value,
                'platform' => $variant->platform->value,
                'market' => $variant->market->value,
                'completion_count' => $completionCount,
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
            'cartTotalHalalah' => (int) $cart->items()->sum('total_halalah'),
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
