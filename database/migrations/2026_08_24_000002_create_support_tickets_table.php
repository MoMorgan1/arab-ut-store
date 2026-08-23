<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('ticket_number', 10)->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('subject', 160);
            $table->string('status', 16)->default('open');
            $table->string('priority', 16)->default('normal');
            $table->foreignId('assigned_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('user_id');
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(<<<'SQL'
                CREATE UNIQUE INDEX support_tickets_active_conversation_key_unique
                ON support_tickets (conversation_id)
                WHERE status = 'open'
                SQL);

            return;
        }

        DB::statement(<<<'SQL'
            ALTER TABLE support_tickets
            ADD COLUMN active_conversation_key BIGINT UNSIGNED
            GENERATED ALWAYS AS (
                CASE WHEN status = 'open' THEN conversation_id ELSE NULL END
            ) STORED,
            ADD UNIQUE INDEX support_tickets_active_conversation_key_unique (active_conversation_key)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
