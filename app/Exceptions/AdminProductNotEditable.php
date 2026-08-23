<?php

namespace App\Exceptions;

use Exception;

final class AdminProductNotEditable extends Exception
{
    public function __construct(
        public readonly string $productPublicId,
        string $message = 'Automation-authoritative products are read-only in v1.',
    ) {
        parent::__construct($message, 422);
    }
}
