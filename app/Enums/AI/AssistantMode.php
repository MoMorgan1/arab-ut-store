<?php

namespace App\Enums\AI;

enum AssistantMode: string
{
    case Agent = 'agent';
    case Demo = 'demo';
    case None = 'none';
}
