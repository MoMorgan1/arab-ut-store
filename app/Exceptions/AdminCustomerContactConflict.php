<?php

namespace App\Exceptions;

use Exception;

final class AdminCustomerContactConflict extends Exception
{
    public function __construct(
        public readonly string $customerPublicId,
        public readonly string $currentUpdatedAt,
        string $message = 'Customer contact details have changed.',
    ) {
        parent::__construct($message, 409);
    }
}
