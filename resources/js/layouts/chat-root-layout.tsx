import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ChatWidget } from '@/components/chat/chat-widget';

export default function ChatRootLayout({ children }: { children: ReactNode }) {
    const page = usePage();
    const { props } = page;
    const locale = (props.locale as string) || 'ar';
    const chatConfig = props.chat;
    const surface = page.component.startsWith('account/') ? 'account' : 'store';

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
