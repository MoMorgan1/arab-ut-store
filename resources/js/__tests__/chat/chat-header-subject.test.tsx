import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatHeader } from '@/components/chat/chat-header';

const noop = vi.fn();

function renderHeader(subject: string | null, locale = 'ar') {
    return render(
        <ChatHeader
            canRestart
            isRestarting={false}
            locale={locale}
            onBack={noop}
            onClose={noop}
            onRestart={noop}
            soundEnabled={false}
            onToggleSound={noop}
            subject={subject}
        />,
    );
}

describe('ChatHeader title', () => {
    afterEach(cleanup);

    it('shows the brand while the conversation has no title', () => {
        renderHeader(null);

        expect(
            screen.getByRole('heading', { name: 'مساعد عرب التيميت' }),
        ).toBeInTheDocument();
        expect(screen.getByText('عادة نرد فورًا')).toBeInTheDocument();
    });

    it('takes the conversation title and moves the brand under it', () => {
        renderHeader('سعر الكوينز اليوم');

        expect(
            screen.getByRole('heading', { name: 'سعر الكوينز اليوم' }),
        ).toHaveAttribute('dir', 'auto');
        expect(screen.getByText('مساعد عرب التيميت')).toBeInTheDocument();
        expect(screen.queryByText('عادة نرد فورًا')).not.toBeInTheDocument();
    });

    it('treats a blank title as no title', () => {
        renderHeader('   ', 'en');

        expect(
            screen.getByRole('heading', { name: 'Arab UT Assistant' }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Usually replies instantly'),
        ).toBeInTheDocument();
    });
});
