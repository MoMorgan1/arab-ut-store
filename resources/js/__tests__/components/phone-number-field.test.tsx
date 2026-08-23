import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import PhoneNumberField from '@/components/phone-number-field';

afterEach(cleanup);

describe('PhoneNumberField', () => {
    it('shows localized country names and dial codes in option text', () => {
        const { rerender } = render(
            <PhoneNumberField
                id="phone"
                labels={{ country: 'رمز الدولة', number: 'رقم الهاتف' }}
                locale="ar"
                onChange={() => {}}
                value=""
            />,
        );

        const saudiOptionAr = screen.getByRole('option', {
            name: 'السعودية (+966)',
        });
        expect(saudiOptionAr).toBeInTheDocument();
        expect(saudiOptionAr).toHaveValue('+966');

        rerender(
            <PhoneNumberField
                id="phone"
                labels={{ country: 'Country code', number: 'Phone number' }}
                locale="en"
                onChange={() => {}}
                value=""
            />,
        );

        const saudiOptionEn = screen.getByRole('option', {
            name: 'Saudi Arabia (+966)',
        });
        expect(saudiOptionEn).toBeInTheDocument();
        expect(saudiOptionEn).toHaveValue('+966');
    });

    it('emits normalized E.164 when typing a national number with leading zero stripped', () => {
        const handleChange = vi.fn();
        render(
            <PhoneNumberField
                id="phone"
                labels={{ country: 'رمز الدولة', number: 'رقم الهاتف' }}
                locale="ar"
                onChange={handleChange}
                value=""
            />,
        );

        const input = screen.getByLabelText('رقم الهاتف');
        fireEvent.change(input, { target: { value: '0501234567' } });

        expect(handleChange).toHaveBeenCalledWith('+966501234567');
    });

    it('re-emits with the new dial code when switching country', () => {
        function ControlledWrapper() {
            const [value, setValue] = useState('+966501234567');

            return (
                <PhoneNumberField
                    id="phone"
                    labels={{ country: 'رمز الدولة', number: 'رقم الهاتف' }}
                    locale="ar"
                    onChange={setValue}
                    value={value}
                />
            );
        }

        render(<ControlledWrapper />);

        const select = screen.getByLabelText('رمز الدولة');
        expect(select).toHaveValue('+966');

        fireEvent.change(select, { target: { value: '+20' } });

        expect(select).toHaveValue('+20');
        expect(screen.getByLabelText('رقم الهاتف')).toHaveValue('501234567');
    });

    it('pre-selects the country and separates national digits from an existing E.164 value', () => {
        render(
            <PhoneNumberField
                id="phone"
                labels={{ country: 'Country code', number: 'Phone number' }}
                locale="en"
                onChange={() => {}}
                value="+201001234567"
            />,
        );

        const select = screen.getByLabelText('Country code');
        const input = screen.getByLabelText('Phone number');

        expect(select).toHaveValue('+20');
        expect(input).toHaveValue('1001234567');
    });
});
