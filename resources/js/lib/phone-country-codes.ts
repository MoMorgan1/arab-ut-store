export const phoneCountries = [
    { iso: 'SA', dial: '+966', name: { ar: 'السعودية', en: 'Saudi Arabia' } },
    { iso: 'EG', dial: '+20', name: { ar: 'مصر', en: 'Egypt' } },
    {
        iso: 'AE',
        dial: '+971',
        name: { ar: 'الإمارات', en: 'United Arab Emirates' },
    },
    { iso: 'KW', dial: '+965', name: { ar: 'الكويت', en: 'Kuwait' } },
    { iso: 'QA', dial: '+974', name: { ar: 'قطر', en: 'Qatar' } },
    { iso: 'BH', dial: '+973', name: { ar: 'البحرين', en: 'Bahrain' } },
    { iso: 'OM', dial: '+968', name: { ar: 'عُمان', en: 'Oman' } },
] as const;

export type PhoneCountry = (typeof phoneCountries)[number];
export type PhoneCountryDial = PhoneCountry['dial'];

export const phoneCountryCodes = phoneCountries.map(
    (country) => country.dial,
) as readonly PhoneCountryDial[];

export function splitE164(
    value: string,
): { dial: string; national: string } | null {
    if (!value.startsWith('+')) {
        return null;
    }

    const matchingCountries = phoneCountries
        .filter((country) => value.startsWith(country.dial))
        .sort((a, b) => b.dial.length - a.dial.length);

    const match = matchingCountries[0];

    if (!match) {
        return null;
    }

    return {
        dial: match.dial,
        national: value.slice(match.dial.length),
    };
}
