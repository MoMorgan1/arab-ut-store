<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\PublishOrderPaidEvent;
use App\Fulfillment\Outbox\OutboxQueue;
use Illuminate\Console\Command;

final class PublishOrderPaidEvents extends Command
{
    protected $signature = 'orders:publish-paid-events';

    protected $description = 'Publish due order-paid outbox events to the configured n8n workflow';

    public function handle(PublishOrderPaidEvent $publish, OutboxQueue $queue): int
    {
        $queue->reclaimStale('order.paid');

        $ceiling = max(1, (int) config('services.n8n.order_paid_max_attempts', 10));

        $retired = $queue->retireExhausted('order.paid', $ceiling, 'Paid-order');

        $events = $queue->due('order.paid', $ceiling);
        $failed = 0;

        foreach ($events as $event) {
            if (! $publish->execute($event)) {
                $failed++;
            }
        }

        if ($retired > 0) {
            $this->warn(sprintf(
                'Retired %d paid-order event(s) as failed after %d attempt(s); requeue with orders:requeue-paid-event.',
                $retired,
                $ceiling,
            ));
        }

        $this->info(sprintf('Processed %d paid-order event(s); %d deferred.', $events->count(), $failed));

        // A deferral is the outbox working as designed - n8n not answering,
        // or a request the store cannot compose yet - and the row already
        // carries the reason and its backoff. Exiting non-zero for it made
        // the scheduler log "failed with exit code [1]" every minute an order
        // waited, on top of the warning the publisher had already written,
        // and buried the one line that matters: the retirement error above.
        return self::SUCCESS;
    }
}
