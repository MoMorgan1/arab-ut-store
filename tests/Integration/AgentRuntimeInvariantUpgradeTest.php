<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class);

test('agent runtime migration preserves legacy messages through sqlite rollback and remigration', function () {
    withLegacyAgentRuntimeDatabase(function (): void {
        assertAgentRuntimeLegacyMessageLifecycle();
    });
});

test('agent runtime migration preserves legacy messages through mariadb rollback and remigration', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The generated-column agent runtime invariant requires MariaDB/MySQL.');
    }

    DB::table('agent_runs')->delete();
    DB::table('agent_turns')->delete();
    DB::table('chat_messages')->delete();
    DB::table('chat_conversations')->delete();

    $turnMigration = agentRuntimeTurnMigration();
    $runMigration = agentRuntimeRunMigration();

    try {
        $runMigration->down();
        $turnMigration->down();

        assertAgentRuntimeLegacyMessageLifecycle();
    } finally {
        if (! Schema::hasTable('agent_turns')) {
            $turnMigration->up();
        }

        if (! Schema::hasTable('agent_runs')) {
            $runMigration->up();
        }

        DB::table('chat_messages')->delete();
        DB::table('chat_conversations')->delete();
    }
});

function assertAgentRuntimeLegacyMessageLifecycle(): void
{
    $conversationId = seedAgentRuntimeLegacyConversation();
    $demoCustomerId = seedAgentRuntimeLegacyMessage($conversationId, 'Legacy demo customer');
    $demoReplyId = seedAgentRuntimeLegacyMessage(
        $conversationId,
        'Legacy demo reply',
        $demoCustomerId,
        'assistant',
    );
    $unrepliedCustomerId = seedAgentRuntimeLegacyMessage($conversationId, 'Old unreplied customer');
    $migration = agentRuntimeTurnMigration();

    $migration->up();

    expect(Schema::hasColumns('chat_messages', ['agent_eligible_at', 'agent_prompt_blocked_at']))
        ->toBeTrue()
        ->and(Schema::hasIndex('chat_messages', 'idx_chat_messages_agent_claim'))->toBeTrue()
        ->and(DB::table('chat_messages')->whereIn('id', [$demoCustomerId, $unrepliedCustomerId])
            ->whereNotNull('agent_eligible_at')->exists())->toBeFalse()
        ->and(DB::table('chat_messages')->whereIn('id', [$demoCustomerId, $unrepliedCustomerId])
            ->whereNotNull('agent_prompt_blocked_at')->exists())->toBeFalse();

    $migration->down();

    expect(Schema::hasColumns('chat_messages', ['agent_eligible_at', 'agent_prompt_blocked_at']))
        ->toBeFalse()
        ->and(DB::table('chat_messages')->where('id', $demoCustomerId)->value('content'))
        ->toBe('Legacy demo customer')
        ->and(DB::table('chat_messages')->where('id', $unrepliedCustomerId)->value('content'))
        ->toBe('Old unreplied customer')
        ->and(DB::table('chat_messages')->where('id', $demoReplyId)->value('reply_to_message_id'))
        ->toBe($demoCustomerId);

    $migration->up();

    expect(Schema::hasIndex('chat_messages', 'idx_chat_messages_agent_claim'))->toBeTrue()
        ->and(DB::table('chat_messages')->whereIn('id', [$demoCustomerId, $unrepliedCustomerId])
            ->whereNotNull('agent_eligible_at')->exists())->toBeFalse()
        ->and(DB::table('chat_messages')->whereIn('id', [$demoCustomerId, $unrepliedCustomerId])
            ->whereNotNull('agent_prompt_blocked_at')->exists())->toBeFalse()
        ->and(DB::table('chat_messages')->where('id', $demoReplyId)->value('reply_to_message_id'))
        ->toBe($demoCustomerId);
}

function withLegacyAgentRuntimeDatabase(Closure $scenario): void
{
    $originalConnection = config('database.default');
    $databasePath = tempnam(sys_get_temp_dir(), 'agent-runtime-upgrade-');
    config()->set('database.connections.agent_runtime_upgrade', [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('agent_runtime_upgrade');

    try {
        createAgentRuntimeLegacyChatTables();
        $scenario();
    } finally {
        DB::disconnect('agent_runtime_upgrade');
        DB::setDefaultConnection($originalConnection);
        @unlink($databasePath);
    }
}

function createAgentRuntimeLegacyChatTables(): void
{
    Schema::create('chat_conversations', function (Blueprint $table): void {
        $table->id();
        $table->ulid('public_id')->unique();
        $table->unsignedBigInteger('user_id')->nullable();
        $table->string('guest_key', 64)->nullable();
        $table->string('status', 32)->default('open');
        $table->string('locale', 5)->default('ar');
        $table->string('subject')->nullable();
        $table->timestamp('last_message_at')->nullable();
        $table->timestamp('closed_at')->nullable();
        $table->string('close_reason', 64)->nullable();
        $table->string('active_owner_key')->nullable();
        $table->timestamps();
    });

    Schema::create('chat_messages', function (Blueprint $table): void {
        $table->id();
        $table->ulid('public_id')->unique();
        $table->unsignedBigInteger('conversation_id');
        $table->unsignedBigInteger('reply_to_message_id')->nullable()->unique();
        $table->string('client_message_id', 64)->nullable();
        $table->string('sender_type', 32);
        $table->string('message_type', 32)->default('text');
        $table->text('content');
        $table->json('metadata')->nullable();
        $table->timestamps();

        $table->foreign('conversation_id')->references('id')->on('chat_conversations')->cascadeOnDelete();
        $table->foreign('reply_to_message_id')->references('id')->on('chat_messages')->nullOnDelete();
        $table->unique(['conversation_id', 'client_message_id'], 'uq_chat_messages_client_id');
    });
}

function seedAgentRuntimeLegacyConversation(): int
{
    return DB::table('chat_conversations')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'user_id' => null,
        'guest_key' => str_repeat('c', 64),
        'status' => 'open',
        'locale' => 'ar',
        'last_message_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function seedAgentRuntimeLegacyMessage(
    int $conversationId,
    string $content,
    ?int $replyToMessageId = null,
    string $senderType = 'customer',
): int {
    return DB::table('chat_messages')->insertGetId([
        'public_id' => (string) Str::ulid(),
        'conversation_id' => $conversationId,
        'reply_to_message_id' => $replyToMessageId,
        'client_message_id' => $senderType === 'customer' ? (string) Str::uuid() : null,
        'sender_type' => $senderType,
        'message_type' => 'text',
        'content' => $content,
        'metadata' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function agentRuntimeTurnMigration(): object
{
    return require database_path('migrations/2026_08_21_000001_create_agent_turns_table.php');
}

function agentRuntimeRunMigration(): object
{
    return require database_path('migrations/2026_08_21_000002_create_agent_runs_table.php');
}
