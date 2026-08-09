<?php

namespace App\Enums;

enum Platform: string
{
    case PlayStation = 'playstation';
    case Xbox = 'xbox';
    case Pc = 'pc';

    public function market(): Market
    {
        return match ($this) {
            self::PlayStation, self::Xbox => Market::Console,
            self::Pc => Market::Pc,
        };
    }
}
