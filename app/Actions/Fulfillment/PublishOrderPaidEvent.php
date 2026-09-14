<?php

namespace App\Actions\Fulfillment;

use App\Exceptions\PlacementRequestIncomplete;
use App\Models\IntegrationEvent;
use App\Models\Order;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;
use UnexpectedValueException;

/**
 * Sends one paid order's placement request to n8n.
 *
 * The stored outbox row names the order; the wire body adds the `items` block
 * `ComposePlacementRequest` builds at this moment - configuration, budget and
 * the EA account - so the request leaves with today's credentials and today's
 * supplier prices, and the table it came from never held either.
 */
final class PublishOrderPaidEvent
{
    public function __construct(private readonly ComposePlacementRequest $compose) {}

    public function execute(IntegrationEvent $event): bool
    {
        if ($event->fresh()?->status === 'processed') {
            return true;
        }

        $claimed = IntegrationEvent::query()
            ->whereKey($event->id)
            ->where('event_type', 'order.paid')
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return $event->fresh()?->status === 'processed';
        }

        $event->refresh();

        $order = Order::query()
            ->with('user')
            ->where('public_id', $event->aggregate_id)
            ->first();

        if (! $order instanceof Order) {
            $this->release($event, 'order_missing');

            return false;
        }

        try {
            $items = $this->compose->execute($order, now());
        } catch (PlacementRequestIncomplete $incomplete) {
            Log::warning('Placement request could not be composed; the event will be retried.', [
                'event_id' => $event->event_id,
                'order_number' => $order->order_number,
                'reason' => $incomplete->reason,
                'detail' => $incomplete->getMessage(),
            ]);
            $this->release($event, $incomplete->reason);

            return false;
        }

        // Every automated item gained a placement since the row was written -
        // staff pasted the references, or the items were cancelled. There is
        // nothing for n8n to place, and an empty request would only teach the
        // workflow to ignore requests.
        if ($items === []) {
            $this->markProcessed($event);

            return true;
        }

        try {
            [$url, $key, $secret] = $this->configuration();
            $body = json_encode([
                'eventId' => $event->event_id,
                'eventType' => $event->event_type,
                'schemaVersion' => 2,
                'occurredAt' => $event->created_at->utc()->toIso8601String(),
                'data' => [
                    ...$event->payload,
                    // Both suppliers file the account under a customer name;
                    // v14 sent Salla's full name. Phone and email stay out.
                    'customer_name' => (string) $order->user->name,
                    'items' => $items,
                ],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) now()->utc()->timestamp;
            $signature = hash_hmac('sha256', $timestamp."\n".$event->event_id."\n".$body, $secret);
            $response = Http::acceptJson()
                ->withHeaders([
                    'X-ArabUT-Key' => $key,
                    'X-ArabUT-Timestamp' => $timestamp,
                    'X-ArabUT-Event' => $event->event_id,
                    'X-ArabUT-Signature' => $signature,
                ])
                ->withBody($body, 'application/json')
                ->connectTimeout(5)
                // ship-coins answers only once the shipment is placed and
                // reported, and choosing a supplier is up to a dozen UTT
                // prediction calls plus an FFT preview before the placement
                // itself. A run that outlasts this is not harmful: the row is
                // retried, and a retry carries only what is still unplaced.
                ->timeout(60)
                ->post($url);
            $acknowledged = $response->successful()
                && $response->json('data.acknowledged') === true;
        } catch (ConnectionException|JsonException|UnexpectedValueException) {
            $acknowledged = false;
        }

        if (! $acknowledged) {
            $this->release($event, 'delivery_failed');

            return false;
        }

        $this->markProcessed($event);

        return true;
    }

    /** @return array{string, string, string} */
    private function configuration(): array
    {
        $url = config('services.n8n.order_paid_url');
        $key = config('services.n8n.order_paid_key');
        $secret = config('services.n8n.order_paid_secret');
        $parts = is_string($url) ? parse_url($url) : false;

        if (! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! is_string($key)
            || preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) !== 1
            || ! is_string($secret)
            || strlen($secret) < 32) {
            throw new UnexpectedValueException('Publisher configuration is unavailable.');
        }

        return [$url, $key, $secret];
    }

    private function markProcessed(IntegrationEvent $event): void
    {
        IntegrationEvent::query()->whereKey($event->id)->where('status', 'processing')->update([
            'status' => 'processed',
            'processed_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    /**
     * Back to pending with a backoff, and a reason the queue-health panel
     * and the retirement log can show: `delivery_failed` is n8n not
     * answering, the others are the store unable to compose the request.
     */
    private function release(IntegrationEvent $event, string $reason): void
    {
        $delayMinutes = min(60, 2 ** min(6, max(0, $event->attempts - 1)));

        IntegrationEvent::query()->whereKey($event->id)->where('status', 'processing')->update([
            'status' => 'pending',
            'available_at' => now()->addMinutes($delayMinutes),
            'last_error' => $reason,
            'updated_at' => now(),
        ]);
    }
}
