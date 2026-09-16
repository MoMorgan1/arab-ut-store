<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\AlertOwnerOfFulfillmentSilence;
use App\Actions\Fulfillment\SweepFulfillmentAlarms as SweepFulfillmentAlarmsAction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Looks for paid work that has stopped moving, and tells Mohamed once.
 *
 * The state pass and the mail are two steps on purpose: the panel's count has
 * to be right even on a run where no address is configured or the mail cannot
 * be queued, so the sweep commits what it found before anything tries to send.
 */
final class SweepFulfillmentAlarms extends Command
{
    protected $signature = 'fulfillment:alarms';

    protected $description = 'Raise and clear alarms for paid items no supplier is working on';

    public function handle(SweepFulfillmentAlarmsAction $sweep, AlertOwnerOfFulfillmentSilence $alert): int
    {
        $summary = $sweep->execute();

        if ($summary['raised'] > 0 || $summary['resolved'] > 0) {
            Log::info('Fulfillment alarm sweep summary.', $summary);
        }

        $notified = $alert->execute();

        $this->components->info(sprintf(
            'Raised %d, resolved %d, %d open, %d notified.',
            $summary['raised'],
            $summary['resolved'],
            $summary['open'],
            $notified,
        ));

        return self::SUCCESS;
    }
}
