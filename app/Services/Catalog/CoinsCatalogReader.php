<?php

namespace App\Services\Catalog;

use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\PriceRule;
use App\Models\PriceRun;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ServicePriceSchedule;
use App\Services\Pricing\CoinsPriceCalculator;
use App\ValueObjects\Pricing\CoinsPricingRule;
use App\ValueObjects\Pricing\CoinsQuantityRules;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;

final class CoinsCatalogReader
{
    private const PRICING_GROUPS = ['console_normal', 'console_fast', 'pc'];

    /** @var array<string, mixed>|null */
    private ?array $legalRanges = null;

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
        $this->assertHomepageProduct($product);

        foreach ([Platform::PlayStation, Platform::Pc] as $platform) {
            $this->variant($product, $platform);
        }

        $rules = $this->pricingRules(['console_normal', 'console_fast', 'pc']);
        $this->assertPricingCoverage($rules);

        return $product;
    }

    public function assertHomepageProduct(Product $product): void
    {
        $this->assertLocalizedNames($product);
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

    private ?CoinsQuantityRules $quantityRules = null;

    /** @var array<string, mixed>|null */
    private ?array $coinsConfiguration = null;

    /**
     * Resolved once per instance: the homepage builds a schedule per platform
     * and delivery speed, and each one asks for the bounds.
     */
    public function quantityRules(): CoinsQuantityRules
    {
        return $this->quantityRules ??= CoinsQuantityRules::fromConfiguration(
            $this->coinsConfiguration(),
        );
    }

    /**
     * The largest quantity of this group a supplier could actually deliver
     * when prices were last published, or null when the run did not say.
     *
     * The catalogue ceiling in config is what the store is willing to sell at
     * its widest; this is what the market could answer on the hour. They are
     * different questions, and conflating them is how a storefront ends up
     * offering twenty million coins on a day the whole pool holds thirty
     * thousand. The narrower of the two wins, and null means the run predates
     * this field, so the configured ceiling stands unchanged.
     */
    public function availableMaximum(string $group): ?int
    {
        $ranges = $this->appliedLegalRanges();
        $maximum = $ranges[$group]['maximum'] ?? null;

        return is_int($maximum) && $maximum > 0 ? $maximum : null;
    }

    /**
     * Read once per request. Every platform and delivery asks this, and the
     * answer cannot change between two questions on the same page.
     *
     * @return array<string, mixed>
     */
    private function appliedLegalRanges(): array
    {
        if ($this->legalRanges !== null) {
            return $this->legalRanges;
        }

        $payload = PriceRun::query()
            ->where('status', 'applied')
            ->latest('id')
            ->value('payload');

        $ranges = is_array($payload) ? ($payload['legalRanges'] ?? null) : null;

        return $this->legalRanges = is_array($ranges) ? $ranges : [];
    }

    /**
     * Whether fast console orders must state the account's current Coins
     * balance on the credentials step. Absent means no: the field stays off
     * until an admin turns it on, and a fresh database fails closed the same
     * way.
     */
    public function requiresCurrentBalance(): bool
    {
        return ($this->coinsConfiguration()['requiresCurrentBalance'] ?? null) === true;
    }

    /** @return array<string, mixed> */
    private function coinsConfiguration(): array
    {
        if ($this->coinsConfiguration !== null) {
            return $this->coinsConfiguration;
        }

        // The admin edits these; config carries the seeded default so a fresh
        // database, and every test that never seeds one, still has bounds.
        $schedule = ServicePriceSchedule::query()
            ->where('service_type', ServiceType::Coins)
            ->where('is_active', true)
            ->first();

        return $this->coinsConfiguration = $schedule === null
            ? (array) Config::array('coins.quantity')
            : (array) $schedule->configuration;
    }

    /** @param array<string, CoinsPricingRule> $rules */
    private function assertPricingCoverage(array $rules): void
    {
        $minimum = $this->quantityRules()->minimum();

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

        $this->calculator->calculate($rule, $minimum, $normalRule);
        $this->calculator->calculate($rule, $maximum, $normalRule);

        foreach ($this->quantityRules()->sliderStops() as $quantity) {
            if ($quantity < $minimum) {
                continue;
            }

            if ($quantity > $maximum) {
                break;
            }

            $this->calculator->calculate($rule, $quantity, $normalRule);
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
