import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    Bot,
    CheckCircle2,
    Cpu,
    EyeOff,
    Globe,
    MessageSquare,
    Send,
    User as UserIcon,
    XCircle,
    Zap,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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
import { DATE_LOCALE } from '@/lib/date-locale';
import type {
    AdminChatMessage,
    AdminConversationDetailPageProps,
    AdminSupportTicket,
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

    const dateFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: 'UTC',
    });

    const timeFormatter = new Intl.DateTimeFormat(DATE_LOCALE, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        timeZone: 'UTC',
    });

    const isGuest = conversation.ownerType === 'guest';

    // Keep the open thread live.
    //
    // Without this the operator is staring at a snapshot: a customer can reply
    // and nothing moves until the page is reloaded by hand, which is exactly
    // the friction that stops this being usable for a day's work. Only polls
    // while a handoff is live — an ordinary archived conversation is not going
    // to change — and pauses whenever the tab is hidden, so an admin who leaves
    // a tab open overnight is not making requests all night.
    const isLiveThread =
        conversation.handoffState === 'requested' ||
        conversation.handoffState === 'active';

    useEffect(() => {
        if (!isLiveThread) {
            return;
        }

        let timer: ReturnType<typeof setTimeout> | null = null;
        let stopped = false;

        const tick = () => {
            if (stopped) {
                return;
            }

            if (typeof document !== 'undefined' && document.hidden) {
                timer = setTimeout(tick, 10000);

                return;
            }

            router.reload({
                // reload() preserves component state and scroll by default,
                // so the composer keeps whatever is half-typed in it.
                only: ['messages', 'conversation', 'ticket'],
                onFinish: () => {
                    if (!stopped) {
                        timer = setTimeout(tick, 10000);
                    }
                },
            });
        };

        timer = setTimeout(tick, 10000);

        return () => {
            stopped = true;

            if (timer !== null) {
                clearTimeout(timer);
            }
        };
    }, [isLiveThread]);

    // Newest message in view on load and after every reply, the way a chat
    // behaves. A transcript that opens at the top makes the operator scroll
    // before they can read the thing they came to answer.
    const transcriptEndRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        transcriptEndRef.current?.scrollIntoView({ block: 'end' });
    }, [props.messages.length]);
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
                    <div ref={transcriptEndRef} />
                </div>
            </section>

            {props.canReply && !isGuest ? (
                <StaffReplyPanel
                    basePath={conversationsListUrl}
                    conversationPublicId={conversation.publicId}
                    copy={copy}
                    ticket={props.ticket}
                />
            ) : null}

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
    const isStaff = message.senderType === 'staff';
    const isNote = message.messageType === 'internal_note';

    // A note has to be unmistakable at a glance: it is the one thing on this
    // page that was written to the customer's thread but is never shown to
    // them, and mistaking it for a reply is how a private remark gets sent.
    if (isNote) {
        return (
            <div
                className="rounded-lg border border-dashed border-muted-foreground/50 bg-muted/40 px-4 py-3"
                dir="auto"
            >
                <div className="flex items-center gap-1.5">
                    <EyeOff
                        aria-hidden="true"
                        className="size-3.5 text-muted-foreground"
                    />
                    <span className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        {copy.internalNoteLabel}
                    </span>
                    {message.staffName ? (
                        <span className="text-[11px] text-muted-foreground">
                            · <bdi>{message.staffName}</bdi>
                        </span>
                    ) : null}
                    {message.createdAt ? (
                        <span className="text-[10px] text-muted-foreground/70 tabular-nums">
                            <bdi>
                                {timeFormatter.format(
                                    new Date(message.createdAt),
                                )}
                            </bdi>
                        </span>
                    ) : null}
                </div>
                <p className="mt-1 text-xs leading-relaxed break-words whitespace-pre-wrap text-foreground">
                    {message.content}
                </p>
            </div>
        );
    }

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
                isAssistant || isCustomer ? 'items-start' : 'items-end'
            }`}
            dir="auto"
        >
            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {isStaff ? (
                    <>
                        <span className="inline-flex size-5 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-primary-foreground">
                            {(message.staffName?.trim() ?? '').charAt(0) || '#'}
                        </span>
                        <span className="font-semibold text-primary">
                            <bdi>
                                {message.staffName?.trim()
                                    ? message.staffName.trim()
                                    : copy.staffSender}
                            </bdi>
                        </span>
                    </>
                ) : isAssistant ? (
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
                    isStaff
                        ? 'border-[1.5px] border-primary bg-primary/10 text-foreground'
                        : isAssistant
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

/**
 * Reply, note, take over and resolve.
 *
 * Replying takes the thread over implicitly — the server does it in the same
 * transaction — so the panel says so in plain words rather than offering a
 * separate "take over first" step nobody would remember to press.
 */
function StaffReplyPanel({
    basePath,
    conversationPublicId,
    copy,
    ticket,
}: {
    basePath: string;
    conversationPublicId: string;
    copy: AdminConversationDetailPageProps['adminUi']['conversationDetail'];
    ticket: AdminSupportTicket | null;
}) {
    const [mode, setMode] = useState<'reply' | 'note'>('reply');
    const [content, setContent] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const maxLength = 4000;
    const trimmed = content.trim();
    const canSubmit = trimmed !== '' && !isSubmitting;
    const isResolved = ticket !== null && ticket.status !== 'open';

    const conversationPath = `${basePath}/${conversationPublicId}`;

    const submit = () => {
        if (!canSubmit) {
            return;
        }

        setIsSubmitting(true);
        setError(null);

        router.post(
            `${conversationPath}/${mode === 'reply' ? 'reply' : 'note'}`,
            { content: trimmed },
            {
                preserveScroll: true,
                onFinish: () => setIsSubmitting(false),
                onSuccess: () => setContent(''),
                onError: (errors) =>
                    setError(errors.chat ?? errors.content ?? copy.replyFailed),
            },
        );
    };

    return (
        <section
            aria-labelledby="conversation-reply-heading"
            className="rounded-lg border border-border bg-card p-5 text-card-foreground shadow-xs"
        >
            <h2 className="sr-only" id="conversation-reply-heading">
                {copy.replySection}
            </h2>

            <div className="flex flex-wrap items-center gap-2">
                <button
                    aria-pressed={mode === 'reply'}
                    className={`inline-flex min-h-11 items-center rounded-md px-3 text-xs font-bold ${
                        mode === 'reply'
                            ? 'border border-primary bg-primary/10 text-primary'
                            : 'border border-border text-muted-foreground'
                    }`}
                    onClick={() => setMode('reply')}
                    type="button"
                >
                    {copy.replyToCustomer}
                </button>
                <button
                    aria-pressed={mode === 'note'}
                    className={`inline-flex min-h-11 items-center rounded-md px-3 text-xs font-bold ${
                        mode === 'note'
                            ? 'border border-primary bg-primary/10 text-primary'
                            : 'border border-border text-muted-foreground'
                    }`}
                    onClick={() => setMode('note')}
                    type="button"
                >
                    {copy.internalNoteLabel}
                </button>
                <span className="ms-auto text-xs text-muted-foreground tabular-nums">
                    <bdi>
                        {content.length} / {maxLength}
                    </bdi>
                </span>
            </div>

            <textarea
                aria-label={
                    mode === 'reply'
                        ? copy.replyToCustomer
                        : copy.internalNoteLabel
                }
                className="mt-3 min-h-24 w-full rounded-lg border border-border bg-background px-4 py-3 text-xs leading-relaxed text-foreground"
                dir="auto"
                maxLength={maxLength}
                onChange={(event) => setContent(event.target.value)}
                onKeyDown={(event) => {
                    // Enter sends, Shift+Enter breaks the line. Mohamed answers
                    // dozens of these a day; reaching for the mouse each time is
                    // the difference between a tool and a chore.
                    if (event.key === 'Enter' && !event.shiftKey) {
                        event.preventDefault();
                        submit();
                    }
                }}
                placeholder={
                    mode === 'reply'
                        ? copy.replyPlaceholder
                        : copy.notePlaceholder
                }
                value={content}
            />

            <div className="mt-3 flex flex-wrap items-center gap-2">
                <button
                    className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-primary px-5 text-sm font-bold text-primary-foreground disabled:opacity-50"
                    disabled={!canSubmit}
                    onClick={submit}
                    type="button"
                >
                    <Send aria-hidden="true" className="size-4" />
                    {mode === 'reply' ? copy.sendReply : copy.saveNote}
                </button>

                {ticket !== null && !isResolved ? (
                    <button
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-border px-4 text-sm font-semibold text-foreground disabled:opacity-50"
                        disabled={isSubmitting}
                        onClick={() => {
                            setIsSubmitting(true);
                            router.patch(
                                `${basePath.replace('/conversations', '/tickets')}/${ticket.publicId}`,
                                { status: 'resolved' },
                                {
                                    preserveScroll: true,
                                    onFinish: () => setIsSubmitting(false),
                                },
                            );
                        }}
                        type="button"
                    >
                        <CheckCircle2 aria-hidden="true" className="size-4" />
                        {copy.resolveTicket}
                    </button>
                ) : null}

                {ticket === null || !ticket.assignedToMe ? (
                    <button
                        className="inline-flex min-h-11 items-center gap-2 rounded-lg border border-border px-4 text-sm font-semibold text-foreground disabled:opacity-50"
                        disabled={isSubmitting}
                        onClick={() => {
                            setIsSubmitting(true);
                            router.post(
                                `${conversationPath}/take-over`,
                                {},
                                {
                                    preserveScroll: true,
                                    onFinish: () => setIsSubmitting(false),
                                },
                            );
                        }}
                        type="button"
                    >
                        {copy.takeOver}
                    </button>
                ) : null}
            </div>

            {error !== null ? (
                <p
                    className="mt-3 rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive"
                    role="alert"
                >
                    {error}
                </p>
            ) : null}

            <p className="mt-3 text-xs text-muted-foreground">
                {mode === 'reply' ? copy.replyTakesOverNotice : copy.noteNotice}{' '}
                {copy.enterToSend}
            </p>
        </section>
    );
}
