<?php

namespace App\Admin\Queries;

use App\Enums\WalletEntryType;
use App\Models\LoyaltyTier;
use App\Models\WalletEntry;

final readonly class ReadAdminLoyalty
{
    public function __construct(
        private CountCustomersPerTier $countCustomersPerTier,
    ) {}

    /**
     * @return array{
     *     tiers: list<LoyaltyTier>,
     *     kpis: array{
     *         customersPerTier: array<string, int>,
     *         cashbackCreditedLast30DaysHalalah: int
     *     }
     * }
     */
    public function get(): array
    {
        /** @var list<LoyaltyTier> $tiers */
        $tiers = LoyaltyTier::query()
            ->orderBy('rank')
            ->get()
            ->all();

        $customersPerTier = $this->countCustomersPerTier->execute();

        $since = now()->subDays(30);

        $credited = (int) WalletEntry::query()
            ->where('type', WalletEntryType::Cashback)
            ->where('created_at', '>=', $since)
            ->sum('amount_halalah');

        $reversed = (int) WalletEntry::query()
            ->where('type', WalletEntryType::CashbackReversal)
            ->where('created_at', '>=', $since)
            ->sum('amount_halalah');

        $netCashbackHalalah = max(0, $credited - $reversed);

        return [
            'tiers' => $tiers,
            'kpis' => [
                'customersPerTier' => $customersPerTier,
                'cashbackCreditedLast30DaysHalalah' => $netCashbackHalalah,
            ],
        ];
    }
}
