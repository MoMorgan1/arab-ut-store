<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\PruneObservationGaps as PruneObservationGapsAction;
use Illuminate\Console\Command;

final class PruneObservationGaps extends Command
{
    protected $signature = 'fulfillment:prune-observation-gaps';

    protected $description = 'Delete measured observation gaps past the retention window';

    public function handle(PruneObservationGapsAction $prune): int
    {
        $deleted = $prune->execute();

        $this->components->info("Pruned {$deleted} observation gap(s).");

        return self::SUCCESS;
    }
}
