<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\PublishOrderPaidEvent;
use App\Models\IntegrationEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PublishOrderPaidEvents extends Command
{
    protected $signature = 'orders:publish-paid-events';

    protected $description = 'Publish due order-paid outbox events to the configured n8n workflow';

    public function handle(PublishOrderPaidEvent $publish): int
    {
        IntegrationEvent::query()
            ->where('event_type', 'order.paid')
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->update(['status' => 'pending', 'available_at' => now()]);

        $ceiling = max(1, (int) config('services.n8n.order_paid_max_attempts', 10));

        $retired = $this->retireExhausted($ceiling);

        $events = IntegrationEvent::query()
            ->where('event_type', 'order.paid')
            ->where('status', 'pending')
            ->where('attempts', '<', $ceiling)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(50)
            ->get();
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

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Retire events the publisher would otherwise skip forever.
     *
     * The selection below only takes rows below the attempt ceiling, so a row
     * that reaches it used to keep status "pending" for eternity - invisible to
     * failed_jobs, uncounted by the queue-health panel, and a paid order that
     * n8n never heard about. Marking it failed is what makes it surface at all.
     *
     * @return int Number of rows this run actually moved to failed.
     */
    private function retireExhausted(int $ceiling): int
    {
        $exhausted = IntegrationEvent::query()
            ->where('event_type', 'order.paid')
            ->where('status', 'pending')
            ->where('attempts', '>=', $ceiling)
            ->orderBy('id')
            ->limit(100)
            ->get();

        $retired = 0;

        foreach ($exhausted as $event) {
            $moved = IntegrationEvent::query()
                ->whereKey($event->id)
                ->where('status', 'pending')
                ->where('attempts', '>=', $ceiling)
                ->update([
                    'status' => 'failed',
                    'last_error' => 'max_attempts_exceeded',
                    'updated_at' => now(),
                ]);

            if ($moved === 1) {
                $retired++;

                $payload = json_decode((string) $event->getRawOriginal('payload'), true);

                Log::error('Paid-order event retired after exhausting every delivery attempt.', [
                    'event_id' => $event->event_id,
                    'aggregate_type' => $event->aggregate_type,
                    'aggregate_id' => $event->aggregate_id,
                    'order_number' => is_array($payload) && is_string($payload['order_number'] ?? null)
                        ? $payload['order_number']
                        : null,
                    'attempts' => $event->attempts,
                    'requeue' => 'php artisan orders:requeue-paid-event '.$event->event_id,
                ]);
            }
        }

        return $retired;
    }
}
