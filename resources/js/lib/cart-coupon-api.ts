import type { StoredCartCoupon } from '@/types/store-shell';

export class CartCouponError extends Error {
    readonly code: string;
    readonly detail: string | null;

    constructor(code: string, detail: string | null = null) {
        super(`Coupon request failed: ${code}`);
        this.code = code;
        this.detail = detail;
    }
}

type CouponPayload = { data: unknown };

function endpoint(path: string): string {
    const url = new URL(path, window.location.origin);

    if (url.origin !== window.location.origin) {
        throw new Error('Unsafe coupon endpoint.');
    }

    return `${url.pathname}${url.search}`;
}

function csrfToken(): string {
    const token = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    if (token === undefined || token === '') {
        throw new Error('CSRF token is unavailable.');
    }

    return token;
}

function rejection(payload: unknown): CartCouponError {
    if (typeof payload === 'object' && payload !== null && 'error' in payload) {
        const error = payload.error as Record<string, unknown> | null;

        if (
            typeof error === 'object' &&
            error !== null &&
            typeof error.code === 'string' &&
            error.code.startsWith('coupon_')
        ) {
            const detail =
                typeof error.message === 'string' && error.message !== ''
                    ? error.message
                    : null;

            return new CartCouponError(error.code, detail);
        }
    }

    return new CartCouponError('coupon_error');
}

async function send(
    path: string,
    method: 'POST' | 'DELETE',
    body?: string,
): Promise<StoredCartCoupon | null> {
    let response: Response;

    try {
        response = await fetch(endpoint(path), {
            body,
            cache: 'no-store',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            method,
        });
    } catch {
        throw new CartCouponError('coupon_error');
    }

    const payload: unknown = await response.json().catch(() => null);

    if (!response.ok) {
        throw rejection(payload);
    }

    if (method === 'DELETE') {
        return null;
    }

    if (
        typeof payload !== 'object' ||
        payload === null ||
        !('data' in payload)
    ) {
        throw new CartCouponError('coupon_error');
    }

    const data = (payload as CouponPayload).data as Record<string, unknown>;

    if (
        typeof data !== 'object' ||
        data === null ||
        typeof data.code !== 'string' ||
        data.code === '' ||
        (data.discountType !== 'percent' && data.discountType !== 'fixed') ||
        !Number.isSafeInteger(data.discountHalalah) ||
        Number(data.discountHalalah) < 0
    ) {
        throw new CartCouponError('coupon_error');
    }

    return {
        code: data.code,
        discountType: data.discountType,
        discountHalalah: Number(data.discountHalalah),
    };
}

export async function applyCartCoupon(
    path: string,
    code: string,
): Promise<StoredCartCoupon> {
    const applied = await send(
        path,
        'POST',
        JSON.stringify({ code: code.trim().toUpperCase() }),
    );

    if (applied === null) {
        throw new CartCouponError('coupon_error');
    }

    return applied;
}

export async function removeCartCoupon(path: string): Promise<void> {
    await send(path, 'DELETE');
}
