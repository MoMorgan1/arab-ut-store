import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Bot,
    CheckCircle2,
    Cpu,
    Globe,
    MessageSquare,
    User as UserIcon,
    XCircle,
    Zap,
} from 'lucide-react';

import AdminBadge from '@/components/admin/admin-badge';
import type { AdminBadgeVariant } from '@/components/admin/admin-badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    AdminChatMessage,
    AdminConversationDetailPageProps,
} from '@/types/admin';

function getTurnStatusVariant(status: string): AdminBadgeVariant {
    switch (status) {
        case 'completed':
            return 'success';
        case 'running':
            return 'info';
        case 'waiting':
            return 'warning';
        case 'failed':
            return 'danger';
        case 'cancelled':
        default:
            return 'neutral';
    }
}

export default function AdminConversationDetailPage() {
    const { props, url } = usePage<AdminConversationDetailPageProps>();
    const copy = props.adminUi.conversationDetail;
    const conversationsCopy = props.adminUi.conversations;
    const conversation = props.conversation;

    const pathname = new URL(url, window.location.origin).pathname;
    const isLocalized = pathname.startsWith('/en/admin');
    const conversationsListUrl = isLocalized
        ? '/en/admin/conversations'
        : '/admin/conversations';

    const dateFormatter = new Intl.DateTimeFormat(props.locale, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const timeFormatter = new Intl.DateTimeFormat(props.locale, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: 'UTC',
    });

    const isGuest = conversation.ownerType === 'guest';
    const closeReasonLabel = conversation.closeReason
        ? (copy.closeReasons[conversation.closeReason] ??
          conversation.closeReason)
        : null;

    return (
        <article className="space-y-6" dir={props.direction}>
            <Head
                title={copy.headTitle.replace(':id', conversation.publicId)}
            />

            {/* Page Header */}
            <header className="flex flex-col gap-4 border-b border-border pb-5">
                <div>
                    <Link
                        className="inline-flex min-h-11 items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring motion-reduce:transition-none"
                        href={conversationsListUrl}
                    >
                        <ArrowLeft aria-hidden="true" className="size-3.5" />
                        <span>{copy.backToConversations}</span>
                    </Link>
                </div>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex min-w-0 flex-col gap-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="text-xl font-bold tracking-tight text-foreground md:text-2xl">
                                <span>{copy.title}</span>
                            </h1>
                            <AdminBadge
                                icon={
                                    conversation.status === 'open'
                                        ? CheckCircle2
                                        : XCircle
                                }
                                variant={
                                    conversation.status === 'open'
                                        ? 'success'
                                        : 'neutral'
                                }
                            >
                                {conversation.status === 'open'
                                    ? conversationsCopy.statusOpen
                                    : conversationsCopy.statusClosed}
                            </AdminBadge>
                            <span className="inline-flex items-center gap-1 text-xs font-semibold text-muted-foreground uppercase">
                                <Globe aria-hidden="true" className="size-3" />
                                <span>{conversation.locale}</span>
                            </span>
                        </div>
                        <p
                            className="text-xs [overflow-wrap:anywhere] text-muted-foreground tabular-nums"
                            title={conversation.publicId}
                        >
                            <bdi>{conversation.publicId}</bdi>
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                        {conversation.createdAt ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {copy.createdAt}
                                </span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {dateFormatter.format(
                                            new Date(conversation.createdAt),
                                        )}
                                    </bdi>
                                </span>
                            </div>
                        ) : null}
                        {conversation.closedAt ? (
                            <div className="flex flex-col">
                                <span className="font-medium text-foreground">
                                    {copy.closedAt}
                                </span>
                                <span className="tabular-nums">
                                    <bdi>
                                        {dateFormatter.format(
                                            new Date(conversation.closedAt),
                                        )}
                                    </bdi>
                                </span>
                            </div>
                        ) : null}
                    </div>
                </div>
            </header>

            {/* Conversation Summary Section */}
            <section
                aria-labelledby="conversation-summary-heading"
                className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
            >
                <div className="flex items-center justify-between border-b border-border pb-3">
                    <div className="flex items-center gap-2">
                        <MessageSquare
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        <h2
                            className="text-sm font-semibold text-foreground"
                            id="conversation-summary-heading"
                        >
                            {copy.summarySection}
                        </h2>
                    </div>
                    <span className="text-xs font-semibold text-muted-foreground tabular-nums">
                        {conversation.messageCount} {copy.messageCount}
                    </span>
                </div>

                <dl className="mt-4 grid grid-cols-1 gap-4 text-xs sm:grid-cols-2 lg:grid-cols-4">
                    <div>
                        <dt className="font-medium text-muted-foreground">
                            {copy.owner}
                        </dt>
                        <dd className="mt-1 flex items-center gap-1.5 font-semibold text-foreground">
                            {isGuest ? (
                                <span className="text-muted-foreground italic">
                                    {copy.guestSender}
                                </span>
                            ) : (
                                <>
                                    <UserIcon
                                        aria-hidden="true"
                                        className="size-3.5 text-muted-foreground"
                                    />
                                    <span>
                                        <bdi>
                                            {conversation.customerName ??
                                                copy.customerSender}
                                        </bdi>
                                    </span>
                                </>
                            )}
                        </dd>
                    </div>

                    <div>
                        <dt className="font-medium text-muted-foreground">
                            {copy.locale}
                        </dt>
                        <dd className="mt-1 font-semibold text-foreground uppercase">
                            {conversation.locale === 'ar'
                                ? conversationsCopy.localeAr
                                : conversationsCopy.localeEn}{' '}
                            ({conversation.locale})
                        </dd>
                    </div>

                    <div>
                        <dt className="font-medium text-muted-foreground">
                            {copy.lastMessageAt}
                        </dt>
                        <dd className="mt-1 text-foreground tabular-nums">
                            {conversation.lastMessageAt ? (
                                <bdi>
                                    {dateFormatter.format(
                                        new Date(conversation.lastMessageAt),
                                    )}
                                </bdi>
                            ) : (
                                '—'
                            )}
                        </dd>
                    </div>

                    <div>
                        <dt className="font-medium text-muted-foreground">
                            {copy.closeReason}
                        </dt>
                        <dd className="mt-1 text-foreground">
                            {closeReasonLabel ? (
                                <span>{closeReasonLabel}</span>
                            ) : (
                                <span className="text-muted-foreground/60 italic">
                                    —
                                </span>
                            )}
                        </dd>
                    </div>
                </dl>
            </section>

            {/* Transcript Thread Section */}
            <section
                aria-labelledby="conversation-transcript-heading"
                className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
            >
                <div className="flex items-center justify-between border-b border-border pb-3">
                    <div className="flex items-center gap-2">
                        <MessageSquare
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        <h2
                            className="text-sm font-semibold text-foreground"
                            id="conversation-transcript-heading"
                        >
                            {copy.transcriptSection}
                        </h2>
                    </div>
                    <span className="text-xs text-muted-foreground tabular-nums">
                        {props.messages.length} {copy.messageCount}
                    </span>
                </div>

                <div className="mt-5 space-y-4">
                    {props.messages.length > 0 ? (
                        props.messages.map((message) => (
                            <TranscriptMessageItem
                                copy={copy}
                                dateFormatter={dateFormatter}
                                key={message.publicId}
                                message={message}
                                timeFormatter={timeFormatter}
                            />
                        ))
                    ) : (
                        <p className="py-8 text-center text-xs text-muted-foreground">
                            {copy.noMessages}
                        </p>
                    )}
                </div>
            </section>

            {/* Agent Runtime Turns Section */}
            <section
                aria-labelledby="conversation-turns-heading"
                className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
            >
                <div className="flex items-center justify-between border-b border-border pb-3">
                    <div className="flex items-center gap-2">
                        <Cpu
                            aria-hidden="true"
                            className="size-4 text-primary"
                        />
                        <h2
                            className="text-sm font-semibold text-foreground"
                            id="conversation-turns-heading"
                        >
                            {copy.turnsSection}
                        </h2>
                    </div>
                    <span className="text-xs font-semibold text-muted-foreground tabular-nums">
                        {props.turns.length}
                    </span>
                </div>

                <div className="mt-4">
                    {props.turns.length > 0 ? (
                        <div className="overflow-x-auto">
                            <Table className="text-xs">
                                <TableHeader>
                                    <TableRow className="border-b border-border text-muted-foreground">
                                        <TableHead className="font-medium">
                                            {copy.turnId}
                                        </TableHead>
                                        <TableHead className="font-medium">
                                            {copy.turnStatus}
                                        </TableHead>
                                        <TableHead className="font-medium">
                                            {copy.promptVersion}
                                        </TableHead>
                                        <TableHead className="font-medium">
                                            {copy.model}
                                        </TableHead>
                                        <TableHead className="font-medium">
                                            {copy.runStatus}
                                        </TableHead>
                                        <TableHead className="font-medium">
                                            {copy.latency}
                                        </TableHead>
                                        <TableHead className="font-medium">
                                            {copy.tokens}
                                        </TableHead>
                                        <TableHead className="font-medium">
                                            {copy.turnCreatedAt}
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody className="divide-y divide-border/60">
                                    {props.turns.map((turn) => (
                                        <TableRow key={turn.publicId}>
                                            <TableCell className="font-mono text-xs text-muted-foreground tabular-nums">
                                                <bdi>{turn.publicId}</bdi>
                                            </TableCell>
                                            <TableCell>
                                                <AdminBadge
                                                    variant={getTurnStatusVariant(
                                                        turn.status,
                                                    )}
                                                >
                                                    {turn.status}
                                                </AdminBadge>
                                            </TableCell>
                                            <TableCell className="font-mono text-xs text-muted-foreground">
                                                {turn.promptVersion || '—'}
                                            </TableCell>
                                            <TableCell className="font-medium text-foreground">
                                                {turn.model ?? '—'}
                                            </TableCell>
                                            <TableCell>
                                                {turn.latestRunStatus ? (
                                                    <AdminBadge
                                                        variant={getTurnStatusVariant(
                                                            turn.latestRunStatus,
                                                        )}
                                                    >
                                                        {turn.latestRunStatus}
                                                    </AdminBadge>
                                                ) : (
                                                    <span className="text-muted-foreground/60 italic">
                                                        —
                                                    </span>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-foreground tabular-nums">
                                                {turn.latencyMs !== null ? (
                                                    <span className="inline-flex items-center gap-1">
                                                        <Zap
                                                            aria-hidden="true"
                                                            className="size-3 text-muted-foreground"
                                                        />
                                                        <span>
                                                            {turn.latencyMs}ms
                                                        </span>
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground tabular-nums">
                                                {turn.inputTokens !== null ||
                                                turn.outputTokens !== null ? (
                                                    <span>
                                                        {turn.inputTokens ?? 0}{' '}
                                                        /{' '}
                                                        {turn.outputTokens ?? 0}
                                                    </span>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                            <TableCell className="text-muted-foreground tabular-nums">
                                                {turn.createdAt ? (
                                                    <bdi>
                                                        {dateFormatter.format(
                                                            new Date(
                                                                turn.createdAt,
                                                            ),
                                                        )}
                                                    </bdi>
                                                ) : (
                                                    '—'
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    ) : (
                        <p className="py-6 text-center text-xs text-muted-foreground">
                            {copy.noTurns}
                        </p>
                    )}
                </div>
            </section>
        </article>
    );
}

function TranscriptMessageItem({
    copy,
    dateFormatter,
    message,
    timeFormatter,
}: {
    copy: AdminConversationDetailPageProps['adminUi']['conversationDetail'];
    dateFormatter: Intl.DateTimeFormat;
    message: AdminChatMessage;
    timeFormatter: Intl.DateTimeFormat;
}) {
    const isAssistant = message.senderType === 'assistant';
    const isSystem = message.senderType === 'system';
    const isCustomer = message.senderType === 'customer';

    if (isSystem) {
        return (
            <div className="flex justify-center py-2">
                <div className="max-w-prose rounded-md border border-border/80 bg-muted/40 px-3 py-1.5 text-center text-xs text-muted-foreground">
                    <span className="font-semibold">{copy.systemSender}:</span>{' '}
                    <span>{message.content}</span>
                    {message.createdAt ? (
                        <span className="ms-2 text-[10px] text-muted-foreground/70 tabular-nums">
                            <bdi>
                                {timeFormatter.format(
                                    new Date(message.createdAt),
                                )}
                            </bdi>
                        </span>
                    ) : null}
                </div>
            </div>
        );
    }

    return (
        <div
            className={`flex flex-col gap-1.5 ${
                isAssistant ? 'items-start' : 'items-end'
            }`}
        >
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {isAssistant ? (
                    <>
                        <span className="inline-flex size-5 items-center justify-center rounded-full bg-primary/10 text-primary">
                            <Bot aria-hidden="true" className="size-3" />
                        </span>
                        <span className="font-semibold text-primary">
                            {copy.assistantSender}
                        </span>
                    </>
                ) : (
                    <>
                        <span className="font-semibold text-foreground">
                            {isCustomer
                                ? copy.customerSender
                                : copy.guestSender}
                        </span>
                        <span className="inline-flex size-5 items-center justify-center rounded-full bg-accent text-foreground">
                            <UserIcon aria-hidden="true" className="size-3" />
                        </span>
                    </>
                )}
                {message.createdAt ? (
                    <span className="text-[11px] text-muted-foreground tabular-nums">
                        <bdi>
                            {dateFormatter.format(new Date(message.createdAt))}
                        </bdi>
                    </span>
                ) : null}
            </div>

            <div
                className={`max-w-2xl rounded-lg px-4 py-3 text-xs leading-relaxed shadow-2xs ${
                    isAssistant
                        ? 'border border-primary/20 bg-primary/5 text-foreground'
                        : 'border border-border bg-card text-card-foreground'
                }`}
            >
                <p className="break-words whitespace-pre-wrap">
                    {message.content}
                </p>
            </div>
        </div>
    );
}
