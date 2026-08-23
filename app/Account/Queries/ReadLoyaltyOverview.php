<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Enums\WalletEntryType;
use App\Models\LoyaltyTier;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use UnexpectedValueException;

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
     *         lifetime: array{amountMinor: string, currency: string},
     *         entries: array<int, array{
     *             id: string,
     *             sequence: int,
     *             type: string,
     *             effect: string,
     *             amount: array{amountMinor: string, currency: string},
     *             createdAt: string|null,
     *             order: array{number: string, url: string}|null
     *         }>
     *     }
     * }
     */
    public function for(User $user, string $locale): array
    {
        $tiers = LoyaltyTier::query()
            ->select(['key', 'name_ar', 'name_en', 'rank', 'minimum_lifetime_spend_halalah', 'cashback_basis_points'])
            ->where('is_active', true)
            ->orderBy('minimum_lifetime_spend_halalah')
            ->orderBy('rank')
            ->get();

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

        $cashback = $this->cashbackData($account, $user, $locale);

        return [
            'tiers' => $tierList,
            'currentTier' => $progress['currentTier'] ?? null,
            'nextTier' => $progress['nextTier'] ?? null,
            'remaining' => $progress['remaining'] ?? null,
            'progressPercent' => $progress['progressPercent'] ?? 0,
            'eligibleSpend' => $progress['eligibleSpend'] ?? AccountMoney::fromMinor(0, 'SAR'),
            'cashback' => $cashback,
        ];
    }

    /**
     * @return array{
     *     lifetime: array{amountMinor: string, currency: string},
     *     entries: array<int, array{
     *         id: string,
     *         sequence: int,
     *         type: string,
     *         effect: string,
     *         amount: array{amountMinor: string, currency: string},
     *         createdAt: string|null,
     *         order: array{number: string, url: string}|null
     *     }>
     * }
     */
    private function cashbackData(?WalletAccount $account, User $user, string $locale): array
    {
        if (! $account instanceof WalletAccount) {
            return [
                'lifetime' => AccountMoney::fromMinor(0, 'SAR'),
                'entries' => [],
            ];
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

        $entries = WalletEntry::query()
            ->select([
                'id',
                'public_id',
                'wallet_account_id',
                'order_id',
                'type',
                'sequence',
                'amount_halalah',
                'created_at',
            ])
            ->where('wallet_account_id', $account->id)
            ->whereIn('type', [WalletEntryType::Cashback->value, WalletEntryType::CashbackReversal->value])
            ->with(['order' => fn ($query) => $query
                ->select(['id', 'public_id', 'user_id', 'order_number'])
                ->where('user_id', $user->id)])
            ->orderByDesc('sequence')
            ->limit(10)
            ->get()
            ->map(function (WalletEntry $entry) use ($locale): array {
                $type = $entry->getAttribute('type');
                $amount = $entry->getAttribute('amount_halalah');
                $order = $entry->order;

                if (! $type instanceof WalletEntryType || ! is_int($amount)) {
                    throw new UnexpectedValueException('Wallet entry has an invalid stored value.');
                }

                return [
                    'id' => $entry->public_id,
                    'sequence' => $entry->sequence,
                    'type' => $type->value,
                    'effect' => $type === WalletEntryType::Cashback ? 'credit' : 'debit',
                    'amount' => AccountMoney::fromMinor($amount, 'SAR'),
                    'createdAt' => $entry->created_at->toIso8601String(),
                    'order' => $order instanceof Order ? [
                        'number' => $order->order_number,
                        'url' => route(
                            $locale === 'en' ? 'localized.account.orders.show' : 'account.orders.show',
                            ['order' => $order->public_id],
                            absolute: false,
                        ),
                    ] : null,
                ];
            })
            ->values()
            ->all();

        return [
            'lifetime' => AccountMoney::fromMinor($lifetimeHalalah, 'SAR'),
            'entries' => $entries,
        ];
    }
}
