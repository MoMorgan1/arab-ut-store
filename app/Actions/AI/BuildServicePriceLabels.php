<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\Pricing\ConvertDisplayMoney;
use App\Actions\Pricing\QuoteCoins;
use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Models\ProductVariant;
use App\Support\Money;
use DomainException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Derives live starting prices for the assistant's service cards.
 *
 * Prices are never persisted in the message payload because chat messages persist
 * indefinitely. Instead, starting prices are computed fresh on page render and
 * cached briefly.
 */
final readonly class BuildServicePriceLabels
{
    public function __construct(
        private QuoteCoins $quoteCoins,
        private ReadManualServicePricing $manualPricing,
        private ConvertDisplayMoney $convertDisplayMoney,
        private BuildSbcSuggestions $sbcSuggestions,
    ) {}

    /**
     * @return array<string, array{amountMinor: int, currency: string, unit: string}>
     */
    public function execute(string $displayCurrency): array
    {
        return Cache::remember(
            "chat.service-prices.{$displayCurrency}",
            60,
            function () use ($displayCurrency): array {
                try {
                    $converter = $this->convertDisplayMoney->prepare($displayCurrency);
                } catch (Throwable) {
                    return [];
                }

                $prices = [];

                // coins: unit = 'per_100k'
                try {
                    $coinsHalalah = $this->cheapestCoinsHalalah();
                    $converted = $converter->convert(Money::fromHalalah($coinsHalalah));
                    $prices['coins'] = [
                        'amountMinor' => $converted['amountMinor'],
                        'currency' => $converted['currency'],
                        'unit' => 'per_100k',
                    ];
                } catch (Throwable) {
                    // omit key
                }

                // sbc: unit = 'total'
                try {
                    $sbcHalalah = $this->cheapestSbcHalalah();
                    $converted = $converter->convert(Money::fromHalalah($sbcHalalah));
                    $prices['sbc'] = [
                        'amountMinor' => $converted['amountMinor'],
                        'currency' => $converted['currency'],
                        'unit' => 'total',
                    ];
                } catch (Throwable) {
                    // omit key
                }

                // Each shelved SBC challenge, keyed by its slug, so the shelf
                // can show a real price per card without any of them being
                // frozen into the message.
                foreach ($this->sbcSuggestions->execute(app()->getLocale()) as $item) {
                    try {
                        $converted = $converter->convert(
                            Money::fromHalalah($this->sbcHalalahForSlug($item['id'])),
                        );
                        $prices['sbc:'.$item['id']] = [
                            'amountMinor' => $converted['amountMinor'],
                            'currency' => $converted['currency'],
                            'unit' => 'total',
                        ];
                    } catch (Throwable) {
                        // omit key
                    }
                }

                // rivals: unit = 'total'
                try {
                    $rivalsPricing = $this->manualPricing->rivals()['pricing'];
                    $rivalsHalalah = $rivalsPricing->cheapestStepHalalah();
                    $converted = $converter->convert(Money::fromHalalah($rivalsHalalah));
                    $prices['rivals'] = [
                        'amountMinor' => $converted['amountMinor'],
                        'currency' => $converted['currency'],
                        'unit' => 'total',
                    ];
                } catch (Throwable) {
                    // omit key
                }

                // fut_champions: unit = 'total'
                try {
                    $futPricing = $this->manualPricing->futChampions()['pricing'];
                    $futHalalah = $futPricing->cheapestRankHalalah();
                    $converted = $converter->convert(Money::fromHalalah($futHalalah));
                    $prices['fut_champions'] = [
                        'amountMinor' => $converted['amountMinor'],
                        'currency' => $converted['currency'],
                        'unit' => 'total',
                    ];
                } catch (Throwable) {
                    // omit key
                }

                return $prices;
            },
        );
    }

    private function cheapestCoinsHalalah(): int
    {
        $quotes = [];

        try {
            $quotes[] = $this->quoteCoins->execute(
                Platform::PlayStation,
                DeliveryMode::Normal,
                100_000,
            )->total->halalah();
        } catch (Throwable) {
            // PlayStation quote unavailable
        }

        try {
            $quotes[] = $this->quoteCoins->execute(
                Platform::Pc,
                null,
                100_000,
            )->total->halalah();
        } catch (Throwable) {
            // PC quote unavailable
        }

        if ($quotes === []) {
            throw new DomainException('No active coins quote available.');
        }

        return min($quotes);
    }

    private function cheapestSbcHalalah(): int
    {
        $lowest = ProductVariant::query()
            ->where('is_active', true)
            ->whereRaw('COALESCE(admin_price_halalah, sale_price_halalah, price_halalah) > 0')
            ->whereHas('product', function ($query): void {
                $query->where('service_type', ServiceType::Sbc)
                    ->where('is_visible', true)
                    ->whereNull('archived_at')
                    ->where(function ($categoryQuery): void {
                        $categoryQuery->whereNull('category_id')
                            ->orWhereHas('category', fn ($category) => $category->where('is_visible', true));
                    });
            })
            ->selectRaw('MIN(COALESCE(admin_price_halalah, sale_price_halalah, price_halalah)) as lowest_price')
            ->value('lowest_price');

        if ($lowest === null || ! is_numeric($lowest) || (int) $lowest <= 0) {
            throw new DomainException('No active SBC products available.');
        }

        return (int) $lowest;
    }

    /**
     * The cheapest active variant of one SBC challenge, so a shelf card shows
     * the price the customer would actually start from on that product's page.
     */
    private function sbcHalalahForSlug(string $slug): int
    {
        $lowest = ProductVariant::query()
            ->where('is_active', true)
            ->whereRaw('COALESCE(admin_price_halalah, sale_price_halalah, price_halalah) > 0')
            ->whereHas('product', function ($query) use ($slug): void {
                $query->where('service_type', ServiceType::Sbc)
                    ->where('slug', $slug)
                    ->where('is_visible', true)
                    ->whereNull('archived_at')
                    ->where(function ($categoryQuery): void {
                        $categoryQuery->whereNull('category_id')
                            ->orWhereHas('category', fn ($category) => $category->where('is_visible', true));
                    });
            })
            ->selectRaw('MIN(COALESCE(admin_price_halalah, sale_price_halalah, price_halalah)) as lowest_price')
            ->value('lowest_price');

        if ($lowest === null || ! is_numeric($lowest) || (int) $lowest <= 0) {
            throw new DomainException('No active variant for this SBC challenge.');
        }

        return (int) $lowest;
    }
}
