<?php

namespace App\Enums\Chat;

enum ChatHandoffState: string
{
    case None = 'none';
    case Offered = 'offered';
    case Requested = 'requested';
    case Active = 'active';
    case Resolved = 'resolved';

    /**
     * States in which a human owns the conversation and نواف must stay silent.
     *
     * @return list<self>
     */
    public static function liveStates(): array
    {
        return [self::Requested, self::Active];
    }

    public function isLive(): bool
    {
        return in_array($this, self::liveStates(), true);
    }
}
