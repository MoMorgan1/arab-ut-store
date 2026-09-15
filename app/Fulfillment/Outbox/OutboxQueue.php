<?php

namespace App\Fulfillment\Outbox;

use App\Models\IntegrationEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * What a publishing command does around the sends: hands back rows a dead
 * run left in `processing`, retires rows past the attempt ceiling, and
 * selects what is due. One event type per call, so each command owns its
 * own queue and its own ceiling.
 */
final class OutboxQueue
{
    /** A run that claimed a row and died leaves it here; after this long it is anyone's again. */
    private const int STALE_CLAIM_MINUTES = 10;

    public function reclaimStale(string $eventType): void
    {
        IntegrationEvent::query()
            ->where('event_type', $eventType)
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(self::STALE_CLAIM_MINUTES))
            ->update(['status' => 'pending', 'available_at' => now()]);
    }

    /**
     * Retire events the publisher would otherwise skip forever.
     *
     * The selection below only takes rows below the attempt ceiling, so a row
     * that reaches it used to keep status "pending" for eternity - invisible to
     * failed_jobs, uncounted by the queue-health panel, and a paid order that
     * n8n never heard about. Marking it failed is what makes it surface at all.
     *
     * @param  string  $label  how the log names the event: "Paid-order", "Challenge"
     * @return int Number of rows this run actually moved to failed.
     */
    public function retireExhausted(string $eventType, int $ceiling, string $label): int
    {
        $exhausted = IntegrationEvent::query()
            ->where('event_type', $eventType)
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

                Log::error("{$label} event retired after exhausting every delivery attempt.", [
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

    /** @return Collection<int, IntegrationEvent> */
    public function due(string $eventType, int $ceiling, int $limit = 50): Collection
    {
        return IntegrationEvent::query()
            ->where('event_type', $eventType)
            ->where('status', 'pending')
            ->where('attempts', '<', $ceiling)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }
}
