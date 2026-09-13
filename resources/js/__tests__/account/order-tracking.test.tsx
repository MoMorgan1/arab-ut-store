import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import OrderTracking from '@/components/account/order-tracking';
import type {
    OrderItemTracking,
    OrderTrackingChallenge,
} from '@/types/account';

/**
 * Every assertion here is a screen that once contradicted itself.
 *
 * The card is built from two independent things - what the supplier reported
 * and how the store chose to present it - and each defect this file pins came
 * from one of them being read where the other belonged: a ring forced full
 * above a bar reading zero, a finishing time promised during a cooldown that
 * has none, a completion caption printed from a leftover timestamp. They were
 * all found by looking at the screen, which is exactly what a test is for.
 */

// The copy is not what is under test, and the real block is forty keys of
// Arabic and English. Every string answers with its own key name, so an
// assertion can name the string it expects to see - or not see - without the
// test carrying a translation table it would then have to maintain.
const strings = new Proxy(
    {},
    { get: (_target, key) => String(key) },
) as Parameters<typeof OrderTracking>[0]['strings'];

function tracking(
    overrides: Partial<OrderItemTracking> = {},
): OrderItemTracking {
    return {
        kind: 'coins',
        phase: 'coins',
        presentation: 'transferring',
        headline: 'Transferring',
        subline: 'Moving coins to your :console account',
        holdReason: null,
        holdMessage: null,
        holdTone: null,
        completedAt: null,
        actions: [],
        supported: true,
        observedAt: new Date().toISOString(),
        accountCoins: { amount: null, state: 'unknown' },
        progress: {
            coinsDelivered: 400_000,
            coinsOrdered: 1_000_000,
            squadsDone: null,
            squadsTotal: null,
            solvesDone: null,
            solvesTotal: null,
        },
        challenges: null,
        coverage: null,
        workStarted: true,
        ...overrides,
    };
}

function challenge(
    overrides: Partial<OrderTrackingChallenge> = {},
): OrderTrackingChallenge {
    return {
        target: 1,
        state: 'solving',
        stateLabel: 'Solving',
        help: {
            title: 'Solving',
            desc: 'Working on it',
            action: 'Please wait',
        },
        squads: { done: 1, total: 3 },
        solves: { done: 0, total: 1 },
        holdReason: null,
        holdMessage: null,
        holdTone: null,
        coinsUsed: 120_000,
        finishedAt: null,
        actions: [],
        ...overrides,
    };
}

function mount(state: OrderItemTracking) {
    return render(
        <OrderTracking
            imageUrl="/images/store/coins/ut-coin-80.webp"
            itemName="Coins"
            locale="en"
            onAction={() => undefined}
            platform="playstation"
            strings={strings}
            tracking={state}
        />,
    );
}

describe('the coins card', () => {
    it('offers a finishing time while coins are moving', () => {
        const { container } = mount(tracking());

        expect(container.querySelector('.track-eta')).not.toBeNull();
    });

    it.each([
        'cooldown_tempban',
        'cooldown_daily_limit',
        'logging_in',
        'not_reported',
    ])(
        'promises no finishing time during %s, which has none to give',
        (presentation) => {
            const { container } = mount(tracking({ presentation }));

            expect(container.querySelector('.track-eta')).toBeNull();
        },
    );

    it('rings the percentage it reports, even when the item is complete', () => {
        // A completed item whose delivered count never arrived showed a full
        // green ring above a bar reading zero.
        const { container } = mount(
            tracking({
                presentation: 'completed',
                completedAt: new Date().toISOString(),
                progress: {
                    coinsDelivered: 0,
                    coinsOrdered: 1_000_000,
                    squadsDone: null,
                    squadsTotal: null,
                    solvesDone: null,
                    solvesTotal: null,
                },
            }),
        );

        const bar = container.querySelector<HTMLElement>(
            '.track-progress__fill',
        );
        const ring = container.querySelector<HTMLElement>(
            '.track-ring-wrapper',
        );

        expect(bar?.style.width).toBe('0%');
        expect(ring?.dataset.percent ?? '0').toBe('0');
    });
});

describe('a challenge card', () => {
    it('says when it was solved', () => {
        const { container } = mount(
            tracking({
                kind: 'challenge',
                phase: 'challenge',
                challenges: [
                    challenge({
                        state: 'done',
                        stateLabel: 'Complete',
                        finishedAt: new Date(Date.now() - 60_000).toISOString(),
                    }),
                ],
            }),
        );

        expect(
            container.querySelector('.track-challenge__finished'),
        ).not.toBeNull();
    });

    it('does not call a failure complete because a timestamp survived it', () => {
        // A retried challenge keeps the time of its earlier attempt, and the
        // card printed a green "solved" caption under a red failure chip.
        const { container } = mount(
            tracking({
                kind: 'challenge',
                phase: 'challenge',
                challenges: [
                    challenge({
                        state: 'failed',
                        stateLabel: 'Could not finish',
                        holdTone: 'action',
                        finishedAt: new Date(Date.now() - 60_000).toISOString(),
                    }),
                ],
            }),
        );

        expect(
            container.querySelector('.track-challenge__finished'),
        ).toBeNull();
    });
});

describe('a challenge card reads its colour from the status', () => {
    it.each([
        ['success', 'track-challenge__chip--green'],
        ['danger', 'track-challenge__chip--red'],
        ['waiting', 'track-challenge__chip--amber'],
        ['working', 'track-challenge__chip--gold'],
    ] as const)('wears %s as %s', (tone, expected) => {
        const { container } = mount(
            tracking({
                kind: 'challenge',
                phase: 'challenge',
                challenges: [challenge({ tone })],
            }),
        );

        expect(container.querySelector(`.${expected}`)).not.toBeNull();
    });

    it('shows a failure as stopped even when it carries no message to explain it', () => {
        // tooExpensive, clickFailed and failed carry no hold reason, so the card
        // used to fall through to gold and a spinning loader: "could not finish"
        // beside something that said it was still working.
        const { container } = mount(
            tracking({
                kind: 'challenge',
                phase: 'challenge',
                challenges: [
                    challenge({
                        state: 'failed',
                        stateLabel: 'Could not finish',
                        tone: 'danger',
                        holdReason: null,
                        holdMessage: null,
                        holdTone: null,
                    }),
                ],
            }),
        );

        expect(
            container.querySelector('.track-challenge__chip--red'),
        ).not.toBeNull();
        expect(
            container.querySelector('.track-challenge__chip .track-spin'),
        ).toBeNull();
    });
});
