<?php

namespace App\Actions\Orders;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Permanently delete cancelled orders that money never touched.
 *
 * Cancellation alone removes nothing, on purpose: ExpireAbandonedCheckouts
 * cancels abandoned checkouts while a customer may still be mid-redirect at
 * Paylink, and deleting the order the moment it cancels would leave a late
 * webhook arriving for an order that no longer exists. Instead, cancelled
 * orders age through a grace period, and this sweep removes the ones no money
 * ever reached.
 *
 * The owner's one inviolable rule: an order that captured money, ever, is
 * never deleted. Money that arrived through a lost webhook is exactly what
 * this sweep is exposed to, so the guard is checked under a row lock inside
 * the delete transaction itself - the last possible moment - and every order
 * it holds back is reported rather than quietly filtered away.
 *
 * Each order is purged inside its own transaction, so one bad row cannot
 * half-delete another order: a failure rolls back that order alone and the
 * sweep continues with the rest.
 */
final class PurgeDeadCancelledOrders
{
    private const CHUNK_SIZE = 100;

    /**
     * @return array{
     *     deleted: int,
     *     skipped_money: list<string>,
     *     skipped_wallet_ledger: list<string>,
     *     skipped_receipt: list<string>,
     *     failed: list<string>
     * }
     */
    public function execute(): array
    {
        $graceHours = (int) config('services.orders.purge_cancelled_grace_hours', 24);

        if ($graceHours < 1) {
            throw new RuntimeException('The cancelled-order purge grace period is unavailable.');
        }

        $cutoff = now()->subHours($graceHours);

        /** @var array{deleted: int, skipped_money: list<string>, skipped_wallet_ledger: list<string>, skipped_receipt: list<string>, failed: list<string>} $summary */
        $summary = [
            'deleted' => 0,
            'skipped_money' => [],
            'skipped_wallet_ledger' => [],
            'skipped_receipt' => [],
            'failed' => [],
        ];

        Order::query()
            ->where('status', OrderStatus::Cancelled)
            ->where('cancelled_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ($orders) use (&$summary): void {
                foreach ($orders as $order) {
                    $this->purgeOne($order, $summary);
                }
            });

        return $summary;
    }

    /**
     * @param  array{deleted: int, skipped_money: list<string>, skipped_wallet_ledger: list<string>, skipped_receipt: list<string>, failed: list<string>}  $summary
     */
    private function purgeOne(Order $order, array &$summary): void
    {
        $status = 'failed';
        $attachmentFiles = [];

        try {
            ['status' => $status, 'files' => $attachmentFiles] = $this->attempt($order);

            match ($status) {
                'deleted' => $summary['deleted']++,
                'money' => $summary['skipped_money'][] = $order->order_number,
                'wallet_ledger' => $summary['skipped_wallet_ledger'][] = $order->order_number,
                'receipt' => $summary['skipped_receipt'][] = $order->order_number,
                default => null, // no longer cancelled: nothing to purge, nothing to report
            };
        } catch (Throwable $exception) {
            $summary['failed'][] = $order->order_number;

            Log::error('Failed to purge a dead cancelled order; it will be retried on the next run.', [
                'order_number' => $order->order_number,
                'error' => $exception->getMessage(),
            ]);
        }

        if ($status === 'deleted' && $attachmentFiles !== []) {
            $this->deleteAttachmentFiles($attachmentFiles);
        }
    }

    /**
     * Purge a single order, or report the reason it must be left alone.
     *
     * @return array{status: string, files: list<array{disk: string, path: string}>}
     */
    private function attempt(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            /** @var Order|null $locked */
            $locked = Order::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->first();

            if (! $locked instanceof Order || $locked->status !== OrderStatus::Cancelled) {
                return ['status' => 'changed', 'files' => []];
            }

            // The money guard, re-checked under the lock: a webhook can move
            // between candidate selection and this transaction. paid_at is the
            // store's own record that money arrived, whatever the payment rows
            // say - the same settled signal the checkout expirer refuses to
            // cancel past. Salla imports write it onto cancelled historical
            // orders too: those are audit records, not dead weight.
            if ($locked->paid_at !== null || $this->hasCapturedMoney($locked)) {
                return ['status' => 'money', 'files' => []];
            }

            // The wallet ledger is financial history that must not be
            // orphaned. An order a wallet entry still points at is skipped and
            // reported, never deleted.
            if ($locked->walletEntries()->exists()) {
                return ['status' => 'wallet_ledger', 'files' => []];
            }

            // A receipt is issued financial paper backed by a stored file.
            // Nothing in this purge should ever produce one, so finding one
            // means the order is not what it looks like: leave it for a human.
            if ($locked->receipt()->exists()) {
                return ['status' => 'receipt', 'files' => []];
            }

            $itemIds = $locked->items()->pluck('id')->all();

            // Access logs are the restrict FK blocking the secrets; they go
            // first so the credential trail dies with the order.
            DB::table('secret_access_logs')
                ->whereIn(
                    'order_item_secret_id',
                    DB::table('order_item_secrets')->whereIn('order_item_id', $itemIds)->select('id'),
                )
                ->delete();

            // Fulfillment jobs restrict-delete order items; their attempts
            // cascade behind the job.
            if ($itemIds !== []) {
                DB::table('fulfillment_jobs')->whereIn('order_item_id', $itemIds)->delete();
            }

            // Redemption rows are kept only for audit while the order exists;
            // the live coupon counts already exclude cancelled orders through
            // the join, so nothing observes their removal.
            DB::table('coupon_redemptions')
                ->where('order_id', $locked->getKey())
                ->delete();

            $locked->payments()->delete();

            // Import provenance pointing at a deleted order is a dangling
            // reference, and would stop a future re-import recreating it.
            DB::table('external_refs')
                ->where('entity', 'order')
                ->where('internal_id', $locked->getKey())
                ->delete();

            $attachmentFiles = [];

            foreach (DB::table('fulfillment_attachments')
                ->whereIn('order_item_id', $itemIds)
                ->get(['disk', 'path']) as $row) {
                $attachmentFiles[] = ['disk' => (string) $row->disk, 'path' => (string) $row->path];
            }

            // Cascades order_items, order_discounts, order_status_history,
            // the item secrets and the attachment rows; nulls the notification
            // and review references.
            $locked->delete();

            return ['status' => 'deleted', 'files' => $attachmentFiles];
        });
    }

    /**
     * Money was taken if any payment captured or refunded anything, ever, or
     * if a refund record exists: a refund row is proof money moved even if the
     * payment columns disagree.
     */
    private function hasCapturedMoney(Order $order): bool
    {
        $captured = $order->payments()
            ->where(function (Builder $query): void {
                $query->where('captured_halalah', '>', 0)
                    ->orWhere('refunded_halalah', '>', 0);
            })
            ->exists();

        return $captured || $order->refunds()->exists();
    }

    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    private function deleteAttachmentFiles(array $files): void
    {
        foreach ($files as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['path']);
            } catch (Throwable $exception) {
                Log::warning('Could not delete a fulfilment attachment file left by a purged order.', [
                    'disk' => $file['disk'],
                    'path' => $file['path'],
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
