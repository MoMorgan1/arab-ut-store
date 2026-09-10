import { useEffect, useState } from 'react';

const FIELD_TAGS = new Set(['INPUT', 'TEXTAREA', 'SELECT']);

function isField(target: EventTarget | null): boolean {
    return target instanceof HTMLElement && FIELD_TAGS.has(target.tagName);
}

/**
 * True while a text field is focused, so a bottom bar can step out of the way of
 * the on-screen keyboard instead of fighting it for the bottom of the viewport.
 *
 * The close is debounced because moving focus between two fields fires
 * `focusout` before `focusin`; without the delay the bar would flicker on every
 * tab between inputs.
 */
export function useKeyboardOpen(debounceMs = 120): boolean {
    const [isOpen, setIsOpen] = useState(false);

    useEffect(() => {
        let closeTimer: number | undefined;

        function clearPendingClose() {
            if (closeTimer !== undefined) {
                window.clearTimeout(closeTimer);
                closeTimer = undefined;
            }
        }

        function handleFocusIn(event: FocusEvent) {
            if (isField(event.target)) {
                clearPendingClose();
                setIsOpen(true);
            }
        }

        function handleFocusOut(event: FocusEvent) {
            if (!isField(event.target)) {
                return;
            }

            clearPendingClose();
            closeTimer = window.setTimeout(() => {
                closeTimer = undefined;
                setIsOpen(false);
            }, debounceMs);
        }

        window.addEventListener('focusin', handleFocusIn);
        window.addEventListener('focusout', handleFocusOut);

        return () => {
            clearPendingClose();
            window.removeEventListener('focusin', handleFocusIn);
            window.removeEventListener('focusout', handleFocusOut);
        };
    }, [debounceMs]);

    return isOpen;
}

export default useKeyboardOpen;
