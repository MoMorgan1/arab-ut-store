<?php

namespace App\Console\Commands;

use App\Actions\Orders\PurgeDeadCancelledOrders as PurgeDeadCancelledOrdersAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PurgeDeadCancelledOrders extends Command
{
    protected $signature = 'orders:purge-cancelled';

    protected $description = 'Permanently delete cancelled orders that never captured money, past the grace period';

    public function handle(PurgeDeadCancelledOrdersAction $purge): int
    {
        $summary = $purge->execute();

        Log::info('Cancelled-order purge summary.', $summary);

        $this->components->info("Purged {$summary['deleted']} dead cancelled order(s).");

        $this->reportSkips('with money captured or refunded', $summary['skipped_money']);
        $this->reportSkips('with wallet ledger entries', $summary['skipped_wallet_ledger']);
        $this->reportSkips('with receipts', $summary['skipped_receipt']);
        $this->reportSkips('that failed to delete', $summary['failed']);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $orderNumbers
     */
    private function reportSkips(string $reason, array $orderNumbers): void
    {
        if ($orderNumbers === []) {
            return;
        }

        $this->components->warn(sprintf(
            'Skipped %d cancelled order(s) %s: %s',
            count($orderNumbers),
            $reason,
            implode(', ', $orderNumbers),
        ));
    }
}
