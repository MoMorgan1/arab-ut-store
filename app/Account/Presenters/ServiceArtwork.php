<?php

namespace App\Account\Presenters;

use App\Enums\ServiceType;

/**
 * The storefront artwork per service, the same files the home page cards use.
 *
 * Manual services carry no product media, so without this an invoice line or
 * an order card renders an empty box. Coins keep the coin the whole store uses.
 */
final class ServiceArtwork
{
    private const PATHS = [
        'coins' => '/images/store/coins/ut-coin-80.webp',
        'sbc' => '/images/store/services/sbc.webp',
        'objectives' => '/images/store/services/objectives.webp',
        'rivals' => '/images/store/services/rivals.webp',
        'fut_champions' => '/images/store/services/fut-champions.webp',
    ];

    public static function for(ServiceType $service): string
    {
        return self::PATHS[$service->value];
    }
}
