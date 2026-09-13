import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Info,
    Lightbulb,
    Loader2,
    PencilLine,
    RotateCw,
    X,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

import { formatInteger } from '@/lib/money';
import { TrackingRing } from '@/lib/tracking-ring';
import type { RingOptions } from '@/lib/tracking-ring';
import { cn } from '@/lib/utils';
import type {
    AccountLiveOrderPageProps,
    OrderItemTracking,
    OrderTrackingChallenge,
} from '@/types/account';

import '@/styles/order-tracking.css';

type TrackingStrings =
    AccountLiveOrderPageProps['accountUi']['orders']['tracking'];

type Platform = 'playstation' | 'xbox' | 'pc';

/**
 * The coins the tracker assumes move per minute when it has nothing better to
 * go on. Its own baseline, carried over so the first estimate a customer sees
 * here matches the one they saw there.
 */
const BASELINE_COINS_PER_MINUTE = 11_100;

/**
 * Presentations that mean the order is still moving. The ring throws embers
 * only for these; on anything else it holds its arc still, which is how a
 * stopped order reads as stopped before anyone has read a word.
 */
const ACTIVE_PRESENTATIONS = new Set([
    'processing',
    'cooldown_tempban',
    'cooldown_listing',
    'cooldown_daily_limit',
    'logging_in',
    'preparing',
    'transferring',
    'transferring_part_done',
    'finishing',
    'not_reported',
]);

const DANGER_PRESENTATIONS = new Set(['needs_review', 'stopped', 'cancelled']);

function ringMode(
    presentation: string,
    holdTone: string | null,
): RingOptions['mode'] {
    if (presentation === 'completed') {
        return 'success';
    }

    if (DANGER_PRESENTATIONS.has(presentation)) {
        return 'danger';
    }

    // An informational hold is amber whatever the headline says: the ring and the
    // box have to agree, or the screen contradicts itself.
    return holdTone === 'info' ? 'warning' : 'progress';
}

function consoleName(platform: Platform, strings: TrackingStrings): string {
    if (platform === 'playstation') {
        return strings.console_playstation;
    }

    if (platform === 'xbox') {
        return strings.console_xbox;
    }

    return strings.console_pc;
}

/**
 * How long ago we last heard, in words. Deliberately coarse: the customer wants
 * to know whether this is fresh, not the second it arrived.
 */
function freshness(
    observedAt: string | null,
    now: number,
    locale: 'ar' | 'en',
    strings: TrackingStrings,
): string | null {
    if (observedAt === null) {
        return null;
    }

    const then = Date.parse(observedAt);

    if (Number.isNaN(then)) {
        return null;
    }

    const minutes = Math.max(0, Math.floor((now - then) / 60_000));

    if (minutes < 1) {
        return strings.freshness_just_now;
    }

    if (minutes < 60) {
        return strings.freshness_minutes.replace(
            ':count',
            formatInteger(minutes, locale),
        );
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return strings.freshness_hours.replace(
            ':count',
            formatInteger(hours, locale),
        );
    }

    return strings.freshness_days.replace(
        ':count',
        formatInteger(Math.floor(hours / 24), locale),
    );
}

/**
 * The tracker's estimate: what is left divided by the baseline rate. It is an
 * estimate and it is labelled as one; the alternative is a customer refreshing
 * every thirty seconds to guess for themselves.
 */
function estimate(
    remaining: number,
    locale: 'ar' | 'en',
    strings: TrackingStrings,
): string | null {
    if (remaining <= 0) {
        return null;
    }

    const minutes = Math.ceil(remaining / BASELINE_COINS_PER_MINUTE);

    if (minutes <= 0) {
        return null;
    }

    const duration =
        minutes >= 60
            ? strings.eta_hours_minutes
                  .replace(
                      ':hours',
                      formatInteger(Math.floor(minutes / 60), locale),
                  )
                  .replace(':minutes', formatInteger(minutes % 60, locale))
            : strings.eta_minutes.replace(
                  ':count',
                  formatInteger(minutes, locale),
              );

    return strings.eta.replace(':duration', duration);
}

function Ring({
    percent,
    mode,
    active,
    indeterminate,
    icon,
}: RingOptions & { icon: 'spinner' | 'done' | 'error' | 'info' }) {
    const holder = useRef<HTMLDivElement>(null);
    const ring = useRef<TrackingRing | null>(null);
    const wasComplete = useRef(percent >= 100);

    useEffect(() => {
        if (holder.current === null) {
            return;
        }

        const instance = new TrackingRing(holder.current, {
            percent,
            mode,
            active,
            indeterminate,
        });
        ring.current = instance;

        return () => {
            instance.destroy();
            ring.current = null;
        };
        // The ring is created once and steered through set() below, because
        // recreating it would restart the arc from zero on every poll.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        ring.current?.set({ percent, mode, active, indeterminate });

        // The burst belongs to the moment of completion, not to every render of
        // a completed order: a customer reopening the page a week later is not
        // owed confetti.
        if (percent >= 100 && !wasComplete.current) {
            ring.current?.burst();
        }

        wasComplete.current = percent >= 100;
    }, [percent, mode, active, indeterminate]);

    const iconClass =
        mode === 'success'
            ? 'track-ring-icon track-ring-icon--green'
            : mode === 'danger'
              ? 'track-ring-icon track-ring-icon--red'
              : mode === 'warning'
                ? 'track-ring-icon track-ring-icon--amber'
                : 'track-ring-icon';

    return (
        <div
            className="track-ring-wrapper"
            data-active={active ? 'true' : 'false'}
            data-mode={mode}
            data-percent={percent}
            ref={holder}
        >
            {/* The CSS ring is the fallback when a canvas cannot be created. */}
            <svg
                aria-hidden="true"
                className={cn(
                    'track-ring-svg',
                    indeterminate && 'track-ring-svg--spinning',
                )}
                viewBox="0 0 100 100"
            >
                <circle className="track-ring-bg" cx="50" cy="50" r="44" />
                <circle
                    className={cn(
                        'track-ring-bar',
                        mode === 'success' && 'track-ring-bar--green',
                        mode === 'danger' && 'track-ring-bar--red',
                        mode === 'warning' && 'track-ring-bar--amber',
                        mode === 'progress' && 'track-ring-bar--gold',
                    )}
                    cx="50"
                    cy="50"
                    r="44"
                    style={{
                        strokeDasharray: `${(percent / 100) * 276.5} 276.5`,
                    }}
                />
            </svg>
            <div className={iconClass}>
                {icon === 'done' ? (
                    <CheckCircle2 aria-hidden="true" />
                ) : icon === 'error' ? (
                    <XCircle aria-hidden="true" />
                ) : icon === 'info' ? (
                    <Info aria-hidden="true" />
                ) : (
                    <Loader2 aria-hidden="true" />
                )}
            </div>
        </div>
    );
}

function ActionBox({
    tone,
    message,
    actions,
    strings,
    onAction,
}: {
    tone: 'action' | 'info';
    message: string | null;
    actions: string[];
    strings: TrackingStrings;
    onAction: (action: string) => void;
}) {
    if (message === null && actions.length === 0) {
        return null;
    }

    return (
        <div
            className={cn(
                'track-action-box',
                tone === 'info'
                    ? 'track-action-box--amber'
                    : 'track-action-box--red',
            )}
        >
            <div className="track-action-box__head">
                {tone === 'info' ? (
                    <Info aria-hidden="true" />
                ) : (
                    <AlertTriangle aria-hidden="true" />
                )}
                <span>
                    {tone === 'info' ? strings.info : strings.action_required}
                </span>
            </div>
            {message !== null ? (
                <p className="track-action-box__body">{message}</p>
            ) : null}
            {actions.length > 0 ? (
                <div className="track-action-box__actions">
                    {actions.map((action, index) => (
                        <button
                            className={cn(
                                'track-btn',
                                index === 0
                                    ? 'track-btn--primary'
                                    : 'track-btn--secondary',
                            )}
                            key={action}
                            onClick={() => onAction(action)}
                            type="button"
                        >
                            {action === 'edit_credentials' ? (
                                <PencilLine aria-hidden="true" />
                            ) : (
                                <RotateCw aria-hidden="true" />
                            )}
                            {action === 'edit_credentials'
                                ? strings.edit_credentials
                                : action === 'retry_challenge'
                                  ? strings.retry_challenge
                                  : strings.resume}
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

/**
 * The tracker's progress area: the reading on one side, the ratio on the other,
 * and a nine-pixel track with a travelling sheen under both.
 */
function ProgressBar({
    percent,
    label,
    lead,
    tone,
}: {
    percent: number;
    label: string;
    /** The challenge cards put the word "التقدم" where the coins card puts the reading. */
    lead?: string;
    tone: 'gold' | 'green' | 'danger';
}) {
    return (
        <div className="track-progress">
            <div className="track-progress__meta">
                {lead === undefined ? (
                    <span
                        className="track-progress__pct"
                        style={
                            tone === 'danger'
                                ? { color: 'var(--track-danger)' }
                                : undefined
                        }
                    >
                        {percent}%
                    </span>
                ) : (
                    <span>{lead}</span>
                )}
                <span className="track-progress__count">{label}</span>
            </div>
            <div className="track-progress__bar">
                <div
                    className={cn(
                        'track-progress__fill',
                        tone === 'green' && 'track-progress__fill--green',
                    )}
                    style={{
                        width: `${percent}%`,
                        ...(tone === 'danger'
                            ? { background: 'var(--track-danger)' }
                            : {}),
                    }}
                />
            </div>
        </div>
    );
}
/**
 * How long ago, in the words the tracker uses. It says "منذ ٢٣ يوماً" rather
 * than a date, because the customer is asking "is this recent", not "when".
 */
function relativeTime(
    iso: string,
    now: number,
    locale: 'ar' | 'en',
): string | null {
    const then = Date.parse(iso);

    if (Number.isNaN(then)) {
        return null;
    }

    const minutes = Math.round((then - now) / 60_000);
    const format = new Intl.RelativeTimeFormat(
        locale === 'ar' ? 'ar-SA' : 'en',
        { numeric: 'auto' },
    );

    if (Math.abs(minutes) < 60) {
        return format.format(minutes, 'minute');
    }

    const hours = Math.round(minutes / 60);

    if (Math.abs(hours) < 24) {
        return format.format(hours, 'hour');
    }

    return format.format(Math.round(hours / 24), 'day');
}

/**
 * One challenge, laid out as the tracker lays it out: the badge at the start
 * with its state underneath, the challenge's own name across from it, then the
 * squad progress, the two figures, and — once it is done — when it finished.
 */
function ChallengeCard({
    challenge,
    name,
    imageUrl,
    locale,
    now,
    productUrl,
    strings,
    onAction,
    onHelp,
    onZoom,
}: {
    challenge: OrderTrackingChallenge;
    name: string;
    imageUrl: string;
    locale: 'ar' | 'en';
    now: number;
    productUrl: string;
    strings: TrackingStrings;
    onAction: (action: string, target: number) => void;
    onHelp: (challenge: OrderTrackingChallenge) => void;
    onZoom: (src: string, label: string) => void;
}) {
    const done = challenge.squads.done ?? 0;
    const total = challenge.squads.total ?? 0;
    const percent =
        total > 0 ? Math.min(Math.round((done / total) * 100), 100) : 0;
    const solvesTotal = challenge.solves.total ?? 1;

    const chipTone =
        challenge.state === 'done'
            ? 'track-challenge__chip--green'
            : challenge.holdTone === 'info'
              ? 'track-challenge__chip--amber'
              : challenge.holdTone === 'action'
                ? 'track-challenge__chip--red'
                : 'track-challenge__chip--gold';

    const finished =
        challenge.finishedAt !== null
            ? relativeTime(challenge.finishedAt, now, locale)
            : null;

    return (
        <div className="track-challenge">
            <div className="track-challenge__top">
                <button
                    aria-label={name}
                    className="track-challenge__thumb-button"
                    onClick={() => onZoom(imageUrl, name)}
                    type="button"
                >
                    <img
                        alt=""
                        className="track-challenge__thumb"
                        src={imageUrl}
                    />
                </button>
                <a
                    className="track-challenge__name"
                    dir="ltr"
                    href={productUrl}
                >
                    {name}
                </a>
            </div>

            <div className="track-challenge__status-row">
                <button
                    aria-label={strings.challenge_help}
                    className="track-challenge__help"
                    onClick={() => onHelp(challenge)}
                    type="button"
                >
                    ?
                </button>
                <span className={cn('track-challenge__chip', chipTone)}>
                    {challenge.state === 'done' ? (
                        <CheckCircle2 aria-hidden="true" />
                    ) : challenge.holdTone === 'action' ? (
                        <AlertTriangle aria-hidden="true" />
                    ) : (
                        <Loader2 aria-hidden="true" className="track-spin" />
                    )}
                    {challenge.stateLabel}
                </span>
            </div>

            {total > 0 ? (
                <ProgressBar
                    label={strings.squads
                        .replace(':done', formatInteger(done, locale))
                        .replace(':total', formatInteger(total, locale))
                        .replace(':percent', formatInteger(percent, locale))}
                    lead={strings.progress}
                    percent={percent}
                    tone={challenge.state === 'done' ? 'green' : 'gold'}
                />
            ) : null}

            <div className="track-challenge__stats">
                {/* The tracker hides the repeat count when a challenge is solved
                    once, because "1 / 1" is a fact nobody needed. */}
                {solvesTotal >= 2 ? (
                    <div className="track-stat">
                        <span className="track-stat__label">
                            {strings.repeat}
                        </span>
                        <strong className="track-stat__val" dir="ltr">
                            {formatInteger(challenge.solves.done ?? 0, locale)}{' '}
                            / {formatInteger(solvesTotal, locale)}
                        </strong>
                    </div>
                ) : null}
                <div className="track-stat">
                    <span className="track-stat__label">
                        {strings.coins_used}
                    </span>
                    <strong className="track-stat__val">
                        {formatInteger(challenge.coinsUsed ?? 0, locale)}
                    </strong>
                </div>
            </div>

            {finished !== null ? (
                <span className="track-challenge__finished">
                    <CheckCircle2 aria-hidden="true" />
                    {strings.challenge_completed_ago.replace(':when', finished)}
                </span>
            ) : null}

            {challenge.holdMessage !== null ? (
                <ActionBox
                    actions={[]}
                    message={challenge.holdMessage}
                    onAction={() => undefined}
                    strings={strings}
                    tone={challenge.holdTone === 'info' ? 'info' : 'action'}
                />
            ) : null}

            {challenge.actions.length > 0 ? (
                <div className="track-challenge__actions">
                    {challenge.actions.map((action) => (
                        <button
                            className="track-btn"
                            key={action}
                            onClick={() => onAction(action, challenge.target)}
                            type="button"
                        >
                            {action === 'edit_credentials' ? (
                                <PencilLine aria-hidden="true" />
                            ) : (
                                <RotateCw aria-hidden="true" />
                            )}
                            {action === 'edit_credentials'
                                ? strings.edit_credentials
                                : strings.retry_challenge}
                        </button>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

export default function OrderTracking({
    tracking,
    itemName,
    imageUrl,
    platform,
    locale,
    strings,
    onAction,
}: {
    tracking: OrderItemTracking;
    itemName: string;
    imageUrl: string;
    platform: Platform;
    locale: 'ar' | 'en';
    strings: TrackingStrings;
    onAction: (action: string, target: number | null) => void;
}) {
    // Recomputed on a timer so "a minute ago" does not sit there saying "just
    // now" while the customer watches it.
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    const delivered = tracking.progress?.coinsDelivered ?? 0;
    const ordered = tracking.progress?.coinsOrdered ?? 0;
    const percent =
        ordered > 0
            ? Math.min(Math.round((delivered / ordered) * 100), 100)
            : 0;
    const remaining = Math.max(0, ordered - delivered);

    const mode = ringMode(tracking.presentation, tracking.holdTone);
    const active = ACTIVE_PRESENTATIONS.has(tracking.presentation);
    const complete = tracking.presentation === 'completed';

    const subline = useMemo(
        () =>
            tracking.subline.replace(
                ':console',
                consoleName(platform, strings),
            ),
        [tracking.subline, platform, strings],
    );

    const age = freshness(tracking.observedAt, now, locale, strings);
    const eta =
        active && !complete && ordered > 0
            ? estimate(remaining, locale, strings)
            : null;
    const completedAgo =
        tracking.completedAt !== null
            ? relativeTime(tracking.completedAt, now, locale)
            : null;

    const challenges = tracking.challenges ?? [];

    // Tapping a badge opens it at full size. The tracker does the same, and a
    // 64px thumbnail of a squad badge is a thing you squint at otherwise.
    const [zoom, setZoom] = useState<{ src: string; label: string } | null>(
        null,
    );

    // One explanation open at a time, raised by whichever card's "?" was
    // pressed. A dialog rather than a panel inside the card, because it
    // explains a state the customer may want to read while looking at the
    // buttons underneath it.
    const [help, setHelp] = useState<OrderTrackingChallenge | null>(null);

    useEffect(() => {
        if (help === null && zoom === null) {
            return;
        }

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setHelp(null);
                setZoom(null);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [help, zoom]);

    // The challenges all belong to one purchased item, so they share its
    // product page. The store lists challenges at /sbc; there is no per-set
    // page to send anyone to.
    const productUrl = locale === 'en' ? '/en/sbc' : '/sbc';

    // The tracker keeps coins and challenges in two panes behind a pair of tabs
    // rather than stacking them, and shows the tabs only when the order has
    // both. A Challenge item ships its coins first, so it is the only kind that
    // ever can.
    const hasBoth = challenges.length > 0 && ordered > 0;

    const [tab, setTab] = useState<'coins' | 'challenges'>(
        // Open on whatever is moving now: once the coins have landed, the
        // challenge is the part still running.
        tracking.phase === 'challenge' && challenges.length > 0
            ? 'challenges'
            : 'coins',
    );

    const pane =
        challenges.length === 0 ? 'coins' : hasBoth ? tab : 'challenges';
    const allChallengesDone =
        challenges.length > 0 && challenges.every((c) => c.state === 'done');

    // The tracker drops the "remaining" box once nothing remains, and the row
    // closes up to two columns rather than leaving a zero on the screen.
    const showRemaining = !complete && remaining > 0;
    const statBoxes =
        (ordered > 0 ? 1 : 0) +
        (showRemaining ? 1 : 0) +
        (tracking.accountCoins.state !== 'unknown' ? 1 : 0);

    return (
        <div className="track-container">
            <div className="track-card">
                {age !== null ? (
                    <span className="track-freshness">{age}</span>
                ) : null}

                {hasBoth ? (
                    <div className="track-tabs" role="tablist">
                        <span
                            aria-hidden="true"
                            className="track-tabs__slider"
                            style={{
                                width: 'calc(50% - 4px)',
                                insetInlineStart:
                                    tab === 'coins' ? '4px' : 'calc(50% + 0px)',
                            }}
                        />
                        <button
                            aria-selected={tab === 'coins'}
                            className={cn(
                                'track-tab',
                                tab === 'coins' && 'track-tab--active',
                            )}
                            onClick={() => setTab('coins')}
                            role="tab"
                            type="button"
                        >
                            <img
                                alt=""
                                className="track-tab__logo"
                                src="/images/store/coins/ut-coin-80.webp"
                            />
                            <span>{strings.tab_coins}</span>
                        </button>
                        <button
                            aria-selected={tab === 'challenges'}
                            className={cn(
                                'track-tab',
                                tab === 'challenges' && 'track-tab--active',
                            )}
                            onClick={() => setTab('challenges')}
                            role="tab"
                            type="button"
                        >
                            <img
                                alt=""
                                className="track-tab__logo"
                                src="/images/store/navigation/logo-sbc-256.webp"
                            />
                            <span>{strings.tab_challenges}</span>
                        </button>
                    </div>
                ) : null}

                {pane === 'coins' ? (
                    <>
                        <Ring
                            active={active}
                            icon={
                                complete
                                    ? 'done'
                                    : DANGER_PRESENTATIONS.has(
                                            tracking.presentation,
                                        )
                                      ? 'error'
                                      : tracking.holdTone === 'info'
                                        ? 'info'
                                        : 'spinner'
                            }
                            indeterminate={ordered === 0 && !complete}
                            mode={mode}
                            percent={complete ? 100 : percent}
                        />

                        <h4 className="track-headline">{tracking.headline}</h4>
                        <p className="track-subline">{subline}</p>

                        {complete && completedAgo !== null ? (
                            <p className="track-completed-ago">
                                <CheckCircle2 aria-hidden="true" />
                                {strings.order_completed_ago.replace(
                                    ':when',
                                    completedAgo,
                                )}
                            </p>
                        ) : null}

                        {ordered > 0 ? (
                            <ProgressBar
                                label={`${formatInteger(delivered, locale)} / ${formatInteger(ordered, locale)} ${strings.coins_unit}`}
                                percent={percent}
                                tone={
                                    complete
                                        ? 'green'
                                        : DANGER_PRESENTATIONS.has(
                                                tracking.presentation,
                                            )
                                          ? 'danger'
                                          : 'gold'
                                }
                            />
                        ) : null}

                        {eta !== null ? (
                            <div className="track-eta">
                                <Clock aria-hidden="true" />
                                <span>{eta}</span>
                            </div>
                        ) : null}

                        {statBoxes > 0 ? (
                            <div
                                className={cn(
                                    'track-stats',
                                    statBoxes === 2 && 'track-stats--two-col',
                                    statBoxes === 1 && 'track-stats--one-col',
                                )}
                            >
                                {ordered > 0 ? (
                                    <div className="track-stat">
                                        <span className="track-stat__label">
                                            {strings.delivered}
                                        </span>
                                        <strong className="track-stat__val">
                                            {formatInteger(delivered, locale)}
                                        </strong>
                                    </div>
                                ) : null}
                                {showRemaining ? (
                                    <div className="track-stat">
                                        <span className="track-stat__label">
                                            {strings.remaining}
                                        </span>
                                        <strong className="track-stat__val">
                                            {formatInteger(remaining, locale)}
                                        </strong>
                                    </div>
                                ) : null}
                                {tracking.accountCoins.state !== 'unknown' ? (
                                    <div className="track-stat">
                                        <span className="track-stat__label">
                                            {strings.account_coins}
                                        </span>
                                        <strong
                                            className={cn(
                                                'track-stat__val',
                                                tracking.accountCoins.state ===
                                                    'preparing' &&
                                                    'track-stat__val--amber',
                                            )}
                                        >
                                            {tracking.accountCoins.state ===
                                            'preparing'
                                                ? strings.account_coins_preparing
                                                : formatInteger(
                                                      tracking.accountCoins
                                                          .amount ?? 0,
                                                      locale,
                                                  )}
                                        </strong>
                                    </div>
                                ) : null}
                            </div>
                        ) : null}
                    </>
                ) : (
                    <>
                        <div
                            className={cn(
                                'track-challenges__head',
                                allChallengesDone &&
                                    'track-challenges__head--done',
                            )}
                        >
                            {allChallengesDone
                                ? strings.challenges_all_done
                                : strings.challenges_count.replace(
                                      ':count',
                                      formatInteger(challenges.length, locale),
                                  )}
                        </div>

                        <div className="track-challenges">
                            {challenges.map((challenge) => (
                                <ChallengeCard
                                    challenge={challenge}
                                    imageUrl={imageUrl}
                                    key={challenge.target}
                                    locale={locale}
                                    name={itemName}
                                    now={now}
                                    onAction={onAction}
                                    onHelp={setHelp}
                                    onZoom={(src, label) =>
                                        setZoom({ src, label })
                                    }
                                    productUrl={productUrl}
                                    strings={strings}
                                />
                            ))}
                        </div>
                    </>
                )}

                {/* One action box, shared by both panes and sitting under them.
                    That is where the tracker puts it, and it is what its own
                    copy means when it tells the customer to look below. */}
                {tracking.holdMessage !== null ||
                tracking.actions.length > 0 ? (
                    <ActionBox
                        actions={tracking.actions}
                        message={tracking.holdMessage}
                        onAction={(action) => onAction(action, null)}
                        strings={strings}
                        tone={tracking.holdTone === 'info' ? 'info' : 'action'}
                    />
                ) : null}
            </div>

            {help !== null ? (
                <div
                    className="track-modal"
                    onClick={() => setHelp(null)}
                    role="presentation"
                >
                    <div
                        aria-labelledby="track-help-title"
                        aria-modal="true"
                        className="track-modal__panel"
                        onClick={(event) => event.stopPropagation()}
                        role="dialog"
                    >
                        <div className="track-modal__head">
                            <h5
                                className="track-modal__title"
                                id="track-help-title"
                            >
                                <Info aria-hidden="true" />
                                <span>{help.help.title}</span>
                            </h5>
                            <button
                                aria-label={strings.close}
                                className="track-modal__close"
                                onClick={() => setHelp(null)}
                                type="button"
                            >
                                <X aria-hidden="true" />
                            </button>
                        </div>
                        <p className="track-modal__body">
                            {help.holdMessage ?? help.help.desc}
                        </p>
                        {help.help.action !== '' ? (
                            <div className="track-modal__action">
                                <strong>
                                    <Lightbulb aria-hidden="true" />
                                    {strings.help_action_label}
                                </strong>
                                <p>{help.help.action}</p>
                            </div>
                        ) : null}
                    </div>
                </div>
            ) : null}

            {zoom !== null ? (
                <div
                    className="track-zoom"
                    onClick={() => setZoom(null)}
                    role="presentation"
                >
                    <button
                        aria-label={strings.close}
                        className="track-zoom__close"
                        onClick={() => setZoom(null)}
                        type="button"
                    >
                        <X aria-hidden="true" />
                    </button>
                    <img
                        alt={zoom.label}
                        className="track-zoom__image"
                        src={zoom.src}
                    />
                </div>
            ) : null}
        </div>
    );
}
