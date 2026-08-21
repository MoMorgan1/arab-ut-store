import {
    CircleAlert,
    CircleCheck,
    Clock3,
    Hourglass,
    RotateCcw,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type { AdminBadgeVariant } from '@/components/admin/admin-badge';

export const statusIcons: Record<string, LucideIcon> = {
    cancelled: CircleAlert,
    completed: CircleCheck,
    in_progress: Clock3,
    pending_payment: Clock3,
    received: CircleAlert,
    refunded: RotateCcw,
    waiting_for_customer: Hourglass,
};

export function getStatusVariant(status: string): AdminBadgeVariant {
    switch (status) {
        case 'completed':
            return 'success';
        case 'refunded':
            return 'neutral';
        case 'received':
        case 'in_progress':
            return 'info';
        case 'waiting_for_customer':
        case 'pending_payment':
            return 'warning';
        case 'cancelled':
            return 'danger';
        default:
            return 'neutral';
    }
}

export function getStatusCssColor(status: string): string {
    switch (status) {
        case 'completed':
            return 'var(--primary)';
        case 'received':
        case 'in_progress':
            return 'var(--status-info)';
        case 'waiting_for_customer':
        case 'pending_payment':
            return 'var(--status-warning)';
        case 'cancelled':
            return 'var(--status-danger)';
        case 'refunded':
        default:
            return 'var(--status-neutral)';
    }
}
