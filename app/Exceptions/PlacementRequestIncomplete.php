<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The placement request for an order could not be composed.
 *
 * Carries a short reason the outbox stores as `last_error`, so the queue
 * health panel and the retirement log say what actually stopped a paid order
 * reaching n8n - a missing pricing run reads differently from a purged
 * credential, and both differ from n8n being down.
 */
final class PlacementRequestIncomplete extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
