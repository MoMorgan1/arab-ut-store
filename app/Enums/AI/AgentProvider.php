<?php

namespace App\Enums\AI;

enum AgentProvider: string
{
    case Fake = 'fake';
    case OpenAi = 'openai';
}
