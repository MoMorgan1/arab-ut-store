<?php

namespace App\Services\Catalog;

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsPricingRule;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

final class CoinsCatalogReader
{
    private const PRICING_GROUPS = ['console_normal', 'console_fast', 'pc'];

    public function __construct(private readonly CoinsPriceCalculator $calculator) {}

    public function product(): Product
    {
        $products = Product::query()
            ->where('service_type', ServiceType::Coins->value)
            ->where('is_visible', true)
            ->whereNull('archived_at')
            ->limit(2)
            ->get();

        return $this->soleOrFail($products, 'Coins product');
    }

    public function variant(Product $product, Platform $platform): ProductVariant
    {
        $variants = $product->variants()
            ->where('service_type', ServiceType::Coins->value)
            ->where('platform', $platform->value)
            ->where('is_active', true)
            ->limit(2)
            ->get();
        $variant = $this->soleOrFail($variants, "{$platform->value} Coins variant");

        if ($variant->market !== $platform->market()) {
            throw new DomainException('The Coins variant market is malformed.');
        }

        return $variant;
    }

    /**
     * @param  list<string>  $requiredGroups
     * @return array<string, CoinsPricingRule>
     */
    public function pricingRules(array $requiredGroups): array
    {
        $records = PriceRule::query()
            ->whereNull('product_variant_id')
            ->whereNull('platform')
            ->where('service_type', ServiceType::Coins->value)
            ->where('is_active', true)
            ->get();
        $rules = [];

        foreach ($records as $record) {
            $configuration = $record->getAttribute('configuration');

            if (! is_array($configuration)) {
                throw new DomainException('The active Coins pricing rule configuration is malformed.');
            }

            $group = $configuration['group'] ?? null;

            if (! is_string($group) || ! in_array($group, self::PRICING_GROUPS, true)) {
                throw new DomainException('The active Coins pricing rule group is malformed.');
            }

            if (! in_array($group, $requiredGroups, true)) {
                continue;
            }

            if (array_key_exists($group, $rules)) {
                throw new DomainException("The active Coins pricing rule [{$group}] is ambiguous.");
            }

            $rules[$group] = CoinsPricingRule::fromConfiguration($configuration, $group);
        }

        foreach ($requiredGroups as $group) {
            if (! array_key_exists($group, $rules)) {
                throw new DomainException("The active Coins pricing rule [{$group}] is unavailable.");
            }
        }

        return $rules;
    }

    public function assertHomepageAvailable(): Product
    {
        $product = $this->product();
        $this->assertLocalizedNames($product);

        foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
            $this->variant($product, $platform);
        }

        $rules = $this->pricingRules(['console_normal', 'console_fast', 'pc']);
        $this->assertPricingCoverage($rules);

        return $product;
    }

    private function assertLocalizedNames(Product $product): void
    {
        foreach (['name_ar', 'name_en'] as $attribute) {
            $name = $product->getAttribute($attribute);

            if (! is_string($name) || preg_match('/\S/u', $name) !== 1) {
                throw new DomainException("The Coins product {$attribute} is blank or malformed.");
            }
        }
    }

    /** @param array<string, CoinsPricingRule> $rules */
    private function assertPricingCoverage(array $rules): void
    {
        $minimum = $this->positiveConfiguredInteger('coins.quantity.minimum');
        $increment = $this->positiveConfiguredInteger('coins.quantity.increment');

        if ($minimum % $increment !== 0) {
            throw new DomainException('The Coins minimum quantity must align with its increment.');
        }

        $normal = $rules['console_normal'];
        $this->assertRuleCoversRange(
            $normal,
            $minimum,
            $this->positiveConfiguredInteger('coins.platforms.playstation.deliveries.normal.maximum'),
        );
        $this->assertRuleCoversRange(
            $rules['console_fast'],
            $minimum,
            $this->positiveConfiguredInteger('coins.platforms.playstation.deliveries.fast.maximum'),
            $normal,
        );
        $this->assertRuleCoversRange(
            $rules['pc'],
            $minimum,
            $this->positiveConfiguredInteger('coins.platforms.pc.maximum'),
        );
    }

    private function assertRuleCoversRange(
        CoinsPricingRule $rule,
        int $minimum,
        int $maximum,
        ?CoinsPricingRule $normalRule = null,
    ): void {
        if ($maximum < $minimum) {
            throw new DomainException('A Coins maximum quantity cannot be below the minimum.');
        }

        $increment = $this->positiveConfiguredInteger('coins.quantity.increment');

        for ($quantity = $minimum; $quantity <= $maximum; $quantity += $increment) {
            $this->calculator->calculate($rule, $quantity, $normalRule);

            if ($quantity > $maximum - $increment) {
                break;
            }
        }
    }

    private function positiveConfiguredInteger(string $key): int
    {
        $value = config($key);

        if (! is_int($value) || $value <= 0) {
            throw new DomainException("The Coins configuration [{$key}] must be a positive integer.");
        }

        return $value;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, TModel>  $models
     * @return TModel
     */
    private function soleOrFail(Collection $models, string $resource): mixed
    {
        if ($models->count() !== 1) {
            throw new DomainException("The active {$resource} is unavailable or ambiguous.");
        }

        return $models->firstOrFail();
    }
}
