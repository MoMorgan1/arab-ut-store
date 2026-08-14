<?php

namespace App\Console\Commands;

use App\Actions\Fulfillment\PublishOrderPaidEvent;
use App\Models\IntegrationEvent;
use Illuminate\Console\Command;

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

        $events = IntegrationEvent::query()
            ->where('event_type', 'order.paid')
            ->where('status', 'pending')
            ->where('attempts', '<', 10)
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

        $this->info(sprintf('Processed %d paid-order event(s); %d deferred.', $events->count(), $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
