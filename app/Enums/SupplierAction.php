<?php

namespace App\Enums;

/**
 * The things a customer may be allowed to do to their own stuck job.
 *
 * This is an allowed-action set, not one boolean: editing credentials and
 * resuming are gated separately, and a challenge retry is narrower still.
 */
enum SupplierAction: string
{
    case EditCredentials = 'edit_credentials';
    case Resume = 'resume';
    case RetryChallenge = 'retry_challenge';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $action): string => $action->value, self::cases());
    }
}
