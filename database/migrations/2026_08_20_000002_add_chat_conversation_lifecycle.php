<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason', 64)->nullable();
            $table->string('active_owner_key')->nullable();
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->constrained('chat_messages')
                ->nullOnDelete();
            $table->unique('reply_to_message_id', 'chat_messages_reply_to_message_id_unique');
        });

        $this->reconcileOpenConversations();
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteInvariant();
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->installMariaDbInvariant();
        }

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->unique('active_owner_key', 'chat_conversations_active_owner_key_unique');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS chat_conversations_derive_active_owner_insert');
            DB::statement('DROP TRIGGER IF EXISTS chat_conversations_derive_active_owner_update');
        }

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropUnique('chat_conversations_active_owner_key_unique');
            $table->dropColumn(['active_owner_key', 'closed_at', 'close_reason']);
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropForeign(['reply_to_message_id']);
            $table->dropUnique('chat_messages_reply_to_message_id_unique');
            $table->dropColumn('reply_to_message_id');
        });
    }

    private function reconcileOpenConversations(): void
    {
        $byOwner = [];

        foreach (DB::table('chat_conversations')->where('status', 'open')->cursor() as $conversation) {
            $ownerKey = $conversation->user_id !== null
                ? 'user:'.$conversation->user_id
                : 'guest:'.$conversation->guest_key;

            $byOwner[$ownerKey][] = $conversation;
        }

        foreach ($byOwner as $ownerKey => $conversations) {
            usort($conversations, static fn (object $left, object $right): int => [
                $right->last_message_at ?? '',
                (int) $right->id,
            ] <=> [
                $left->last_message_at ?? '',
                (int) $left->id,
            ]);

            $latest = array_shift($conversations);
            DB::table('chat_conversations')
                ->where('id', $latest->id)
                ->update(['active_owner_key' => $ownerKey]);

            foreach ($conversations as $conversation) {
                DB::table('chat_conversations')
                    ->where('id', $conversation->id)
                    ->update([
                        'status' => 'closed',
                        'closed_at' => now(),
                        'close_reason' => 'invariant_upgrade_duplicate',
                        'active_owner_key' => null,
                    ]);
            }
        }
    }

    private function installSqliteInvariant(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER chat_conversations_derive_active_owner_insert
            AFTER INSERT ON chat_conversations
            BEGIN
                UPDATE chat_conversations
                SET active_owner_key = CASE
                    WHEN NEW.status = 'open' AND NEW.user_id IS NOT NULL THEN 'user:' || NEW.user_id
                    WHEN NEW.status = 'open' AND NEW.guest_key IS NOT NULL THEN 'guest:' || NEW.guest_key
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER chat_conversations_derive_active_owner_update
            AFTER UPDATE OF user_id, guest_key, status, active_owner_key ON chat_conversations
            WHEN COALESCE(NEW.active_owner_key, '') <> COALESCE(
                CASE
                    WHEN NEW.status = 'open' AND NEW.user_id IS NOT NULL THEN 'user:' || NEW.user_id
                    WHEN NEW.status = 'open' AND NEW.guest_key IS NOT NULL THEN 'guest:' || NEW.guest_key
                    ELSE NULL
                END,
                ''
            )
            BEGIN
                UPDATE chat_conversations
                SET active_owner_key = CASE
                    WHEN NEW.status = 'open' AND NEW.user_id IS NOT NULL THEN 'user:' || NEW.user_id
                    WHEN NEW.status = 'open' AND NEW.guest_key IS NOT NULL THEN 'guest:' || NEW.guest_key
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);
    }

    private function installMariaDbInvariant(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE chat_conversations
            MODIFY active_owner_key VARCHAR(255)
            GENERATED ALWAYS AS (
                CASE
                    WHEN status = 'open' AND user_id IS NOT NULL THEN CONCAT('user:', user_id)
                    WHEN status = 'open' AND guest_key IS NOT NULL THEN CONCAT('guest:', guest_key)
                    ELSE NULL
                END
            ) STORED
            SQL);
    }
};
