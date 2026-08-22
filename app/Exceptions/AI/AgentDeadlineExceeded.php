<?php

namespace App\Exceptions\AI;

use RuntimeException;

final class AgentDeadlineExceeded extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Assistant request deadline exceeded.');
    }
}
