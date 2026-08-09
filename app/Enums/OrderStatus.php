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
}
