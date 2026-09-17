<?php

namespace App\Enums;

use App\Admin\Actions\ResendFulfillmentItem;

/**
 * Which of the three unrelated writes an operator asked for.
 *
 * "Re-send" is one word for three different instructions, and conflating them
 * is how an order gets paid for twice - so the caller names one and
 * {@see ResendFulfillmentItem} still checks the row allows
 * it. An enum rather than a string because the match on it must be exhaustive:
 * a fourth instruction should fail to compile, not fall through to a default
 * arm that quietly reports "nothing to do".
 */
enum FulfillmentResendAction: string
{
    /** Re-open the order's placement request, for an item with no job. */
    case Send = 'send';

    /** The supplier's own resume instruction, for a job that stalled. */
    case Resume = 'resume';

    /** The supplier's own challenge retry, for one position on the card. */
    case RetryChallenge = 'retry_challenge';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
