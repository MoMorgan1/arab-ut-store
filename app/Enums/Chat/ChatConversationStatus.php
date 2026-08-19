<?php

namespace App\Enums\Chat;

enum ChatConversationStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Archived = 'archived';
}
