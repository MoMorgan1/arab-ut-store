import { describe, expect, it } from 'vitest';
import { isLinkableUrl, parseInlineTokens } from '@/lib/chat-format';
import type { LinkToken } from '@/lib/chat-format';

function linksIn(text: string): LinkToken[] {
    return parseInlineTokens(text).filter(
        (token): token is LinkToken => token.type === 'link',
    );
}

describe('assistant links', () => {
    it('turns the store and tracking links into link tokens', () => {
        expect(
            linksIn('تقدر تشوف الأسعار هنا: https://store.arab-ut.com'),
        ).toEqual([
            expect.objectContaining({
                type: 'link',
                href: 'https://store.arab-ut.com',
            }),
        ]);

        expect(linksIn('Track it at https://track.arab-ut.com')).toHaveLength(
            1,
        );
    });

    /**
     * The assistant writes this text, so every URL in it is model output. A
     * hallucinated or injected domain must stay inert — readable and copyable,
     * but not one tap away.
     */
    it('leaves every other host as plain text', () => {
        for (const hostile of [
            'https://evil.example.com',
            'https://store.arab-ut.com.attacker.io',
            'https://arab-ut.com.evil.co/login',
            'http://192.168.1.1/admin',
            'javascript:alert(1)',
        ]) {
            expect(linksIn(`Open ${hostile} now`)).toHaveLength(0);
            expect(isLinkableUrl(hostile)).toBe(false);
        }
    });

    it('does not swallow the punctuation that follows a link', () => {
        const [link] = linksIn('الأسعار على https://store.arab-ut.com.');

        expect(link.href).toBe('https://store.arab-ut.com');
    });

    it('keeps a path and query on the href', () => {
        const [link] = linksIn(
            'https://store.arab-ut.com/sbc?platform=console',
        );

        expect(link.href).toBe(
            'https://store.arab-ut.com/sbc?platform=console',
        );
    });

    it('still tokenises money that appears beside a link', () => {
        const tokens = parseInlineTokens(
            'يبدأ من 3.60 SAR — https://store.arab-ut.com',
        );

        expect(tokens.some((t) => t.type === 'money')).toBe(true);
        expect(tokens.some((t) => t.type === 'link')).toBe(true);
    });
});
