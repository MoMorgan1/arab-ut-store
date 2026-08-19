import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { ChatWidget } from '@/components/chat/chat-widget';

export default function ChatRootLayout({ children }: { children: ReactNode }) {
    const { props } = usePage();
    const locale = (props.locale as string) || 'ar';
    const chatConfig = props.chat;

    return (
        <>
            {children}
            <ChatWidget
                enabled={chatConfig?.enabled}
                demoAssistant={chatConfig?.demoAssistant}
                locale={locale}
            />
        </>
    );
}
