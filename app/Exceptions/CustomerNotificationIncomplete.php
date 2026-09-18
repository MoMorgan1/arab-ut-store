<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A customer notification could not be composed for sending.
 *
 * Carries a short reason the publisher stores as `last_error` - never a
 * phone number, never message text. The queue-health panel reads the reason,
 * not the secret.
 */
final class CustomerNotificationIncomplete extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
