import type { ChatGroupedCluster, ChatMessage } from '@/types/chat';

const MAX_GROUPING_INTERVAL_MS = 90 * 1000; // 90 seconds

function isSameDay(date1: Date, date2: Date): boolean {
    return (
        date1.getFullYear() === date2.getFullYear() &&
        date1.getMonth() === date2.getMonth() &&
        date1.getDate() === date2.getDate()
    );
}

export function groupChatMessages(
    messages: ChatMessage[],
): ChatGroupedCluster[] {
    if (messages.length === 0) {
        return [];
    }

    const clusters: ChatGroupedCluster[] = [];
    let currentCluster: ChatGroupedCluster | null = null;
    let lastMessageDate: Date | null = null;

    for (const message of messages) {
        const messageDate = new Date(message.createdAt);

        if (
            currentCluster !== null &&
            currentCluster.senderType === message.senderType &&
            lastMessageDate !== null &&
            isSameDay(lastMessageDate, messageDate) &&
            Math.abs(messageDate.getTime() - lastMessageDate.getTime()) <=
                MAX_GROUPING_INTERVAL_MS
        ) {
            currentCluster.messages.push(message);
            currentCluster.lastMessageAt = message.createdAt;
        } else {
            currentCluster = {
                id: `cluster-${message.publicId || message.tempId || String(Math.random())}`,
                senderType: message.senderType,
                messages: [message],
                firstMessageAt: message.createdAt,
                lastMessageAt: message.createdAt,
            };
            clusters.push(currentCluster);
        }

        lastMessageDate = messageDate;
    }

    return clusters;
}
