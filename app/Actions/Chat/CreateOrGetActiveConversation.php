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
        $activePublicId = is_string($sessionPointer) && $sessionPointer !== ''
            ? $sessionPointer
            : null;

        $conversation = DB::transaction(
            fn (): ChatConversation => $this->acquire($owner, $activePublicId, $locale),
            attempts: 3,
        );

        $request->session()->put(
            ResolveChatOwner::ACTIVE_CONVERSATION_SESSION_KEY,
            $conversation->public_id,
        );

        return $conversation;
    }

    private function acquire(ChatOwner $owner, ?string $activePublicId, ?string $locale): ChatConversation
    {
        $pointed = $this->pointedOpenConversation($owner, $activePublicId);

        if ($pointed instanceof ChatConversation) {
            return $pointed;
        }

        $open = $this->canonicalOpenConversation($owner);

        if ($open instanceof ChatConversation) {
            return $open;
        }

        $inactive = $this->recentInactiveConversation($owner);

        return $this->mutateOrRecoverWinner($owner, $inactive, $locale);
    }

    private function pointedOpenConversation(ChatOwner $owner, ?string $activePublicId): ?ChatConversation
    {
        if ($activePublicId === null) {
            return null;
        }

        return ChatConversation::query()
            ->forOwner($owner)
            ->open()
            ->where('public_id', $activePublicId)
            ->lockForUpdate()
            ->first();
    }

    private function recentInactiveConversation(ChatOwner $owner): ?ChatConversation
    {
        return ChatConversation::query()
            ->forOwner($owner)
            ->closedForInactivity()
            ->where('last_message_at', '>=', now()->subDays((int) config('chat.reopen_within_days', 7)))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function reopen(ChatConversation $conversation): ChatConversation
    {
        $conversation->forceFill([
            'status' => ChatConversationStatus::Open,
            'closed_at' => null,
            'close_reason' => null,
        ])->save();

        return $conversation->refresh();
    }

    private function mutateOrRecoverWinner(
        ChatOwner $owner,
        ?ChatConversation $inactive,
        ?string $locale,
    ): ChatConversation {
        try {
            if ($inactive instanceof ChatConversation) {
                return $this->reopen($inactive);
            }

            return $this->createChatConversation->execute(
                $owner,
                $locale ?? app()->getLocale(),
            );
        } catch (QueryException $exception) {
            if (! $this->isActiveOwnerUniqueViolation($exception)) {
                throw $exception;
            }

            $winner = $this->canonicalOpenConversation($owner);

            if (! $winner instanceof ChatConversation) {
                throw $exception;
            }

            return $winner;
        }
    }

    private function canonicalOpenConversation(ChatOwner $owner): ?ChatConversation
    {
        return ChatConversation::query()
            ->forOwner($owner)
            ->open()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();
    }

    private function isActiveOwnerUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        if ($sqlState !== '23000') {
            return false;
        }

        if ($driverCode === 1062) {
            return str_contains($message, 'chat_conversations_active_owner_key_unique');
        }

        return $driverCode === 19
            && str_contains($message, 'UNIQUE constraint failed: chat_conversations.active_owner_key');
    }
}
