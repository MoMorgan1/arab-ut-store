<?php

namespace App\Console\Commands;

use App\Enums\NotificationStatus;
use App\Models\IntegrationEvent;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RequeueFailedPaidEvent extends Command
{
    protected $signature = 'orders:requeue-paid-event
        {event_id : The event_id (ULID) from the retirement error log or the admin queue-health panel}';

    protected $description = 'Requeue a failed outbox event (order.paid, challenge.ready or customer.notify) so its publisher delivers it again';

    public function handle(): int
    {
        $eventId = (string) $this->argument('event_id');

        $event = IntegrationEvent::query()
            ->whereIn('event_type', ['order.paid', 'challenge.ready', 'customer.notify'])
            ->where('event_id', $eventId)
            ->first();

        if ($event === null) {
            $this->error(sprintf('No outbox event exists with event id "%s".', $eventId));

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

        // A retired customer notification left its delivery row failed too,
        // and the publisher finishes a non-queued row without sending. The
        // requeue grants both rows a fresh budget together, or the event's
        // would be spent on a row that refuses to send.
        if ($event->event_type === 'customer.notify') {
            NotificationDelivery::query()
                ->where('integration_event_id', $event->id)
                ->where('status', NotificationStatus::Failed)
                ->update([
                    'status' => NotificationStatus::Queued,
                    'last_error' => null,
                    'failed_at' => null,
                    'available_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $payload = json_decode((string) $event->getRawOriginal('payload'), true);

        Log::info('Outbox event requeued for delivery.', [
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
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
