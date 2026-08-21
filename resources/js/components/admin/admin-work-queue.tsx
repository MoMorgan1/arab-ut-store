import type { LucideIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';

export default function AdminWorkQueue({
    children,
    icon: Icon,
    title,
}: PropsWithChildren<{ icon: LucideIcon; title: string }>) {
    return (
        <section className="rounded-xl border border-border bg-card p-5">
            <header className="flex items-center gap-2 pb-4">
                <Icon
                    aria-hidden="true"
                    className="h-4 w-4 shrink-0 text-muted-foreground"
                />
                <h2 className="text-base font-semibold text-card-foreground">
                    {title}
                </h2>
            </header>
            <div className="min-w-0">{children}</div>
        </section>
    );
}
