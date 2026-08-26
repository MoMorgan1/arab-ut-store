<?php

namespace App\Console\Commands;

use App\Actions\Pricing\PrunePricingHistory as PrunePricingHistoryAction;
use Illuminate\Console\Command;

final class PrunePricingHistory extends Command
{
    protected $signature = 'pricing-history:prune';

    protected $description = 'Delete pricing runs and superseded price rules past the retention window';

    public function handle(PrunePricingHistoryAction $prunePricingHistory): int
    {
        ['runs' => $runs, 'rules' => $rules] = $prunePricingHistory->execute();

        $this->components->info("Pruned {$runs} pricing run(s) and {$rules} superseded price rule(s).");

        return self::SUCCESS;
    }
}
