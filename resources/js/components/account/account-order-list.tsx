import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export default function AccountOrderList({
    children,
    className,
    'aria-label': ariaLabel,
}: {
    children: ReactNode;
    className?: string;
    'aria-label'?: string;
}) {
    return (
        <ul
            aria-label={ariaLabel}
            className={cn('account-order-list', className)}
        >
            {children}
        </ul>
    );
}
