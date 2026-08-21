<?php

namespace App\Exceptions\AI;

use App\Enums\AI\AgentErrorCode;
use RuntimeException;

final class AgentConfigurationException extends RuntimeException
{
    public function __construct(public readonly AgentErrorCode $errorCode)
    {
        parent::__construct('Assistant runtime configuration is invalid.');
    }
}
