import { describe, expect, it } from 'vitest';

import { formatDocumentTitle } from '@/lib/document-title';

describe('formatDocumentTitle', () => {
    it('combines a page title with the application name', () => {
        expect(formatDocumentTitle('Home', 'Arab UT')).toBe('Home - Arab UT');
    });

    it('does not duplicate the application name', () => {
        expect(formatDocumentTitle('Arab UT', 'Arab UT')).toBe('Arab UT');
    });
});
