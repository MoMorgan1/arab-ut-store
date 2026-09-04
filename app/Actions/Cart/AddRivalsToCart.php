<?php

namespace App\Actions\Cart;

use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Exceptions\Cart\ReplacedCartItemMissing;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\FulfillmentAttachment;
use App\Models\ProductVariant;
use App\Security\RivalsCartFingerprint;
use App\ValueObjects\Cart\CartOwner;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

final readonly class AddRivalsToCart
{
    private const SCOPE = 'rivals-cart';

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
            $hash = RivalsCartFingerprint::generate(
                $owner->databaseKey(),
                $validated,
                (string) config('app.key'),
            );
            $claim = $this->support->claim($owner, $key, self::SCOPE, $hash);
            $replay = $this->support->replay($claim);

            if ($replay !== null) {
                return $replay;
            }

            $pricing = $this->readPricing->rivals(lock: true);
            $schedule = $pricing['schedule'];

            if ($schedule->version !== $validated['scheduleVersion']) {
                throw new DomainException('The Rivals pricing page is stale.');
            }

            $platform = Platform::from($validated['platform']);
            $variant = $this->support->eligibleVariant(ServiceType::Rivals, $platform);
            $rules = $pricing['pricing'];
            $weeklyMatches = $validated['mode'] === 'weekly_matches';

            // offersWeeklyMatches() is false until an admin sets both the price
            // and the included wins, and the storefront hides the option until
            // then. A request arriving anyway is refused rather than priced at
            // a number nobody chose.
            if ($weeklyMatches && ! $rules->offersWeeklyMatches()) {
                throw new DomainException('Rivals weekly matches are not on sale.');
            }

            $price = $weeklyMatches
                ? $rules->weeklyMatchesPriceHalalah()
                : $rules->priceForRoute($validated['currentDivision'], $validated['targetDivision']);
            $cart = $this->acquireActiveCart->execute($owner);
            $replaced = $this->softRemoveReplaced($cart, $validated['replaceCartItemId'] ?? null);
            $this->assertVariantNotInCart->execute($cart, $variant);
            $item = $this->createItem(
                $cart,
                $variant,
                $validated,
                $price,
                $schedule->version,
                $weeklyMatches ? $rules->weeklyMatchesIncludedWins() : null,
            );
            $this->persistFulfillment($item, $validated, $replaced);
            $body = $this->support->responseBody($cart, $item, $locale);
            $this->support->complete($claim, $body);

            return ['status' => 201, 'body' => $body];
        }, attempts: 3);
    }

    /** @param array<string, mixed> $validated */
    private function persistFulfillment(CartItem $item, array $validated, ?CartItem $replaced): void
    {
        $credentials = $this->support->credentials($validated);
        $image = $validated['squadImage'] ?? null;

        if ($image instanceof UploadedFile) {
            $this->persistFulfillment->execute($item, $credentials, $image);

            return;
        }

        $source = $replaced?->squadImage()->first();

        if (! $source instanceof FulfillmentAttachment) {
            throw new DomainException('The kept squad image is no longer available.');
        }

        $this->persistFulfillment->executeWithCarriedImage($item, $credentials, $source);
    }

    /**
     * Soft-removes the owner's line being replaced, so the new line can
     * take its variant and the old one stays restorable in the undo window.
     */
    private function softRemoveReplaced(Cart $cart, mixed $replaceCartItemId): ?CartItem
    {
        if (! is_string($replaceCartItemId) || $replaceCartItemId === '') {
            return null;
        }

        $replaced = CartItem::query()
            ->where('public_id', $replaceCartItemId)
            ->where('cart_id', $cart->id)
            ->lockForUpdate()
            ->first();

        if (! $replaced instanceof CartItem) {
            throw new ReplacedCartItemMissing('The replaced cart item is unavailable.');
        }

        $replaced->update(['removed_at' => now()]);

        return $replaced;
    }

    /** @param array<string, mixed> $validated */
    private function createItem(
        Cart $cart,
        ProductVariant $variant,
        array $validated,
        int $price,
        int $scheduleVersion,
        ?int $includedWins,
    ): CartItem {
        return $cart->items()->create([
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'unit_price_halalah' => $price,
            'total_halalah' => $price,
            'configuration' => [
                'service_type' => ServiceType::Rivals->value,
                'platform' => $variant->platform->value,
                'market' => $variant->market->value,
                'pc_store' => $validated['pcStore'] ?? null,
                'mode' => $validated['mode'],
                'current_division' => $validated['currentDivision'] ?? null,
                'target_division' => $validated['targetDivision'] ?? null,
                // Frozen with the order: what the week included when it was
                // bought, so a later settings change never rewrites the promise.
                'included_wins' => $includedWins,
                'quoted_at' => now()->utc()->toIso8601String(),
                'price_version' => $scheduleVersion,
                'schedule_version' => $scheduleVersion,
            ],
        ]);
    }
}
