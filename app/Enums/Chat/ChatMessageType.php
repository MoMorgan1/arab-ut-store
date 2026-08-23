<?php

namespace App\Enums\Chat;

enum ChatMessageType: string
{
    case Text = 'text';
    case System = 'system';
    case InternalNote = 'internal_note';
}
