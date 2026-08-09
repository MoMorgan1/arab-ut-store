<?php

namespace App\Enums;

enum FulfillmentStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case InProgress = 'in_progress';
    case WaitingForCustomer = 'waiting_for_customer';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
