<?php

declare(strict_types=1);

namespace App\Support\Feeds;

use App\Account\Presenters\ItemArtwork;
use App\Actions\Pricing\QuoteCoins;
use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Catalog\CoinsCatalogReader;
use DomainException;
use InvalidArgumentException;
use ValueError;

/**
 * The product feed Meta's catalogue reads.
 *
 * One row per variant a customer can buy, keyed on the variant SKU - the same
 * id the purchase event already sends - so an ad, a page view and a sale all
 * name the same thing. Every price is the cheapest a customer could pay for
 * that variant: one completion for a challenge, the smallest coin order, the
 * cheapest rung of a manual service. A variant whose price cannot be resolved
 * is left out rather than advertised at a guess, because Meta compares the
 * price in the feed against the price on the page it links to.
 */
final class MetaCatalogFeed
{
    /** The columns Meta reads, in the order the file writes them. */
    public const COLUMNS = [
        'id',
        'title',
        'description',
        'availability',
        'condition',
        'price',
        'link',
        'image_link',
        'brand',
        'item_group_id',
    ];

    private const BRAND = 'Arab UT';

    private const CURRENCY = 'SAR';

    /** Meta truncates a longer title itself; we would rather choose where. */
    private const TITLE_LIMIT = 150;

    private const DESCRIPTION_LIMIT = 2000;

    private const RIVALS_LADDER = ['7', '6', '5', '4', '3', '2', '1', 'elite'];

    private const CHAMPIONS_RANKS = [1, 2, 3, 4, 5, 6];

    /** @var array{rivals: int|null, champions: int|null}|null */
    private ?array $manualFromPrices = null;

    public function __construct(
        private readonly QuoteCoins $quoteCoins,
        private readonly CoinsCatalogReader $coinsCatalog,
        private readonly ReadManualServicePricing $manualPricing,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function rows(): array
    {
        $rows = [];

        $products = Product::query()
            ->storefrontVisible()
            ->with(['variants', 'media'])
            ->orderBy('id')
            ->get();

        foreach ($products as $product) {
            $link = $this->link($product);

            if ($link === null) {
                continue;
            }

            $image = $this->image($product);

            foreach ($product->variants as $variant) {
                if (! $variant->is_active) {
                    continue;
                }

                $price = $this->priceHalalah($product, $variant);

                if ($price === null || $price <= 0) {
                    continue;
                }

                $rows[] = $this->row($product, $variant, $price, $link, $image);
            }
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function row(
        Product $product,
        ProductVariant $variant,
        int $priceHalalah,
        string $link,
        string $image,
    ): array {
        return [
            'id' => (string) $variant->sku,
            'title' => $this->title($product, $variant),
            'description' => $this->description($product, $variant),
            'availability' => 'in stock',
            'condition' => 'new',
            'price' => number_format($priceHalalah / 100, 2, '.', '').' '.self::CURRENCY,
            'link' => $link,
            'image_link' => $image,
            'brand' => self::BRAND,
            'item_group_id' => (string) $product->slug,
        ];
    }

    /**
     * The cheapest a customer could pay for this variant.
     *
     * A configured service carries no single price on its row, so each service
     * answers for itself; only the catalogue services hold one outright.
     */
    private function priceHalalah(Product $product, ProductVariant $variant): ?int
    {
        return match ($product->service_type) {
            ServiceType::Coins => $this->coinsFrom($variant),
            ServiceType::Rivals => $this->manualFrom()['rivals'],
            ServiceType::FutChampions => $this->manualFrom()['champions'],
            default => $variant->effectivePriceHalalah(),
        };
    }

    /**
     * The smallest coin order a customer may place, at today's rate.
     *
     * A quote is only accepted with the delivery mode its platform allows: PC
     * has none, a console must name one, and normal delivery is the cheaper of
     * the two. Anything the pricing cannot answer leaves coins out of the feed
     * rather than breaking the file for every other row.
     */
    private function coinsFrom(ProductVariant $variant): ?int
    {
        $delivery = $variant->platform === Platform::Pc ? null : DeliveryMode::Normal;

        try {
            return $this->quoteCoins
                ->execute($variant->platform, $delivery, $this->coinsCatalog->quantityRules()->minimum())
                ->total
                ->halalah();
        } catch (DomainException|InvalidArgumentException|ValueError) {
            return null;
        }
    }

    /**
     * The cheapest single rung of each manual service, read once.
     *
     * @return array{rivals: int|null, champions: int|null}
     */
    private function manualFrom(): array
    {
        if ($this->manualFromPrices === null) {
            $this->manualFromPrices = [
                'rivals' => $this->cheapestRivalsStep(),
                'champions' => $this->cheapestChampionsRank(),
            ];
        }

        return $this->manualFromPrices;
    }

    private function cheapestRivalsStep(): ?int
    {
        try {
            $pricing = $this->manualPricing->rivals()['pricing'];
        } catch (DomainException) {
            return null;
        }

        $ladder = self::RIVALS_LADDER;
        $prices = [];

        foreach (array_slice($ladder, 0, -1) as $index => $from) {
            $price = $pricing->priceForRoute($from, $ladder[$index + 1]);

            if ($price > 0) {
                $prices[] = $price;
            }
        }

        return $prices === [] ? null : min($prices);
    }

    private function cheapestChampionsRank(): ?int
    {
        try {
            $pricing = $this->manualPricing->futChampions()['pricing'];
        } catch (DomainException) {
            return null;
        }

        $prices = [];

        foreach (self::CHAMPIONS_RANKS as $rank) {
            $price = $pricing->priceForRank($rank, false);

            if ($price > 0) {
                $prices[] = $price;
            }
        }

        return $prices === [] ? null : min($prices);
    }

    /** The page the ad sends the customer to. */
    private function link(Product $product): ?string
    {
        $slug = (string) $product->slug;

        return match ($product->service_type) {
            // Coins live on the home page, under the configurator's anchor.
            ServiceType::Coins => route('home').'#coins',
            ServiceType::Rivals => route('store.rivals'),
            ServiceType::FutChampions => route('store.fut_champions'),
            ServiceType::Sbc, ServiceType::Objectives => $slug === ''
                ? null
                : route("store.{$product->service_type->value}.show", ['slug' => $slug]),
        };
    }

    private function image(Product $product): string
    {
        $path = ItemArtwork::forProduct($product);

        return str_starts_with($path, 'http') ? $path : url($path);
    }

    private function title(Product $product, ProductVariant $variant): string
    {
        $productName = trim((string) $product->name_ar);
        $variantName = trim((string) $variant->name_ar);
        $title = $variantName === '' || $variantName === $productName
            ? $productName
            : "{$productName} - {$variantName}";

        return $this->clamp($title, self::TITLE_LIMIT);
    }

    private function description(Product $product, ProductVariant $variant): string
    {
        $description = trim((string) $product->description_ar);

        if ($description === '') {
            $description = $this->title($product, $variant);
        }

        return $this->clamp(
            trim((string) preg_replace('/\s+/u', ' ', $description)),
            self::DESCRIPTION_LIMIT,
        );
    }

    private function clamp(string $value, int $limit): string
    {
        return mb_strlen($value) <= $limit
            ? $value
            : mb_substr($value, 0, $limit - 1).'…';
    }
}
