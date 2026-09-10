<?php

namespace App\Enums;

enum ServiceType: string
{
    case Coins = 'coins';
    case Sbc = 'sbc';
    case Objectives = 'objectives';
    case Rivals = 'rivals';
    case FutChampions = 'fut_champions';

    /**
     * A manual service is fulfilled by a booster signing into the customer's
     * account, so it carries credentials and a squad image instead of coins.
     */
    public function isManual(): bool
    {
        return in_array($this, self::manual(), true);
    }

    /** @return list<self> */
    public static function manual(): array
    {
        return [self::FutChampions, self::Rivals];
    }

    /** @return list<self> Services that own a ServicePriceSchedule row. */
    public static function scheduled(): array
    {
        return [self::FutChampions, self::Rivals, self::Coins];
    }
}
