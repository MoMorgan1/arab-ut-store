<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final readonly class CreateOrGetActiveConversation
{
    public function __construct(private CreateChatConversation $createChatConversation) {}

    public function execute(ChatOwner $owner, Request $request, ?string $locale = null): ChatConversation
    {
        $sessionPointer = $request->session()->get(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY);
        $effectiveLocale = $locale === 'en' || ($locale === null && app()->getLocale() === 'en') ? 'en' : 'ar';

        try {
            $conversation = DB::transaction(
                fn (): ChatConversation => $this->acquire($owner, $sessionPointer, $effectiveLocale),
                attempts: 3,
            );
        } catch (QueryException $failure) {
            if (! $this->isActiveOwnerUniqueViolation($failure)) {
                throw $failure;
            }

            $conversation = DB::transaction(function () use ($owner): ?ChatConversation {
                return ChatConversation::query()
                    ->forOwner($owner)->open()->orderByDesc('last_message_at')->orderByDesc('id')->lockForUpdate()->first();
            }, attempts: 3);

            if (! $conversation instanceof ChatConversation) {
                throw $failure;
            }
        }

        $request->session()->put(ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY, $conversation->public_id);

        return $conversation;
    }

    private function acquire(ChatOwner $owner, mixed $sessionPointer, string $locale): ChatConversation
    {
        if (is_string($sessionPointer) && $sessionPointer !== '') {
            $pointedConversation = ChatConversation::query()
                ->forOwner($owner)->open()->where('public_id', $sessionPointer)->lockForUpdate()->first();

            if ($pointedConversation instanceof ChatConversation) {
                return $pointedConversation;
            }
        }

        $activeConversation = ChatConversation::query()
            ->forOwner($owner)->open()->orderByDesc('last_message_at')->orderByDesc('id')->lockForUpdate()->first();

        if ($activeConversation instanceof ChatConversation) {
            return $activeConversation;
        }

        $reopenAfter = now()->subDays(max(0, (int) config('chat.reopen_within_days', 7)));
        $inactiveConversation = ChatConversation::query()
            ->forOwner($owner)->closedForInactivity()->whereLastActivityAtOrAfter($reopenAfter)
            ->orderByLastActivityDesc()->orderByDesc('id')->lockForUpdate()->first();

        if ($inactiveConversation instanceof ChatConversation) {
            $lastActivity = $inactiveConversation->last_message_at
                ?? $inactiveConversation->closed_at
                ?? $inactiveConversation->updated_at;

            $inactiveConversation->forceFill([
                'status' => ChatConversationStatus::Open,
                'last_message_at' => $lastActivity,
                'closed_at' => null,
                'close_reason' => null,
            ])->save();

            return $inactiveConversation;
        }

        return $this->createChatConversation->execute($owner, $locale);
    }

    private function isActiveOwnerUniqueViolation(QueryException $failure): bool
    {
        $message = strtolower($failure->getMessage());

        return str_contains($message, 'chat_conversations_active_owner_key_unique')
            || (str_contains($message, 'unique constraint failed')
                && str_contains($message, 'chat_conversations.active_owner_key'));
    }
}
