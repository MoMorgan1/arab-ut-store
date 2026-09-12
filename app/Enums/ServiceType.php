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
     * A manual service is delivered by a human operator or booster rather
     * than fulfilled through automated supplier bots.
     */
    public function isManual(): bool
    {
        return in_array($this, self::manual(), true);
    }

    /**
     * Whether this service is booster-configured: delivered by a booster who needs
     * a division target and squad image, and priced from a schedule.
     */
    public function isBoosterConfigured(): bool
    {
        return in_array($this, self::boosterConfigured(), true);
    }

    /** @return list<self> Every service delivered manually by a person. */
    public static function manual(): array
    {
        return [self::Objectives, self::Rivals, self::FutChampions];
    }

    /**
     * Services whose price comes from a schedule and whose delivery needs a
     * division target and a squad image, which is a narrower thing than being
     * delivered by a person.
     *
     * @return list<self>
     */
    public static function boosterConfigured(): array
    {
        return [self::Rivals, self::FutChampions];
    }

    /** @return list<self> Services that own a ServicePriceSchedule row. */
    public static function scheduled(): array
    {
        return [self::FutChampions, self::Rivals, self::Coins];
    }
}
