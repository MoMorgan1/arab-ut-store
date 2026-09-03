<?php

namespace App\Actions\Cart;

use App\Actions\Pricing\QuoteCoins;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Exceptions\Cart\ReplacedCartItemMissing;
use App\Exceptions\IdempotencyConflict;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\IdempotencyKey;
use App\Models\ProductVariant;
use App\Security\CoinsCartFingerprint;
use App\ValueObjects\Cart\CartOwner;
use App\ValueObjects\Pricing\CoinsQuote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

final readonly class AddCoinsToCart
{
    private const SCOPE = 'coins-cart';

    public function __construct(
        private QuoteCoins $quoteCoins,
        private AcquireActiveCart $acquireActiveCart,
        private AssertVariantNotInCart $assertVariantNotInCart,
        private PersistCartItemCredentials $persistCredentials,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function execute(CartOwner $owner, array $validated, string $idempotencyKey, string $locale): array
    {
        return DB::transaction(fn (): array => $this->store(
            $owner,
            $validated,
            $idempotencyKey,
            $locale,
        ), attempts: 3);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status: int, body: array<string, mixed>}
     */
    private function store(CartOwner $owner, array $validated, string $idempotencyKey, string $locale): array
    {
        $idempotencyScope = self::SCOPE.':'.$owner->idempotencyScope();
        $requestHash = CoinsCartFingerprint::generate(
            $owner->databaseKey(),
            $validated,
            (string) config('app.key'),
        );
        $idempotencyClaim = $this->claim($idempotencyKey, $idempotencyScope, $requestHash);

        if ($idempotencyClaim->response_status !== null && $idempotencyClaim->response_body !== null) {
            return $this->replay($idempotencyClaim);
        }

        $quote = $this->quote($validated);
        $productVariant = ProductVariant::where('public_id', $quote->variantId)->sole();
        $activeCart = $this->acquireActiveCart->execute($owner);
        $this->softRemoveReplaced($activeCart, $validated['replaceCartItemId'] ?? null);
        $this->assertVariantNotInCart->execute($activeCart, $productVariant);
        $cartItem = $this->createCartItem($activeCart, $productVariant, $quote);
        $this->persistCredentials->execute($cartItem, $validated['credentials']);
        $safeResponseBody = $this->responseBody($activeCart, $cartItem, $quote, $locale);
        $this->completeClaim($idempotencyClaim, $safeResponseBody);

        return ['status' => 201, 'body' => $safeResponseBody];
    }

    private function claim(string $idempotencyKey, string $idempotencyScope, string $requestHash): IdempotencyKey
    {
        DB::table('idempotency_keys')->insertOrIgnore([
            'public_id' => (string) Str::ulid(),
            'key' => $idempotencyKey,
            'scope' => $idempotencyScope,
            'request_hash' => $requestHash,
            'expires_at' => now()->addDay(),
            'created_at' => now(),
        ]);

        $idempotencyClaim = IdempotencyKey::where('key', $idempotencyKey)->lockForUpdate()->firstOrFail();

        if ($idempotencyClaim->scope !== $idempotencyScope
            || ! hash_equals((string) $idempotencyClaim->request_hash, $requestHash)) {
            throw new IdempotencyConflict;
        }

        return $idempotencyClaim;
    }

    /** @return array{status: int, body: array<string, mixed>} */
    private function replay(IdempotencyKey $idempotencyClaim): array
    {
        try {
            $safeResponseBody = json_decode(
                (string) $idempotencyClaim->response_body,
                true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (JsonException) {
            throw new IdempotencyConflict;
        }

        if (! is_array($safeResponseBody)) {
            throw new IdempotencyConflict;
        }

        return ['status' => (int) $idempotencyClaim->response_status, 'body' => $safeResponseBody];
    }

    /** @param array<string, mixed> $validated */
    private function quote(array $validated): CoinsQuote
    {
        return $this->quoteCoins->execute(
            Platform::from((string) $validated['platform']),
            isset($validated['delivery']) ? DeliveryMode::from((string) $validated['delivery']) : null,
            (int) $validated['quantity'],
        );
    }

    private function softRemoveReplaced(Cart $activeCart, mixed $replaceCartItemId): void
    {
        if (! is_string($replaceCartItemId) || $replaceCartItemId === '') {
            return;
        }

        $replaced = CartItem::query()
            ->where('public_id', $replaceCartItemId)
            ->where('cart_id', $activeCart->id)
            ->lockForUpdate()
            ->first();

        if (! $replaced instanceof CartItem) {
            throw new ReplacedCartItemMissing('The replaced cart item is unavailable.');
        }

        $replaced->update(['removed_at' => now()]);
    }

    private function createCartItem(Cart $activeCart, ProductVariant $productVariant, CoinsQuote $quote): CartItem
    {
        return $activeCart->items()->create([
            'product_variant_id' => $productVariant->id,
            'quantity' => 1,
            'unit_price_halalah' => $quote->total->halalah(),
            'total_halalah' => $quote->total->halalah(),
            'configuration' => [
                'service_type' => ServiceType::Coins->value,
                'platform' => $quote->platform->value,
                'market' => $quote->platform->market()->value,
                'delivery' => $quote->delivery?->value,
                'coins_quantity' => $quote->quantity,
                'quoted_at' => $quote->pricedAt->utc()->toIso8601String(),
                'price_version' => (int) $productVariant->price_version,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function responseBody(Cart $activeCart, CartItem $cartItem, CoinsQuote $quote, string $locale): array
    {
        $cartRoute = $locale === 'en' ? 'localized.store.cart' : 'store.cart';
        $routeParameters = $locale === 'en' ? ['locale' => 'en'] : [];

        return ['data' => [
            'cartItemId' => $cartItem->public_id,
            'cartCount' => $activeCart->items()->count(),
            'cartTotalHalalah' => (int) $activeCart->items()->sum('total_halalah'),
            'cartUrl' => route($cartRoute, $routeParameters, absolute: false),
            'quote' => $this->quoteSummary($quote),
        ]];
    }

    /** @return array<string, mixed> */
    private function quoteSummary(CoinsQuote $quote): array
    {
        return [
            'platform' => $quote->platform->value,
            'market' => $quote->platform->market()->value,
            'delivery' => $quote->delivery?->value,
            'quantity' => $quote->quantity,
            'total' => [
                'amountHalalah' => $quote->total->halalah(),
                'currency' => 'SAR',
            ],
            'pricedAt' => $quote->pricedAt->utc()->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $safeResponseBody */
    private function completeClaim(IdempotencyKey $idempotencyClaim, array $safeResponseBody): void
    {
        $idempotencyClaim->forceFill([
            'response_status' => 201,
            'response_body' => json_encode($safeResponseBody, JSON_THROW_ON_ERROR),
        ])->save();
    }
}
