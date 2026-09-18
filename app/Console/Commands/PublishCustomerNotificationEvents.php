<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\PublishCustomerNotificationEvent;
use App\Enums\NotificationStatus;
use App\Fulfillment\Notifications\CustomerNotificationCatalog;
use App\Fulfillment\Outbox\OutboxQueue;
use App\Fulfillment\Outbox\SignedOutboxDelivery;
use App\Models\IntegrationEvent;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PublishCustomerNotificationEvents extends Command
{
    protected $signature = 'orders:publish-customer-notifications';

    protected $description = 'Publish due customer-notification outbox events to the configured n8n webhook';

    public function handle(PublishCustomerNotificationEvent $publish, OutboxQueue $queue): int
    {
        // The owner switch. With no webhook configured this command touches
        // nothing - no claims, no attempts, no retirement, and no log line
        // that reads like an alarm. Queued rows simply wait, and stale ones
        // expire at send time once sending is ever switched on.
        if (! app(SignedOutboxDelivery::class)->isConfigured('customer_notify')) {
            $this->info('Customer notifications are not configured; leaving the queue untouched.');

            return self::SUCCESS;
        }

        $queue->reclaimStale(CustomerNotificationCatalog::EVENT_TYPE);

        $ceiling = max(1, (int) config('services.n8n.customer_notify_max_attempts', 10));

        $retired = $queue->retireExhausted(CustomerNotificationCatalog::EVENT_TYPE, $ceiling, 'Customer-notification');

        if ($retired > 0) {
            // Retirement is the outbox's word, and the delivery row has to
            // hear it. `retireExhausted` only knows about integration_events;
            // a notification left reading `queued` beside a failed event is
            // saying it is still owed when nothing will ever send it, and the
            // requeue command - which looks for a failed row - would not find
            // it either.
            NotificationDelivery::query()
                ->whereIn('integration_event_id', IntegrationEvent::query()
                    ->select('id')
                    ->where('event_type', CustomerNotificationCatalog::EVENT_TYPE)
                    ->where('status', 'failed'))
                ->where('status', NotificationStatus::Queued)
                ->update([
                    'status' => NotificationStatus::Failed,
                    'failed_at' => now(),
                    'last_error' => DB::raw("COALESCE(last_error, 'max_attempts_exceeded')"),
                    'updated_at' => now(),
                ]);
        }

        $events = $queue->due(CustomerNotificationCatalog::EVENT_TYPE, $ceiling);
        $failed = 0;

        foreach ($events as $event) {
            if (! $publish->execute($event)) {
                $failed++;
            }
        }

        if ($retired > 0) {
            $this->warn(sprintf(
                'Retired %d customer-notification event(s) as failed after %d attempt(s); requeue with orders:requeue-paid-event.',
                $retired,
                $ceiling,
            ));
        }

        $this->info(sprintf('Processed %d customer-notification event(s); %d deferred.', $events->count(), $failed));

        // As with the paid-order publisher: a deferral is the outbox working,
        // not the command failing.
        return self::SUCCESS;
    }
}
