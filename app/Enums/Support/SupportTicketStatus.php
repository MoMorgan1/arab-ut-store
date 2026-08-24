<?php

namespace App\Enums\Support;

enum SupportTicketStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function isLive(): bool
    {
        return $this === self::Open;
    }
}
