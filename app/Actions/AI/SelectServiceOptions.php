<?php

namespace App\Actions\AI;

use App\Actions\Pricing\ReadManualServicePricing;
use App\Enums\DeliveryMode;
use App\Enums\Platform;
use Throwable;

/**
 * Extracts validated, typed service options from a customer's message.
 *
 * When a customer asks about a specific configuration (e.g. 500k coins on PS5,
 * or Rivals from div 5 to elite), this action resolves the requested options
 * so the service card can deep-link directly to that configured state.
 *
 * This action is intentionally conservative: if any required option is missing,
 * ambiguous, unpriced, or unresolvable, it returns an empty array. A plain link
 * to the service configurator is always better than preselecting a wrong option.
 */
final readonly class SelectServiceOptions
{
    /** Quantities the store actually offers in the coin configurator. */
    private const COIN_QUANTITIES = [100_000, 500_000, 1_000_000, 2_000_000, 5_000_000];

    public function __construct(
        private ReadManualServicePricing $manualPricing,
    ) {}

    /**
     * Resolves the detected options for the given service, or returns an empty
     * array when options cannot be confidently and completely determined.
     *
     * @return array<string, mixed>
     */
    public function execute(string $customerText, string $serviceKey): array
    {
        if (trim($customerText) === '') {
            return [];
        }

        return match ($serviceKey) {
            'coins' => $this->detectCoinsOptions($customerText),
            'rivals' => $this->detectRivalsOptions($customerText),
            'fut_champions' => $this->detectFutChampionsOptions($customerText),
            default => [],
        };
    }

    /**
     * Whatever the message did say, without the completeness gate.
     *
     * execute() is all-or-nothing on purpose: a card must not preselect half a
     * configuration. Asking the customer is the opposite problem — the assistant
     * needs to know which single thing is still missing so it can ask for that
     * one and nothing else.
     *
     * @return array<string, mixed>
     */
    public function partial(string $customerText, string $serviceKey): array
    {
        if (trim($customerText) === '') {
            return [];
        }

        $normalized = SelectSupportKnowledge::normalize($customerText);

        if ($this->isOrderOrSupportInquiry($normalized)) {
            return [];
        }

        return match ($serviceKey) {
            'coins' => $this->partialCoins($normalized),
            'fut_champions' => $this->partialChampions($normalized),
            'rivals' => $this->partialRivals($normalized),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function partialCoins(string $normalized): array
    {
        $found = [];
        $platform = $this->parseCoinsPlatform($normalized);

        // Xbox is deliberately absent from the coins configurator, so it counts
        // as "console chosen" for the purpose of what is left to ask.
        if ($platform !== null) {
            $found['platform'] = $platform === Platform::Xbox->value
                ? Platform::PlayStation->value
                : $platform;
        }

        $quantity = $this->parseCoinQuantity($normalized);

        if ($quantity !== null) {
            $found['quantity'] = $quantity;
        }

        if (isset($found['platform'])) {
            $delivery = $this->parseCoinsDelivery($normalized, $found['platform']);

            if ($delivery !== null) {
                $found['delivery'] = $delivery;
            }
        }

        return $found;
    }

    /**
     * @return array<string, mixed>
     */
    private function partialChampions(string $normalized): array
    {
        $found = [];
        $rank = $this->parseFutChampionsRank($normalized);

        if ($rank !== null) {
            $found['rank'] = $rank;
        }

        $urgent = $this->parseFutChampionsUrgency($normalized);

        if ($urgent !== null) {
            $found['urgent'] = $urgent;
        }

        return $found;
    }

    /**
     * Coins require both a known platform and a supported quantity tier.
     * PlayStation and Xbox also default to normal delivery unless fast delivery
     * was explicitly requested.
     *
     * @return array<string, mixed>
     */
    private function detectCoinsOptions(string $text): array
    {
        $normalized = SelectSupportKnowledge::normalize($text);

        if ($this->isOrderOrSupportInquiry($normalized)) {
            return [];
        }

        $platform = $this->parseCoinsPlatform($normalized);
        $quantity = $this->parseCoinQuantity($normalized);

        if ($platform === null || $quantity === null) {
            return [];
        }

        // The coins configurator sells console coins under one PlayStation
        // option; there is no Xbox choice to preselect even though the price
        // is the same. Naming a platform the page cannot show would put a
        // console customer on a card that disagrees with the form it opens,
        // so the quantity carries over on its own and they pick the rest.
        if ($platform === Platform::Xbox->value) {
            return ['quantity' => $quantity];
        }

        if ($platform === Platform::Pc->value) {
            return [
                'platform' => $platform,
                'quantity' => $quantity,
            ];
        }

        $delivery = $this->parseCoinsDelivery($normalized, $platform);

        if ($delivery === null) {
            return [
                'platform' => $platform,
                'quantity' => $quantity,
            ];
        }

        return [
            'platform' => $platform,
            'delivery' => $delivery,
            'quantity' => $quantity,
        ];
    }

    /**
     * Rivals requires both current and target divisions (7..1, elite). The
     * route is verified against active pricing: non-advancing or unpriced routes
     * are rejected to prevent invalid configurator states.
     *
     * @return array<string, mixed>
     */
    private function detectRivalsOptions(string $text): array
    {
        $normalized = SelectSupportKnowledge::normalize($text);

        if ($this->isOrderOrSupportInquiry($normalized)) {
            return [];
        }

        $route = $this->parseRivalsRoute($normalized);

        if ($route === null) {
            return [];
        }

        [$from, $to] = $route;

        try {
            $pricing = $this->manualPricing->rivals()['pricing'];

            if (! in_array($to, $pricing->availableTargets($from), true)) {
                return [];
            }

            $price = $pricing->priceForRoute($from, $to);

            if ($price <= 0) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return [
            'currentDivision' => $from,
            'targetDivision' => $to,
        ];
    }

    /**
     * FUT Champions requires a target rank (1..6). Urgency defaults to false
     * (standard) unless urgent keywords are detected.
     *
     * @return array<string, mixed>
     */
    private function detectFutChampionsOptions(string $text): array
    {
        $normalized = SelectSupportKnowledge::normalize($text);

        if ($this->isOrderOrSupportInquiry($normalized)) {
            return [];
        }

        $rank = $this->parseFutChampionsRank($normalized);

        if ($rank === null) {
            return [];
        }

        $urgent = $this->parseFutChampionsUrgency($normalized);

        try {
            $pricing = $this->manualPricing->futChampions()['pricing'];
            $price = $pricing->priceForRank($rank, $urgent ?? false);

            if ($price <= 0) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return $urgent === null
            ? ['rank' => $rank]
            : ['rank' => $rank, 'urgent' => $urgent];
    }

    /**
     * Prevents order status queries, tracking questions, or general support
     * numbers from being falsely interpreted as buy options.
     */
    private function isOrderOrSupportInquiry(string $normalized): bool
    {
        if (preg_match('/(?:طلب(?:ي)?\s*رقم|رقم\s*الطلب|order\s*(?:#|no|number))\s*\d+/ui', $normalized) === 1) {
            return true;
        }

        return preg_match('/\b(?:order\s*tracking|تتبع\s*الطلب|وين\s*طلبي|شيك\s*طلبي)\b/ui', $normalized) === 1;
    }

    /**
     * Resolves the requested platform for coins. Ambiguous messages mentioning
     * multiple platforms return null.
     */
    private function parseCoinsPlatform(string $normalized): ?string
    {
        $platforms = [];

        if (preg_match('/\b(?:playstation|ps5|ps4|psn|ps)\b/i', $normalized) === 1
            || preg_match('/(?:بلايستيشن|بليستيشن|سوني\s*5|سوني\s*4|سوني)/u', $normalized) === 1) {
            $platforms[] = Platform::PlayStation->value;
        }

        if (preg_match('/\b(?:xbox|xbox\s*series|xbox\s*one)\b/i', $normalized) === 1
            || preg_match('/(?:اكس\s*بوكس|اكسبوكس)/u', $normalized) === 1) {
            $platforms[] = Platform::Xbox->value;
        }

        if (preg_match('/\b(?:pc|steam|ea\s*app)\b/i', $normalized) === 1
            || preg_match('/(?:بي\s*سي|كمبيوتر|حاسوب|ستيم)/u', $normalized) === 1) {
            $platforms[] = Platform::Pc->value;
        }

        $unique = array_values(array_unique($platforms));

        return count($unique) === 1 ? $unique[0] : null;
    }

    /**
     * Every way a customer writes a coin amount, longest form first.
     *
     * Order is load-bearing and the scan consumes what it matches: "نص مليون"
     * has to match the half-million branch before the bare "مليون" branch can
     * see it, otherwise one phrase yields two different amounts and reads like
     * a customer who asked for two quantities at once.
     *
     * The Arabic here is already normalized upstream (ة to ه, أ/إ/آ to ا), so
     * each spelling appears in its normalized form only.
     *
     * @var list<array{pattern: string, value: int|null, scale: int|null}>
     */
    private const QUANTITY_FORMS = [
        ['pattern' => '(?:نص|نصف)\s*مليون|half\s+(?:a\s+)?million', 'value' => 500_000, 'scale' => null],
        ['pattern' => 'مليونين|مليونان|two\s+million', 'value' => 2_000_000, 'scale' => null],
        ['pattern' => '(?:خمسه|خمس)\s*(?:ملايين|مليون)|five\s+million', 'value' => 5_000_000, 'scale' => null],
        ['pattern' => '(?:ميه|مئه|مايه)\s*الف|one\s+hundred\s+thousand', 'value' => 100_000, 'scale' => null],
        ['pattern' => '(\d+(?:\.\d+)?)\s*(?:m\b|million\b|ملايين|مليون)', 'value' => null, 'scale' => 1_000_000],
        ['pattern' => '(\d+(?:\.\d+)?)\s*(?:k\b|الاف|الف)', 'value' => null, 'scale' => 1_000],
        ['pattern' => 'مليون|million', 'value' => 1_000_000, 'scale' => null],
        ['pattern' => '(\d{6,7})(?![\d.])', 'value' => null, 'scale' => 1],
    ];

    /**
     * Extracts a coin quantity in raw coins (500_000, not "500k").
     *
     * One ordered scan rather than a regex per form: overlapping forms would
     * each contribute a candidate and the message would look ambiguous when it
     * was not. Two genuinely different amounts still return null - a customer
     * comparing two sizes has not chosen one, and guessing chooses for them.
     */
    private function parseCoinQuantity(string $normalized): ?int
    {
        $alternation = [];

        foreach (self::QUANTITY_FORMS as $index => $form) {
            $alternation[] = '(?P<f'.$index.'>'.$form['pattern'].')';
        }

        $found = preg_match_all(
            '/(?<![\w.])(?:'.implode('|', $alternation).')/u',
            $normalized,
            $matches,
            PREG_SET_ORDER,
        );

        if ($found === false || $found === 0) {
            return null;
        }

        $candidates = [];

        foreach ($matches as $match) {
            foreach (self::QUANTITY_FORMS as $index => $form) {
                if (($match['f'.$index] ?? '') === '') {
                    continue;
                }

                if ($form['value'] !== null) {
                    $candidates[] = $form['value'];

                    break;
                }

                $number = $this->scaledNumber($match, $index);

                // Reaching here means the form is a scaled one, so it carries a
                // scale; the fixed-value forms all returned above.
                if ($number !== null) {
                    $candidates[] = (int) round($number * $form['scale']);
                }

                break;
            }
        }

        $unique = array_values(array_unique($candidates));

        if (count($unique) !== 1) {
            return null;
        }

        return in_array($unique[0], self::COIN_QUANTITIES, true) ? $unique[0] : null;
    }

    /**
     * The number a scaled form captured, read from the numbered group that sits
     * inside the named branch that actually matched.
     *
     * @param  array<int|string, string>  $match
     */
    private function scaledNumber(array $match, int $index): ?float
    {
        $branch = $match['f'.$index] ?? '';

        foreach ($match as $key => $value) {
            if (! is_int($key) || $value === '' || ! is_numeric($value)) {
                continue;
            }

            if (str_contains($branch, $value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Resolves delivery mode. Fast delivery is selected when explicitly requested;
     * otherwise console orders default to normal delivery.
     */
    private function parseCoinsDelivery(string $normalized, string $platform): ?string
    {
        if ($platform === Platform::Pc->value) {
            return null;
        }

        $hasFast = preg_match('/\b(?:fast|express|speed|urgent)\b/i', $normalized) === 1
            || preg_match('/(?:سريع|سريعه|عاجل|مستعجل|شحن\s*سريع)/u', $normalized) === 1;

        $hasNormal = preg_match('/\b(?:normal|standard)\b/i', $normalized) === 1
            || preg_match('/(?:عادي|عاديه|بطيء)/u', $normalized) === 1;

        if ($hasFast && $hasNormal) {
            return null;
        }

        if ($hasFast) {
            return DeliveryMode::Fast->value;
        }

        if ($hasNormal) {
            return DeliveryMode::Normal->value;
        }

        // Silence is not a choice. Assuming normal delivery would preselect a
        // speed the customer never named and quote them a price for it, so the
        // assistant asks instead.
        return null;
    }

    /**
     * What a Rivals message did say about the route.
     *
     * Rivals is priced by route, not by a list of independent options, and one
     * message rarely carries both ends. A customer who says "I'm in division 5"
     * has named the start; a customer who names a bare division has named
     * nothing usable, because the same number reads as either end. So the
     * question is always asked from the start.
     *
     * @return array<string, mixed>
     */
    private function partialRivals(string $normalized): array
    {
        $route = $this->parseRivalsRoute($normalized);

        if ($route !== null) {
            return ['currentDivision' => $route[0], 'targetDivision' => $route[1]];
        }

        $current = $this->parseRivalsCurrentDivision($normalized);

        return $current === null ? [] : ['currentDivision' => $current];
    }

    /**
     * The division a customer says they are in right now.
     *
     * This needs explicit "I am in" phrasing. A bare number in a Rivals message
     * is ambiguous — "ديفيجن ٣" is as likely to be where they want to reach as
     * where they are — and guessing wrong would quote a price for a route they
     * never asked for.
     */
    private function parseRivalsCurrentDivision(string $normalized): ?string
    {
        $pattern = '/(?:انا\s*في|حاليا|الحين|وصلت|عندي|واقف\s*(?:في|ب)?|i\s*am\s*in|i\x27?m\s*in|currently\s*(?:in|at)?)\s*'
            .'(?:ديفيجن|ديفجن|ديف|div|division)?\s*([1-7]|elite|ايليت|الايليت|إيليت)(?![\w.])/ui';

        if (preg_match($pattern, $normalized, $matches) !== 1) {
            return null;
        }

        return self::normalizeDivision($matches[1]);
    }

    /**
     * Resolves Rivals route as [from, to].
     *
     * @return array{0: string, 1: string}|null
     */
    private function parseRivalsRoute(string $normalized): ?array
    {
        // 1. Phrased "from X to Y" (e.g. "من 5 لايليت", "from div 5 to elite", "من ديفيجن 6 الى 2")
        if (preg_match('/(?:من|from)\s*(?:ديفيجن|ديفجن|ديف|div|division)?\s*([1-7]|elite|ايليت|الايليت|إيليت)\s*(?:الى|إلى|الي|ل|حتى|to|-|->)\s*(?:ديفيجن|ديفجن|ديف|div|division)?\s*([1-7]|elite|ايليت|الايليت|إيليت)/ui', $normalized, $matches) === 1) {
            $from = self::normalizeDivision($matches[1]);
            $to = self::normalizeDivision($matches[2]);

            return $from !== null && $to !== null ? [$from, $to] : null;
        }

        // 2. Phrased "div X to Y" (e.g. "ديفيجن 5 الى ايليت", "div 3 to div 1")
        if (preg_match('/(?:ديفيجن|ديفجن|ديف|div|division)\s*([1-7]|elite|ايليت|الايليت|إيليت)\s*(?:الى|إلى|الي|ل|حتى|to|-|->)\s*(?:ديفيجن|ديفجن|ديف|div|division)?\s*([1-7]|elite|ايليت|الايليت|إيليت)/ui', $normalized, $matches) === 1) {
            $from = self::normalizeDivision($matches[1]);
            $to = self::normalizeDivision($matches[2]);

            return $from !== null && $to !== null ? [$from, $to] : null;
        }

        // 3. Phrased "X to Y" / "X ل Y" (e.g. "5 to elite", "6 ل 2", "5 -> 1")
        if (preg_match('/(?<![\w.])([1-7])\s*(?:الى|إلى|الي|ل|to|->)\s*(elite|ايليت|الايليت|إيليت|[1-7])(?![\w.])/ui', $normalized, $matches) === 1) {
            $from = self::normalizeDivision($matches[1]);
            $to = self::normalizeDivision($matches[2]);

            return $from !== null && $to !== null ? [$from, $to] : null;
        }

        // 4. Phrased "تصعيد من X الى Y"
        if (preg_match('/تصعيد\s*(?:من)?\s*(?:ديفيجن|ديفجن|ديف|div|division)?\s*([1-7]|elite|ايليت|الايليت|إيليت)\s*(?:الى|إلى|الي|ل|حتى|to)\s*(?:ديفيجن|ديفجن|ديف|div|division)?\s*([1-7]|elite|ايليت|الايليت|إيليت)/ui', $normalized, $matches) === 1) {
            $from = self::normalizeDivision($matches[1]);
            $to = self::normalizeDivision($matches[2]);

            return $from !== null && $to !== null ? [$from, $to] : null;
        }

        return null;
    }

    private static function normalizeDivision(string $raw): ?string
    {
        $raw = trim($raw);

        if (in_array($raw, ['elite', 'ايليت', 'الايليت', 'إيليت'], true)) {
            return 'elite';
        }

        if (in_array($raw, ['7', '6', '5', '4', '3', '2', '1'], true)) {
            return $raw;
        }

        return null;
    }

    /**
     * Resolves the requested FUT Champions rank (1..6).
     */
    private function parseFutChampionsRank(string $normalized): ?int
    {
        if (preg_match_all('/(?:رانك|الرانك|رتبة|رتبه|الرتبة|الرتبه|rank)\s*([1-9]\d*)/ui', $normalized, $matches)) {
            $ranks = array_map(static fn (string $num): int => (int) $num, $matches[1]);
            $unique = array_values(array_unique($ranks));

            if (count($unique) !== 1) {
                return null;
            }

            $rank = $unique[0];

            return $rank >= 1 && $rank <= 6 ? $rank : null;
        }

        return null;
    }

    /**
     * Checks if urgent completion was requested for FUT Champions.
     */
    private function parseFutChampionsUrgency(string $normalized): ?bool
    {
        if (preg_match('/\b(?:urgent|express|fast)\b/i', $normalized) === 1
            || preg_match('/(?:عاجل|مستعجل|مستعجله|سريع|سريعه)/u', $normalized) === 1) {
            return true;
        }

        if (preg_match('/\b(?:normal|standard)\b/i', $normalized) === 1
            || preg_match('/(?:عادي|عاديه)/u', $normalized) === 1) {
            return false;
        }

        // Silence is not a choice: urgency changes the price, so an unstated
        // one is a question for the customer, not a default.
        return null;
    }
}
