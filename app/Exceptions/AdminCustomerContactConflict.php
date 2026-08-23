<?php

namespace App\Exceptions;

use Exception;

final class AdminCustomerContactConflict extends Exception
{
    /**
     * @param  array{first_name: string, last_name: string, email: string, phone: string|null}  $current
     */
    public function __construct(
        public readonly string $customerPublicId,
        public readonly array $current,
        string $message = 'Customer contact details have changed.',
    ) {
        parent::__construct($message, 409);
    }
}
