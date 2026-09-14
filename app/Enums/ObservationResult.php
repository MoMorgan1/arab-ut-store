<?php

namespace App\Enums;

/**
 * The outcome of one scheduled read of a fulfillment job's supplier.
 *
 * These are the answers a sweep needs to decide how to advance a job's
 * `next_poll_at` and how to account for supplier latency in its summary log.
 * They are deliberately not the customer's vocabulary: no case here ever
 * becomes a string a customer reads.
 */
enum ObservationResult: string
{
    /** A reading arrived and was applied. */
    case Observed = 'observed';

    /**
     * A response arrived that we could not use: no challenge ids on the
     * placement, or a bulk challenge response naming none of our ids.
     */
    case Unreadable = 'unreadable';

    /** The supplier call failed with a SupplierUnavailable. */
    case Unavailable = 'unavailable';

    /** The supplier could not be called because its configuration is missing. */
    case NotConfigured = 'not_configured';

    /** Another reader holds the job's lock. */
    case Busy = 'busy';
}
