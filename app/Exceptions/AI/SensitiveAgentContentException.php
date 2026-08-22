<?php

namespace App\Exceptions\AI;

use RuntimeException;

final class SensitiveAgentContentException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sensitive agent prompt content was rejected.');
    }
}
