<?php

namespace App\Enums;

/**
 * Which half of a challenge delivery a job is in.
 *
 * A challenge order is delivered in two phases against one account: first the
 * supplier ships the coins the challenge costs plus any coins the customer
 * bought on the same order, and only once that whole shipment has landed does
 * the supplier enter the challenge and solve it. The phase order is load-bearing:
 * a challenge that starts before its funding has landed fails. A plain coins job
 * has no phase (null).
 */
enum DeliveryPhase: string
{
    case Coins = 'coins';
    case Challenge = 'challenge';
}
