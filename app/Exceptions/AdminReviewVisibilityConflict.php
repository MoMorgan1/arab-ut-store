<?php

namespace App\Exceptions;

use Exception;

final class AdminReviewVisibilityConflict extends Exception
{
    public function __construct(
        public readonly string $reviewPublicId,
        public readonly bool $currentVisible,
        string $message = 'This review\'s storefront visibility has changed.',
    ) {
        parent::__construct($message, 409);
    }
}
