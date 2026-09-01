import { usePage } from '@inertiajs/react';
import React, { Suspense, useLayoutEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ChatLauncher } from '@/components/chat/chat-launcher';
import { currentAppearanceIsDark } from '@/hooks/use-appearance';
import { applyDocumentShell } from '@/lib/document-shell';

const LazyChatWidget = React.lazy(() =>
    import('@/components/chat/chat-widget').then((module) => ({
        default: module.ChatWidget,
    })),
);

export default function ChatRootLayout({ children }: { children: ReactNode }) {
    const page = usePage();
    const { props } = page;
    const locale = (props.locale as string) || 'ar';
    const chatConfig = props.chat;
    // Chat history is owner-scoped and guests always receive an empty list, so
    // the widget only asks for it when someone is actually logged in.
    const isAuthenticated =
        (props.auth as { user?: unknown } | undefined)?.user != null;
    const surface = page.component.startsWith('account/') ? 'account' : 'store';
    const [hasOpened, setHasOpened] = useState(false);

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

    if (!chatConfig?.enabled) {
        return children;
    }

    return (
        <>
            {children}
            {!hasOpened ? (
                <div
                    className={`chat-widget-root fixed right-4 bottom-4 z-50 sm:right-6 sm:bottom-6 ${
                        surface === 'account' ? 'chat-widget-root--account' : ''
                    }`}
                    dir={locale === 'en' ? 'ltr' : 'rtl'}
                >
                    <ChatLauncher
                        isOpen={false}
                        unreadCount={0}
                        locale={locale}
                        canGreet={surface !== 'account'}
                        onToggle={() => setHasOpened(true)}
                    />
                </div>
            ) : (
                <Suspense
                    fallback={
                        <div
                            className={`chat-widget-root fixed right-4 bottom-4 z-50 sm:right-6 sm:bottom-6 ${
                                surface === 'account'
                                    ? 'chat-widget-root--account'
                                    : ''
                            }`}
                            dir={locale === 'en' ? 'ltr' : 'rtl'}
                        >
                            <ChatLauncher
                                isOpen={false}
                                unreadCount={0}
                                locale={locale}
                                canGreet={surface !== 'account'}
                                onToggle={() => {}}
                            />
                        </div>
                    }
                >
                    <LazyChatWidget
                        enabled={chatConfig?.enabled}
                        isAuthenticated={isAuthenticated}
                        locale={locale}
                        surface={surface}
                        initialOpen={true}
                    />
                </Suspense>
            )}
        </>
    );
}
