import { useRef } from 'react';

import { useErrorShake } from '@/components/motion/error-shake';

export function FieldError({
    error,
    id,
}: {
    error: string | undefined;
    id?: string;
}) {
    const ref = useRef<HTMLParagraphElement | null>(null);
    useErrorShake(ref, error);

    if (error === undefined) {
        return null;
    }

    return (
        <p
            ref={ref}
            className="t-shakeable coins-field-error"
            id={id}
            role="alert"
        >
            <span aria-hidden="true" className="coins-field-error__icon" />
            {error}
        </p>
    );
}
