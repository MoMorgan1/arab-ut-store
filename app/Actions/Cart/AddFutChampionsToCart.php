<?php

namespace App\Actions\Cart;

use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\ProductVariant;
use App\Security\FutChampionsCartFingerprint;
use App\ValueObjects\Cart\CartOwner;
use DomainException;
use Illuminate\Support\Facades\DB;

final readonly class AddFutChampionsToCart
{
    private const SCOPE = 'fut-champions-cart';

    public function __construct(
        private ReadManualServicePricing $readPricing,
        private AcquireActiveCart $acquireActiveCart,
        private AssertVariantNotInCart $assertVariantNotInCart,
        private PersistManualServiceFulfillment $persistFulfillment,
        private ManualServiceCartSupport $support,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array{status: int, body: array<string, mixed>}
     */
    public function execute(CartOwner $owner, array $validated, string $key, string $locale): array
    {
        return DB::transaction(function () use ($owner, $validated, $key, $locale): array {
            $hash = FutChampionsCartFingerprint::generate(
                $owner->databaseKey(),
                $validated,
                (string) config('app.key'),
            );
            $claim = $this->support->claim($owner, $key, self::SCOPE, $hash);
            $replay = $this->support->replay($claim);

            if ($replay !== null) {
                return $replay;
            }

            $pricing = $this->readPricing->futChampions(lock: true);
            $schedule = $pricing['schedule'];

            if ($schedule->version !== $validated['scheduleVersion']) {
                throw new DomainException('The FUT Champions pricing page is stale.');
            }

            $platform = Platform::from($validated['platform']);
            $variant = $this->support->eligibleVariant(ServiceType::FutChampions, $platform);
            $price = $pricing['pricing']->priceForRank((int) $validated['rank'], (bool) $validated['urgent']);
            $cart = $this->acquireActiveCart->execute($owner);
            $this->assertVariantNotInCart->execute($cart, $variant);
            $item = $this->createItem($cart, $variant, $validated, $price, $schedule->version);
            $this->persistFulfillment->execute(
                $item,
                $this->support->credentials($validated),
                $validated['squadImage'],
            );
            $body = $this->support->responseBody($cart, $item, $locale);
            $this->support->complete($claim, $body);

            return ['status' => 201, 'body' => $body];
        }, attempts: 3);
    }

    /** @param array<string, mixed> $validated */
    private function createItem(
        Cart $cart,
        ProductVariant $variant,
        array $validated,
        int $price,
        int $scheduleVersion,
    ): CartItem {
        return $cart->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price_halalah' => $price,
            'total_halalah' => $price,
            'configuration' => [
                'service_type' => ServiceType::FutChampions->value,
                'platform' => $variant->platform->value,
                'market' => $variant->market->value,
                'pc_store' => $validated['pcStore'] ?? null,
                'rank' => (int) $validated['rank'],
                'urgent' => (bool) $validated['urgent'],
                'matches_played' => (int) $validated['matchesPlayed'],
                'quoted_at' => now()->utc()->toIso8601String(),
                'price_version' => $scheduleVersion,
                'schedule_version' => $scheduleVersion,
            ],
        ]);
    }
}
