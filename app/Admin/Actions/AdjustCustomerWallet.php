<?php

namespace App\Admin\Actions;

use App\Admin\Audit\StaffAuditEvent;
use App\Enums\AdminPermission;
use App\Enums\UserRole;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\User;
use App\Models\WalletEntry;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class AdjustCustomerWallet
{
    public function __construct(
        private WalletLedgerWriter $writer,
        private RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @return array{entry: WalletEntry, balance_after_halalah: int}
     */
    public function execute(
        User $actor,
        string $customerPublicId,
        int $amountHalalah,
        string $reason,
        ?string $ipAddress = null,
    ): array {
        if (! $actor->is_active || ! $actor->can(AdminPermission::WalletAdjust->value)) {
            throw new AuthorizationException('This action requires wallet.adjust permission.');
        }

        if ($amountHalalah === 0 || abs($amountHalalah) > 100_000) {
            throw ValidationException::withMessages([
                'amount_halalah' => 'Adjustment amount must be a non-zero integer up to 100,000 Halalah.',
            ]);
        }

        return DB::transaction(function () use (
            $actor,
            $customerPublicId,
            $amountHalalah,
            $reason,
            $ipAddress,
        ): array {
            /** @var User $customer */
            $customer = User::query()
                ->where('public_id', $customerPublicId)
                ->where('role', UserRole::Customer)
                ->firstOrFail();

            $account = $this->writer->lockAccountFor($customer->id);

            $balanceBefore = (int) $account->balance_halalah;
            $balanceAfter = $balanceBefore + $amountHalalah;

            if ($balanceAfter < 0) {
                throw ValidationException::withMessages([
                    'amount_halalah' => (string) trans('admin.customerDetail.walletInsufficientBalance'),
                ]);
            }

            $reference = 'admin-adjustment:'.Str::lower((string) Str::ulid());
            $direction = $amountHalalah > 0 ? 'credit' : 'debit';

            $result = $this->writer->append($account, [
                'type' => WalletEntryType::Adjustment,
                'amount_halalah' => abs($amountHalalah),
                'balance_delta_halalah' => $amountHalalah,
                'order_id' => null,
                'refund_id' => null,
                'created_by_user_id' => $actor->id,
                'reference' => $reference,
                'metadata' => [
                    'reason' => $reason,
                    'direction' => $direction,
                ],
            ]);

            $this->recordStaffAudit->execute(
                $actor,
                $customer,
                new StaffAuditEvent(
                    action: 'customers.wallet_adjusted',
                    metadata: [
                        'amount_halalah' => abs($amountHalalah),
                        'balance_delta_halalah' => $amountHalalah,
                        'balance_before_halalah' => $balanceBefore,
                        'balance_after_halalah' => $balanceAfter,
                        'direction' => $direction,
                        'reason' => $reason,
                        'reference' => $reference,
                    ],
                    ipAddress: $ipAddress,
                ),
            );

            return $result;
        });
    }
}
