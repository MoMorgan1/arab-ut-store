<?php

namespace App\Services\AI;

use GuzzleHttp\Handler\StreamHandler;

final readonly class OpenAiStreamHandlerStack
{
    public function make(): StreamHandler
    {
        return new StreamHandler;
    }
}
