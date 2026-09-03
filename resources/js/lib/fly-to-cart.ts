export type FlyToCartOptions = {
    from: HTMLElement | DOMRect;
    imageUrl: string;
    imageAlt: string;
};

function rectOf(from: HTMLElement | DOMRect): DOMRect {
    return from instanceof HTMLElement ? from.getBoundingClientRect() : from;
}

function reducedMotion(): boolean {
    return (
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

function cartIconCenter(): { x: number; y: number } | null {
    const icon = document.querySelector('[data-cart-icon]');

    if (!(icon instanceof HTMLElement)) {
        return null;
    }

    const rect = icon.getBoundingClientRect();
    const inViewport =
        rect.bottom > 0 &&
        rect.top < window.innerHeight &&
        rect.right > 0 &&
        rect.left < window.innerWidth;

    if (!inViewport) {
        return null;
    }

    return {
        x: rect.left + rect.width / 2,
        y: rect.top + rect.height / 2,
    };
}

/**
 * Flies a small product chip from the add-to-cart button to the header cart
 * icon along an arc. Resolves when the flight lands (or immediately when the
 * flight is skipped) so emitters can dispatch `arabut:cart-count` after the
 * landing; the header bump reacts to that event alone.
 */
export function flyToCart(options: FlyToCartOptions): Promise<void> {
    // Decoration must never turn a successful add into an error: any
    // synchronous failure (an engine rejecting the keyframes, say) is
    // swallowed and the emitter carries on.
    try {
        return runFlight(options).catch(() => undefined);
    } catch {
        document
            .querySelectorAll('.store-fly-chip')
            .forEach((chip) => chip.remove());

        return Promise.resolve();
    }
}

function runFlight({
    from,
    imageUrl,
    imageAlt,
}: FlyToCartOptions): Promise<void> {
    if (reducedMotion()) {
        return Promise.resolve();
    }

    const target = cartIconCenter();

    if (target === null) {
        return Promise.resolve();
    }

    const source = rectOf(from);
    const startX = source.left + source.width / 2;
    const startY = source.top + source.height / 2;

    const chip = document.createElement('div');
    chip.className = 'store-fly-chip';
    chip.setAttribute('aria-hidden', 'true');

    const image = document.createElement('img');
    image.src = imageUrl;
    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    image.width = 56;
    image.height = 56;
    chip.appendChild(image);

    if (imageAlt !== '') {
        chip.setAttribute('aria-label', imageAlt);
    }

    const size = 56;
    chip.style.left = `${startX - size / 2}px`;
    chip.style.top = `${startY - size / 2}px`;
    document.body.appendChild(chip);

    const deltaX = target.x - startX;
    const deltaY = target.y - startY;
    const lift = Math.min(160, Math.max(64, Math.hypot(deltaX, deltaY) / 3));

    if (typeof chip.animate !== 'function') {
        chip.remove();

        return Promise.resolve();
    }

    const flight = chip.animate(
        [
            {
                transform: 'translate(0px, 0px) scale(1)',
                opacity: 1,
                offset: 0,
            },
            {
                transform: 'translate(0px, 0px) scale(1.08)',
                opacity: 1,
                offset: 0.12,
            },
            {
                transform: `translate(${deltaX / 2}px, ${deltaY / 2 - lift}px) scale(0.85)`,
                opacity: 1,
                offset: 0.55,
            },
            {
                transform: `translate(${deltaX}px, ${deltaY}px) scale(0.4)`,
                opacity: 1,
                offset: 0.88,
            },
            {
                transform: `translate(${deltaX}px, ${deltaY}px) scale(0.3)`,
                opacity: 0,
                offset: 1,
            },
        ],
        {
            // Long enough to be read on a phone: the chip lifts off the button,
            // arcs across the screen and lands on the cart.
            duration: 760,
            easing: 'cubic-bezier(0.45, 0.05, 0.25, 1)',
            fill: 'forwards',
        },
    );

    return Promise.resolve(flight.finished).then(
        () => {
            chip.remove();
        },
        () => {
            chip.remove();
        },
    );
}
