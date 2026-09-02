import { router } from '@inertiajs/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';


import {
    CONSENT_COOKIE,
    declineConsent,
    grantConsent,
    initAnalytics,
    readConsent,
    resetAnalyticsForTests,
    riyals,
    shouldShowConsentBanner,
    trackAddToCart,
    trackBeginCheckout,
    trackPurchase,
} from '@/lib/analytics';
import { announceCartAddition } from '@/lib/cart-added-event';

vi.mock('@inertiajs/react', () => ({
    router: { on: vi.fn(() => () => undefined) },
}));

function clearCookie() {
    document.cookie = `${CONSENT_COOKIE}=; Max-Age=0; Path=/`;
}

function injectedScripts(): string[] {
    return Array.from(document.head.querySelectorAll('script[src]')).map(
        (script) => script.getAttribute('src') ?? '',
    );
}

beforeEach(() => {
    resetAnalyticsForTests();
    clearCookie();
    window.localStorage.clear();
    document.head.querySelectorAll('script[src]').forEach((s) => s.remove());
    delete window.__arabutAnalytics;
    delete window.gtag;
    delete window.fbq;
    delete window.ttq;
    delete window.dataLayer;
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('riyals', () => {
    it('converts halalah to riyals', () => {
        expect(riyals(75_000)).toBe(750);
        expect(riyals(1)).toBe(0.01);
    });
});

describe('without vendor ids', () => {
    it('is a no-op and never shows the banner', () => {
        initAnalytics();
        grantConsent();
        trackAddToCart({ id: 'x', name: 'X', quantity: 1, price: 1 });

        expect(shouldShowConsentBanner()).toBe(false);
        expect(injectedScripts()).toEqual([]);
        expect(router.on).not.toHaveBeenCalled();
    });
});

describe('with vendor ids', () => {
    beforeEach(() => {
        window.__arabutAnalytics = {
            ga4: 'G-TEST',
            meta: '123',
            tiktok: 'TT1',
        };
    });

    it('shows the banner and loads nothing until a choice is made', () => {
        initAnalytics();

        expect(shouldShowConsentBanner()).toBe(true);
        expect(injectedScripts()).toEqual([]);
        expect(window.gtag).toBeUndefined();
        expect(window.fbq).toBeUndefined();
        expect(window.ttq).toBeUndefined();
        expect(router.on).toHaveBeenCalledWith(
            'navigate',
            expect.any(Function),
        );
    });

    it('declining stores the choice and still sends nothing', () => {
        initAnalytics();
        declineConsent();
        trackAddToCart({ id: 'x', name: 'X', quantity: 1, price: 1 });

        expect(readConsent()).toBe('denied');
        expect(shouldShowConsentBanner()).toBe(false);
        expect(injectedScripts()).toEqual([]);
        expect(window.fbq).toBeUndefined();
    });

    it('accepting loads the three vendors in the documented order and sends a page view', () => {
        initAnalytics();
        grantConsent();

        expect(readConsent()).toBe('granted');
        expect(injectedScripts()).toEqual([
            'https://www.googletagmanager.com/gtag/js?id=G-TEST',
            'https://connect.facebook.net/en_US/fbevents.js',
            'https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=TT1&lib=ttq',
        ]);

        // Google: consent update before config, no automatic page view.
        const layer = window.dataLayer as unknown[][];
        expect(layer[0]).toEqual([
            'consent',
            'update',
            expect.objectContaining({ analytics_storage: 'granted' }),
        ]);
        expect(layer.find((call) => call[0] === 'config')).toEqual([
            'config',
            'G-TEST',
            { send_page_view: false },
        ]);
        expect(
            layer.find(
                (call) => call[0] === 'event' && call[1] === 'page_view',
            ),
        ).toBeDefined();

        // Meta: revoke before init, grant after.
        const fbq = window.fbq as NonNullable<Window['fbq']>;
        expect(fbq.queue?.slice(0, 3)).toEqual([
            ['consent', 'revoke'],
            ['init', '123'],
            ['consent', 'grant'],
        ]);
        expect(fbq.queue).toContainEqual(['track', 'PageView']);

        // TikTok: load then page.
        const ttq = window.ttq as unknown as { _q: unknown[][] };
        expect(ttq._q).toEqual([['load', 'TT1'], ['page']]);
    });

    it('fans an add-to-cart out in each vendor shape with SAR money', () => {
        initAnalytics();
        grantConsent();
        (window.dataLayer as unknown[]).length = 0;

        announceCartAddition({
            analytics: {
                id: 'division-rivals',
                name: 'Rivals',
                priceMinorSar: 75_000,
                quantity: 1,
                serviceType: 'rivals',
            },
            cartUrl: '/cart',
            imageAlt: '',
            imageUrl: '',
            itemLabel: 'Rivals',
        });

        expect(window.dataLayer?.[0]).toEqual([
            'event',
            'add_to_cart',
            {
                value: 750,
                currency: 'SAR',
                items: [
                    {
                        item_id: 'division-rivals',
                        item_name: 'Rivals',
                        quantity: 1,
                        price: 750,
                    },
                ],
            },
        ]);

        const fbq = window.fbq as NonNullable<Window['fbq']>;
        const meta = fbq.queue?.find(
            (call) => Array.isArray(call) && call[1] === 'AddToCart',
        ) as unknown[];
        expect(meta[2]).toEqual({
            value: 750,
            currency: 'SAR',
            content_type: 'product',
            content_ids: ['division-rivals'],
            contents: [{ id: 'division-rivals', quantity: 1, item_price: 750 }],
            num_items: 1,
        });
        expect(meta[3]).toEqual({ eventID: expect.any(String) });

        const ttq = window.ttq as unknown as { _q: unknown[][] };
        expect(ttq._q.at(-1)).toEqual([
            'track',
            'AddToCart',
            expect.objectContaining({
                value: 750,
                currency: 'SAR',
                contents: [
                    expect.objectContaining({
                        content_id: 'division-rivals',
                        price: 750,
                    }),
                ],
            }),
        ]);
    });

    it('omits money when the emitter had no SAR price', () => {
        initAnalytics();
        grantConsent();
        (window.dataLayer as unknown[]).length = 0;

        trackAddToCart({ id: 'sbc-1', name: 'SBC', quantity: 1 });

        expect(window.dataLayer?.[0]).toEqual([
            'event',
            'add_to_cart',
            {
                items: [{ item_id: 'sbc-1', item_name: 'SBC', quantity: 1 }],
            },
        ]);
    });

    it('sends begin_checkout with the payable amount', () => {
        initAnalytics();
        grantConsent();
        (window.dataLayer as unknown[]).length = 0;

        trackBeginCheckout(
            [{ id: 'a', name: 'A', quantity: 2, price: 10 }],
            20,
        );

        expect(window.dataLayer?.[0]).toEqual([
            'event',
            'begin_checkout',
            expect.objectContaining({ value: 20, currency: 'SAR' }),
        ]);
    });

    it('sends purchase once per order across reloads', () => {
        initAnalytics();
        grantConsent();
        (window.dataLayer as unknown[]).length = 0;

        const order = {
            orderId: 'ord-1',
            value: 75,
            currency: 'SAR',
            items: [{ id: 'COINS', name: 'Coins', quantity: 2, price: 50 }],
        };

        expect(trackPurchase(order)).toBe(true);
        expect(trackPurchase(order)).toBe(false);

        resetAnalyticsForTests();
        initAnalytics();
        expect(trackPurchase(order)).toBe(false);

        const purchases = (window.dataLayer as unknown[][]).filter(
            (call) => call[0] === 'event' && call[1] === 'purchase',
        );
        expect(purchases).toHaveLength(1);
        expect(purchases[0][2]).toEqual(
            expect.objectContaining({
                transaction_id: 'ord-1',
                value: 75,
                currency: 'SAR',
            }),
        );

        const ttq = window.ttq as unknown as { _q: unknown[][] };
        expect(
            ttq._q.find((call) => call[1] === 'CompletePayment'),
        ).toBeDefined();
    });

    it('sends nothing while consent is denied even after vendors exist', () => {
        initAnalytics();
        grantConsent();
        (window.dataLayer as unknown[]).length = 0;

        declineConsent();
        trackAddToCart({ id: 'x', name: 'X', quantity: 1, price: 1 });

        expect(window.dataLayer).toEqual([]);
    });
});
