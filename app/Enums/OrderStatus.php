<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Received = 'received';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    /**
     * The status a customer is shown.
     *
     * Staff need the full ladder, but "payment received" and "in progress" are
     * one waiting state to the customer, and a refund is a cancellation they
     * were paid back for. Collapsing here rather than in the labels keeps the
     * existing keys, icons and CSS working untouched.
     */
    public function forCustomer(): self
    {
        return match ($this) {
            self::Received => self::InProgress,
            self::Refunded => self::Cancelled,
            default => $this,
        };
    }
}
