<?php

namespace App\Exceptions;

use Exception;

final class AdminVariantPriceConflict extends Exception
{
    public function __construct(
        public readonly string $variantPublicId,
        public readonly int $currentPriceVersion,
        public readonly int $currentEffectivePriceHalalah,
        string $message = 'This variant has been repriced since it was loaded.',
    ) {
        parent::__construct($message, 409);
    }
}
