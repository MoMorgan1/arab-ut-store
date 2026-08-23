import { usePage } from '@inertiajs/react';
import { useLayoutEffect } from 'react';
import type { ReactNode } from 'react';
import { ChatWidget } from '@/components/chat/chat-widget';
import { currentAppearanceIsDark } from '@/hooks/use-appearance';
import { applyDocumentShell } from '@/lib/document-shell';

export default function ChatRootLayout({ children }: { children: ReactNode }) {
    const page = usePage();
    const { props } = page;
    const locale = (props.locale as string) || 'ar';
    const chatConfig = props.chat;
    const surface = page.component.startsWith('account/') ? 'account' : 'store';

    // This layout wraps every page, so it is the one place that sees every
    // Inertia visit. Blade stamps the palette classes on a full document load
    // only; without this the root element keeps whichever shell it was served
    // with, and an admin page reached by a client-side visit renders in the
    // storefront palette with no gold accents until the visitor refreshes.
    // useLayoutEffect so the swap lands before the browser paints the new page.
    useLayoutEffect(() => {
        applyDocumentShell(page.component, currentAppearanceIsDark());
    }, [page.component]);

    if (page.component.startsWith('admin/')) {
        return children;
    }

    return (
        <>
            {children}
            <ChatWidget
                enabled={chatConfig?.enabled}
                locale={locale}
                surface={surface}
            />
        </>
    );
}
