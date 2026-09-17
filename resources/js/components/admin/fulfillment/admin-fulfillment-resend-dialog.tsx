import { KeyRound, TriangleAlert, Truck } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import type {
    AdminFilterOption,
    AdminFulfillmentAction,
    AdminFulfillmentRow,
    AdminTranslations,
} from '@/types/admin';

export type AdminFulfillmentResendDialogProps = {
    action: AdminFulfillmentAction;
    copy: AdminTranslations['fulfillment'];
    isSubmitting: boolean;
    onCancel: () => void;
    onConfirm: (reasonCode: string, challengePosition: number | null) => void;
    reasonCodes: AdminFilterOption[];
    row: AdminFulfillmentRow;
};

/**
 * The deliberate step before a supplier is asked to do something.
 *
 * A Dialog rather than an inline confirm or a second sheet: the row detail is
 * already a sheet, and an inline confirm on a table row is far too easy to hit
 * by accident for an action that spends money. The precedent is the admin's
 * existing refund control, which is the other money confirmation on the
 * surface.
 *
 * The reason is a fixed list, not a text box. `forms.md` requires an
 * allowlisted reason code, and `audit-logging.md` forbids copying a free-text
 * reason into audit metadata - so there is nowhere for prose to go, and a
 * field that accepted it would only invite a paste of something that must not
 * be stored.
 */
export default function AdminFulfillmentResendDialog({
    action,
    copy,
    isSubmitting,
    onCancel,
    onConfirm,
    reasonCodes,
    row,
}: AdminFulfillmentResendDialogProps) {
    // Empty string rather than null: Radix reads undefined as "uncontrolled",
    // and a select that starts uncontrolled and becomes controlled on the
    // first choice is the React warning nobody ever comes back to fix.
    const [reasonCode, setReasonCode] = useState('');
    const [solve, setSolve] = useState('');
    const [showRequired, setShowRequired] = useState(false);

    const isSend = action === 'send';
    // A placement can hold several solves, and the supplier retries the one it
    // is told. With one solve the position is not a question; with more, a
    // default of zero would retry a solve that is finished and leave the
    // failed one alone, so the operator picks.
    const solveCount =
        action === 'retry_challenge' ? (row.placement?.challengeCount ?? 0) : 0;
    const asksForSolve = solveCount > 1;
    const title = (
        isSend
            ? copy.dialog.sendTitle
            : action === 'resume'
              ? copy.dialog.resumeTitle
              : copy.dialog.retryTitle
    ).replace(':order', row.orderNumber);
    const body = isSend
        ? copy.dialog.sendBody
        : action === 'resume'
          ? copy.dialog.resumeBody
          : copy.dialog.retryBody;
    const confirmLabel = isSend
        ? copy.action.sendLong
        : action === 'resume'
          ? copy.action.resumeLong
          : copy.action.retryLong;

    return (
        <Dialog
            onOpenChange={(open) => {
                if (!open) {
                    onCancel();
                }
            }}
            open
        >
            <DialogContent
                className="motion-reduce:animate-none sm:max-w-lg"
                closeLabel={copy.dialog.cancel}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{body}</DialogDescription>
                </DialogHeader>

                <div className="flex flex-col gap-1.5">
                    <Label htmlFor="resend-reason">
                        {copy.dialog.reasonLabel}
                    </Label>
                    <Select
                        onValueChange={(value) => {
                            setReasonCode(value);
                            setShowRequired(false);
                        }}
                        value={reasonCode}
                    >
                        <SelectTrigger
                            className="min-h-11 w-full text-sm"
                            id="resend-reason"
                        >
                            <SelectValue
                                placeholder={copy.dialog.reasonPlaceholder}
                            />
                        </SelectTrigger>
                        <SelectContent className="motion-reduce:animate-none">
                            {reasonCodes.map((option) => (
                                <SelectItem
                                    className="min-h-11 text-sm"
                                    key={option.value}
                                    value={option.value}
                                >
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {showRequired ? (
                        <p
                            className="text-xs font-medium text-destructive"
                            role="alert"
                        >
                            {reasonCode === ''
                                ? copy.dialog.reasonRequired
                                : copy.dialog.challengeRequired}
                        </p>
                    ) : null}
                </div>

                {asksForSolve ? (
                    <div className="flex flex-col gap-1.5">
                        <Label htmlFor="resend-solve">
                            {copy.dialog.challengeLabel}
                        </Label>
                        <Select
                            onValueChange={(value) => {
                                setSolve(value);
                                setShowRequired(false);
                            }}
                            value={solve}
                        >
                            <SelectTrigger
                                className="min-h-11 w-full text-sm"
                                id="resend-solve"
                            >
                                <SelectValue
                                    placeholder={
                                        copy.dialog.challengePlaceholder
                                    }
                                />
                            </SelectTrigger>
                            <SelectContent className="motion-reduce:animate-none">
                                {Array.from(
                                    { length: solveCount },
                                    (_, index) => (
                                        <SelectItem
                                            className="min-h-11 text-sm"
                                            key={index}
                                            value={String(index)}
                                        >
                                            {copy.dialog.challengeOption.replace(
                                                ':number',
                                                String(index + 1),
                                            )}
                                        </SelectItem>
                                    ),
                                )}
                            </SelectContent>
                        </Select>
                    </div>
                ) : null}

                {isSend ? (
                    // Not boilerplate. Composing the request decrypts the
                    // customer's EA account and writes a secret access log row
                    // for the read, so the operator is told that pressing this
                    // touches somebody's credentials even though they never
                    // see them.
                    //
                    // No password prompt is promised, because there is none to
                    // promise: the owner ruled recent-password confirmation out
                    // on 2026-09-18 (`forms.md`, *Sensitive actions*). The
                    // permission, this confirm step and the audit row are the
                    // gate.
                    <div className="flex gap-2.5 rounded-md border border-status-warning/30 bg-status-warning/8 px-3 py-2.5 text-[13px] leading-relaxed text-foreground">
                        <KeyRound
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-status-warning"
                        />
                        <span>{copy.dialog.credentialNote}</span>
                    </div>
                ) : null}

                {isSend ? (
                    // The one risk this screen cannot see. A supplier that
                    // bought and never reported it leaves no job row, so the
                    // item still reads as never placed and the request is
                    // recomposed for it - which buys the same coins again.
                    // Nothing in our database can rule that out, so the
                    // operator is told to go and look before pressing.
                    <div className="flex gap-2.5 rounded-md border border-status-danger/30 bg-status-danger/8 px-3 py-2.5 text-[13px] leading-relaxed text-foreground">
                        <TriangleAlert
                            aria-hidden="true"
                            className="mt-0.5 size-4 shrink-0 text-status-danger"
                        />
                        <span>{copy.dialog.sendUnrecorded}</span>
                    </div>
                ) : null}

                <DialogFooter>
                    <Button
                        className="min-h-11"
                        disabled={isSubmitting}
                        onClick={onCancel}
                        type="button"
                        variant="outline"
                    >
                        {copy.dialog.cancel}
                    </Button>
                    <Button
                        className="min-h-11 gap-2"
                        disabled={isSubmitting}
                        onClick={() => {
                            if (
                                reasonCode === '' ||
                                (asksForSolve && solve === '')
                            ) {
                                setShowRequired(true);

                                return;
                            }

                            onConfirm(
                                reasonCode,
                                asksForSolve ? Number(solve) : null,
                            );
                        }}
                        type="button"
                    >
                        {isSubmitting ? (
                            <Spinner className="size-4" />
                        ) : (
                            <Truck aria-hidden="true" className="size-4" />
                        )}
                        <span>
                            {isSubmitting ? copy.action.sending : confirmLabel}
                        </span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
