import { router } from '@inertiajs/react';

import { CART_ADDED_EVENT } from '@/lib/cart-added-event';
import type { CartAddedDetail } from '@/lib/cart-added-event';

/**
 * First-party analytics: one normalised event feeds GA4, Meta Pixel and
 * TikTok Pixel. Nothing is loaded until the visitor accepts the consent
 * banner, and a refusal sends nothing to anybody. Vendor ids come from
 * `window.__arabutAnalytics`, written by app.blade.php only on pages that
 * may track. See docs/decisions/2026-09-02-analytics-tracking-design.md.
 */

export const CONSENT_COOKIE = 'arabut_consent';
export const CONSENT_VERSION = '1';
const CONSENT_MAX_AGE_DAYS = 365;
const TRACKED_ORDERS_KEY = 'arabut_tracked_orders';
const CURRENCY = 'SAR';

export type ConsentChoice = 'granted' | 'denied';

export type AnalyticsItem = {
    id: string;
    name: string;
    /** Riyals, never halalah. */
    price?: number;
    quantity: number;
};

type Vendors = { ga4?: string; meta?: string; tiktok?: string };

let initialised = false;
let vendorsLoaded = false;

export function analyticsVendors(): Vendors {
    if (typeof window === 'undefined') {
        return {};
    }

    const vendors = window.__arabutAnalytics ?? {};

    return {
        ga4: vendors.ga4 || undefined,
        meta: vendors.meta || undefined,
        tiktok: vendors.tiktok || undefined,
    };
}

export function analyticsEnabled(): boolean {
    const vendors = analyticsVendors();

    return Boolean(vendors.ga4 || vendors.meta || vendors.tiktok);
}

export function riyals(halalah: number): number {
    return Math.round(halalah) / 100;
}

export function readConsent(): ConsentChoice | null {
    if (typeof document === 'undefined') {
        return null;
    }

    const match = document.cookie
        .split('; ')
        .find((entry) => entry.startsWith(`${CONSENT_COOKIE}=`));

    if (match === undefined) {
        return null;
    }

    const [version, choice] = decodeURIComponent(
        match.slice(CONSENT_COOKIE.length + 1),
    ).split(':');

    if (version !== CONSENT_VERSION) {
        return null;
    }

    return choice === 'granted' || choice === 'denied' ? choice : null;
}

function writeConsent(choice: ConsentChoice) {
    const maxAge = CONSENT_MAX_AGE_DAYS * 24 * 60 * 60;
    const secure = window.location.protocol === 'https:' ? '; Secure' : '';

    document.cookie = `${CONSENT_COOKIE}=${encodeURIComponent(
        `${CONSENT_VERSION}:${choice}`,
    )}; Max-Age=${maxAge}; Path=/; SameSite=Lax${secure}`;
}

export function shouldShowConsentBanner(): boolean {
    return analyticsEnabled() && readConsent() === null;
}

function eventId(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

function injectScript(src: string): void {
    const script = document.createElement('script');

    script.async = true;
    script.src = src;
    document.head.append(script);
}

function loadGa4(id: string) {
    window.dataLayer = window.dataLayer ?? [];
    window.gtag =
        window.gtag ??
        function gtag(...args: unknown[]) {
            window.dataLayer?.push(args);
        };
    window.gtag('consent', 'update', {
        ad_storage: 'granted',
        ad_user_data: 'granted',
        ad_personalization: 'granted',
        analytics_storage: 'granted',
    });
    injectScript(`https://www.googletagmanager.com/gtag/js?id=${id}`);
    window.gtag('js', new Date());
    window.gtag('config', id, { send_page_view: false });
}

function loadMeta(id: string) {
    if (window.fbq === undefined) {
        // The official base snippet, expressed without the minified IIFE: a
        // queueing stub until fbevents.js takes over.
        const queue: unknown[] = [];
        const stub = ((...args: unknown[]) => {
            queue.push(args);
        }) as NonNullable<Window['fbq']>;

        stub.queue = queue;
        window.fbq = stub;
        injectScript('https://connect.facebook.net/en_US/fbevents.js');
    }

    window.fbq('consent', 'revoke');
    window.fbq('init', id);
    window.fbq('consent', 'grant');
}

function loadTikTok(id: string) {
    if (window.ttq !== undefined) {
        return;
    }

    // Queueing stub mirroring TikTok's base code: calls made before
    // events.js arrives are replayed by the library from `ttq._q`.
    const queue: unknown[][] = [];
    const stub = {
        _q: queue,
        load(pixelId: string) {
            queue.push(['load', pixelId]);
        },
        page() {
            queue.push(['page']);
        },
        track(event: string, params?: Record<string, unknown>) {
            queue.push(['track', event, params]);
        },
    };

    window.ttq = stub;
    injectScript(
        `https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=${id}&lib=ttq`,
    );
    stub.load(id);
}

function loadVendors() {
    if (vendorsLoaded || readConsent() !== 'granted') {
        return;
    }

    const vendors = analyticsVendors();

    if (vendors.ga4) {
        loadGa4(vendors.ga4);
    }

    if (vendors.meta) {
        loadMeta(vendors.meta);
    }

    if (vendors.tiktok) {
        loadTikTok(vendors.tiktok);
    }

    vendorsLoaded = true;
}

function tracking(): boolean {
    return vendorsLoaded && readConsent() === 'granted';
}

function metaContents(items: AnalyticsItem[]) {
    return items.map((item) => ({
        id: item.id,
        quantity: item.quantity,
        ...(item.price === undefined ? {} : { item_price: item.price }),
    }));
}

function ga4Items(items: AnalyticsItem[]) {
    return items.map((item) => ({
        item_id: item.id,
        item_name: item.name,
        quantity: item.quantity,
        ...(item.price === undefined ? {} : { price: item.price }),
    }));
}

function tiktokContents(items: AnalyticsItem[]) {
    return items.map((item) => ({
        content_id: item.id,
        content_type: 'product',
        content_name: item.name,
        quantity: item.quantity,
        ...(item.price === undefined ? {} : { price: item.price }),
    }));
}

function sendCommerce(
    names: { ga4: string; meta: string; tiktok: string },
    items: AnalyticsItem[],
    value: number | undefined,
    extra: Record<string, unknown> = {},
) {
    if (!tracking()) {
        return;
    }

    const id = eventId();
    const money = value === undefined ? {} : { value, currency: CURRENCY };

    window.gtag?.('event', names.ga4, {
        ...money,
        items: ga4Items(items),
        ...extra,
    });
    window.fbq?.(
        'track',
        names.meta,
        {
            ...money,
            content_type: 'product',
            content_ids: items.map((item) => item.id),
            contents: metaContents(items),
            num_items: items.reduce((sum, item) => sum + item.quantity, 0),
        },
        { eventID: id },
    );
    window.ttq?.track(names.tiktok, {
        ...money,
        contents: tiktokContents(items),
        event_id: id,
    });
}

export function trackPageView(path: string = window.location.pathname) {
    if (!tracking()) {
        return;
    }

    const vendors = analyticsVendors();

    if (vendors.ga4) {
        window.gtag?.('event', 'page_view', {
            page_location: window.location.href,
            page_path: path,
            page_title: document.title,
        });
    }

    window.fbq?.('track', 'PageView');
    window.ttq?.page();
}

export function trackViewItem(item: AnalyticsItem) {
    sendCommerce(
        { ga4: 'view_item', meta: 'ViewContent', tiktok: 'ViewContent' },
        [item],
        item.price,
    );
}

export function trackAddToCart(item: AnalyticsItem) {
    sendCommerce(
        { ga4: 'add_to_cart', meta: 'AddToCart', tiktok: 'AddToCart' },
        [item],
        item.price === undefined ? undefined : item.price * item.quantity,
    );
}

export function trackBeginCheckout(items: AnalyticsItem[], value: number) {
    sendCommerce(
        {
            ga4: 'begin_checkout',
            meta: 'InitiateCheckout',
            tiktok: 'InitiateCheckout',
        },
        items,
        value,
    );
}

function trackedOrders(): string[] {
    try {
        const raw = window.localStorage.getItem(TRACKED_ORDERS_KEY);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        return Array.isArray(parsed)
            ? parsed.filter(
                  (entry): entry is string => typeof entry === 'string',
              )
            : [];
    } catch {
        return [];
    }
}

function rememberOrder(orderId: string) {
    try {
        const orders = [...trackedOrders(), orderId].slice(-50);

        window.localStorage.setItem(TRACKED_ORDERS_KEY, JSON.stringify(orders));
    } catch {
        // Storage can be unavailable (private mode, quota); the event still
        // went out once for this page load.
    }
}

/**
 * Sends `purchase` once per order per browser. The order page is
 * re-openable, so the id set in localStorage is what prevents a reload from
 * counting a second sale.
 */
export function trackPurchase(order: {
    orderId: string;
    value: number;
    currency: string;
    items: AnalyticsItem[];
}): boolean {
    if (!tracking() || trackedOrders().includes(order.orderId)) {
        return false;
    }

    rememberOrder(order.orderId);
    sendCommerce(
        { ga4: 'purchase', meta: 'Purchase', tiktok: 'CompletePayment' },
        order.items,
        order.value,
        { transaction_id: order.orderId },
    );

    return true;
}

export function grantConsent() {
    writeConsent('granted');
    loadVendors();
    trackPageView();
}

export function declineConsent() {
    writeConsent('denied');
}

function handleCartAddition(event: Event) {
    const detail = (event as CustomEvent<CartAddedDetail>).detail;

    if (detail?.analytics === undefined) {
        return;
    }

    const { analytics } = detail;

    trackAddToCart({
        id: analytics.id,
        name: analytics.name,
        quantity: analytics.quantity,
        ...(analytics.priceMinorSar === undefined
            ? {}
            : { price: riyals(analytics.priceMinorSar) }),
    });
}

/** Called once from app.tsx. Safe to call on pages without analytics. */
export function initAnalytics() {
    if (initialised || typeof window === 'undefined' || !analyticsEnabled()) {
        return;
    }

    initialised = true;
    loadVendors();
    trackPageView();
    router.on('navigate', (event) => {
        trackPageView(
            new URL(event.detail.page.url, window.location.origin).pathname,
        );
    });
    window.addEventListener(CART_ADDED_EVENT, handleCartAddition);
}

/** Test hook: forget module state between cases. */
export function resetAnalyticsForTests() {
    initialised = false;
    vendorsLoaded = false;
}
