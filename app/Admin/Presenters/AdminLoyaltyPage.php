<?php

namespace App\Admin\Presenters;

use App\Models\LoyaltyTier;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final readonly class AdminLoyaltyPage
{
    public function __construct(
        private AdminShell $shell,
    ) {}

    /**
     * @param array{
     *     tiers: list<LoyaltyTier>,
     *     kpis: array{
     *         customersPerTier: array<string, int>,
     *         cashbackCreditedLast30DaysHalalah: int
     *     }
     * } $data
     * @return array<string, mixed>
     */
    public function for(
        User $actor,
        string $locale,
        array $data,
    ): array {
        $currentRouteName = (string) request()->route()?->getName();
        $prefix = str_starts_with($currentRouteName, 'localized.admin.')
            ? 'localized.admin.'
            : 'admin.';

        $presentedTiers = array_map(
            fn (LoyaltyTier $tier): array => [
                'id' => (string) $tier->public_id,
                'key' => (string) $tier->key,
                'nameAr' => (string) $tier->name_ar,
                'nameEn' => (string) $tier->name_en,
                'rank' => (int) $tier->rank,
                'minimumLifetimeSpend' => [
                    'amountMinor' => (string) $tier->minimum_lifetime_spend_halalah,
                    'currency' => 'SAR',
                ],
                'cashbackBasisPoints' => (int) $tier->cashback_basis_points,
                'cashbackPercent' => number_format((float) ($tier->cashback_basis_points / 100), 1).'%',
                'isActive' => (bool) $tier->is_active,
                'updatedAt' => $this->isoString($tier->updated_at),
            ],
            $data['tiers'],
        );

        $updateTierUrlTemplate = route(
            $prefix.'marketing.loyalty.tiers.update',
            ['publicId' => '__ID__'],
            absolute: false,
        );

        return [
            'locale' => $locale,
            'direction' => $locale === 'en' ? 'ltr' : 'rtl',
            'adminUi' => (array) trans('admin', locale: $locale),
            ...$this->shell->for($actor, $locale),
            'tiers' => $presentedTiers,
            'kpis' => [
                'customersPerTier' => $data['kpis']['customersPerTier'],
                'cashbackCreditedLast30Days' => [
                    'amountMinor' => (string) $data['kpis']['cashbackCreditedLast30DaysHalalah'],
                    'currency' => 'SAR',
                ],
            ],
            'updateTierUrlTemplate' => $updateTierUrlTemplate,
            'confirmPasswordUrl' => route('password.confirm', absolute: false),
        ];
    }

    private function isoString(mixed $date): string
    {
        if ($date instanceof CarbonInterface) {
            return $date->utc()->toIso8601String();
        }

        if (is_string($date) && $date !== '') {
            return Carbon::parse($date, 'UTC')->utc()->toIso8601String();
        }

        return '';
    }
}
