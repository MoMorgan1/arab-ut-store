<?php

namespace App\Console\Commands;

use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class MaintainChatConversations extends Command
{
    protected $signature = 'chat:maintain-conversations';

    protected $description = 'Close inactive conversations and purge expired closed conversation history';

    public function handle(): int
    {
        $now = now();
        $closed = ChatConversation::query()
            ->open()
            ->where('last_message_at', '<=', $now->copy()->subHours((int) config('chat.auto_close_hours')))
            ->update([
                'status' => ChatConversationStatus::Closed->value,
                'closed_at' => $now,
                'close_reason' => ChatConversationCloseReason::Inactive->value,
                'updated_at' => $now,
            ]);
        $guestPurged = $this->purgeExpiredClosedConversations(
            guestOwned: true,
            cutoff: $now->copy()->subDays((int) config('chat.guest_retention_days')),
        );
        $userPurged = $this->purgeExpiredClosedConversations(
            guestOwned: false,
            cutoff: $now->copy()->subDays((int) config('chat.user_retention_days')),
        );

        $this->components->info("Closed {$closed} inactive conversation(s).");
        $this->components->info("Purged {$guestPurged} expired guest conversation(s).");
        $this->components->info("Purged {$userPurged} expired authenticated conversation(s).");

        return self::SUCCESS;
    }

    private function purgeExpiredClosedConversations(bool $guestOwned, CarbonInterface $cutoff): int
    {
        $purged = 0;

        $this->expiredClosedConversations($guestOwned, $cutoff)
            ->chunkById(200, function (Collection $conversations) use (&$purged, $guestOwned, $cutoff): void {
                $purged += $this->expiredClosedConversations($guestOwned, $cutoff)
                    ->whereKey($conversations->modelKeys())
                    ->delete();
            });

        return $purged;
    }

    /** @return Builder<ChatConversation> */
    private function expiredClosedConversations(bool $guestOwned, CarbonInterface $cutoff): Builder
    {
        $query = ChatConversation::query()
            ->where('status', ChatConversationStatus::Closed)
            ->where('last_message_at', '<=', $cutoff);

        if ($guestOwned) {
            return $query->whereNull('user_id')->whereNotNull('guest_key');
        }

        return $query->whereNotNull('user_id')->whereNull('guest_key');
    }
}
