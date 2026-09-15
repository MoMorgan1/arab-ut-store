import { useCallback, useState } from 'react';
import type { ImgHTMLAttributes } from 'react';

import { cn } from '@/lib/utils';

/**
 * A product's artwork, which says it is on its way rather than leaving a hole.
 *
 * Catalog artwork is lazy, so on a slow connection a card arrives complete —
 * frame, name, prices — with an empty space where the picture belongs. The
 * catalog's skeleton grid does not cover this: that one stands in while the
 * list itself is being fetched, and by the time these cards render it is
 * already gone. The slot shimmers with the same placeholder material until
 * the bitmap paints over it.
 */
export function ArtworkImage({
    className,
    ...props
}: ImgHTMLAttributes<HTMLImageElement>) {
    const [loaded, setLoaded] = useState(false);

    // A cached picture can be complete before React attaches the handler, and
    // then `load` never fires: the shimmer would run under a painted image
    // for as long as the card lives.
    const settleIfReady = useCallback((node: HTMLImageElement | null) => {
        if (node !== null && node.complete) {
            setLoaded(true);
        }
    }, []);

    return (
        <img
            {...props}
            className={cn('store-artwork-image', className)}
            data-loaded={loaded ? 'true' : 'false'}
            // A picture that failed is not a picture that is still coming:
            // either way the shimmer stops, and the alt text stands in.
            onError={() => setLoaded(true)}
            onLoad={() => setLoaded(true)}
            ref={settleIfReady}
        />
    );
}
