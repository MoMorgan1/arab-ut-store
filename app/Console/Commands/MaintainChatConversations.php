<?php

namespace App\Console\Commands;

use App\Actions\Chat\CloseChatConversation;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

final class MaintainChatConversations extends Command
{
    protected $signature = 'chat:maintain-conversations';

    protected $description = 'Close inactive chat conversations and purge expired closed conversations';

    public function handle(CloseChatConversation $closeChatConversation): int
    {
        $closedCount = $this->closeInactiveConversations($closeChatConversation);
        $deletedCount = $this->purgeExpiredConversations();

        $this->components->info("Closed {$closedCount} inactive conversation(s).");
        $this->components->info("Deleted {$deletedCount} expired conversation(s).");

        return self::SUCCESS;
    }

    private function closeInactiveConversations(CloseChatConversation $closeChatConversation): int
    {
        $closedCount = 0;
        $cutoff = now()->subHours((int) config('chat.auto_close_hours'));

        ChatConversation::query()
            ->open()
            ->where('last_message_at', '<=', $cutoff)
            ->chunkById(200, function ($conversations) use ($closeChatConversation, $cutoff, &$closedCount): void {
                foreach ($conversations as $conversation) {
                    if ($closeChatConversation->closeIfInactive($conversation, $cutoff)) {
                        $closedCount++;
                    }
                }
            });

        return $closedCount;
    }

    private function purgeExpiredConversations(): int
    {
        $deletedCount = 0;

        $this->purgeExpiredConversationsForOwner(
            fn (Builder $query): Builder => $query->whereNull('user_id'),
            now()->subDays((int) config('chat.guest_retention_days')),
            $deletedCount,
        );
        $this->purgeExpiredConversationsForOwner(
            fn (Builder $query): Builder => $query->whereNotNull('user_id'),
            now()->subDays((int) config('chat.user_retention_days')),
            $deletedCount,
        );

        return $deletedCount;
    }

    /**
     * @param  callable(Builder<ChatConversation>): Builder<ChatConversation>  $ownerConstraint
     */
    private function purgeExpiredConversationsForOwner(callable $ownerConstraint, DateTimeInterface $cutoff, int &$deletedCount): void
    {
        $ownerConstraint(
            ChatConversation::query()
                ->where('status', 'closed')
                ->where('closed_at', '<=', $cutoff),
        )->chunkById(200, function ($conversations) use ($ownerConstraint, $cutoff, &$deletedCount): void {
            foreach ($conversations as $conversation) {
                $deletedCount += $ownerConstraint(
                    ChatConversation::query()
                        ->whereKey($conversation->id)
                        ->where('status', ChatConversationStatus::Closed)
                        ->where('closed_at', '<=', $cutoff),
                )->delete();
            }
        });
    }
}
