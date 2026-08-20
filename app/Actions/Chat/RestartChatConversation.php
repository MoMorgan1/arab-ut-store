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
        private CreateChatConversation $createChatConversation,
        private CloseChatConversation $closeChatConversation,
    ) {}

    public function execute(ChatOwner $owner, Request $request, ?string $locale): ChatConversation
    {
        $effectiveLocale = $locale === 'en' || ($locale === null && app()->getLocale() === 'en') ? 'en' : 'ar';

        $conversation = DB::transaction(function () use ($owner, $effectiveLocale): ChatConversation {
            $currentConversation = ChatConversation::query()
                ->forOwner($owner)->open()->orderByDesc('last_message_at')->orderByDesc('id')->lockForUpdate()->first();

            if ($currentConversation instanceof ChatConversation) {
                $this->closeChatConversation->execute($currentConversation, ChatConversationCloseReason::CustomerStartedNew);
            }

            return $this->createChatConversation->execute($owner, $effectiveLocale);
        }, attempts: 3);

        $request->session()->put(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY, $conversation->public_id);

        return $conversation;
    }
}
