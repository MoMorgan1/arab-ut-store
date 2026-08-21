<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->timestamp('agent_eligible_at')->nullable()->after('metadata');
            $table->timestamp('agent_prompt_blocked_at')->nullable()->after('agent_eligible_at');
            $table->index(
                [
                    'conversation_id',
                    'sender_type',
                    'agent_prompt_blocked_at',
                    'agent_eligible_at',
                    'id',
                ],
                'idx_chat_messages_agent_claim',
            );
        });

        Schema::create('agent_turns', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('status', 32)->default('waiting');
            $table->unsignedBigInteger('first_customer_message_id');
            $table->unsignedBigInteger('last_customer_message_id');
            $table->foreignId('assistant_message_id')->nullable()->unique()
                ->constrained('chat_messages')->nullOnDelete();
            $table->timestamp('debounce_until');
            $table->string('prompt_version', 64);
            $table->unsignedTinyInteger('attempt_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('terminal_error_code', 64)->nullable();
            $table->unsignedBigInteger('active_conversation_key')->nullable();
            $table->timestamps();

            $table->unique(
                ['conversation_id', 'last_customer_message_id'],
                'uq_agent_turns_message_boundary',
            );
            $table->index(['conversation_id', 'id']);
            $table->index(['status', 'updated_at']);
        });

        $driver = DB::connection()->getDriverName();

        if (in_array($driver, ['mariadb', 'mysql'], true)) {
            $this->installMariaDbActiveTurnInvariant();
        } elseif ($driver === 'sqlite') {
            $this->installSqliteActiveTurnInvariant();
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS agent_turns_derive_active_conversation_insert');
            DB::statement('DROP TRIGGER IF EXISTS agent_turns_derive_active_conversation_update');
            DB::statement('DROP INDEX IF EXISTS uq_agent_turns_active_conversation');
        }

        Schema::dropIfExists('agent_turns');

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropIndex('idx_chat_messages_agent_claim');
            $table->dropColumn(['agent_prompt_blocked_at', 'agent_eligible_at']);
        });
    }

    private function installMariaDbActiveTurnInvariant(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE agent_turns
            MODIFY active_conversation_key BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                CASE
                    WHEN status IN ('waiting', 'running') THEN conversation_id
                    ELSE NULL
                END
            ) STORED,
            ADD UNIQUE INDEX uq_agent_turns_active_conversation (active_conversation_key)
            SQL);
    }

    private function installSqliteActiveTurnInvariant(): void
    {
        DB::statement('CREATE UNIQUE INDEX uq_agent_turns_active_conversation ON agent_turns (active_conversation_key)');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agent_turns_derive_active_conversation_insert
            AFTER INSERT ON agent_turns
            BEGIN
                UPDATE agent_turns
                SET active_conversation_key = CASE
                    WHEN NEW.status IN ('waiting', 'running') THEN NEW.conversation_id
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER agent_turns_derive_active_conversation_update
            AFTER UPDATE OF conversation_id, status, active_conversation_key ON agent_turns
            WHEN COALESCE(NEW.active_conversation_key, 0) <> COALESCE(
                CASE
                    WHEN NEW.status IN ('waiting', 'running') THEN NEW.conversation_id
                    ELSE NULL
                END,
                0
            )
            BEGIN
                UPDATE agent_turns
                SET active_conversation_key = CASE
                    WHEN NEW.status IN ('waiting', 'running') THEN NEW.conversation_id
                    ELSE NULL
                END
                WHERE id = NEW.id;
            END
            SQL);
    }
};
