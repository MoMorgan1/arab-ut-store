<?php

namespace App\Actions\Chat;

use App\Models\ChatConversation;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;
use Illuminate\Support\Facades\DB;

final readonly class ClaimGuestChatConversations
{
    /** @param list<ChatOwner> $guestOwners */
    public function execute(array $guestOwners, User $user): void
    {
        $guestKeys = array_values(array_filter(array_map(
            fn (ChatOwner $owner): ?string => $owner->guestKey(),
            $guestOwners,
        )));

        if ($guestKeys === []) {
            return;
        }

        DB::transaction(function () use ($guestKeys, $user): void {
            ChatConversation::query()
                ->whereNull('user_id')
                ->whereIn('guest_key', $guestKeys)
                ->update([
                    'user_id' => $user->id,
                    'guest_key' => null,
                    'updated_at' => now(),
                ]);
        }, attempts: 3);
    }
}
