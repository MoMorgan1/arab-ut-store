<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Models\User;
use App\Notifications\ReviewInviteNotification;

final class InviteOrderReview
{
    /**
     * Ask the customer what they thought, once, an hour after completion.
     *
     * The timestamp is written inside the same transaction as the completion,
     * so a rolled-back attempt invites nobody and the transaction's three
     * retries cannot send a second mail. Imported Salla orders are completed
     * from import and get no invitation.
     */
    public function execute(Order $order): void
    {
        if ($order->channel === 'salla_import' || $order->review_invited_at !== null) {
            return;
        }

        $customer = $order->user;

        if (! $customer instanceof User) {
            return;
        }

        $order->forceFill(['review_invited_at' => now()])->save();

        $customer->notify(
            (new ReviewInviteNotification($order))->delay(now()->addHour()),
        );
    }
}
