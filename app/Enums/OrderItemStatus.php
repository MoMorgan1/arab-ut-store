<?php

namespace App\Enums;

enum OrderItemStatus: string
{
    case PendingPayment = 'pending_payment';
    case Received = 'received';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case Failed = 'failed';

    /** Mirrors OrderStatus::forCustomer(); see the note there. */
    public function forCustomer(): self
    {
        return match ($this) {
            self::Received => self::InProgress,
            self::Refunded => self::Cancelled,
            default => $this,
        };
    }
}
