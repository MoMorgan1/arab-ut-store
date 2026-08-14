<?php

namespace App\Actions\Fulfillment;

use App\Models\IntegrationEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use UnexpectedValueException;

final class PublishOrderPaidEvent
{
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

        try {
            [$url, $key, $secret] = $this->configuration();
            $body = json_encode([
                'eventId' => $event->event_id,
                'eventType' => $event->event_type,
                'schemaVersion' => $event->schema_version,
                'occurredAt' => $event->created_at->utc()->toIso8601String(),
                'data' => $event->payload,
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
                ->timeout(12)
                ->post($url);
            $acknowledged = $response->successful()
                && $response->json('data.acknowledged') === true;
        } catch (ConnectionException|JsonException|UnexpectedValueException) {
            $acknowledged = false;
        }

        if (! $acknowledged) {
            $this->release($event);

            return false;
        }

        IntegrationEvent::query()->whereKey($event->id)->where('status', 'processing')->update([
            'status' => 'processed',
            'processed_at' => now(),
            'last_error' => null,
            'updated_at' => now(),
        ]);

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

    private function release(IntegrationEvent $event): void
    {
        $delayMinutes = min(60, 2 ** min(6, max(0, $event->attempts - 1)));

        IntegrationEvent::query()->whereKey($event->id)->where('status', 'processing')->update([
            'status' => 'pending',
            'available_at' => now()->addMinutes($delayMinutes),
            'last_error' => 'delivery_failed',
            'updated_at' => now(),
        ]);
    }
}
