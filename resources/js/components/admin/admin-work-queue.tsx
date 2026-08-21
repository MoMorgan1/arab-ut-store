import type { LucideIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';

export default function AdminWorkQueue({
    children,
    icon: Icon,
    title,
}: PropsWithChildren<{ icon: LucideIcon; title: string }>) {
    return (
        <section className="admin-work-queue">
            <header>
                <Icon aria-hidden="true" />
                <h2>{title}</h2>
            </header>
            <div className="admin-work-queue__body">{children}</div>
        </section>
    );
}
