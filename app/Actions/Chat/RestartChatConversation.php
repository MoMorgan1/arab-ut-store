<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class RestartChatConversation
{
    public function __construct(
        private CloseChatConversation $closeChatConversation,
        private CreateChatConversation $createChatConversation,
    ) {}

    public function execute(ChatOwner $owner, Request $request, ?string $locale): ChatConversation
    {
        return DB::transaction(
            fn (): ChatConversation => $this->restart($owner, $request, $locale),
            attempts: 3,
        );
    }

    private function restart(ChatOwner $owner, Request $request, ?string $locale): ChatConversation
    {
        $current = $this->lockedOpenConversation($owner);
        $effectiveLocale = $locale ?? app()->getLocale();

        if ($current instanceof ChatConversation) {
            $effectiveLocale = $locale ?? $current->locale;
            $this->closeChatConversation->execute(
                $current,
                ChatConversationCloseReason::CustomerStartedNew,
            );
        }

        $conversation = $this->createChatConversation->execute($owner, $effectiveLocale);
        $request->session()->put(
            ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY,
            $conversation->public_id,
        );

        return $conversation;
    }

    private function lockedOpenConversation(ChatOwner $owner): ?ChatConversation
    {
        return ChatConversation::query()
            ->forOwner($owner)
            ->open()
            ->lockForUpdate()
            ->first();
    }
}
