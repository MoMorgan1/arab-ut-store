import { AlertTriangle } from 'lucide-react';

import type { AdminQueueHealth, AdminTranslations } from '@/types/admin';

/**
 * Silent when the queue is healthy.
 *
 * The store sent no email for months and nothing said so. This is the one
 * place that says otherwise - so it earns its space only when something is
 * actually wrong, and stays invisible on a good day.
 */
export default function AdminQueueHealthBanner({
    dateFormatter,
    health,
    locale,
    translations,
}: {
    dateFormatter: Intl.DateTimeFormat;
    health: AdminQueueHealth | null;
    locale: 'ar' | 'en';
    translations: AdminTranslations['overview'];
}) {
    if (health === null) {
        return null;
    }

    const hasFailures = health.failedJobs > 0;
    const isStalled = health.stalledJobs > 0;

    if (!hasFailures && !isStalled) {
        return null;
    }

    const copy = translations.queueHealth;
    const numberFormatter = new Intl.NumberFormat(locale);

    return (
        <aside
            aria-label={copy.title}
            className="flex flex-col gap-2.5 rounded-xl border border-status-danger/40 bg-status-danger/5 p-3.5 sm:p-4"
        >
            <div className="flex items-center gap-2">
                <AlertTriangle
                    aria-hidden="true"
                    className="h-4 w-4 shrink-0 text-status-danger"
                />
                <h2 className="text-sm font-semibold text-status-danger">
                    {copy.title}
                </h2>
            </div>

            {hasFailures ? (
                <div className="space-y-1">
                    <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm text-foreground">
                        <span className="font-bold tabular-nums">
                            {numberFormatter.format(health.failedJobs)}
                        </span>
                        <span>{copy.failedJobs}</span>
                        {health.latestFailure !== null ? (
                            <span className="text-xs text-muted-foreground">
                                {copy.latestFailure}:{' '}
                                <bdi
                                    className="font-medium text-foreground"
                                    dir="ltr"
                                    translate="no"
                                >
                                    {health.latestFailure.name}
                                </bdi>{' '}
                                <time
                                    className="tabular-nums"
                                    dateTime={health.latestFailure.failedAt}
                                >
                                    {dateFormatter.format(
                                        new Date(health.latestFailure.failedAt),
                                    )}
                                </time>
                            </span>
                        ) : null}
                    </p>
                    <p className="max-w-prose text-xs text-muted-foreground">
                        {copy.failedHint}
                    </p>
                </div>
            ) : null}

            {isStalled ? (
                <div className="space-y-1">
                    <p className="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm text-foreground">
                        <span className="font-bold tabular-nums">
                            {numberFormatter.format(health.stalledJobs)}
                        </span>
                        <span>{copy.stalledJobs}</span>
                        {health.oldestQueuedAt !== null ? (
                            <span className="text-xs text-muted-foreground">
                                {copy.stalledSince}:{' '}
                                <time
                                    className="tabular-nums"
                                    dateTime={health.oldestQueuedAt}
                                >
                                    {dateFormatter.format(
                                        new Date(health.oldestQueuedAt),
                                    )}
                                </time>
                            </span>
                        ) : null}
                    </p>
                    <p className="max-w-prose text-xs text-muted-foreground">
                        {copy.stalledHint}
                    </p>
                </div>
            ) : null}
        </aside>
    );
}
