<?php

namespace App\Exceptions;

use Exception;

final class AdminCategoryVisibilityConflict extends Exception
{
    public function __construct(
        public readonly string $categoryPublicId,
        public readonly bool $currentHidden,
        string $message = 'This category\'s storefront visibility has changed.',
    ) {
        parent::__construct($message, 409);
    }
}
