import { CircleAlert, CircleCheck, Clock3, Hourglass, RotateCcw } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type { AdminBadgeVariant } from '@/components/admin/admin-badge';
import type {
    AdminFulfillmentAlarm,
    AdminFulfillmentRow,
    AdminTranslations,
} from '@/types/admin';

/**
 * The hold reasons that say "we are handling it" rather than asking the
 * customer for something.
 *
 * The seven are named in `App\Enums\OrderHoldReason`'s own docblock, and the
 * split is the one an operator actually needs: is this waiting on me, or on
 * them? The other fourteen cases ask the customer to do something, so they
 * read as a warning here and these read as information.
 *
 * (The enum's comment says "eleven" for the other group; it predates the three
 * cases added on 2026-09-13. Fourteen is what the enum now holds.)
 */
const WE_HANDLE_IT = new Set([
    'ea_servers',
    'store_stock',
    'connection',
    'no_player',
    'maintenance',
    'paused',
    'below_minimum',
]);

/** Presentation values that mean the delivery is finishing well. */
const GOOD_PRESENTATIONS = new Set(['completed', 'finishing']);

/** Presentation values that mean somebody should look. */
const WARN_PRESENTATIONS = new Set(['stopped', 'needs_review']);

/** Presentation values that are neither progress nor trouble. */
const NEUTRAL_PRESENTATIONS = new Set(['cancelled', 'refunded', 'not_reported']);

export type AdminFulfillmentBadge = {
    label: string;
    variant: AdminBadgeVariant;
    icon: LucideIcon;
    /** The one-sentence explanation, for the detail panel and the title. */
    hint: string | null;
};

/**
 * The single badge a row shows, and the muted line under it.
 *
 * One badge rather than three, in a fixed order of urgency: an open alarm, a
 * hold the supplier reported, then the delivery's own presentation. A row with
 * all three would otherwise be a wall of pills, and the alarm is the only one
 * of the three worth waking somebody for.
 */
export function fulfillmentBadge(
    row: AdminFulfillmentRow,
    copy: AdminTranslations,
): AdminFulfillmentBadge | null {
    const alarm = mostUrgentAlarm(row.alarms);

    if (alarm) {
        // A stall whose supplier is inside its circuit cooldown is the one
        // alarm that is not danger. We are choosing not to ask that supplier,
        // the readings will resume on their own, and there is nothing for an
        // operator to chase - so it reads as a warning rather than joining the
        // rows that need somebody now. Colouring it red is how a panel teaches
        // an operator to ignore red.
        const isCooldown = alarm.kind === 'stalled' && alarm.circuitOpen;

        return {
            label: copy.fulfillment.alarm[alarm.kind] ?? alarm.kind,
            variant: isCooldown ? 'warning' : 'danger',
            icon: isCooldown ? Clock3 : CircleAlert,
            hint: alarmHint(alarm, copy),
        };
    }

    const hold = row.job?.holdReason;

    if (hold) {
        return {
            label: copy.holdReasons[hold] ?? hold,
            variant: WE_HANDLE_IT.has(hold) ? 'info' : 'warning',
            icon: WE_HANDLE_IT.has(hold) ? Clock3 : Hourglass,
            hint: null,
        };
    }

    const presentation = row.job?.presentation;

    if (presentation) {
        return {
            label: copy.fulfillment.presentation[presentation] ?? presentation,
            variant: presentationVariant(presentation),
            icon: presentationIcon(presentation),
            hint: null,
        };
    }

    return null;
}

/**
 * Which alarm a row leads with when it carries more than one.
 *
 * `unplaced` first, because an item no supplier has is a different order of
 * problem from one whose readings are late. The sweep makes `silent` and
 * `stalled` mutually exclusive, so in practice this only ever chooses between
 * `unplaced` and one of the other two.
 */
export function mostUrgentAlarm(
    alarms: AdminFulfillmentAlarm[],
): AdminFulfillmentAlarm | null {
    for (const kind of ['unplaced', 'silent', 'stalled'] as const) {
        const found = alarms.find((alarm) => alarm.kind === kind);

        if (found) {
            return found;
        }
    }

    return null;
}

/**
 * The sentence that tells an operator whether to chase this.
 *
 * A stalled item whose supplier is inside its circuit cooldown is one we are
 * deliberately not asking, which is a different problem from a supplier that
 * has gone quiet - and sending somebody after a supplier that is answering
 * fine is sending them to the wrong place. The alarm mail draws the same
 * distinction in `AlertOwnerOfFulfillmentSilence::stalledDetail()`, so the
 * screen and the mail say the same thing.
 */
export function alarmHint(
    alarm: AdminFulfillmentAlarm,
    copy: AdminTranslations,
): string | null {
    if (alarm.kind === 'stalled' && alarm.circuitOpen) {
        return copy.fulfillment.alarmHint.stalledCircuit ?? null;
    }

    return copy.fulfillment.alarmHint[alarm.kind] ?? null;
}

/**
 * The Signal column's second line: what the last reading said about itself.
 *
 * Failure counts lead, because "reads are failing" is the more specific thing
 * to be told. A stall reports how long it has been quiet instead, which is the
 * first question anyone asks about one.
 */
export function signalDetail(
    row: AdminFulfillmentRow,
    copy: AdminTranslations,
    formatAge: (iso: string) => string,
): string {
    if (!row.job) {
        return copy.fulfillment.noReading;
    }

    if (row.job.pollFailures > 0) {
        return copy.fulfillment.failedReads.replace(
            ':count',
            String(row.job.pollFailures),
        );
    }

    const stalled = row.alarms.find((alarm) => alarm.kind === 'stalled');

    if (stalled) {
        return stalled.quietMinutes === null
            ? copy.fulfillment.quietUnread
            : copy.fulfillment.quietFor.replace(
                  ':count',
                  String(stalled.quietMinutes),
              );
    }

    if (row.job.observedAt) {
        return copy.fulfillment.readAgo.replace(
            ':age',
            formatAge(row.job.observedAt),
        );
    }

    return copy.fulfillment.noReading;
}

function presentationVariant(presentation: string): AdminBadgeVariant {
    if (GOOD_PRESENTATIONS.has(presentation)) {
        return 'success';
    }

    if (WARN_PRESENTATIONS.has(presentation)) {
        return 'warning';
    }

    if (NEUTRAL_PRESENTATIONS.has(presentation)) {
        return 'neutral';
    }

    return 'info';
}

function presentationIcon(presentation: string): LucideIcon {
    if (GOOD_PRESENTATIONS.has(presentation)) {
        return CircleCheck;
    }

    if (WARN_PRESENTATIONS.has(presentation)) {
        return Hourglass;
    }

    if (presentation === 'refunded' || presentation === 'cancelled') {
        return RotateCcw;
    }

    if (presentation === 'not_reported') {
        return CircleAlert;
    }

    return Clock3;
}

/**
 * A short age, in the admin's own vocabulary.
 *
 * The admin has no shared relative-time formatter - every other surface prints
 * an absolute date - so this is new, and deliberately arithmetic rather than
 * `Intl.RelativeTimeFormat`: an operations queue wants "5h 08m", not "5 hours
 * ago", and wants it to read the same in both locales because the numbers are
 * what is being compared down the column.
 */
export function formatShortAge(iso: string, now: number = Date.now()): string {
    const elapsed = Math.max(0, now - new Date(iso).getTime());
    const totalMinutes = Math.floor(elapsed / 60_000);

    if (totalMinutes < 1) {
        return `${Math.floor(elapsed / 1000)}s`;
    }

    if (totalMinutes < 60) {
        return `${totalMinutes}m`;
    }

    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours < 24) {
        return `${hours}h ${String(minutes).padStart(2, '0')}m`;
    }

    return `${Math.floor(hours / 24)}d ${hours % 24}h`;
}
