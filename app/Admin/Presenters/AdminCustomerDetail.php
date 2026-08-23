<?php

namespace App\Admin\Presenters;

use App\Enums\WalletEntryType;
use App\Models\Order;
use App\Models\StaffAuditLog;
use App\Models\User;
use App\Models\WalletEntry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class AdminCustomerDetail
{
    /**
     * @param  array{ordersCount: int, totalSpent: int, lastOrderAt: ?string}  $ordersSummary
     * @param  list<Order>  $recentOrders
     * @param  array{balance: int, entriesCount: int}  $walletSummary
     * @param  list<WalletEntry>  $recentWalletEntries
     * @param  list<StaffAuditLog>|null  $auditLogs
     * @return array{
     *     id: string,
     *     name: string,
     *     firstName: string,
     *     lastName: string,
     *     email: string,
     *     phone: ?string,
     *     preferredLocale: string,
     *     isActive: bool,
     *     createdAt: string,
     *     updatedAt: string,
     *     emailVerifiedAt: ?string,
     *     phoneVerifiedAt: ?string,
     *     ordersSummary: array{
     *         ordersCount: int,
     *         totalSpent: array{amountMinor: string, currency: string},
     *         lastOrderAt: ?string
     *     },
     *     recentOrders: list<array{
     *         id: string,
     *         orderNumber: string,
     *         status: string,
     *         total: array{amountMinor: string, currency: string},
     *         placedAt: ?string
     *     }>,
     *     walletSummary: array{
     *         balance: array{amountMinor: string, currency: string},
     *         entriesCount: int
     *     },
     *     recentWalletEntries: list<array{
     *         id: string,
     *         type: string,
     *         direction: 'credit'|'debit'|'neutral',
     *         amount: array{amountMinor: string, currency: string},
     *         createdAt: string,
     *         reference: ?string
     *     }>,
     *     recentAuditLogs: list<array{
     *         id: string,
     *         action: string,
     *         actor: ?array{name: string, role: string},
     *         createdAt: string,
     *         metadata: array<string, mixed>
     *     }>|null
     * }
     */
    public function present(
        User $user,
        array $ordersSummary,
        array $recentOrders,
        array $walletSummary,
        array $recentWalletEntries,
        ?array $auditLogs,
        string $locale,
    ): array {
        return [
            'id' => (string) $user->public_id,
            'name' => trim((string) $user->first_name.' '.(string) $user->last_name),
            'firstName' => (string) $user->first_name,
            'lastName' => (string) $user->last_name,
            'email' => (string) $user->email,
            'phone' => $user->phone !== null ? (string) $user->phone : null,
            'preferredLocale' => (string) ($user->preferred_locale ?? 'ar'),
            'isActive' => (bool) $user->is_active,
            'createdAt' => $this->isoString($user->created_at),
            'updatedAt' => $this->isoString($user->updated_at),
            'emailVerifiedAt' => $this->nullableIsoString($user->email_verified_at),
            'phoneVerifiedAt' => $this->nullableIsoString($user->phone_verified_at),
            'ordersSummary' => [
                'ordersCount' => $ordersSummary['ordersCount'],
                'totalSpent' => [
                    'amountMinor' => (string) $ordersSummary['totalSpent'],
                    'currency' => 'SAR',
                ],
                'lastOrderAt' => $this->nullableIsoString($ordersSummary['lastOrderAt']),
            ],
            'recentOrders' => array_map(
                fn (Order $order): array => [
                    'id' => (string) $order->public_id,
                    'orderNumber' => (string) $order->order_number,
                    'status' => $order->status->value,
                    'total' => [
                        'amountMinor' => (string) $order->total_halalah,
                        'currency' => (string) $order->currency,
                    ],
                    'placedAt' => $this->nullableIsoString($order->placed_at),
                ],
                $recentOrders,
            ),
            'walletSummary' => [
                'balance' => [
                    'amountMinor' => (string) $walletSummary['balance'],
                    'currency' => 'SAR',
                ],
                'entriesCount' => $walletSummary['entriesCount'],
            ],
            'recentWalletEntries' => array_map(
                fn (WalletEntry $entry): array => [
                    'id' => (string) $entry->public_id,
                    'type' => $entry->type->value,
                    'direction' => $this->deriveWalletDirection($entry),
                    'amount' => [
                        'amountMinor' => (string) $entry->amount_halalah,
                        'currency' => 'SAR',
                    ],
                    'createdAt' => $this->isoString($entry->created_at),
                    'reference' => $entry->reference !== null ? (string) $entry->reference : null,
                ],
                $recentWalletEntries,
            ),
            'recentAuditLogs' => $auditLogs !== null
                ? array_map(
                    fn (StaffAuditLog $log): array => [
                        'id' => (string) $log->public_id,
                        'action' => (string) $log->action,
                        'actor' => $log->actor instanceof User
                            ? [
                                'name' => $log->actor->name,
                                'role' => $log->actor->role->value,
                            ]
                            : null,
                        'createdAt' => $this->isoString($log->created_at),
                        'metadata' => $this->filterSafeAuditMetadata(is_array($log->metadata) ? $log->metadata : []),
                    ],
                    $auditLogs,
                )
                : null,
        ];
    }

    /**
     * @return 'credit'|'debit'|'neutral'
     */
    private function deriveWalletDirection(WalletEntry $entry): string
    {
        $type = $entry->type;
        $metadata = $entry->metadata;
        $direction = is_array($metadata) ? ($metadata['direction'] ?? null) : null;

        return match ($type) {
            WalletEntryType::Credit, WalletEntryType::Refund => 'credit',
            WalletEntryType::Debit => 'debit',
            WalletEntryType::Adjustment => match ($direction) {
                'credit' => 'credit',
                'debit' => 'debit',
                default => 'neutral',
            },
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function filterSafeAuditMetadata(array $metadata): array
    {
        $safeKeys = [
            'reason_code',
            'case_reference',
            'previous_active',
            'new_active',
            'contact_changed',
            'contact_previous',
            'contact_new',
        ];

        return array_intersect_key($metadata, array_flip($safeKeys));
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

    private function nullableIsoString(mixed $date): ?string
    {
        if ($date instanceof CarbonInterface) {
            return $date->utc()->toIso8601String();
        }

        if (is_string($date) && $date !== '') {
            return Carbon::parse($date, 'UTC')->utc()->toIso8601String();
        }

        return null;
    }
}
