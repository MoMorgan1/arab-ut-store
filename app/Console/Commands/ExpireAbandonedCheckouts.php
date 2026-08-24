<?php

namespace App\Console\Commands;

use App\Actions\Checkout\ExpireAbandonedCheckouts as ExpireAbandonedCheckoutsAction;
use Illuminate\Console\Command;

final class ExpireAbandonedCheckouts extends Command
{
    protected $signature = 'checkouts:expire-abandoned';

    protected $description = 'Cancel unpaid checkouts past their grace period, releasing the coupon uses they reserved';

    public function handle(ExpireAbandonedCheckoutsAction $expireAbandonedCheckouts): int
    {
        $cancelled = $expireAbandonedCheckouts->execute();

        $this->components->info("Cancelled {$cancelled} abandoned checkout(s).");

        return self::SUCCESS;
    }
}
