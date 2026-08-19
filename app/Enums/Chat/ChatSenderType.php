<?php

namespace App\Enums\Chat;

enum ChatSenderType: string
{
    case Customer = 'customer';
    case Assistant = 'assistant';
    case System = 'system';
}
