<?php

namespace App\Exceptions;

use Exception;

final class AdminProductConflict extends Exception
{
    /**
     * @param array{
     *     name_ar: string,
     *     name_en: string,
     *     description_ar: string|null,
     *     description_en: string|null,
     *     is_visible: bool,
     *     sort_order: int
     * } $current
     */
    public function __construct(
        public readonly string $productPublicId,
        public readonly array $current,
        string $message = 'Product details have changed.',
    ) {
        parent::__construct($message, 409);
    }
}
