<?php

namespace App\Account\Queries;

use App\Account\Presenters\AccountMoney;
use App\Enums\WalletEntryType;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletAccount;
use App\Models\WalletEntry;
use UnexpectedValueException;

final class ReadWalletLedger
{
    private const PER_PAGE = 10;

    /** @return array{wallet: array<string, mixed>} */
    public function for(User $user, string $locale): array
    {
        $account = WalletAccount::query()
            ->select(['id', 'user_id', 'balance_halalah'])
            ->where('user_id', $user->id)
            ->first();

        if (! $account instanceof WalletAccount) {
            return ['wallet' => [
                'exists' => false,
                'balance' => null,
                'entries' => [],
                'pagination' => $this->emptyPagination(),
            ]];
        }

        $paginator = WalletEntry::query()
            ->select([
                'id',
                'public_id',
                'wallet_account_id',
                'order_id',
                'type',
                'sequence',
                'amount_halalah',
                'balance_after_halalah',
                'created_at',
            ])
            ->where('wallet_account_id', $account->id)
            ->with(['order' => fn ($query) => $query
                ->select(['id', 'public_id', 'user_id', 'order_number'])
                ->where('user_id', $user->id)])
            ->orderByDesc('sequence')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $balance = $account->getAttribute('balance_halalah');

        if (! is_int($balance)) {
            throw new UnexpectedValueException('Wallet balance must be an integer.');
        }

        return ['wallet' => [
            'exists' => true,
            'balance' => AccountMoney::fromMinor($balance, 'SAR'),
            'entries' => $paginator->getCollection()
                ->map(fn (WalletEntry $entry): array => $this->present($entry, $locale))
                ->values()
                ->all(),
            'pagination' => [
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'perPage' => $paginator->perPage(),
                'total' => $paginator->total(),
                'nextUrl' => $this->relativeUrl($paginator->nextPageUrl()),
                'previousUrl' => $this->relativeUrl($paginator->previousPageUrl()),
            ],
        ]];
    }

    /** @return array<string, mixed> */
    private function present(WalletEntry $entry, string $locale): array
    {
        $type = $entry->getAttribute('type');
        $amount = $entry->getAttribute('amount_halalah');
        $balanceAfter = $entry->getAttribute('balance_after_halalah');
        $order = $entry->order;

        if (! $type instanceof WalletEntryType || ! is_int($amount) || ! is_int($balanceAfter)) {
            throw new UnexpectedValueException('Wallet entry has an invalid stored value.');
        }

        return [
            'id' => $entry->public_id,
            'sequence' => $entry->sequence,
            'type' => $type->value,
            'effect' => match ($type) {
                WalletEntryType::Credit, WalletEntryType::Refund => 'credit',
                WalletEntryType::Debit => 'debit',
                WalletEntryType::Adjustment => 'neutral',
            },
            'amount' => AccountMoney::fromMinor($amount, 'SAR'),
            'balanceAfter' => AccountMoney::fromMinor($balanceAfter, 'SAR'),
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
    }

    /** @return array{currentPage: int, lastPage: int, perPage: int, total: int, nextUrl: null, previousUrl: null} */
    private function emptyPagination(): array
    {
        return [
            'currentPage' => 1,
            'lastPage' => 1,
            'perPage' => self::PER_PAGE,
            'total' => 0,
            'nextUrl' => null,
            'previousUrl' => null,
        ];
    }

    private function relativeUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        return (is_string($path) ? $path : '').(is_string($query) ? "?{$query}" : '');
    }
}
