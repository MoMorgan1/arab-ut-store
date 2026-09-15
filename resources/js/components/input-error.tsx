import type { HTMLAttributes } from 'react';
import { useRef } from 'react';

import { useErrorShake } from '@/components/motion/error-shake';
import { cn } from '@/lib/utils';

export default function InputError({
    message,
    className = '',
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string }) {
    const ref = useRef<HTMLParagraphElement | null>(null);
    useErrorShake(ref, message);

    return message ? (
        <p
            {...props}
            ref={ref}
            className={cn(
                't-shakeable text-sm text-red-600 dark:text-red-400',
                className,
            )}
        >
            {message}
        </p>
    ) : null;
}
