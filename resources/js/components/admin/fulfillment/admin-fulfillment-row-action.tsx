import { RotateCcw, Truck } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type {
    AdminFulfillmentAction,
    AdminFulfillmentRow,
    AdminTranslations,
} from '@/types/admin';

/**
 * The one control a row offers, or nothing.
 *
 * Three different writes, and which one exists is decided by the server from
 * the row's own state, never here. `send` appears only on a row with no
 * fulfillment job; `resume` and `retry_challenge` only when the job's
 * `allowed_actions` lists them. **A row that already has a placement is never
 * offered `send`** - that is the double spend, and the control not existing is
 * the defence rather than the 409 the placement endpoint would answer with.
 *
 * `send` carries the primary weight because it is the only one that spends new
 * money. The other two instruct a supplier about work already paid for, so
 * they read at the secondary weight.
 */
export default function AdminFulfillmentRowAction({
    copy,
    fullWidth = false,
    isPending,
    onAction,
    row,
}: {
    copy: AdminTranslations['fulfillment'];
    fullWidth?: boolean;
    isPending: boolean;
    onAction: (action: AdminFulfillmentAction) => void;
    row: AdminFulfillmentRow;
}) {
    const action = primaryAction(row.actions);

    if (action === null) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    const isSend = action === 'send';

    return (
        <Button
            className={cn(
                'min-h-9 gap-1.5 px-2.5 text-xs',
                fullWidth && 'min-h-11 w-full text-sm',
            )}
            disabled={isPending}
            onClick={() => onAction(action)}
            type="button"
            variant={isSend ? 'default' : 'outline'}
        >
            {isPending ? (
                <Spinner className="size-3.5" />
            ) : isSend ? (
                <Truck aria-hidden="true" className="size-3.5" />
            ) : (
                <RotateCcw aria-hidden="true" className="size-3.5" />
            )}
            <span>{label(action, copy, isPending, fullWidth)}</span>
        </Button>
    );
}

/**
 * Which action a row leads with when it offers more than one.
 *
 * `send` can never coexist with the other two - it only exists where there is
 * no job at all - so in practice this chooses between resume and a challenge
 * retry, and resume is the broader instruction of the two.
 */
function primaryAction(
    actions: AdminFulfillmentAction[],
): AdminFulfillmentAction | null {
    for (const candidate of ['send', 'resume', 'retry_challenge'] as const) {
        if (actions.includes(candidate)) {
            return candidate;
        }
    }

    return null;
}

function label(
    action: AdminFulfillmentAction,
    copy: AdminTranslations['fulfillment'],
    isPending: boolean,
    long: boolean,
): string {
    if (isPending) {
        return copy.action.sending;
    }

    if (action === 'send') {
        return long ? copy.action.sendLong : copy.action.send;
    }

    if (action === 'resume') {
        return long ? copy.action.resumeLong : copy.action.resume;
    }

    return long ? copy.action.retryLong : copy.action.retry;
}
