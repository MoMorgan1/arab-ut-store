<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('guest_key', 64)->nullable();
            $table->string('status', 32)->default('open');
            $table->string('locale', 5)->default('ar');
            $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'updated_at']);
            $table->index(['user_id', 'last_message_at']);
            $table->index(['guest_key', 'updated_at']);
            $table->index(['guest_key', 'last_message_at']);
        });

        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::unprepared(<<<'SQL'
                CREATE TRIGGER chk_chat_conversations_owner_insert
                BEFORE INSERT ON chat_conversations
                FOR EACH ROW
                WHEN NOT ((NEW.user_id IS NOT NULL AND NEW.guest_key IS NULL) OR (NEW.user_id IS NULL AND NEW.guest_key IS NOT NULL))
                BEGIN
                    SELECT RAISE(ABORT, 'ChatConversation must have exactly one of user_id or guest_key.');
                END;
            SQL);

            DB::unprepared(<<<'SQL'
                CREATE TRIGGER chk_chat_conversations_owner_update
                BEFORE UPDATE OF user_id, guest_key ON chat_conversations
                FOR EACH ROW
                WHEN NOT ((NEW.user_id IS NOT NULL AND NEW.guest_key IS NULL) OR (NEW.user_id IS NULL AND NEW.guest_key IS NOT NULL))
                BEGIN
                    SELECT RAISE(ABORT, 'ChatConversation must have exactly one of user_id or guest_key.');
                END;
            SQL);
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE chat_conversations ADD CONSTRAINT chk_chat_conversations_owner CHECK ((user_id IS NOT NULL AND guest_key IS NULL) OR (user_id IS NULL AND guest_key IS NOT NULL))');
        }

        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $table->string('client_message_id', 64)->nullable();
            $table->string('sender_type', 32);
            $table->string('message_type', 32)->default('text');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'client_message_id'], 'uq_chat_messages_client_id');
        });
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP TRIGGER IF EXISTS chk_chat_conversations_owner_insert');
            DB::statement('DROP TRIGGER IF EXISTS chk_chat_conversations_owner_update');
        }

        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
