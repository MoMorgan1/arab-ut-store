<?php

namespace App\Enums;

/**
 * Customer-facing headline presentation state for order tracking.
 *
 * Ported from the ordered cascade in track.arab-ut.com (ui.js:419-544).
 * Serves as the discriminator so the client renders the correct headline and sub-line
 * without re-evaluating supplier vocabulary or complex status cascades.
 *
 * Note: Retrying (optimistic retry grace window) is NOT represented here;
 * it is strictly client-side state in sessionStorage (ui.js:250) owned by the frontend.
 */
enum TrackingPresentation: string
{
    case Processing = 'processing';
    case LoggingIn = 'logging_in';
    case Preparing = 'preparing';
    case Transferring = 'transferring';
    case TransferringPartDone = 'transferring_part_done';
    case Finishing = 'finishing';
    case Completed = 'completed';
    case Stopped = 'stopped';
    case NeedsReview = 'needs_review';
    case NotReported = 'not_reported';

    public function headline(string $locale = 'ar'): string
    {
        return (string) trans("orders.tracking_states.{$this->value}.headline", [], $locale);
    }

    /**
     * @param  array<string, string>  $replace
     */
    public function subline(string $locale = 'ar', array $replace = []): string
    {
        return (string) trans("orders.tracking_states.{$this->value}.subline", $replace, $locale);
    }
}
