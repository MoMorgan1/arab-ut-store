<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Enums\WalletEntryType;
use App\Models\LoyaltyTier;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;

final readonly class ReadLoyaltyOverview
{
    public function __construct(
        private ResolveLoyaltyProgress $loyaltyProgress,
    ) {}

    /**
     * @return array{
     *     tiers: array<int, array{
     *         key: string,
     *         name: string,
     *         minimum: array{amountMinor: string, currency: string},
     *         cashbackPercent: float
     *     }>,
     *     currentTier: array{key: string, name: string, minimum: array{amountMinor: string, currency: string}}|null,
     *     nextTier: array{key: string, name: string, minimum: array{amountMinor: string, currency: string}}|null,
     *     remaining: array{amountMinor: string, currency: string}|null,
     *     progressPercent: int,
     *     eligibleSpend: array{amountMinor: string, currency: string},
     *     cashback: array{
     *         lifetime: array{amountMinor: string, currency: string}
     *     }
     * }|null
     */
    public function for(User $user, string $locale): ?array
    {
        $tiers = LoyaltyTier::query()
            ->select(['key', 'name_ar', 'name_en', 'rank', 'minimum_lifetime_spend_halalah', 'cashback_basis_points'])
            ->where('is_active', true)
            ->orderBy('minimum_lifetime_spend_halalah')
            ->orderBy('rank')
            ->get();

        if ($tiers->isEmpty()) {
            return null;
        }

        $tierList = $tiers->map(fn (LoyaltyTier $tier): array => [
            'key' => (string) $tier->getAttribute('key'),
            'name' => (string) $tier->getAttribute($locale === 'en' ? 'name_en' : 'name_ar'),
            'minimum' => AccountMoney::fromMinor((int) $tier->getAttribute('minimum_lifetime_spend_halalah'), 'SAR'),
            'cashbackPercent' => (float) ((int) $tier->getAttribute('cashback_basis_points') / 100),
        ])->values()->all();

        $progress = $this->loyaltyProgress->for($user, $locale);

        $account = WalletAccount::query()
            ->where('user_id', $user->id)
            ->first();

        return [
            'tiers' => $tierList,
            'currentTier' => $progress['currentTier'] ?? null,
            'nextTier' => $progress['nextTier'] ?? null,
            'remaining' => $progress['remaining'] ?? null,
            'progressPercent' => $progress['progressPercent'] ?? 0,
            'eligibleSpend' => $progress['eligibleSpend'] ?? AccountMoney::fromMinor(0, 'SAR'),
            'cashback' => [
                'lifetime' => $this->lifetimeCashback($account),
            ],
        ];
    }

    /**
     * @return array{amountMinor: string, currency: string}
     */
    private function lifetimeCashback(?WalletAccount $account): array
    {
        if (! $account instanceof WalletAccount) {
            return AccountMoney::fromMinor(0, 'SAR');
        }

        $cashbackHalalah = (int) WalletEntry::query()
            ->where('wallet_account_id', $account->id)
            ->where('type', WalletEntryType::Cashback->value)
            ->sum('amount_halalah');

        $reversalHalalah = (int) WalletEntry::query()
            ->where('wallet_account_id', $account->id)
            ->where('type', WalletEntryType::CashbackReversal->value)
            ->sum('amount_halalah');

        $lifetimeHalalah = max(0, $cashbackHalalah - $reversalHalalah);

        return AccountMoney::fromMinor($lifetimeHalalah, 'SAR');
    }
}
