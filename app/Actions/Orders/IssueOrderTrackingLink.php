<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\OrderTrackingLink;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class IssueOrderTrackingLink
{
    /**
     * The absolute capability URL for an order, issued once and reused after.
     *
     * Returns the same URL on every call while the link is live, so a resend
     * never produces a second token that a message already in flight does not
     * reference.
     */
    public function execute(Order $order): string
    {
        // The resend path reads rather than writes, so it never takes the lock.
        $token = $this->liveToken($order);

        if ($token !== null) {
            return $this->urlFor($order, $token);
        }

        $lock = Cache::lock("order-tracking-link:{$order->id}", 10);

        // Two messages sent for the same order race here, and neither is allowed
        // to fail: the caller is a notification, and a message that cannot name a
        // link is a message that should not be sent. So the loser waits for the
        // holder to commit and then re-reads its token.
        //
        // Waiting has to be able to give up, and giving up must not throw. If the
        // holder outlasts the wait we carry on anyway: the re-read below returns
        // their token if they did commit, and the unique index on order_id turns a
        // genuine collision into one more re-read rather than a duplicate token
        // that a delivered message would never reference.
        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            // Nothing to handle. The two reads below are the recovery.
        }

        try {
            return $this->urlFor($order, $this->liveToken($order) ?? $this->issue($order));
        } catch (UniqueConstraintViolationException $exception) {
            $token = $this->liveToken($order);

            if ($token === null) {
                // The row exists (that is what the constraint said) but carries no
                // live token, so something other than this race wrote it. Say so
                // rather than invent a link.
                throw $exception;
            }

            return $this->urlFor($order, $token);
        } finally {
            $lock->release();
        }
    }

    /** Stamps the link revoked; a later execute re-issues a fresh token. */
    public function revoke(Order $order): void
    {
        OrderTrackingLink::query()
            ->where('order_id', $order->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    private function liveToken(Order $order): ?string
    {
        $link = OrderTrackingLink::query()->where('order_id', $order->id)->first();

        if (! $link instanceof OrderTrackingLink || $link->revoked_at !== null) {
            return null;
        }

        return $link->token_encrypted;
    }

    private function issue(Order $order): string
    {
        $token = Str::random(48);

        $link = OrderTrackingLink::query()->where('order_id', $order->id)->first();

        if ($link instanceof OrderTrackingLink) {
            // A revoked link is re-issued in place: same row, fresh token, cleared
            // stamps. The old token's hash no longer matches, so it 404s on its own.
            $link->token_hash = hash('sha256', $token);
            $link->token_encrypted = $token;
            $link->revoked_at = null;
            $link->last_used_at = null;
            $link->save();
        } else {
            OrderTrackingLink::create([
                'order_id' => $order->id,
                'token_hash' => hash('sha256', $token),
                'token_encrypted' => $token,
            ]);
        }

        return $token;
    }

    private function urlFor(Order $order, string $token): string
    {
        return $order->locale === 'en'
            ? route('localized.store.orders.track', ['locale' => 'en', 'token' => $token])
            : route('store.orders.track', ['token' => $token]);
    }
}
