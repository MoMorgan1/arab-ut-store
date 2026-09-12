<?php

namespace App\Suppliers;

/**
 * The supplier's verdict on an action request.
 *
 * This is not an HTTP success flag: both suppliers answer with HTTP 200 and a
 * failure body, so acceptance is decided from the decoded body.
 */
final readonly class SupplierActionResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public bool $accepted,
        public ?string $code,
        public array $payload,
    ) {}
}
