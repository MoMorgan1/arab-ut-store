<?php

namespace App\Exceptions;

use Exception;

/**
 * A grant the Admin asked for that the rules refuse, carrying a stable reason
 * the UI translates. The reason is deliberately coarse: it never reveals
 * whether an address belongs to an account that simply cannot be promoted.
 */
final class AdminStaffGrantRejected extends Exception
{
    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message, 422);
    }

    public static function noSuchAccount(): self
    {
        return new self('no_such_account', 'No account exists for that email address.');
    }

    public static function serviceAccount(): self
    {
        return new self('no_such_account', 'Service accounts cannot receive interactive Admin access.');
    }

    public static function self(): self
    {
        return new self('self', 'You cannot change your own role.');
    }

    public static function alreadyGranted(string $role): self
    {
        return new self('already_granted', "That account already has the {$role} role.");
    }

    public static function inactiveAccount(): self
    {
        return new self('inactive_account', 'Reactivate the account before granting Admin access.');
    }
}
