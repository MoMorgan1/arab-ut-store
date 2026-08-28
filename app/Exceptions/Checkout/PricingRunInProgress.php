<?php

namespace App\Exceptions\Checkout;

use Exception;

/**
 * A coins quote was computed from pre-run pricing rules while the locked
 * variant row already carried the new price_version - a run is committing
 * right now. Transient: the customer retries in a moment and it clears.
 *
 * Distinct from a replaced variant, which is permanent and is repriced onto
 * the live variant instead.
 */
final class PricingRunInProgress extends Exception {}
