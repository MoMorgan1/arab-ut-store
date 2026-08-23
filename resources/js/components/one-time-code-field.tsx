import type { ChangeEvent, ClipboardEvent, KeyboardEvent } from 'react';
import { useRef } from 'react';

import { cn } from '@/lib/utils';

export type OneTimeCodeFieldProps = {
    id: string;
    label: string;
    value: string;
    onChange: (code: string) => void;
    onComplete?: (code: string) => void;
    disabled?: boolean;
    error?: string;
    autoFocus?: boolean;
    name?: string;
    className?: string;
};

const LENGTH = 6;

/**
 * Six single-digit cells backed by one contiguous digit string. The code
 * is always a prefix: setting cell N keeps cells 0..N-1 and drops anything
 * after it, so a cleared cell never shifts later digits into its place.
 */
export default function OneTimeCodeField({
    autoFocus,
    className,
    disabled,
    error,
    id,
    label,
    name,
    onChange,
    onComplete,
    value = '',
}: OneTimeCodeFieldProps) {
    const inputsRef = useRef<(HTMLInputElement | null)[]>([]);

    function focusCell(index: number) {
        const cell =
            inputsRef.current[Math.min(Math.max(index, 0), LENGTH - 1)];
        cell?.focus();
        cell?.select();
    }

    function commit(next: string) {
        onChange(next);

        if (next.length === LENGTH) {
            onComplete?.(next);
        }
    }

    function fillFrom(index: number, digits: string) {
        const next = (value.slice(0, index) + digits).slice(0, LENGTH);
        commit(next);
        focusCell(next.length === LENGTH ? LENGTH - 1 : next.length);
    }

    function handleCellChange(
        index: number,
        event: ChangeEvent<HTMLInputElement>,
    ) {
        const digits = event.target.value.replace(/\D/g, '');

        if (digits === '') {
            commit(value.slice(0, index));

            return;
        }

        fillFrom(index, digits);
    }

    function handleKeyDown(
        index: number,
        event: KeyboardEvent<HTMLInputElement>,
    ) {
        if (event.key === 'Backspace') {
            event.preventDefault();
            const target = value[index] ? index : index - 1;

            if (target < 0) {
                return;
            }

            commit(value.slice(0, target));
            focusCell(target);

            return;
        }

        if (event.key === 'Delete') {
            event.preventDefault();
            commit(value.slice(0, index));

            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            focusCell(index - 1);

            return;
        }

        if (event.key === 'ArrowRight') {
            event.preventDefault();
            focusCell(index + 1);

            return;
        }

        if (
            event.key.length === 1 &&
            !/^[0-9]$/.test(event.key) &&
            !event.ctrlKey &&
            !event.metaKey &&
            !event.altKey
        ) {
            event.preventDefault();
        }
    }

    function handlePaste(event: ClipboardEvent<HTMLInputElement>) {
        event.preventDefault();
        const digits = event.clipboardData
            .getData('text')
            .replace(/\D/g, '')
            .slice(0, LENGTH);

        if (digits !== '') {
            fillFrom(0, digits);
        }
    }

    return (
        <fieldset className={cn('otp-field', className)} dir="ltr">
            <legend className="sr-only">{label}</legend>
            {Array.from({ length: LENGTH }, (_, index) => {
                const cellValue = value[index] ?? '';

                return (
                    <input
                        key={index}
                        ref={(element) => {
                            inputsRef.current[index] = element;
                        }}
                        id={index === 0 ? id : undefined}
                        className={cn(
                            'otp-field__cell',
                            cellValue !== '' && 'otp-field__cell--filled',
                        )}
                        type="text"
                        inputMode="numeric"
                        pattern="[0-9]*"
                        maxLength={1}
                        autoComplete={index === 0 ? 'one-time-code' : undefined}
                        autoFocus={index === 0 ? autoFocus : undefined}
                        disabled={disabled}
                        aria-label={`${label} ${index + 1}/${LENGTH}`}
                        aria-invalid={index === 0 && error ? true : undefined}
                        aria-describedby={
                            index === 0 && error ? `${id}-error` : undefined
                        }
                        value={cellValue}
                        onChange={(event) => handleCellChange(index, event)}
                        onKeyDown={(event) => handleKeyDown(index, event)}
                        onPaste={handlePaste}
                        onFocus={(event) => event.currentTarget.select()}
                    />
                );
            })}
            {name ? (
                <input
                    type="hidden"
                    name={name}
                    value={value}
                    tabIndex={-1}
                    aria-hidden="true"
                />
            ) : null}
        </fieldset>
    );
}
