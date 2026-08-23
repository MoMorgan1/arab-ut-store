import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type AccountOrderListHeadings = {
    service: string;
    status: string;
    total: string;
};

export default function AccountOrderList({
    children,
    className,
    headings,
    'aria-label': ariaLabel,
}: {
    children: ReactNode;
    className?: string;
    headings?: AccountOrderListHeadings;
    'aria-label'?: string;
}) {
    return (
        <div className={cn('account-order-list-wrapper', className)}>
            {headings ? (
                <div aria-hidden="true" className="account-order-list__head">
                    <span className="account-order-list__th account-order-list__th--service">
                        {headings.service}
                    </span>
                    <span className="account-order-list__th account-order-list__th--status">
                        {headings.status}
                    </span>
                    <span className="account-order-list__th account-order-list__th--total">
                        {headings.total}
                    </span>
                    <span className="account-order-list__th account-order-list__th--action" />
                </div>
            ) : null}
            <ul
                aria-label={ariaLabel}
                className={cn('account-order-list', className)}
            >
                {children}
            </ul>
        </div>
    );
}
