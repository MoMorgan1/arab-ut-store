import { Link, router } from '@inertiajs/react';
import type React from 'react';
import { useCallback, useEffect, useRef, useState } from 'react';

import AppIcon from '@/components/account/app-icon';
import type { AppIconName } from '@/components/account/app-icon';
import { useKeyboardOpen } from '@/hooks/use-keyboard-open';
import { afterPaint, prefersReducedMotion } from '@/hooks/use-travelling-lens';
import { cn } from '@/lib/utils';
import type {
    AccountDestination,
    AccountNavigationItem,
    AccountTranslations,
} from '@/types/account';

const destinationIcons: Record<AccountDestination, AppIconName> = {
    overview: 'grid',
    orders: 'cube',
    wallet: 'wallet',
    profile: 'user',
};

type AccountMobileBottomNavProps = {
    adminUrl?: string | null;
    bottomNav?: { home: string; account: string };
    current: AccountDestination;
    items: AccountNavigationItem[];
    /**
     * Destinations that still need the customer (an unverified number or
     * email). Rendered as a quiet dot, never a count.
     */
    attention?: AccountDestination[];
    translations: AccountTranslations['navigation'];
};

/**
 * Where the lens was last resting, kept outside the component.
 *
 * Inertia rebuilds this bar on every page swap, so the element that should be
 * sliding is thrown away and a new one appears already at its destination:
 * a tap teleported the lens while a drag, which never leaves the page, slid it.
 * Remembering the last placement lets the replacement start where the old one
 * stopped and travel from there.
 */
let carried: { key: AccountDestination; left: number; width: number } | null =
    null;

const ALLOWED_KEYS: AccountDestination[] = [
    'overview',
    'orders',
    'wallet',
    'profile',
];

export function AccountMobileBottomNav({
    adminUrl,
    attention = [],
    bottomNav,
    current,
    items,
    translations,
}: AccountMobileBottomNavProps) {
    const isKeyboardOpen = useKeyboardOpen();
    // The tab lights up the moment it is pressed, rather than when the server
    // answers. On a phone the round trip is what made the bar feel heavy, and
    // this removes the wait from what the finger sees.
    const [pending, setPending] = useState<AccountDestination | null>(null);

    // Filter to ensure strictly the 4 destinations
    const bottomNavItems = items.filter((item) =>
        ALLOWED_KEYS.includes(item.key),
    );

    // A lens of glass sits over the selected tab and slides to the next one,
    // bending what is behind it as it goes. It is one absolutely positioned
    // element measured against the pressed tab rather than a border on each
    // one, so the movement between them is a single transition instead of one
    // thing switching off and another switching on.
    const inner = useRef<HTMLDivElement>(null);

    const tick = useCallback((pattern: number | number[]) => {
        if (
            typeof navigator === 'undefined' ||
            typeof navigator.vibrate !== 'function'
        ) {
            return;
        }

        // A visitor who asked for less motion did not ask to be buzzed either.
        if (prefersReducedMotion()) {
            return;
        }

        navigator.vibrate(pattern);
    }, []);

    const [lens, setLens] = useState<{ left: number; width: number } | null>(
        () => carried,
    );

    // How far the lens is stretched, and by how much it is flattened to keep
    // its area roughly constant. A bead of liquid does not gain volume when it
    // moves, and the eye notices when a highlight does.
    const [stretch, setStretch] = useState(1);
    const lastX = useRef<number | null>(null);
    const settle = useRef<number | null>(null);

    // A drag reads its stretch from the speed of the finger. A tap has no
    // finger movement to read, so the same stretch comes from how far the lens
    // has to travel - otherwise the identical movement behaves like two
    // different materials depending on how it was started.
    const stretchOverTravel = useCallback((distance: number) => {
        if (distance < 4 || prefersReducedMotion()) {
            return;
        }

        // The width of one tab is roughly 90px, so a jump across the whole bar
        // tops the stretch out while a hop next door barely shows.
        setStretch(Math.min(1 + distance / 260, 1.28));

        if (settle.current !== null) {
            window.clearTimeout(settle.current);
        }

        // Released before the travel ends, so the 420ms curve is already
        // easing the lens back to its own shape as it lands.
        settle.current = window.setTimeout(() => setStretch(1), 190);
    }, []);

    useEffect(
        () => () => {
            if (settle.current !== null) {
                window.clearTimeout(settle.current);
            }
        },
        [],
    );

    const active = pending ?? current;

    const placeLens = useCallback(() => {
        const container = inner.current;

        if (container === null) {
            return;
        }

        const target = container.querySelector<HTMLElement>(
            '[data-bar-key="' + active + '"]',
        );

        if (target === null) {
            setLens(null);

            return;
        }

        const next = {
            left: target.offsetLeft,
            width: target.offsetWidth,
        };

        // Moving to a different tab, rather than being re-measured after a
        // rotation or a font swap, is what earns the stretch.
        if (carried !== null && carried.key !== active) {
            stretchOverTravel(Math.abs(next.left - carried.left));
        }

        carried = { key: active, ...next };
        setLens(next);
    }, [active, stretchOverTravel]);

    // Dragging: the lens leaves the tab it was resting on and follows the
    // finger, then settles on whichever tab the finger is over when it lifts.
    // While a drag is running the lens is positioned frame by frame, so the
    // easing that makes a tap look good would make a drag feel like lag.
    const [dragging, setDragging] = useState(false);
    const dragged = useRef(false);
    const crossed = useRef<AccountDestination | null>(null);

    const keyUnderPointer = useCallback(
        (clientX: number): AccountDestination | null => {
            const container = inner.current;

            if (container === null) {
                return null;
            }

            const tabs = Array.from(
                container.querySelectorAll<HTMLElement>('[data-bar-key]'),
            );

            const hit = tabs.find((tab) => {
                const box = tab.getBoundingClientRect();

                return clientX >= box.left && clientX <= box.right;
            });

            return (
                (hit?.dataset.barKey as AccountDestination | undefined) ?? null
            );
        },
        [],
    );

    const onPointerDown = useCallback(
        (event: React.PointerEvent) => {
            // A mouse drag across the bar is not a gesture anyone makes; this is
            // for a finger, and for a pen.
            if (event.pointerType === 'mouse') {
                return;
            }

            dragged.current = false;
            setDragging(true);
            event.currentTarget.setPointerCapture(event.pointerId);
            tick(6);
        },
        [tick],
    );

    const onPointerMove = useCallback(
        (event: React.PointerEvent) => {
            if (!dragging) {
                return;
            }

            const container = inner.current;

            if (container === null) {
                return;
            }

            const box = container.getBoundingClientRect();
            const key = keyUnderPointer(event.clientX);

            // Speed, in pixels since the previous frame. 44px of travel is
            // about the fastest a thumb moves across a bar this size, so that
            // is where the stretch tops out.
            const previous = lastX.current;
            lastX.current = event.clientX;

            if (previous !== null) {
                const speed = Math.abs(event.clientX - previous);
                setStretch(Math.min(1 + speed / 44, 1.28));
            }

            if (key !== null && key !== active) {
                // One tick per tab the finger crosses, the way a picker
                // clicks through its stops.
                if (!dragged.current || key !== crossed.current) {
                    tick(6);
                    crossed.current = key;
                }

                dragged.current = true;
            }

            setLens((current) => {
                if (current === null) {
                    return current;
                }

                const half = current.width / 2;
                const inset = Number.parseFloat(
                    getComputedStyle(container).paddingInlineStart || '0',
                );
                const raw = event.clientX - box.left - half;

                const next = {
                    ...current,
                    left: Math.min(
                        Math.max(raw, inset),
                        box.width - current.width - inset,
                    ),
                };

                // The finger, not the tab, is where the next hop starts from.
                carried = { key: active, ...next };

                return next;
            });
        },
        [active, dragging, keyUnderPointer, tick],
    );

    const onPointerUp = useCallback(
        (event: React.PointerEvent) => {
            if (!dragging) {
                return;
            }

            setDragging(false);
            lastX.current = null;
            // It settles back to its own shape, with the easing the transition
            // gives it: the squash coming out is what sells the material.
            setStretch(1);

            const key = keyUnderPointer(event.clientX);

            if (dragged.current && key !== null && key !== current) {
                const target = bottomNavItems.find((item) => item.key === key);

                if (target !== undefined) {
                    setPending(key);
                    tick(12);
                    router.visit(target.url);

                    return;
                }
            }

            // Nothing chosen: the lens goes back to where it was.
            placeLens();
        },
        [bottomNavItems, current, dragging, keyUnderPointer, placeLens, tick],
    );

    useEffect(() => {
        // A beat late on purpose: the carried position has to be painted once
        // before it changes, or there is nothing for the transition to run
        // from and the lens arrives without having travelled.
        const cancel = afterPaint(placeLens);

        // The bar is a flex row of four, so anything that changes its width -
        // rotation, a resized window, a font that finished loading - moves the
        // tabs under the lens.
        const observer = new ResizeObserver(placeLens);
        const container = inner.current;

        if (container !== null) {
            observer.observe(container);
        }

        return () => {
            cancel();
            observer.disconnect();
        };
    }, [placeLens]);

    return (
        <nav
            aria-label={translations.label}
            className={cn(
                'arabut-bottom-bar',
                'account-mobile-bottom-nav',
                isKeyboardOpen && 'arabut-bottom-bar--keyboard-open',
            )}
        >
            <div
                className={cn(
                    'arabut-bottom-bar__inner',
                    'account-mobile-bottom-nav__inner',
                )}
                onPointerCancel={onPointerUp}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={onPointerUp}
                ref={inner}
            >
                {lens !== null ? (
                    <span
                        aria-hidden="true"
                        className={cn(
                            'arabut-bottom-bar__lens',
                            dragging && 'arabut-bottom-bar__lens--dragging',
                        )}
                        style={
                            {
                                '--lens-x': `${lens.left}px`,
                                '--lens-sx': stretch,
                                // Area held roughly constant: what it gains
                                // lengthways it gives up in height.
                                '--lens-sy': 1 - (stretch - 1) * 0.55,
                                width: `${lens.width}px`,
                            } as React.CSSProperties
                        }
                    />
                ) : null}
                {bottomNavItems.map((item) => {
                    const name = destinationIcons[item.key] || 'grid';
                    const selected = item.key === current;
                    const optimisticallySelected =
                        selected || pending === item.key;
                    const label =
                        item.key === 'overview'
                            ? (bottomNav?.home ?? item.label)
                            : item.key === 'profile'
                              ? (bottomNav?.account ?? item.label)
                              : item.label;

                    return (
                        <Link
                            aria-current={selected ? 'page' : undefined}
                            className={cn(
                                'arabut-bottom-bar__item',
                                'account-mobile-bottom-nav__item',
                                optimisticallySelected &&
                                    'arabut-bottom-bar__item--active',
                            )}
                            data-bar-key={item.key}
                            href={item.url}
                            key={item.key}
                            // Every destination in this bar is on screen at all
                            // times, so the bar warms them all: the tap itself
                            // costs no server round trip.
                            cacheFor="1m"
                            prefetch="hover"
                            onStart={() => {
                                setPending(item.key);
                                tick(10);
                            }}
                        >
                            <span className="account-mobile-bottom-nav__icon-wrap">
                                <AppIcon name={name} />
                            </span>
                            <span className="arabut-bottom-bar__label account-mobile-bottom-nav__label">
                                {label}
                            </span>
                            {attention.includes(item.key) ? (
                                <>
                                    <span
                                        aria-hidden="true"
                                        className="arabut-bottom-bar__dot"
                                    />
                                    {/* The dot is decorative, so the state it
                                        signals needs a text equivalent or the
                                        customer is never told something is
                                        waiting for them. */}
                                    <span className="sr-only">
                                        {translations.attention}
                                    </span>
                                </>
                            ) : null}
                        </Link>
                    );
                })}
                {adminUrl ? (
                    <Link
                        className="arabut-bottom-bar__item account-mobile-bottom-nav__item"
                        href={adminUrl}
                    >
                        <span className="account-mobile-bottom-nav__icon-wrap">
                            <AppIcon name="shield" />
                        </span>
                        <span className="arabut-bottom-bar__label account-mobile-bottom-nav__label">
                            {translations.admin}
                        </span>
                    </Link>
                ) : null}
            </div>
        </nav>
    );
}

export default AccountMobileBottomNav;
