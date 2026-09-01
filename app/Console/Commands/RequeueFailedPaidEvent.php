<?php

namespace App\Console\Commands;

use App\Models\IntegrationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RequeueFailedPaidEvent extends Command
{
    protected $signature = 'orders:requeue-paid-event
        {event_id : The event_id (ULID) from the retirement error log or the admin queue-health panel}';

    protected $description = 'Requeue a failed paid-order event so the publisher delivers it again';

    public function handle(): int
    {
        $eventId = (string) $this->argument('event_id');

        $event = IntegrationEvent::query()
            ->where('event_type', 'order.paid')
            ->where('event_id', $eventId)
            ->first();

        if ($event === null) {
            $this->error(sprintf('No paid-order event exists with event id "%s".', $eventId));

            return self::FAILURE;
        }

        if ($event->status !== 'failed') {
            $this->error(sprintf(
                'Event "%s" has status "%s", not "failed"; there is nothing to requeue.',
                $eventId,
                $event->status,
            ));

            return self::FAILURE;
        }

        // Guarded on status, so a concurrent requeue or publisher run cannot
        // double-reset the row. Resetting attempts grants a fresh retry budget:
        // the selection only takes rows below the ceiling, so keeping the old
        // count would make the requeue a silent no-op.
        $requeued = IntegrationEvent::query()
            ->whereKey($event->id)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'attempts' => 0,
                'available_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($requeued !== 1) {
            $this->error(sprintf('Event "%s" is no longer failed; nothing was requeued.', $eventId));

            return self::FAILURE;
        }

        $payload = json_decode((string) $event->getRawOriginal('payload'), true);

        Log::info('Paid-order event requeued for delivery.', [
            'event_id' => $event->event_id,
            'aggregate_type' => $event->aggregate_type,
            'aggregate_id' => $event->aggregate_id,
            'order_number' => is_array($payload) && is_string($payload['order_number'] ?? null)
                ? $payload['order_number']
                : null,
        ]);

        $this->info(sprintf('Event "%s" requeued; the publisher will retry it on its next run.', $eventId));

        return self::SUCCESS;
    }
}
