<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('staff_user_id')->nullable()->after('sender_type')->constrained('users')->nullOnDelete();
            $table->index(['conversation_id', 'message_type']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropForeign(['staff_user_id']);
            $table->dropIndex(['conversation_id', 'message_type']);
            $table->dropColumn('staff_user_id');
        });
    }
};
