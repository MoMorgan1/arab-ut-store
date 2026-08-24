<?php

use App\Support\ChatNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->string('short_id', 10)->nullable()->after('public_id');
            $table->string('handoff_state', 16)->default('none')->after('close_reason');
            $table->timestamp('last_staff_message_at')->nullable()->after('last_message_at');
        });

        // Backfill every existing row before the unique index goes on.
        DB::table('chat_conversations')->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('chat_conversations')
                    ->where('id', $row->id)
                    ->update(['short_id' => ChatNumber::generate()]);
            }
        });

        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->unique('short_id');
            $table->index('handoff_state');
        });

        // Tighten short_id to NOT NULL on MariaDB only.
        //
        // SQLite cannot alter a column in place: Laravel emulates ->change() by
        // creating a new table, copying the rows and dropping the original. That
        // drop takes the table's TRIGGERS with it — including
        // chat_conversations_derive_active_owner_insert/_update from
        // 2026_08_20_000002, which are the entire one-open-conversation-per-owner
        // invariant on SQLite. The invariant then fails open, silently, and only
        // in the test suite, which is exactly where it is supposed to be enforced.
        //
        // MariaDB alters in place and enforces the invariant with a generated
        // column rather than triggers, so it is safe there. On SQLite the column
        // stays nullable at the database level; ChatConversation's creating hook
        // guarantees a value on every row and the unique index above still holds.
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('chat_conversations', function (Blueprint $table): void {
                $table->string('short_id', 10)->nullable(false)->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('chat_conversations', function (Blueprint $table): void {
            $table->dropUnique(['short_id']);
            $table->dropIndex(['handoff_state']);
            $table->dropColumn(['short_id', 'handoff_state', 'last_staff_message_at']);
        });
    }
};
