import type { ChangeEvent } from 'react';
import { useState } from 'react';

import { Input } from '@/components/ui/input';
import { phoneCountries, splitE164 } from '@/lib/phone-country-codes';
import { cn } from '@/lib/utils';

export type PhoneNumberFieldProps = {
    id: string;
    locale: 'ar' | 'en';
    value: string;
    onChange: (e164: string) => void;
    disabled?: boolean;
    labels: { country: string; number: string };
    error?: string;
    describedBy?: string;
    autoFocus?: boolean;
    autoComplete?: string;
    className?: string;
};

export default function PhoneNumberField({
    autoComplete = 'tel-national',
    autoFocus,
    className,
    describedBy,
    disabled,
    error,
    id,
    labels,
    locale,
    onChange,
    value,
}: PhoneNumberFieldProps) {
    const [selectedDial, setSelectedDial] = useState('+966');
    const parsed = splitE164(value);
    const dial = parsed?.dial ?? selectedDial;
    const national =
        parsed?.national ??
        (value === '' ? '' : value.replace(/^\+/, '').replace(/\D/g, ''));

    const handleCountryChange = (event: ChangeEvent<HTMLSelectElement>) => {
        const newDial = event.target.value;
        setSelectedDial(newDial);
        const cleaned = national.replace(/^0+/, '');
        onChange(cleaned === '' ? '' : `${newDial}${cleaned}`);
    };

    const handleNumberChange = (event: ChangeEvent<HTMLInputElement>) => {
        const rawDigits = event.target.value.replace(/\D/g, '');
        const cleaned = rawDigits.replace(/^0+/, '');
        onChange(cleaned === '' ? '' : `${dial}${cleaned}`);
    };

    return (
        <div className={cn('auth-phone-field', className)} dir="ltr">
            <label className="sr-only" htmlFor={`${id}-country`}>
                {labels.country}
            </label>
            <select
                id={`${id}-country`}
                aria-label={labels.country}
                className="auth-phone-field__country"
                disabled={disabled}
                onChange={handleCountryChange}
                value={dial}
            >
                {phoneCountries.map((country) => (
                    <option key={country.iso} value={country.dial}>
                        {`${country.name[locale]} (${country.dial})`}
                    </option>
                ))}
            </select>
            <Input
                id={id}
                type="tel"
                inputMode="numeric"
                autoComplete={autoComplete}
                autoFocus={autoFocus}
                className="auth-phone-field__number"
                disabled={disabled}
                maxLength={14}
                aria-describedby={
                    describedBy ?? (error ? `${id}-error` : undefined)
                }
                aria-invalid={Boolean(error)}
                aria-label={labels.number}
                onChange={handleNumberChange}
                value={national}
            />
        </div>
    );
}
