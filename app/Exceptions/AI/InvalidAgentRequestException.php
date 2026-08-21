<?php

namespace App\Exceptions\AI;

use RuntimeException;

final class InvalidAgentRequestException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The agent model request is invalid.');
    }
}
