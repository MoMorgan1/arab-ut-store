<?php

namespace App\Actions\AI;

use App\Actions\Catalog\StoreCatalogReader;
use App\Actions\Pricing\ConvertDisplayMoney;
use App\Actions\Pricing\QuoteCoins;
use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use App\Enums\ServiceType;
use App\Support\Money;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The store's current prices, rendered as a compact table for the assistant.
 *
 * The assistant is allowed to quote these numbers because they are read from
 * the catalogue at answer time — it is repeating a store fact, not inventing
 * one. Anything outside this table stays unanswerable: the model is told to
 * send the customer to the product page rather than interpolate a price.
 */
final readonly class BuildLivePriceContext
{
    /** Quantities a customer actually asks about, in coins. */
    private const COIN_QUANTITIES = [100_000, 500_000, 1_000_000, 2_000_000, 5_000_000];

    /** The Rivals ladder, cheapest division first. */
    private const DIVISIONS = ['7', '6', '5', '4', '3', '2', '1'];

    /** How many SBC challenges to name before the list stops being useful. */
    private const SBC_LIMIT = 5;

    /** FUT Champions ranks the store sells. */
    private const RANKS = [6, 5, 4, 3, 2, 1];

    public function __construct(
        private QuoteCoins $quoteCoins,
        private ReadManualServicePricing $manualPricing,
        private ConvertDisplayMoney $convertDisplayMoney,
        private StoreCatalogReader $catalog,
    ) {}

    /**
     * A plain-text block, or an empty string when no price can be read. Failing
     * to a silent absence is deliberate: an assistant with no table refuses to
     * quote, which is safe. A half-built table would let it quote a wrong one.
     */
    public function execute(string $displayCurrency, string $locale): string
    {
        return Cache::remember(
            "chat.live-prices.{$displayCurrency}.{$locale}",
            60,
            fn (): string => $this->render($displayCurrency, $locale),
        );
    }

    private function render(string $displayCurrency, string $locale): string
    {
        try {
            $converter = $this->convertDisplayMoney->prepare($displayCurrency);
        } catch (Throwable) {
            return '';
        }

        $format = function (int $halalah) use ($converter): ?string {
            try {
                $money = $converter->convert(Money::fromHalalah($halalah));

                return number_format($money['amountMinor'] / 100, 2).' '.$money['currency'];
            } catch (Throwable) {
                return null;
            }
        };

        $lines = array_merge(
            $this->coinsLines($format),
            $this->rivalsLines($format),
            $this->championsLines($format),
            $this->sbcLines($locale),
        );

        if ($lines === []) {
            return '';
        }

        $heading = $locale === 'en'
            ? 'Current store prices. Quote these exactly; never calculate or estimate a price that is not listed.'
            : 'أسعار المتجر الحالية. اقتبسها كما هي، ولا تحسب أو تقدّر أي سعر غير مذكور.';

        return "\n\n<live_prices>\n{$heading}\n".implode("\n", $lines)."\n</live_prices>";
    }

    /**
     * @param  callable(int): ?string  $format
     * @return list<string>
     */
    private function coinsLines(callable $format): array
    {
        $lines = [];

        foreach ([
            ['label' => 'PlayStation/Xbox normal delivery', 'platform' => Platform::PlayStation, 'delivery' => DeliveryMode::Normal],
            ['label' => 'PlayStation/Xbox fast delivery', 'platform' => Platform::PlayStation, 'delivery' => DeliveryMode::Fast],
            ['label' => 'PC', 'platform' => Platform::Pc, 'delivery' => null],
        ] as $variant) {
            foreach (self::COIN_QUANTITIES as $quantity) {
                try {
                    $quote = $this->quoteCoins->execute($variant['platform'], $variant['delivery'], $quantity);
                } catch (Throwable) {
                    continue;
                }

                $price = $format($quote->total->halalah());

                if ($price === null) {
                    continue;
                }

                $lines[] = 'coins | '.$variant['label'].' | '.number_format($quantity).' coins | '.$price;
            }
        }

        return $lines;
    }

    /**
     * @param  callable(int): ?string  $format
     * @return list<string>
     */
    private function rivalsLines(callable $format): array
    {
        try {
            $pricing = $this->manualPricing->rivals()['pricing'];
        } catch (Throwable) {
            return [];
        }

        $lines = [];

        foreach (self::DIVISIONS as $from) {
            try {
                $targets = $pricing->availableTargets($from);
            } catch (Throwable) {
                continue;
            }

            foreach ($targets as $to) {
                try {
                    $price = $format($pricing->priceForRoute($from, $to));
                } catch (Throwable) {
                    continue;
                }

                if ($price !== null) {
                    $lines[] = "rivals | Division {$from} to ".($to === 'elite' ? 'Elite' : "Division {$to}").' | '.$price;
                }
            }
        }

        return $lines;
    }

    /**
     * @param  callable(int): ?string  $format
     * @return list<string>
     */
    private function championsLines(callable $format): array
    {
        try {
            $pricing = $this->manualPricing->futChampions()['pricing'];
        } catch (Throwable) {
            return [];
        }

        $lines = [];

        foreach (self::RANKS as $rank) {
            foreach ([false => 'normal', true => 'urgent'] as $urgent => $label) {
                try {
                    $price = $format($pricing->priceForRank($rank, (bool) $urgent));
                } catch (Throwable) {
                    continue;
                }

                if ($price !== null) {
                    $lines[] = "fut champions | rank {$rank} | {$label} | {$price}";
                }
            }
        }

        return $lines;
    }

    /**
     * SBC is a catalogue, not a price list: every challenge is priced on its
     * own. Naming the ones the store actually sells lets the assistant answer
     * "how much are the challenges?" with real examples instead of a refusal,
     * while the shelf beside the reply lets the customer pick one.
     *
     * @return list<string>
     */
    private function sbcLines(string $locale): array
    {
        try {
            $catalog = $this->catalog->category(
                ServiceType::Sbc,
                $locale,
                (string) config('store.default_display_currency'),
                'all',
                'recommended',
                '',
                1,
            );
        } catch (Throwable) {
            return [];
        }

        $lines = [];

        foreach ($catalog['products'] as $product) {
            $name = $product['name'] ?? null;
            $amount = $product['price']['amountMinor'] ?? null;
            $currency = $product['price']['currency'] ?? null;

            if (! is_string($name) || ! is_int($amount) || ! is_string($currency)) {
                continue;
            }

            $lines[] = 'sbc | '.$name.' | '.number_format($amount / 100, 2).' '.$currency;

            if (count($lines) === self::SBC_LIMIT) {
                break;
            }
        }

        return $lines;
    }
}
