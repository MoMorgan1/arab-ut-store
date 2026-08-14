type CartRemovalResponse = { cartCount: number };

function endpoint(path: string): string {
    const url = new URL(path, window.location.origin);

    if (url.origin !== window.location.origin) {
        throw new Error('Unsafe cart item endpoint.');
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

function cartCount(payload: unknown): number | null {
    if (
        typeof payload !== 'object' ||
        payload === null ||
        !('data' in payload)
    ) {
        return null;
    }

    const data = payload.data as Record<string, unknown> | null;

    if (
        typeof data !== 'object' ||
        data === null ||
        Object.keys(data).join(',') !== 'cartCount' ||
        !Number.isSafeInteger(data.cartCount) ||
        Number(data.cartCount) < 0
    ) {
        return null;
    }

    return Number(data.cartCount);
}

export async function removeCartItem(
    path: string,
): Promise<CartRemovalResponse> {
    const response = await fetch(endpoint(path), {
        cache: 'no-store',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        method: 'DELETE',
    });
    const payload: unknown = await response.json();
    const count = cartCount(payload);

    if (!response.ok || count === null) {
        throw new Error('Cart item could not be removed.');
    }

    return { cartCount: count };
}
