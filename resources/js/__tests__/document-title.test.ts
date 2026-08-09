import { describe, expect, it } from 'vitest';

import { formatDocumentTitle } from '@/lib/document-title';

describe('formatDocumentTitle', () => {
    it('combines a page title with the application name', () => {
        expect(formatDocumentTitle('Home', 'Arab UT')).toBe('Home - Arab UT');
    });

    it('does not duplicate the application name', () => {
        expect(formatDocumentTitle('Arab UT', 'Arab UT')).toBe('Arab UT');
    });

    it('uses the exact public brand when no application name is configured', () => {
        expect(formatDocumentTitle('Home')).toBe('Home - Arab UT');
    });

    it.each([
        'Arab UT | FC 27 Ultimate Team Coins',
        'Arab UT | كوينز FC 27 ألتيميت تيم',
    ])(
        'keeps the brand exactly once in a pre-branded locale title',
        (title) => {
            const formatted = formatDocumentTitle(title, 'Arab UT');

            expect(formatted).toBe(title);
            expect(formatted.match(/Arab UT/g)).toHaveLength(1);
        },
    );
});
