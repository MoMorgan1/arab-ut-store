<?php

namespace App\Console\Commands;

use App\Enums\AI\AgentTurnStatus;
use App\Enums\Chat\ChatConversationCloseReason;
use App\Enums\Chat\ChatConversationStatus;
use App\Models\ChatConversation;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class MaintainChatConversations extends Command
{
    protected $signature = 'chat:maintain-conversations';

    protected $description = 'Close inactive chat conversations and purge expired closed conversations';

    public function handle(): int
    {
        $closedCount = $this->closeInactiveConversations();
        $deletedCount = $this->purgeExpiredConversations();

        $this->components->info("Closed {$closedCount} inactive conversation(s).");
        $this->components->info("Deleted {$deletedCount} expired conversation(s).");

        return self::SUCCESS;
    }

    private function closeInactiveConversations(): int
    {
        $closedCount = 0;
        $cutoff = now()->subHours((int) config('chat.auto_close_hours'));

        ChatConversation::query()
            ->open()
            ->where('last_message_at', '<=', $cutoff)
            ->whereDoesntHave('agentTurns', fn (Builder $turns): Builder => $turns
                ->whereIn('status', [AgentTurnStatus::Waiting, AgentTurnStatus::Running]))
            ->chunkById(200, function ($conversations) use ($cutoff, &$closedCount): void {
                foreach ($conversations as $conversation) {
                    if ($this->closeIfInactive($conversation, $cutoff)) {
                        $closedCount++;
                    }
                }
            });

        return $closedCount;
    }

    private function closeIfInactive(ChatConversation $conversation, DateTimeInterface $cutoff): bool
    {
        return DB::transaction(function () use ($conversation, $cutoff): bool {
            $lockedConversation = ChatConversation::query()
                ->whereKey($conversation->id)
                ->open()
                ->where('last_message_at', '<=', $cutoff)
                ->lockForUpdate()
                ->first();

            if (! $lockedConversation instanceof ChatConversation) {
                return false;
            }

            if ($lockedConversation->agentTurns()
                ->whereIn('status', [AgentTurnStatus::Waiting, AgentTurnStatus::Running])
                ->exists()) {
                return false;
            }

            $lockedConversation->forceFill([
                'status' => ChatConversationStatus::Closed,
                'closed_at' => now(),
                'close_reason' => ChatConversationCloseReason::Inactive,
            ])->save();

            return true;
        });
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
                ->whereLastActivityAtOrBefore($cutoff)
                ->whereDoesntHave('agentTurns', fn (Builder $turns): Builder => $turns
                    ->whereIn('status', [AgentTurnStatus::Waiting, AgentTurnStatus::Running])),
        )->chunkById(200, function ($conversations) use ($ownerConstraint, $cutoff, &$deletedCount): void {
            foreach ($conversations as $conversation) {
                $deletedCount += DB::transaction(function () use ($conversation, $cutoff, $ownerConstraint): int {
                    $lockedConversation = $ownerConstraint(
                        ChatConversation::query()
                            ->whereKey($conversation->id)
                            ->where('status', ChatConversationStatus::Closed)
                            ->whereLastActivityAtOrBefore($cutoff),
                    )->lockForUpdate()->first();

                    if (! $lockedConversation instanceof ChatConversation) {
                        return 0;
                    }

                    if ($lockedConversation->agentTurns()
                        ->whereIn('status', [AgentTurnStatus::Waiting, AgentTurnStatus::Running])
                        ->exists()) {
                        return 0;
                    }

                    return $lockedConversation->delete() ? 1 : 0;
                });
            }
        });
    }
}
