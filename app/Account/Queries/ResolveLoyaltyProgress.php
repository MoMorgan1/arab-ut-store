<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Loyalty\Support\EligibleOrderSpend;
use App\Models\LoyaltyTier;
use App\Models\User;

final class ResolveLoyaltyProgress
{
    public function __construct(
        private readonly EligibleOrderSpend $eligibleOrderSpend,
    ) {}

    /**
     * @return array{
     *     eligibleSpend: array{amountMinor: string, currency: string},
     *     currentTier: array{key: string, name: string, minimum: array{amountMinor: string, currency: string}}|null,
     *     nextTier: array{key: string, name: string, minimum: array{amountMinor: string, currency: string}}|null,
     *     remaining: array{amountMinor: string, currency: string}|null,
     *     progressPercent: int
     * }|null
     */
    public function for(User $user, string $locale): ?array
    {
        $tiers = LoyaltyTier::query()
            ->select(['key', 'name_ar', 'name_en', 'rank', 'minimum_lifetime_spend_halalah'])
            ->where('is_active', true)
            ->orderBy('minimum_lifetime_spend_halalah')
            ->orderBy('rank')
            ->get();

        if ($tiers->isEmpty()) {
            return null;
        }

        $eligibleSpend = $this->eligibleOrderSpend->lifetime($user->id);
        $current = $tiers->last(fn (LoyaltyTier $tier): bool => $tier->minimum_lifetime_spend_halalah <= $eligibleSpend);
        $next = $tiers->first(fn (LoyaltyTier $tier): bool => $tier->minimum_lifetime_spend_halalah > $eligibleSpend);

        return [
            'eligibleSpend' => AccountMoney::fromMinor($eligibleSpend, 'SAR'),
            'currentTier' => $current instanceof LoyaltyTier ? $this->tier($current, $locale) : null,
            'nextTier' => $next instanceof LoyaltyTier ? $this->tier($next, $locale) : null,
            'remaining' => $next instanceof LoyaltyTier
                ? AccountMoney::fromMinor($next->minimum_lifetime_spend_halalah - $eligibleSpend, 'SAR')
                : null,
            'progressPercent' => $this->progressPercent($eligibleSpend, $current, $next),
        ];
    }

    /** @return array{key: string, name: string, minimum: array{amountMinor: string, currency: string}} */
    private function tier(LoyaltyTier $tier, string $locale): array
    {
        return [
            'key' => (string) $tier->getAttribute('key'),
            'name' => (string) $tier->getAttribute($locale === 'en' ? 'name_en' : 'name_ar'),
            'minimum' => AccountMoney::fromMinor($tier->minimum_lifetime_spend_halalah, 'SAR'),
        ];
    }

    private function progressPercent(
        int $eligibleSpend,
        ?LoyaltyTier $current,
        ?LoyaltyTier $next,
    ): int {
        if (! $next instanceof LoyaltyTier) {
            return 100;
        }

        $currentMinimum = $current instanceof LoyaltyTier
            ? $current->minimum_lifetime_spend_halalah
            : 0;
        $span = $next->minimum_lifetime_spend_halalah - $currentMinimum;

        if ($span <= 0) {
            return 0;
        }

        $withinTier = max(0, $eligibleSpend - $currentMinimum);

        return min(100, (int) floor(($withinTier / $span) * 100));
    }
}
