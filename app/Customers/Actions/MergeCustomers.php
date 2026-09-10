<?php

namespace App\Customers\Actions;

use App\Admin\Actions\RecordStaffAudit;
use App\Admin\Audit\StaffAuditEvent;
use App\Enums\UserRole;
use App\Enums\WalletEntryType;
use App\Loyalty\Support\WalletLedgerWriter;
use App\Models\User;
use App\Models\WalletAccount;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fold one customer account into another.
 *
 * The usual case is a Salla-era customer who registered again with a new
 * email: two rows, one person. Everything that belongs to the duplicate (its
 * orders, reviews, conversations, carts, coupon uses, sign-in identities) moves
 * to the account that survives; the duplicate is deactivated and its email and
 * phone are freed so the survivor can take them.
 *
 * Wallet money is never moved by rewriting rows: the ledger is append-only and
 * balance_after is per account, so the duplicate's balance is either carried
 * over as a credit on the survivor (with a matching debit on the duplicate) or
 * written off with a debit that leaves the duplicate at zero. Both leave an
 * auditable line on each side.
 */
final class MergeCustomers
{
    /** Tables whose `user_id` column points at the customer who owns the row. */
    private const OWNED_TABLES = [
        'orders',
        'carts',
        'chat_conversations',
        'coupon_redemptions',
        'guest_cart_claims',
        'notification_deliveries',
        'phone_verifications',
        'reviews',
        'secret_access_logs',
        'social_accounts',
        'support_tickets',
        'two_factor_trusted_devices',
        'user_identity_changes',
    ];

    public function __construct(
        private readonly WalletLedgerWriter $walletLedgerWriter,
        private readonly RecordStaffAudit $recordStaffAudit,
    ) {}

    /**
     * @param  string  $wallet  `drop` writes the duplicate's balance off, `transfer` carries it over
     * @param  string  $email  `into` keeps the survivor's email and password, `from` takes the duplicate's
     * @return array<string, int|string|null> a summary for the operator
     */
    public function execute(User $from, User $into, string $wallet = 'drop', string $email = 'into'): array
    {
        if ($from->is($into)) {
            throw new InvalidArgumentException('An account cannot be merged into itself.');
        }

        if ($from->role !== UserRole::Customer || $into->role !== UserRole::Customer) {
            throw new InvalidArgumentException('Only customer accounts can be merged.');
        }

        if (! in_array($wallet, ['transfer', 'drop'], true) || ! in_array($email, ['into', 'from'], true)) {
            throw new InvalidArgumentException('Unknown merge option.');
        }

        return DB::transaction(function () use ($from, $into, $wallet, $email): array {
            $from = User::query()->whereKey($from->id)->lockForUpdate()->sole();
            $into = User::query()->whereKey($into->id)->lockForUpdate()->sole();
            $moved = [];

            foreach (self::OWNED_TABLES as $table) {
                $moved[$table] = DB::table($table)
                    ->where('user_id', $from->id)
                    ->update(['user_id' => $into->id]);
            }

            // Sessions are per browser, not per person; the duplicate simply
            // stops being signed in.
            DB::table('sessions')->where('user_id', $from->id)->delete();

            $walletHalalah = $this->settleWallet($from, $into, $wallet);

            // The duplicate's contact details are released first so the
            // survivor can take them without tripping the unique indexes.
            $fromEmail = $from->email;
            $fromEmailVerifiedAt = $from->email_verified_at;
            $fromPassword = $from->password;
            $fromPhone = $from->phone;
            $fromPhoneVerifiedAt = $from->phone_verified_at;

            $from->forceFill([
                'email' => null,
                'email_verified_at' => null,
                'phone' => null,
                'phone_verified_at' => null,
                'password' => null,
                'is_active' => false,
                'remember_token' => null,
            ])->save();

            $survivor = [];

            if ($email === 'from' && $fromEmail !== null) {
                $survivor['email'] = $fromEmail;
                $survivor['email_verified_at'] = $fromEmailVerifiedAt;
                $survivor['password'] = $fromPassword;
            }

            if ($into->phone === null && $fromPhone !== null) {
                $survivor['phone'] = $fromPhone;
                $survivor['phone_verified_at'] = $fromPhoneVerifiedAt;
            }

            if ($survivor !== []) {
                $into->forceFill($survivor)->save();
            }

            $this->recordStaffAudit->executeFromConsole(
                $into,
                new StaffAuditEvent('customer.merged', [
                    'from_public_id' => (string) $from->public_id,
                    'from_customer_number' => $from->customer_number,
                    'wallet' => $wallet,
                    'wallet_halalah' => $walletHalalah,
                    'email' => $email,
                    'moved' => array_filter($moved),
                    'source' => 'console',
                ], null),
            );

            return [
                'into_public_id' => (string) $into->public_id,
                'into_customer_number' => $into->customer_number,
                'wallet_halalah' => $walletHalalah,
                'moved_rows' => array_sum($moved),
            ];
        });
    }

    /**
     * @return int the halalah the duplicate held before the merge
     */
    private function settleWallet(User $from, User $into, string $wallet): int
    {
        $fromAccount = WalletAccount::query()->where('user_id', $from->id)->lockForUpdate()->first();

        if (! $fromAccount instanceof WalletAccount) {
            return 0;
        }

        $balance = (int) $fromAccount->balance_halalah;

        if ($balance <= 0) {
            return $balance;
        }

        // The reference is unique across the ledger, so each side gets its own.
        $reference = "customer-merge:{$from->id}:{$into->id}";
        $metadata = ['merged_into_user_id' => $into->id, 'wallet' => $wallet];

        $this->walletLedgerWriter->append($fromAccount, [
            'type' => WalletEntryType::Adjustment,
            'amount_halalah' => $balance,
            'balance_delta_halalah' => -$balance,
            'order_id' => null,
            'refund_id' => null,
            'created_by_user_id' => null,
            'reference' => $reference.':out',
            'metadata' => $metadata,
        ]);

        if ($wallet === 'transfer') {
            $intoAccount = $this->walletLedgerWriter->lockAccountFor((int) $into->id);
            $this->walletLedgerWriter->append($intoAccount, [
                'type' => WalletEntryType::Adjustment,
                'amount_halalah' => $balance,
                'balance_delta_halalah' => $balance,
                'order_id' => null,
                'refund_id' => null,
                'created_by_user_id' => null,
                'reference' => $reference.':in',
                'metadata' => ['merged_from_user_id' => $from->id, 'wallet' => $wallet],
            ]);
        }

        return $balance;
    }
}
