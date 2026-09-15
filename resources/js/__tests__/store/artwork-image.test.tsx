import { cleanup, fireEvent, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { ArtworkImage } from '@/components/store/catalog/artwork-image';

describe('ArtworkImage', () => {
    afterEach(cleanup);

    it('shimmers until the picture arrives, then stops', () => {
        const { container } = render(
            <ArtworkImage alt="" height="288" src="/art.webp" width="384" />,
        );
        const image = container.querySelector('img');

        expect(image).toHaveClass('store-artwork-image');
        expect(image).toHaveAttribute('data-loaded', 'false');

        fireEvent.load(image!);

        expect(image).toHaveAttribute('data-loaded', 'true');
    });

    it('stops shimmering when the picture fails, so the slot is not left running', () => {
        const { container } = render(
            <ArtworkImage alt="" src="/missing.webp" />,
        );
        const image = container.querySelector('img');

        fireEvent.error(image!);

        expect(image).toHaveAttribute('data-loaded', 'true');
    });

    it('keeps the caller class and passes the rest of the attributes through', () => {
        const { container } = render(
            <ArtworkImage
                alt="Icon challenge"
                className="store-catalog-card__art"
                loading="lazy"
                src="/art.webp"
            />,
        );
        const image = container.querySelector('img');

        expect(image).toHaveClass('store-artwork-image');
        expect(image).toHaveClass('store-catalog-card__art');
        expect(image).toHaveAttribute('loading', 'lazy');
        expect(image).toHaveAttribute('alt', 'Icon challenge');
    });
});
