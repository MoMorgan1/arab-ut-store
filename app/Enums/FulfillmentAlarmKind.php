<?php

namespace App\Enums;

/**
 * Why an operator is being told about an item nobody else can see.
 *
 * Both cases are silence rather than failure: a failed placement already
 * reaches Mohamed through n8n's own alerting, and a failed read already backs
 * off and retries. What neither can report is that nothing is happening at
 * all - so these are deliberately operator vocabulary, and no case here ever
 * becomes a string a customer reads.
 */
enum FulfillmentAlarmKind: string
{
    /**
     * A paid automated item that no supplier was ever asked to deliver.
     *
     * n8n cannot report this one: from its side the placement succeeded and
     * the callback simply never arrived, so the order is invisible to
     * everything except the customer waiting for it.
     */
    case Unplaced = 'unplaced';

    /**
     * A placed item whose supplier reads have told us nothing for several
     * sweeps in a row.
     *
     * Covers a challenge id the supplier no longer recognises - the read is
     * answered, it just never mentions our id - as well as a supplier that
     * has been unreachable long enough to stop being a blip.
     */
    case Silent = 'silent';
}
