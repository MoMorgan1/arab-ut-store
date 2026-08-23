import type { ReactNode } from 'react';

export default function AccountOrderList({
    children,
    'aria-label': ariaLabel,
}: {
    children: ReactNode;
    'aria-label'?: string;
}) {
    return (
        <ul aria-label={ariaLabel} className="account-order-list">
            {children}
        </ul>
    );
}
