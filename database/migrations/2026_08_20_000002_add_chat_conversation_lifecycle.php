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
            $table->timestamp('closed_at')->nullable()->after('last_message_at');
            $table->string('close_reason', 64)->nullable()->after('closed_at');
            $table->string('active_owner_key')->nullable()->after('close_reason');
        });

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_message_id')->nullable()->after('conversation_id');
            $table->foreign('reply_to_message_id')
                ->references('id')
                ->on('chat_messages')
                ->nullOnDelete();
            $table->unique('reply_to_message_id');
        });

        $this->backfillOpenOwnerKeysAndCloseDuplicates();

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->installSqliteInvariant();

            return;
        }

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            $this->installMariaDbInvariant();
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS chat_conversations_derive_active_owner_insert');
            DB::statement('DROP TRIGGER IF EXISTS chat_conversations_derive_active_owner_update');
            DB::statement('DROP INDEX IF EXISTS chat_conversations_active_owner_key_unique');
        } elseif (in_array($driver, ['mariadb', 'mysql'], true)) {
            DB::statement('ALTER TABLE chat_conversations DROP INDEX chat_conversations_active_owner_key_unique');
            DB::statement('ALTER TABLE chat_conversations MODIFY active_owner_key VARCHAR(255) NULL');
        }

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropForeign(['reply_to_message_id']);
            $table->dropUnique(['reply_to_message_id']);
            $table->dropColumn('reply_to_message_id');
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropColumn(['active_owner_key', 'close_reason', 'closed_at']);
        });
    }

    private function backfillOpenOwnerKeysAndCloseDuplicates(): void
    {
        $openConversations = DB::table('chat_conversations')
            ->select(['id', 'user_id', 'guest_key'])
            ->where('status', 'open')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $openOwnerKeys = [];

        foreach ($openConversations as $conversation) {
            $ownerKey = $conversation->user_id !== null
                ? 'user:'.$conversation->user_id
                : 'guest:'.$conversation->guest_key;

            if (isset($openOwnerKeys[$ownerKey])) {
                DB::table('chat_conversations')->where('id', $conversation->id)->update([
                    'status' => 'closed',
                    'closed_at' => now(),
                    'close_reason' => 'invariant_upgrade_duplicate',
                    'active_owner_key' => null,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $openOwnerKeys[$ownerKey] = true;

            DB::table('chat_conversations')->where('id', $conversation->id)->update([
                'active_owner_key' => $ownerKey,
            ]);
        }

        DB::table('chat_conversations')
            ->where('status', '!=', 'open')
            ->update(['active_owner_key' => null]);
    }

    private function installSqliteInvariant(): void
    {
        DB::statement('CREATE UNIQUE INDEX chat_conversations_active_owner_key_unique ON chat_conversations (active_owner_key)');

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
        DB::statement('ALTER TABLE chat_conversations ADD UNIQUE INDEX chat_conversations_active_owner_key_unique (active_owner_key)');
    }
};
