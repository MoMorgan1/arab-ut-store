<?php

namespace App\Http\Controllers\Store;

use App\Actions\Cart\PurgeRemovedCartItems;
use App\Actions\Cart\RepriceCart;
use App\Actions\Cart\ResolveCartOwner;
use App\Checkout\DiscountEngine;
use App\Checkout\DiscountResult;
use App\Enums\DeliveryMode;
use App\Enums\Market;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Http\Controllers\Controller;
use App\Marketing\PromotionPrice;
use App\Marketing\PromotionPricing;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\CartItemSecret;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WalletAccount;
use App\Services\Catalog\CoinsCatalogReader;
use App\Support\StoreSuggestions;
use App\ValueObjects\Cart\CartRepricing;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

final class CartController extends Controller
{
    public function __construct(
        private readonly PromotionPricing $promotionPricing,
        private readonly DiscountEngine $discountEngine,
        private readonly RepriceCart $repriceCart,
        private readonly CoinsCatalogReader $coinsCatalog,
        private readonly StoreSuggestions $suggestions,
        private readonly PurgeRemovedCartItems $purgeRemovedCartItems,
    ) {}

    public function __invoke(Request $request, ResolveCartOwner $resolveCartOwner): Response
    {
        $localized = $request->route('locale') === 'en';
        $owner = $resolveCartOwner->forRequest($request);
        $ownCartId = Cart::query()->activeForOwner($owner)->value('id');

        if (is_int($ownCartId)) {
            $this->purgeRemovedCartItems->execute($ownCartId);
        }

        $activeCart = Cart::query()
            ->activeForOwner($owner)
            ->with(['items.secret', 'items.squadImage', 'items.productVariant.product.media', 'items.productVariant.product.category', 'coupon.targets'])
            ->first();

        $user = $request->user();
        $repricing = $activeCart instanceof Cart ? $this->repriceCart->execute($activeCart) : null;
        $allItems = $activeCart instanceof Cart ? $activeCart->items : new Collection;
        $storedTotals = $allItems->mapWithKeys(
            fn (CartItem $cartItem): array => [(int) $cartItem->id => (int) $cartItem->total_halalah],
        )->all();

        if ($activeCart instanceof Cart && $repricing instanceof CartRepricing) {
            $this->applyLivePrices($allItems, $repricing);
            // An unavailable item has no live price, so it must not reach the
            // discount engine - a dead line at its stored total could satisfy a
            // coupon minimum or a bundle tier the cart no longer qualifies for.
            $activeCart->setRelation(
                'items',
                $allItems->filter(fn (CartItem $cartItem): bool => $repricing->for($cartItem)->unavailableReason === null)->values(),
            );
        }

        $discountResult = $activeCart instanceof Cart
            ? $this->discountEngine->calculateForCart($activeCart, $user instanceof User ? $user : null)
            : null;

        $activeCart?->setRelation('items', $allItems);

        $safeCartItems = $allItems
            ->map(fn (CartItem $cartItem): array => $this->safeCartItem(
                $cartItem,
                $localized,
                $discountResult,
                $repricing,
                $storedTotals[(int) $cartItem->id] ?? null,
            ))
            ->values()
            ->all();
        $phoneVerified = $user instanceof User && $user->phone_verified_at !== null;
        $hasUnavailable = $repricing instanceof CartRepricing && $repricing->hasUnavailable();
        $walletBalance = $user instanceof User
            ? (int) (WalletAccount::query()->where('user_id', $user->id)->value('balance_halalah') ?? 0)
            : 0;

        return Inertia::render('store/cart', [
            'coinsRequiresBalance' => $this->coinsCatalog->requiresCurrentBalance(),
            'cartPage' => [
                'checkout' => [
                    'checkoutUrl' => route(
                        $localized ? 'localized.store.checkout.paylink' : 'store.checkout.paylink',
                        $localized ? ['locale' => 'en'] : [],
                        absolute: false,
                    ),
                    'couponApplyUrl' => route(
                        $localized ? 'localized.cart.coupons.store' : 'cart.coupons.store',
                        $localized ? ['locale' => 'en'] : [],
                        absolute: false,
                    ),
                    'couponRemoveUrl' => route(
                        $localized ? 'localized.cart.coupons.destroy' : 'cart.coupons.destroy',
                        $localized ? ['locale' => 'en'] : [],
                        absolute: false,
                    ),
                    'walletToggleUrl' => route(
                        $localized ? 'localized.cart.wallet.store' : 'cart.wallet.store',
                        $localized ? ['locale' => 'en'] : [],
                        absolute: false,
                    ),
                    'walletBalanceHalalah' => $walletBalance,
                    'loginUrl' => route(
                        $localized ? 'localized.login' : 'login',
                        $localized ? ['locale' => 'en'] : [],
                        absolute: false,
                    ),
                    'phoneCodeUrl' => route(
                        $localized ? 'localized.store.checkout.phone.send' : 'store.checkout.phone.send',
                        $localized ? ['locale' => 'en'] : [],
                        absolute: false,
                    ),
                    'phoneVerified' => $phoneVerified,
                    'phoneVerifyUrl' => route(
                        $localized ? 'localized.store.checkout.phone.verify' : 'store.checkout.phone.verify',
                        $localized ? ['locale' => 'en'] : [],
                        absolute: false,
                    ),
                ],
                'translations' => trans('store.cart_page'),
                'suggestions' => fn (): array => $this->suggestions->forCart(
                    $activeCart,
                    $request,
                    app()->getLocale(),
                    (string) ($request->session()->get('display_currency') ?? config('store.default_display_currency')),
                ),
            ],
            'cart' => [
                // Lives here, not in cartPage: every partial reload on this page
                // asks for `cart` only, so eligibility parked in cartPage would
                // stay stale after the customer removes an unavailable item.
                'canCheckout' => $phoneVerified && $safeCartItems !== [] && ! $hasUnavailable,
                'count' => count($safeCartItems),
                'currency' => 'SAR',
                'items' => $safeCartItems,
                'coupon' => $this->safeCoupon($activeCart, $discountResult),
                'useWallet' => $activeCart instanceof Cart ? (bool) $activeCart->use_wallet : false,
            ],
        ]);
    }

    /** @return array{code: string, discountType: string, discountHalalah: int}|null */
    private function safeCoupon(?Cart $cart, ?DiscountResult $discountResult): ?array
    {
        if (! $cart instanceof Cart || ! $cart->coupon instanceof Coupon || ! $cart->coupon->is_active) {
            return null;
        }

        if ($discountResult?->appliedCoupon === null) {
            return null;
        }

        return [
            'code' => $discountResult->appliedCoupon->code,
            'discountType' => $discountResult->appliedCoupon->discountType,
            'discountHalalah' => $discountResult->appliedCoupon->discountHalalah,
        ];
    }

    private function itemPromotion(CartItem $cartItem): ?PromotionPrice
    {
        $variant = $cartItem->productVariant;
        $category = $variant->product->category;

        return $this->promotionPricing->resolve(
            $category?->id,
            $variant->service_type,
            (int) $cartItem->total_halalah,
            $variant->product->id,
        );
    }

    /**
     * Adopt the live price on the loaded models so the page renders, and
     * discounts compute, from what the customer would actually be charged.
     *
     * In memory only. This runs on a GET; nothing here may save. An item that
     * cannot be priced keeps its stored figures and is filtered out by the
     * caller instead.
     *
     * @param  Collection<int, CartItem>  $items
     */
    private function applyLivePrices(Collection $items, CartRepricing $repricing): void
    {
        foreach ($items as $cartItem) {
            $price = $repricing->for($cartItem);

            if (! $price->isPriced()) {
                continue;
            }

            $cartItem->unit_price_halalah = $price->unitPriceHalalah;
            $cartItem->total_halalah = $price->totalHalalah;
        }
    }

    /** @return array<string, mixed> */
    private function safeCartItem(
        CartItem $cartItem,
        bool $localized,
        ?DiscountResult $discountResult = null,
        ?CartRepricing $repricing = null,
        ?int $storedTotalHalalah = null,
    ): array {
        $serviceType = $cartItem->productVariant->service_type;
        $isManualService = in_array($serviceType, [
            ServiceType::FutChampions,
            ServiceType::Rivals,
        ], true);
        $credentials = $isManualService ? null : $this->safeCredentials($cartItem->secret);
        $fulfillment = $isManualService ? $this->safeManualFulfillment($cartItem) : null;
        $unavailableReason = $repricing?->for($cartItem)->unavailableReason;
        // No badge on a dead line: the fallback would resolve a promotion from
        // the stored total of an item that has no live price at all.
        $promotion = $unavailableReason !== null
            ? null
            : ($discountResult?->linePromotion((int) $cartItem->id) ?? $this->itemPromotion($cartItem));
        $priceChanged = $unavailableReason === null
            && $storedTotalHalalah !== null
            && $storedTotalHalalah !== (int) $cartItem->total_halalah;

        return [
            'id' => $cartItem->public_id,
            'quantity' => $cartItem->quantity,
            'unitPriceHalalah' => $cartItem->unit_price_halalah,
            'totalHalalah' => $cartItem->total_halalah,
            'previousTotalHalalah' => $priceChanged ? $storedTotalHalalah : null,
            'priceChanged' => $priceChanged,
            'unavailableReason' => $unavailableReason?->value,
            'promotion' => $promotion === null ? null : [
                'badge' => $this->promotionBadge($promotion),
                'discountHalalah' => $promotion->discountHalalah,
            ],
            'configuration' => $this->safeConfiguration($cartItem->configuration),
            'product' => $this->safeProduct($cartItem->productVariant),
            'credentials' => $credentials,
            'credentialsUrl' => $isManualService ? null : $this->credentialsUrl($cartItem, $localized),
            'deleteUrl' => $this->deleteUrl($cartItem, $localized),
            'editUrl' => $this->editUrl($cartItem, $localized),
            'fulfillment' => $fulfillment,
            'requiresCredentials' => $isManualService
                ? ! $fulfillment['credentialsReady']
                : $credentials === null,
        ];
    }

    private function promotionBadge(PromotionPrice $promotion): string
    {
        $locale = app()->getLocale();
        $localized = trim((string) $promotion->promotion->{"badge_{$locale}"});

        if ($localized !== '') {
            return $localized;
        }

        return trim((string) $promotion->promotion->{'badge_'.($locale === 'ar' ? 'en' : 'ar')});
    }

    /** @return array{credentialsReady: bool, squadImagePresent: bool} */
    private function safeManualFulfillment(CartItem $cartItem): array
    {
        $secret = $cartItem->secret;
        $summary = $secret?->masked_summary;
        $configuration = $cartItem->configuration ?? [];
        $platform = $configuration['platform'] ?? null;
        $store = $configuration['pc_store'] ?? null;

        $credentialsReady = $secret instanceof CartItemSecret
            && $secret->getRawOriginal('encrypted_payload') !== null
            && $secret->getAttribute('deleted_at') === null
            && is_array($summary)
            && ($summary['platform'] ?? null) === $platform
            && ($summary['pc_store'] ?? null) === $store
            && ($summary['ea_backup_code_count'] ?? null) === 3;

        if ($credentialsReady && $platform === Platform::PlayStation->value) {
            $credentialsReady = ($summary['has_playstation_password'] ?? null) === true
                && ($summary['playstation_backup_code_count'] ?? null) === 3
                && ($summary['has_ea_password'] ?? null) === false;
        } elseif ($credentialsReady && $platform === Platform::Pc->value) {
            $credentialsReady = ($summary['has_ea_password'] ?? null) === true
                && ($summary['playstation_backup_code_count'] ?? null) === 0
                && ($store !== 'steam' || ($summary['has_steam_password'] ?? null) === true);
        } else {
            $credentialsReady = false;
        }

        return [
            'credentialsReady' => $credentialsReady,
            'squadImagePresent' => $cartItem->squadImage !== null,
        ];
    }

    /** @return array{imageUrl: string|null, name: string, serviceType: string} */
    private function safeProduct(ProductVariant $variant): array
    {
        $product = $variant->product;

        if (! $product instanceof Product) {
            return ['imageUrl' => null, 'name' => '', 'serviceType' => $variant->service_type->value];
        }

        $service = $product->service_type;

        if ($service === ServiceType::Coins) {
            return [
                'imageUrl' => '/images/store/coins/ut-coin-80.webp',
                'name' => trans('store.cart_page.coins_service'),
                'serviceType' => $service->value,
            ];
        }

        $locale = app()->getLocale();
        $name = trim((string) $product->getAttribute("name_{$locale}"));

        if ($name === '') {
            $fallback = $locale === 'ar' ? 'en' : 'ar';
            $name = trim((string) $product->getAttribute("name_{$fallback}"));
        }

        $imageUrl = $this->safeImageUrl($product->media->first());

        // Manual services carry no catalog media; the cart shows the same
        // artwork the storefront rails use.
        if ($imageUrl === null) {
            $imageUrl = match ($service) {
                ServiceType::Rivals => '/images/store/services/rivals.webp',
                ServiceType::FutChampions => '/images/store/services/fut-champions.webp',
                default => null,
            };
        }

        return [
            'imageUrl' => $imageUrl,
            'name' => $name,
            'serviceType' => $service->value,
        ];
    }

    private function safeImageUrl(?ProductMedia $media): ?string
    {
        if (! $media instanceof ProductMedia || $media->disk !== 'public') {
            return null;
        }

        $path = (string) $media->path;

        if ($path === '' || str_contains($path, '..')
            || preg_match('/\A[A-Za-z0-9_\/.\-]+\z/D', $path) !== 1) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /** @return array{hasPassword: true, backupCodeCount: 3}|null */
    private function safeCredentials(?CartItemSecret $secret): ?array
    {
        if ($secret === null
            || $secret->getRawOriginal('encrypted_payload') === null
            || $secret->getAttribute('deleted_at') !== null) {
            return null;
        }

        $summary = $secret->masked_summary;

        if (! is_array($summary)
            || ($summary['has_password'] ?? null) !== true
            || ($summary['backup_code_count'] ?? null) !== 3) {
            return null;
        }

        return [
            'hasPassword' => true,
            'backupCodeCount' => 3,
        ];
    }

    private function credentialsUrl(CartItem $cartItem, bool $localized): string
    {
        $route = $localized
            ? 'localized.cart.items.credentials.show'
            : 'cart.items.credentials.show';

        return route($route, [
            ...($localized ? ['locale' => 'en'] : []),
            'cartItem' => $cartItem->public_id,
        ], absolute: false);
    }

    private function deleteUrl(CartItem $cartItem, bool $localized): string
    {
        $route = $localized
            ? 'localized.cart.items.destroy'
            : 'cart.items.destroy';

        return route($route, [
            ...($localized ? ['locale' => 'en'] : []),
            'cartItem' => $cartItem->public_id,
        ], absolute: false);
    }

    private function editUrl(CartItem $cartItem, bool $localized): ?string
    {
        $serviceType = $cartItem->productVariant->service_type;
        $configuration = $cartItem->configuration ?? [];

        if ($serviceType === ServiceType::Coins) {
            $home = route(
                $localized ? 'localized.home' : 'home',
                $localized ? ['locale' => 'en'] : [],
                absolute: false,
            );
            $query = array_filter([
                'platform' => $configuration['platform'] ?? null,
                'delivery' => $configuration['delivery'] ?? null,
                'quantity' => $configuration['coins_quantity'] ?? null,
                'replace' => $cartItem->public_id,
            ], fn (mixed $value): bool => $value !== null);

            return $home.'?'.http_build_query($query).'#coins';
        }

        if ($serviceType === ServiceType::Sbc) {
            $slug = $cartItem->productVariant->product?->slug;

            if (! is_string($slug) || $slug === '') {
                return null;
            }

            $url = route(
                $localized ? 'localized.store.sbc.show' : 'store.sbc.show',
                [...($localized ? ['locale' => 'en'] : []), 'slug' => $slug],
                absolute: false,
            );
            $query = array_filter([
                'variant' => $cartItem->productVariant->public_id,
                'completions' => $configuration['completion_count'] ?? null,
                'replace' => $cartItem->public_id,
            ], fn (mixed $value): bool => $value !== null);

            return $url.'?'.http_build_query($query);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $configuration
     * @return array<string, bool|int|string|null>
     */
    private function safeConfiguration(?array $configuration): array
    {
        if ($configuration === null) {
            return [];
        }

        return [
            ...$this->safeEnumField($configuration, 'service_type', ServiceType::cases()),
            ...$this->safeEnumField($configuration, 'platform', Platform::cases()),
            ...$this->safeEnumField($configuration, 'market', Market::cases()),
            ...$this->safeEnumField($configuration, 'delivery', DeliveryMode::cases()),
            ...$this->safeCoinsQuantity($configuration),
            ...$this->safeCompletionCount($configuration),
            ...$this->safeManualServiceConfiguration($configuration),
            ...$this->safeQuotedAt($configuration),
            ...$this->safePriceVersion($configuration),
        ];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, bool|int|string>
     */
    private function safeManualServiceConfiguration(array $configuration): array
    {
        $serviceType = $configuration['service_type'] ?? null;

        if (! in_array($serviceType, [
            ServiceType::FutChampions->value,
            ServiceType::Rivals->value,
        ], true)) {
            return [];
        }

        $safe = [];
        $launcher = $configuration['pc_store'] ?? null;
        $scheduleVersion = $configuration['schedule_version'] ?? null;

        if (in_array($launcher, ['ea_app', 'steam'], true)) {
            $safe['pc_launcher'] = $launcher;
        }

        if (is_int($scheduleVersion) && $scheduleVersion > 0) {
            $safe['schedule_version'] = $scheduleVersion;
        }

        if ($serviceType === ServiceType::FutChampions->value) {
            $rank = $configuration['rank'] ?? null;
            $urgent = $configuration['urgent'] ?? null;
            $matchesPlayed = $configuration['matches_played'] ?? null;

            if (is_int($rank) && $rank >= 1 && $rank <= 6) {
                $safe['target_rank'] = $rank;
            }

            if (is_bool($urgent)) {
                $safe['urgent'] = $urgent;
            }

            if (is_int($matchesPlayed) && $matchesPlayed >= 0 && $matchesPlayed <= 100) {
                $safe['matches_played'] = $matchesPlayed;
            }

            return $safe;
        }

        if (($configuration['mode'] ?? null) === 'weekly_matches') {
            $includedWins = $configuration['included_wins'] ?? null;
            $safe['weekly_matches'] = true;

            if (is_int($includedWins) && $includedWins > 0 && $includedWins <= 100) {
                $safe['included_wins'] = $includedWins;
            }

            return $safe;
        }

        $divisions = ['7', '6', '5', '4', '3', '2', '1', 'elite'];
        $from = $configuration['current_division'] ?? null;
        $to = $configuration['target_division'] ?? null;

        if (is_string($from) && in_array($from, $divisions, true)) {
            $safe['from_division'] = $from;
        }

        if (is_string($to) && in_array($to, $divisions, true)) {
            $safe['to_division'] = $to;
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @param  list<ServiceType|Platform|Market|DeliveryMode>  $allowedCases
     * @return array<string, string|null>
     */
    private function safeEnumField(array $configuration, string $field, array $allowedCases): array
    {
        if (! array_key_exists($field, $configuration)) {
            return [];
        }

        $configurationValue = $configuration[$field];

        if ($configurationValue === null) {
            return [$field => null];
        }

        $allowedValues = array_map(fn ($allowedCase): string => $allowedCase->value, $allowedCases);

        return is_string($configurationValue) && in_array($configurationValue, $allowedValues, true)
            ? [$field => $configurationValue]
            : [];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{coins_quantity?: int}
     */
    private function safeCoinsQuantity(array $configuration): array
    {
        $quantity = $configuration['coins_quantity'] ?? null;
        $maximum = max(
            Config::integer('coins.platforms.playstation.maximum'),
            Config::integer('coins.platforms.pc.maximum'),
        );

        // The live rules, not the config defaults: an admin can move the floor
        // and the rounding unit, and a prefill judged against stale numbers is
        // either dropped when it was buyable or echoed when it was not.
        return is_int($quantity)
            && $quantity <= $maximum
            && $this->coinsCatalog->quantityRules()->accepts($quantity)
                ? ['coins_quantity' => $quantity]
                : [];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{completion_count?: int}
     */
    private function safeCompletionCount(array $configuration): array
    {
        $completionCount = $configuration['completion_count'] ?? null;

        return is_int($completionCount) && $completionCount >= 1 && $completionCount <= 100
            ? ['completion_count' => $completionCount]
            : [];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{quoted_at?: string}
     */
    private function safeQuotedAt(array $configuration): array
    {
        $quotedAt = $configuration['quoted_at'] ?? null;

        if (! is_string($quotedAt)
            || DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $quotedAt) === false
            || DateTimeImmutable::getLastErrors() !== false) {
            return [];
        }

        return ['quoted_at' => $quotedAt];
    }

    /**
     * @param  array<string, mixed>  $configuration
     * @return array{price_version?: int}
     */
    private function safePriceVersion(array $configuration): array
    {
        $priceVersion = $configuration['price_version'] ?? null;

        return is_int($priceVersion) && $priceVersion > 0
            ? ['price_version' => $priceVersion]
            : [];
    }
}
