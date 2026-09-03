<?php

namespace App\Exceptions\Cart;

use RuntimeException;

/**
 * One line per product variant: the active cart already holds this variant.
 * A plain RuntimeException on purpose, so the existing DomainException
 * catches in the store controllers do not swallow it.
 */
final class DuplicateCartItem extends RuntimeException {}
