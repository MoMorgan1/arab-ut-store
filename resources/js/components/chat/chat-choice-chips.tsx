import type { ChatChoices } from '@/lib/chat-choices';

/**
 * The one question the assistant is asking, as tappable chips.
 *
 * Tapping a chip sends its text as an ordinary customer message, so the answer
 * travels the same path as typing it by hand and the server re-derives
 * everything. Chips are server-derived, never authored by the model.
 *
 * Only the newest assistant message offers chips: an old question further up
 * the transcript has already been answered, and re-tapping it would send a
 * choice that no longer matches the conversation.
 */
export function ChatChoiceChips({
    choices,
    onChoose,
    disabled = false,
}: {
    choices: ChatChoices | null;
    onChoose: (message: string) => void;
    disabled?: boolean;
}) {
    if (choices === null) {
        return null;
    }

    return (
        <div className="mt-2.5" data-testid="chat-choices">
            <p className="mb-1.5 text-xs text-[var(--chat-muted)]">
                {choices.prompt}
            </p>
            <div className="flex flex-wrap gap-1.5">
                {choices.items.map((item) => (
                    <button
                        key={item.id}
                        type="button"
                        disabled={disabled}
                        onClick={() => onChoose(item.message)}
                        data-testid="chat-choice"
                        className="chat-choice-chip cursor-pointer rounded-full border border-[var(--chat-accent)]/45 bg-[var(--chat-card)] px-3 py-1.5 text-xs font-medium text-[var(--chat-ink)] transition-[transform,background-color,border-color] duration-150 hover:border-[var(--chat-accent)] hover:bg-[var(--chat-accent-soft)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--chat-accent)] active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transition-none"
                    >
                        {item.label}
                    </button>
                ))}
            </div>
        </div>
    );
}
