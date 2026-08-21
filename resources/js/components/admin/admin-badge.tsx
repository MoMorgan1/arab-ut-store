import type { LucideIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';

import { cn } from '@/lib/utils';

export type AdminBadgeVariant =
    'success' | 'warning' | 'info' | 'danger' | 'neutral';

const variantClasses: Record<AdminBadgeVariant, string> = {
    success:
        'border-status-success/30 bg-status-success/10 text-status-success',
    warning:
        'border-status-warning/30 bg-status-warning/10 text-status-warning',
    info: 'border-status-info/30 bg-status-info/10 text-status-info',
    danger: 'border-status-danger/30 bg-status-danger/10 text-status-danger',
    neutral:
        'border-status-neutral/30 bg-status-neutral/10 text-status-neutral',
};

export default function AdminBadge({
    children,
    className,
    icon: Icon,
    variant = 'neutral',
}: PropsWithChildren<{
    className?: string;
    icon?: LucideIcon;
    variant?: AdminBadgeVariant;
}>) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-md border px-2 py-1 text-xs font-semibold',
                variantClasses[variant],
                className,
            )}
        >
            {Icon ? (
                <Icon aria-hidden="true" className="h-3.5 w-3.5 shrink-0" />
            ) : null}
            <span>{children}</span>
        </span>
    );
}
