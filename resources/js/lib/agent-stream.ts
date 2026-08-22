import { ChatApiError } from '@/lib/chat-api';
import type {
    AgentTurnState,
    AgentTurnStatus,
    AppStreamEvent,
    ChatMessage,
    ChatMessageType,
    ChatSenderType,
} from '@/types/chat';

const ALLOWED_STREAM_EVENTS = new Set<string>([
    'turn.created',
    'response.delta',
    'response.completed',
    'response.failed',
]);

const VALID_TURN_STATUSES = new Set<AgentTurnStatus>([
    'waiting',
    'running',
    'completed',
    'failed',
    'cancelled',
]);

const VALID_SENDER_TYPES = new Set<ChatSenderType>([
    'customer',
    'assistant',
    'system',
]);

const VALID_MESSAGE_TYPES = new Set<ChatMessageType>(['text', 'system']);

function isObject(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function validateChatMessage(value: unknown, status = 200): ChatMessage {
    if (!isObject(value)) {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'Invalid message shape in stream event.',
        );
    }

    if (
        typeof value.publicId !== 'string' ||
        value.publicId === '' ||
        typeof value.content !== 'string' ||
        typeof value.senderType !== 'string' ||
        !VALID_SENDER_TYPES.has(value.senderType as ChatSenderType) ||
        typeof value.messageType !== 'string' ||
        !VALID_MESSAGE_TYPES.has(value.messageType as ChatMessageType) ||
        typeof value.createdAt !== 'string'
    ) {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'Invalid message properties in stream event.',
        );
    }

    return value as unknown as ChatMessage;
}

export function validateAgentTurnState(
    value: unknown,
    status = 200,
): AgentTurnState {
    if (!isObject(value)) {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'Invalid turn state shape in stream event.',
        );
    }

    if (
        typeof value.publicId !== 'string' ||
        value.publicId === '' ||
        typeof value.status !== 'string' ||
        !VALID_TURN_STATUSES.has(value.status as AgentTurnStatus) ||
        typeof value.attemptCount !== 'number' ||
        typeof value.retryable !== 'boolean' ||
        typeof value.hasPendingMessages !== 'boolean'
    ) {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'Invalid turn state properties in stream event.',
        );
    }

    if (value.errorCode !== null && typeof value.errorCode !== 'string') {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'Invalid error code in turn state.',
        );
    }

    if (value.message !== null && value.message !== undefined) {
        validateChatMessage(value.message, status);
    }

    return value as unknown as AgentTurnState;
}

export function parseAppStreamFrame(
    frame: string,
    status = 200,
): AppStreamEvent | null {
    const rawLines = frame.split(/\r?\n/);
    const nonCommentLines: string[] = [];

    for (const rawLine of rawLines) {
        const line = rawLine.trim();

        if (line === '' || line.startsWith(':')) {
            // Heartbeat comment or empty line inside frame -> ignore
            continue;
        }

        nonCommentLines.push(rawLine);
    }

    if (nonCommentLines.length === 0) {
        return null;
    }

    let eventName: string | null = null;
    let dataPayloadStr: string | null = null;

    for (const line of nonCommentLines) {
        if (line.startsWith('event:')) {
            if (eventName !== null) {
                throw new ChatApiError(
                    'invalid_stream',
                    status,
                    'Multiple event fields in single SSE frame.',
                );
            }

            eventName = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
            if (dataPayloadStr !== null) {
                throw new ChatApiError(
                    'invalid_stream',
                    status,
                    'Multiple data fields in single SSE frame.',
                );
            }

            dataPayloadStr = line.slice(5).trim();
        } else {
            throw new ChatApiError(
                'invalid_stream',
                status,
                `Unrecognized field in SSE frame: ${line}`,
            );
        }
    }

    if (eventName === null || dataPayloadStr === null) {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'SSE frame missing required event or data field.',
        );
    }

    if (!ALLOWED_STREAM_EVENTS.has(eventName)) {
        throw new ChatApiError(
            'invalid_stream',
            status,
            `Disallowed or unknown SSE event type: ${eventName}`,
        );
    }

    let parsedJson: unknown;

    try {
        parsedJson = JSON.parse(dataPayloadStr);
    } catch {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'Malformed JSON in SSE data payload.',
        );
    }

    if (!isObject(parsedJson)) {
        throw new ChatApiError(
            'invalid_stream',
            status,
            'SSE data payload must be a JSON object.',
        );
    }

    switch (eventName) {
        case 'turn.created': {
            if (!('turn' in parsedJson)) {
                throw new ChatApiError(
                    'invalid_stream',
                    status,
                    'Missing turn in turn.created payload.',
                );
            }

            const turn = validateAgentTurnState(parsedJson.turn, status);

            return {
                event: 'turn.created',
                data: { turn },
            };
        }

        case 'response.delta': {
            if (
                typeof parsedJson.turnPublicId !== 'string' ||
                parsedJson.turnPublicId === '' ||
                typeof parsedJson.delta !== 'string'
            ) {
                throw new ChatApiError(
                    'invalid_stream',
                    status,
                    'Invalid response.delta payload structure.',
                );
            }

            return {
                event: 'response.delta',
                data: {
                    turnPublicId: parsedJson.turnPublicId,
                    delta: parsedJson.delta,
                },
            };
        }

        case 'response.completed': {
            if (!('turn' in parsedJson) || !('message' in parsedJson)) {
                throw new ChatApiError(
                    'invalid_stream',
                    status,
                    'Missing turn or message in response.completed payload.',
                );
            }

            const turn = validateAgentTurnState(parsedJson.turn, status);
            const message = validateChatMessage(parsedJson.message, status);

            return {
                event: 'response.completed',
                data: { turn, message },
            };
        }

        case 'response.failed': {
            if (
                !('turn' in parsedJson) ||
                typeof parsedJson.code !== 'string' ||
                typeof parsedJson.message !== 'string'
            ) {
                throw new ChatApiError(
                    'invalid_stream',
                    status,
                    'Invalid response.failed payload structure.',
                );
            }

            const turn = validateAgentTurnState(parsedJson.turn, status);

            return {
                event: 'response.failed',
                data: {
                    turn,
                    code: parsedJson.code,
                    message: parsedJson.message,
                },
            };
        }

        default:
            throw new ChatApiError(
                'invalid_stream',
                status,
                `Unsupported event: ${eventName}`,
            );
    }
}

export async function readAgentEventStream(
    stream: ReadableStream<Uint8Array>,
    onEvent: (event: AppStreamEvent) => void,
    status = 200,
): Promise<void> {
    const reader = stream.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    try {
        while (true) {
            const { done, value } = await reader.read();
            buffer += decoder.decode(value, { stream: !done });
            const frames = buffer.split(/\r?\n\r?\n/);
            buffer = frames.pop() ?? '';

            for (const frame of frames) {
                const parsed = parseAppStreamFrame(frame, status);

                if (parsed !== null) {
                    onEvent(parsed);
                }
            }

            if (done) {
                if (buffer.trim() !== '') {
                    const parsed = parseAppStreamFrame(buffer, status);

                    if (parsed !== null) {
                        onEvent(parsed);
                    }
                }

                break;
            }
        }
    } finally {
        reader.releaseLock();
    }
}

export async function collectAgentEvents(
    stream: ReadableStream<Uint8Array>,
): Promise<AppStreamEvent[]> {
    const events: AppStreamEvent[] = [];
    await readAgentEventStream(stream, (event) => {
        events.push(event);
    });

    return events;
}
