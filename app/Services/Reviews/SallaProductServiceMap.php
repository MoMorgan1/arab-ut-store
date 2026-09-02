<?php

namespace App\Services\Reviews;

use App\Enums\ServiceType;

/**
 * Which store service a Salla product review was about.
 *
 * The archive source only carries the Salla product name, so the service is
 * read from that name with a fixed, ordered rule table. Store-level reviews
 * (no product) and unrecognised products resolve to null and stay in the
 * global set only. The "we buy your coins" listing is a separate service that
 * has no page here, so it resolves to null as well rather than to Coins.
 */
final class SallaProductServiceMap
{
    /** @var list<array{0: string, 1: ServiceType|null}> */
    private const RULES = [
        ['/نشتري|sell/iu', null],
        ['/رايفل|rival/iu', ServiceType::Rivals],
        ['/فوت|تشامب|رانك|champ|rank/iu', ServiceType::FutChampions],
        ['/مهام|مهمة|objective/iu', ServiceType::Objectives],
        ['/تحدي|ترقي|بكج|أيكون|ايكون|sbc|squad/iu', ServiceType::Sbc],
        ['/كوينز|كوين|coin/iu', ServiceType::Coins],
    ];

    public static function resolve(?string $productName): ?string
    {
        $name = trim((string) $productName);

        if ($name === '') {
            return null;
        }

        foreach (self::RULES as [$pattern, $service]) {
            if (preg_match($pattern, $name) === 1) {
                return $service?->value;
            }
        }

        return null;
    }
}
