import { Head, Link, usePage } from '@inertiajs/react';
import {
    Award,
    BadgePercent,
    ChevronRight,
    FolderTree,
    MessageSquare,
    Settings,
    Ticket,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

import type { AdminMorePageProps, AdminMoreTile } from '@/types/admin';

const tileIcons: Record<AdminMoreTile['key'], LucideIcon> = {
    conversations: MessageSquare,
    categories: FolderTree,
    coupons: Ticket,
    promotions: BadgePercent,
    loyalty: Award,
    settings: Settings,
};

export default function AdminMorePage() {
    const { props } = usePage<AdminMorePageProps>();
    const copy = props.adminUi.more;

    if (!copy) {
        return null;
    }

    return (
        <article className="space-y-8" dir={props.direction}>
            <Head title={copy.headTitle} />

            <header className="flex flex-col gap-1 border-b border-border pb-5">
                <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                    {copy.title}
                </h1>
                <p className="max-w-prose text-sm leading-relaxed text-muted-foreground">
                    {copy.description}
                </p>
            </header>

            {props.groups.length === 0 ? (
                <p className="py-12 text-center text-sm text-muted-foreground">
                    {copy.noTiles}
                </p>
            ) : (
                <div className="space-y-8">
                    {props.groups.map((group) => (
                        <section
                            aria-labelledby={`more-group-${group.key}`}
                            className="space-y-3"
                            key={group.key}
                        >
                            <h2
                                className="text-xs font-bold tracking-wider text-muted-foreground uppercase"
                                id={`more-group-${group.key}`}
                            >
                                {group.label}
                            </h2>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                {group.tiles.map((tile) => {
                                    const Icon = tileIcons[tile.key];

                                    return (
                                        <Link
                                            className="group flex min-h-[88px] min-w-[44px] items-start gap-4 rounded-xl border border-border bg-card p-4 shadow-sm transition-all hover:border-primary/50 hover:bg-accent/40 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring active:scale-[0.99] motion-reduce:transition-none motion-reduce:active:scale-100"
                                            href={tile.url}
                                            key={tile.key}
                                        >
                                            <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg border border-primary/20 bg-primary/10 text-primary transition-colors group-hover:bg-primary group-hover:text-primary-foreground motion-reduce:transition-none">
                                                <Icon
                                                    aria-hidden="true"
                                                    className="h-5 w-5 shrink-0"
                                                />
                                            </div>
                                            <div className="flex min-w-0 flex-1 flex-col gap-1">
                                                <div className="flex items-center justify-between gap-2">
                                                    <span className="text-base font-semibold text-foreground transition-colors group-hover:text-primary motion-reduce:transition-none">
                                                        {tile.label}
                                                    </span>
                                                    <ChevronRight
                                                        aria-hidden="true"
                                                        className="h-4 w-4 shrink-0 text-muted-foreground/60 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none rtl:rotate-180 rtl:group-hover:-translate-x-0.5"
                                                    />
                                                </div>
                                                <p className="line-clamp-2 text-xs leading-relaxed text-muted-foreground">
                                                    {tile.description}
                                                </p>
                                            </div>
                                        </Link>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            )}
        </article>
    );
}
