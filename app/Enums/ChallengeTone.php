<?php

namespace App\Enums;

/**
 * Visual tone for an individual challenge card, derived from the tracker's
 * per-status colour (track/assets/js/ui.js SBC_STATUS_MAP).
 *
 * This is a different question from HoldTone, which skins the shared action
 * box; a card can be marked "working" while the box underneath is empty, or
 * "danger" while the box is informational.
 */
enum ChallengeTone: string
{
    case Success = 'success';
    case Danger = 'danger';
    case Waiting = 'waiting';
    case Working = 'working';
}
