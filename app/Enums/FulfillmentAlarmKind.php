<?php

namespace App\Enums;

/**
 * Why an operator is being told about an item nobody else can see.
 *
 * Every case is silence rather than failure: a failed placement already
 * reaches Mohamed through n8n's own alerting, and a failed read already backs
 * off and retries. What neither can report is that nothing is happening at
 * all - so these are deliberately operator vocabulary, and no case here ever
 * becomes a string a customer reads.
 */
enum FulfillmentAlarmKind: string
{
    /**
     * A paid automated item that no supplier was ever asked to deliver.
     *
     * n8n cannot report this one: from its side the placement succeeded and
     * the callback simply never arrived, so the order is invisible to
     * everything except the customer waiting for it.
     */
    case Unplaced = 'unplaced';

    /**
     * A placed item whose supplier reads have told us nothing for several
     * sweeps in a row.
     *
     * Covers a challenge id the supplier no longer recognises - the read is
     * answered, it just never mentions our id - as well as a supplier that
     * has been unreachable long enough to stop being a blip.
     */
    case Silent = 'silent';

    /**
     * A placed item the poller should be reading, whose newest observation is
     * older than its delivery phase allows.
     *
     * The distinction from {@see self::Silent} is what is broken. Silent
     * counts reads that were attempted and came back useless, so it only fires
     * while something is still trying. This one measures the age of the last
     * reading that landed, so it catches the cases where nothing is failing
     * because nothing is being attempted, or where what arrives cannot be
     * used: a `next_poll_at` a bug pushed into next year, a job that drifted
     * out of the poller's selection, a backlog whose tail never gets read
     * inside the tick's deadline, a circuit that opens faster than it closes,
     * and a supplier answering every read with a status the translator does
     * not recognise - which is a successful read that moves no counter.
     *
     * It does NOT catch a dead scheduler cron, which is the one thing it might
     * look like it should. The sweep that raises this and the mail that sends
     * it run on that same cron; when it dies they all die together and nothing
     * here will ever fire. That failure belongs to `ReadQueueHealth`, which
     * watches the queue tables from inside a web request.
     *
     * The two kinds do overlap, and the sweep makes the boundary exclusive
     * rather than letting one item carry both: below `silent_after_failures`
     * this kind owns it, at or above it Silent does.
     *
     * How old is too old is a per-phase number nobody has measured yet, so it
     * runs on an interim per-band fallback until one exists. See
     * `services.suppliers.alarm.stalled_fallback_minutes` and
     * `stalled_after_minutes` in `config/services.php`.
     */
    case Stalled = 'stalled';
}
