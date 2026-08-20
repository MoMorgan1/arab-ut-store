<?php

namespace App\Actions\Chat;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class ClaimGuestChatConversations
{
    public function __construct(private CloseChatConversation $closeChatConversation) {}

    /** @param list<ChatOwner> $guestOwners */
    public function execute(array $guestOwners, User $user, ?string $activePublicId): void
    {
        $guestKeys = array_values(array_filter(array_map(
            fn (ChatOwner $owner): ?string => $owner->guestKey(),
            $guestOwners,
        )));

        if ($guestKeys === []) {
            return;
        }

        DB::transaction(
            fn () => $this->claim($guestKeys, $user, $activePublicId),
            attempts: 3,
        );
    }

    /** @param list<string> $guestKeys */
    private function claim(array $guestKeys, User $user, ?string $activePublicId): void
    {
        $guestConversations = $this->lockedGuestConversations($guestKeys);
        $openGuests = $guestConversations->filter(
            fn (ChatConversation $conversation): bool => $conversation->status === ChatConversationStatus::Open,
        );
        $winner = $this->winningGuestConversation($openGuests, $activePublicId);
        $this->closeConflictingOpenConversations($openGuests, $winner, $this->lockedUserOpen($user));
        $this->claimOwnership($guestConversations, $user);
    }

    /**
     * @param  list<string>  $guestKeys
     * @return Collection<int, ChatConversation>
     */
    private function lockedGuestConversations(array $guestKeys): Collection
    {
        return ChatConversation::query()
            ->whereNull('user_id')
            ->whereIn('guest_key', $guestKeys)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param Collection<int, ChatConversation> $openGuests */
    private function winningGuestConversation(Collection $openGuests, ?string $activePublicId): ?ChatConversation
    {
        $winner = $activePublicId === null
            ? null
            : $openGuests->firstWhere('public_id', $activePublicId);

        return $winner ?? $openGuests
            ->sortByDesc(fn (ChatConversation $conversation): string => sprintf(
                '%s:%020d',
                $conversation->last_message_at?->format('Y-m-d H:i:s.u') ?? '',
                $conversation->id,
            ))
            ->first();
    }

    private function lockedUserOpen(User $user): ?ChatConversation
    {
        return ChatConversation::query()
            ->where('user_id', $user->id)
            ->whereNull('guest_key')
            ->open()
            ->lockForUpdate()
            ->first();
    }

    /** @param Collection<int, ChatConversation> $openGuests */
    private function closeConflictingOpenConversations(
        Collection $openGuests,
        ?ChatConversation $winner,
        ?ChatConversation $userOpen,
    ): void {
        if ($winner instanceof ChatConversation && $userOpen instanceof ChatConversation) {
            $this->closeForLoginClaim($userOpen);
        }

        foreach ($openGuests as $guestConversation) {
            if (! $winner instanceof ChatConversation || ! $guestConversation->is($winner)) {
                $this->closeForLoginClaim($guestConversation);
            }
        }
    }

    private function closeForLoginClaim(ChatConversation $conversation): void
    {
        $this->closeChatConversation->execute(
            $conversation,
            ChatConversationCloseReason::SupersededByLoginClaim,
        );
    }

    /** @param Collection<int, ChatConversation> $guestConversations */
    private function claimOwnership(Collection $guestConversations, User $user): void
    {
        foreach ($guestConversations as $guestConversation) {
            ChatConversation::query()->whereKey($guestConversation->id)->update([
                'user_id' => $user->id,
                'guest_key' => null,
                'updated_at' => now(),
            ]);
        }
    }
}
