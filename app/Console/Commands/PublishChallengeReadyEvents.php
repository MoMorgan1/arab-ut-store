<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\PublishChallengeReadyEvent;
use App\Fulfillment\Outbox\OutboxQueue;
use Illuminate\Console\Command;

final class PublishChallengeReadyEvents extends Command
{
    protected $signature = 'orders:publish-challenge-events';

    protected $description = 'Publish due challenge-ready outbox events to the configured n8n workflow';

    public function handle(PublishChallengeReadyEvent $publish, OutboxQueue $queue): int
    {
        $queue->reclaimStale('challenge.ready');

        $ceiling = max(1, (int) config('services.n8n.challenge_ready_max_attempts', 10));

        $retired = $queue->retireExhausted('challenge.ready', $ceiling, 'Challenge');

        $events = $queue->due('challenge.ready', $ceiling);
        $failed = 0;

        foreach ($events as $event) {
            if (! $publish->execute($event)) {
                $failed++;
            }
        }

        if ($retired > 0) {
            $this->warn(sprintf(
                'Retired %d challenge event(s) as failed after %d attempt(s); requeue with orders:requeue-paid-event.',
                $retired,
                $ceiling,
            ));
        }

        $this->info(sprintf('Processed %d challenge event(s); %d deferred.', $events->count(), $failed));

        // As with the paid-order publisher: a deferral is the outbox working,
        // not the command failing.
        return self::SUCCESS;
    }
}
