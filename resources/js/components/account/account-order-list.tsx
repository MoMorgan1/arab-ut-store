import type { ReactNode } from 'react';

export type AccountOrderListHeadings = {
    service: string;
    status: string;
    total: string;
};

export default function AccountOrderList({
    children,
    headings,
    'aria-label': ariaLabel,
}: {
    children: ReactNode;
    headings?: AccountOrderListHeadings;
    'aria-label'?: string;
}) {
    return (
        <div className="account-order-list-wrapper">
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
            <ul aria-label={ariaLabel} className="account-order-list">
                {children}
            </ul>
        </div>
    );
}
