<?php

namespace App\Actions\Orders;

use App\Models\Order;
use App\Notifications\NewPaidOrderAlert;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the owner a customer has paid, when an address is configured.
 *
 * Called next to the customer receipt on every path that takes money from a
 * customer (wallet, Paylink). A manual order is the owner's own doing and
 * gets no alert. No address configured means no mail and no error: the
 * checkout is the customer's, and the alert is a convenience on top of it.
 */
final class AlertOwnerOfPaidOrder
{
    public function execute(Order $order): void
    {
        $email = config('store.order_alerts.email');

        if (! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        Notification::route('mail', $email)->notify(new NewPaidOrderAlert($order));
    }
}
