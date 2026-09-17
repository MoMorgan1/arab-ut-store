<?php

namespace App\Enums;

/**
 * What a re-send actually did, in the store's own words.
 *
 * One vocabulary for three readers - the JSON body, the audit row and the
 * HTTP status - because a support question, a log line and a status code that
 * disagree about the same press cost an hour each time. Every case says what
 * was written rather than what was attempted (AGENTS.md *Failures*, rule 3):
 * `Queued` means an outbox row is pending again, not that a supplier has the
 * work.
 */
enum FulfillmentResendOutcome: string
{
    /** An outbox row is pending again, or one was written for the first time. */
    case Queued = 'queued';

    /** The supplier accepted the resume instruction. */
    case ResumeAccepted = 'resume_accepted';

    /** The supplier accepted the challenge retry. */
    case RetryAccepted = 'retry_accepted';

    /** A press is already running for this item. */
    case Busy = 'busy';

    /** The publisher holds the outbox row right now. */
    case InFlight = 'in_flight';

    /** The row moved between the page render and the press. */
    case NotActionable = 'not_actionable';

    /** The supplier said no, or the guarded update affected no row. */
    case Refused = 'refused';

    /**
     * Whether this outcome handed work to somebody.
     *
     * The audit row's action is chosen from this, so "dispatched" never
     * describes a press that changed nothing.
     */
    public function dispatched(): bool
    {
        return match ($this) {
            self::Queued, self::ResumeAccepted, self::RetryAccepted => true,
            self::Busy, self::InFlight, self::NotActionable, self::Refused => false,
        };
    }

    /**
     * The status a caller can act on without reading the body.
     *
     * `Queued` is deliberately not 201: nothing was created and nothing was
     * placed. An existing row changed state, and the body says which.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::Queued, self::ResumeAccepted, self::RetryAccepted => 200,
            // Nothing was sent twice, and nothing is wrong.
            self::Busy, self::InFlight, self::NotActionable => 409,
            self::Refused => 503,
        };
    }
}
