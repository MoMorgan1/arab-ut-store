import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import StoreLayout from '@/layouts/store-layout';

describe('StoreLayout', () => {
    it('renders an Arabic, right-to-left mobile storefront header', () => {
        render(
            <StoreLayout locale="ar" direction="rtl" displayCurrency="SAR">
                <p>محتوى المتجر</p>
            </StoreLayout>,
        );

        expect(screen.getByRole('banner')).toHaveAttribute('dir', 'rtl');
        expect(screen.getByRole('link', { name: 'English' })).toHaveAttribute(
            'href',
            '/en',
        );
        expect(screen.getByText('محتوى المتجر')).toBeInTheDocument();
    });
});
