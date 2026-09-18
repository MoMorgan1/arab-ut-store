<?php

namespace App\Fulfillment\Outbox;

use App\Models\IntegrationEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JsonException;
use UnexpectedValueException;

/**
 * The mechanics every outbox publisher shares: claiming a row, signing and
 * posting the envelope to n8n, and putting the row back with a reason.
 *
 * The envelope and the signature are the store's one convention for talking
 * to n8n (`docs/api/n8n-fulfillment-v1.md`): `X-ArabUT-Key`, a unix
 * timestamp, the event id, and an HMAC-SHA256 over `timestamp\neventId\nbody`
 * with the publisher's own secret. What differs between publishers is the
 * event type, the configuration triple, and the data block composed at send
 * time - so those are arguments here and nothing else is.
 */
final class SignedOutboxDelivery
{
    /**
     * Claims the row for this run, or reports that someone else has it.
     *
     * A conditional update on `pending` and `available_at` is what keeps two
     * scheduler ticks from sending the same event; the attempt counter moves
     * with the claim so a crash after the claim still counts.
     */
    public function claim(IntegrationEvent $event, string $eventType): bool
    {
        $claimed = IntegrationEvent::query()
            ->whereKey($event->id)
            ->where('event_type', $eventType)
            ->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->update([
                'status' => 'processing',
                'attempts' => DB::raw('attempts + 1'),
                'updated_at' => now(),
            ]);

        return $claimed === 1;
    }

    /**
     * Posts the signed envelope and says whether n8n acknowledged it.
     *
     * Every failure - configuration that fails closed, a connection error, a
     * body that cannot be encoded, a 5xx, `acknowledged: false` - is the
     * same answer to the caller: not acknowledged, so release and retry.
     * Nothing about the response is persisted.
     *
     * @param  string  $configurationPrefix  the `services.n8n.<prefix>_url|_key|_secret` triple
     * @param  array<string, mixed>  $data
     */
    public function deliver(IntegrationEvent $event, string $configurationPrefix, int $schemaVersion, array $data): bool
    {
        try {
            [$url, $key, $secret] = $this->configuration($configurationPrefix);
            $body = json_encode([
                'eventId' => $event->event_id,
                'eventType' => $event->event_type,
                'schemaVersion' => $schemaVersion,
                'occurredAt' => $event->created_at->utc()->toIso8601String(),
                'data' => $data,
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
                // The workflows answer only once the supplier call is made
                // and reported: ship-coins spends up to a dozen UTT prediction
                // calls plus an FFT preview before the placement itself. A
                // run that outlasts this is not harmful: the row is retried,
                // and a retry carries only what is still to do.
                ->timeout(60)
                ->post($url);

            return $response->successful() && $response->json('data.acknowledged') === true;
        } catch (ConnectionException|JsonException|UnexpectedValueException) {
            return false;
        }
    }

    public function markProcessed(IntegrationEvent $event): void
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
    public function release(IntegrationEvent $event, string $reason): void
    {
        $delayMinutes = min(60, 2 ** min(6, max(0, $event->attempts - 1)));

        IntegrationEvent::query()->whereKey($event->id)->where('status', 'processing')->update([
            'status' => 'pending',
            'available_at' => now()->addMinutes($delayMinutes),
            'last_error' => $reason,
            'updated_at' => now(),
        ]);
    }

    /**
     * Whether the publisher triple for this prefix is usable, without
     * touching anything. The customer-notification publisher asks first so
     * it stays inert - no claim, no attempt, no log line - until the owner
     * configures the webhook.
     */
    public function isConfigured(string $prefix): bool
    {
        $url = config("services.n8n.{$prefix}_url");
        $key = config("services.n8n.{$prefix}_key");
        $secret = config("services.n8n.{$prefix}_secret");
        $parts = is_string($url) ? parse_url($url) : false;

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null)
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && is_string($key)
            && preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $key) === 1
            && is_string($secret)
            && strlen($secret) >= 32;
    }

    /**
     * Fails closed: an http URL, a URL with credentials in it, a key with
     * characters a header must not carry, or a short secret is no
     * configuration at all.
     *
     * @return array{string, string, string}
     */
    private function configuration(string $prefix): array
    {
        $url = config("services.n8n.{$prefix}_url");
        $key = config("services.n8n.{$prefix}_key");
        $secret = config("services.n8n.{$prefix}_secret");

        if (! $this->isConfigured($prefix) || ! is_string($url) || ! is_string($key) || ! is_string($secret)) {
            throw new UnexpectedValueException('Publisher configuration is unavailable.');
        }

        return [$url, $key, $secret];
    }
}
