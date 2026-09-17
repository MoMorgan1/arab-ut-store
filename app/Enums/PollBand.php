<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Which cadence the poller reads a job on.
 *
 * A job whose order page was opened recently is read every 25 seconds because
 * somebody is watching it; everything else is read every three minutes. The
 * two differ by more than seven times, so anything that reasons about "how
 * long since a reading" has to know which one applies - an hour of silence is
 * twenty missed reads in one band and a hundred and forty in the other.
 *
 * The rule lives here rather than in the poller because three things now need
 * it and agreeing by coincidence is not agreeing: the poller picks the next
 * due time, the stall alarm picks a threshold, and the gap recorder files the
 * measurement.
 */
enum PollBand: string
{
    /** A customer has this order page open, or had it open just now. */
    case Attention = 'attention';

    /** Nobody is watching. */
    case Background = 'background';

    /**
     * The band a job is in, from the stamp a human-triggered read leaves.
     *
     * Only a human path writes `last_viewed_at` - a background read that
     * stamped it would hold every job in the fast band forever - so this is
     * genuinely "is someone looking", not "did we read it recently".
     */
    public static function for(?CarbonImmutable $lastViewedAt): self
    {
        if (! $lastViewedAt instanceof CarbonImmutable) {
            return self::Background;
        }

        return $lastViewedAt->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds(self::windowSeconds()))
            ? self::Attention
            : self::Background;
    }

    public static function windowSeconds(): int
    {
        return max(1, (int) config('services.suppliers.poll.attention_window_seconds', 180));
    }

    public function cadenceSeconds(): int
    {
        return match ($this) {
            self::Attention => max(1, (int) config('services.suppliers.poll.attention_cadence_seconds', 25)),
            self::Background => max(1, (int) config('services.suppliers.poll.background_cadence_seconds', 180)),
        };
    }
}
