<?php

namespace App\Exceptions;

use Exception;

final class AdminProductVisibilityConflict extends Exception
{
    public function __construct(
        public readonly string $productPublicId,
        public readonly bool $currentHidden,
        string $message = 'This product\'s storefront visibility has changed.',
    ) {
        parent::__construct($message, 409);
    }
}
