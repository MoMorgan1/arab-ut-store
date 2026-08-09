<?php

namespace App\Enums;

enum ServiceType: string
{
    case Coins = 'coins';
    case Sbc = 'sbc';
    case Objectives = 'objectives';
    case Rivals = 'rivals';
    case FutChampions = 'fut_champions';
}
