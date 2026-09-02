<?php

namespace App\Exceptions;

use Exception;

final class AdminFaqEntryVisibilityConflict extends Exception
{
    public function __construct(
        public readonly string $faqEntryPublicId,
        public readonly bool $currentVisible,
        string $message = "This FAQ entry's storefront visibility has changed.",
    ) {
        parent::__construct($message, 409);
    }
}
