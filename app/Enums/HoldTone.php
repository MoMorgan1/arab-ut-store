<?php

namespace App\Enums;

/**
 * Visual styling tone for the customer-facing action/info box on the tracking page.
 *
 * Ported from track.arab-ut.com (ui.js:269-323). The tracker distinguishes customer-action
 * errors (red error box) from system-side auto-retry states (amber info box prefixed with
 * __info_box__:). Null indicates no box at all (healthy progress or deactivated).
 */
enum HoldTone: string
{
    case Action = 'action';
    case Info = 'info';
}
