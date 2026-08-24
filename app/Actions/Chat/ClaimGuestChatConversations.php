<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Facades\DB;

final readonly class ClaimGuestChatConversations
{
    public function __construct(private CloseChatConversation $closeChatConversation) {}

    /** @param list<ChatOwner> $guestOwners */
    public function execute(array $guestOwners, User $user, ?string $activePublicId): void
    {
        $guestKeys = array_values(array_unique(array_filter(array_map(
            fn (ChatOwner $owner): ?string => $owner->guestKey(),
            $guestOwners,
        ))));

        if ($guestKeys === []) {
            return;
        }

        DB::transaction(function () use ($guestKeys, $user, $activePublicId): void {
            $guestConversations = ChatConversation::query()
                ->whereNull('user_id')->whereIn('guest_key', $guestKeys)->orderBy('id')->lockForUpdate()->get();
            $userConversation = ChatConversation::query()
                ->where('user_id', $user->id)->whereNull('guest_key')->open()->lockForUpdate()->first();
            $openGuestConversations = $guestConversations
                ->filter(fn (ChatConversation $conversation): bool => $conversation->status === ChatConversationStatus::Open);

            // A conversation a human is actively handling outranks any guest thread. Closing
            // it would leave a live ticket pointing at a closed conversation nobody can post to.
            if ($userConversation instanceof ChatConversation && $userConversation->handoff_state->isLive()) {
                $openGuestConversations->each(fn (ChatConversation $conversation) => $this->closeChatConversation->execute(
                    $conversation,
                    ChatConversationCloseReason::SupersededByLoginClaim,
                ));

                ChatConversation::query()->whereIn('id', $guestConversations->pluck('id'))->update([
                    'user_id' => $user->id,
                    'guest_key' => null,
                    'updated_at' => now(),
                ]);

                return;
            }

            $winner = is_string($activePublicId)
                ? $openGuestConversations->firstWhere('public_id', $activePublicId)
                : null;
            $winner ??= $openGuestConversations
                ->sortByDesc('id')
                ->sortByDesc('last_message_at')
                ->first();

            if ($winner instanceof ChatConversation) {
                if ($userConversation instanceof ChatConversation) {
                    $this->closeChatConversation->execute(
                        $userConversation,
                        ChatConversationCloseReason::SupersededByLoginClaim,
                    );
                }

                $openGuestConversations
                    ->filter(fn (ChatConversation $conversation): bool => $conversation->id !== $winner->id)
                    ->each(fn (ChatConversation $conversation) => $this->closeChatConversation->execute(
                        $conversation,
                        ChatConversationCloseReason::SupersededByLoginClaim,
                    ));
            }

            ChatConversation::query()->whereIn('id', $guestConversations->pluck('id'))->update([
                'user_id' => $user->id,
                'guest_key' => null,
                'updated_at' => now(),
            ]);
        }, attempts: 3);
    }
}
