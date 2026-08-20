<?php

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('lifecycle migration keeps latest duplicate owner thread open and derives one active key', function () {
    withLegacyChatDatabase(function (): void {
        $older = seedLegacyConversation(userId: 7, guestKey: null, lastMessageAt: now()->subHour());
        $newer = seedLegacyConversation(userId: 7, guestKey: null, lastMessageAt: now());

        chatLifecycleMigration()->up();

        expect(DB::table('chat_conversations')->where('id', $newer)->value('active_owner_key'))
            ->toBe('user:7')
            ->and(DB::table('chat_conversations')->where('id', $older)->value('status'))
            ->toBe('closed')
            ->and(DB::table('chat_conversations')->where('id', $older)->value('close_reason'))
            ->toBe('invariant_upgrade_duplicate');
    });
});

test('lifecycle migration derives guest owners and allows a closed owner key to be reused', function () {
    withLegacyChatDatabase(function (): void {
        $guestKey = str_repeat('a', 64);
        seedLegacyConversation(userId: null, guestKey: $guestKey, lastMessageAt: now()->subHour(), status: 'closed');
        $openConversation = seedLegacyConversation(userId: null, guestKey: $guestKey, lastMessageAt: now());

        chatLifecycleMigration()->up();

        expect(DB::table('chat_conversations')->where('id', $openConversation)->value('active_owner_key'))
            ->toBe("guest:{$guestKey}")
            ->and(DB::table('chat_conversations')->where('status', 'closed')->value('active_owner_key'))
            ->toBeNull();

        DB::table('chat_conversations')->where('id', $openConversation)->update(['status' => 'closed']);
        DB::table('chat_conversations')->insert(legacyConversationAttributes(
            userId: null,
            guestKey: $guestKey,
            lastMessageAt: now()->addMinute(),
        ));

        expect(DB::table('chat_conversations')->where('status', 'open')->value('active_owner_key'))
            ->toBe("guest:{$guestKey}");
    });
});

test('direct writes cannot create two open conversations for one owner', function () {
    withInstalledChatLifecycleDatabase(function (): void {
        DB::table('chat_conversations')->insert(legacyConversationAttributes(
            userId: 1,
            guestKey: null,
            lastMessageAt: now(),
        ));

        expect(fn () => DB::table('chat_conversations')->insert(legacyConversationAttributes(
            userId: 1,
            guestKey: null,
            lastMessageAt: now()->addMinute(),
        )))->toThrow(QueryException::class);
    });
});

test('lifecycle migration permits only one reply per message', function () {
    withInstalledChatLifecycleDatabase(function (): void {
        $conversationId = seedLegacyConversation(userId: 1, guestKey: null, lastMessageAt: now());
        $originalMessageId = seedLegacyMessage($conversationId, 'Original');
        seedLegacyMessage($conversationId, 'First reply', $originalMessageId);

        expect(fn () => seedLegacyMessage($conversationId, 'Second reply', $originalMessageId))
            ->toThrow(QueryException::class);
    });
});

test('lifecycle migration rollback restores the legacy schema and can be applied again', function () {
    withLegacyChatDatabase(function (): void {
        $migration = chatLifecycleMigration();
        $migration->up();

        expect(Schema::hasColumns('chat_conversations', ['active_owner_key', 'closed_at', 'close_reason']))
            ->toBeTrue()
            ->and(Schema::hasColumn('chat_messages', 'reply_to_message_id'))
            ->toBeTrue();

        $migration->down();

        expect(Schema::hasColumns('chat_conversations', ['active_owner_key', 'closed_at', 'close_reason']))
            ->toBeFalse()
            ->and(Schema::hasColumn('chat_messages', 'reply_to_message_id'))
            ->toBeFalse();

        $migration->up();

        expect(Schema::hasColumn('chat_conversations', 'active_owner_key'))->toBeTrue();
    });
});

test('lifecycle migration completes a real MariaDB down up and remigration lifecycle', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The generated-column lifecycle requires MariaDB/MySQL.');
    }

    DB::table('chat_messages')->delete();
    DB::table('chat_conversations')->delete();
    $migration = chatLifecycleMigration();
    $user = User::factory()->create();
    $invariantInstalled = true;

    try {
        $migration->down();
        $invariantInstalled = false;
        DB::table('chat_conversations')->insert(legacyConversationAttributes(
            userId: $user->id,
            guestKey: null,
            lastMessageAt: now(),
        ));

        $migration->up();
        $invariantInstalled = true;

        expect(DB::table('chat_conversations')->value('active_owner_key'))->toBe("user:{$user->id}");

        $migration->down();
        $invariantInstalled = false;
        expect(Schema::hasColumn('chat_conversations', 'active_owner_key'))->toBeFalse();

        $migration->up();
        $invariantInstalled = true;
        expect(DB::table('chat_conversations')->value('active_owner_key'))->toBe("user:{$user->id}");
    } finally {
        if (! $invariantInstalled) {
            $migration->up();
        }

        DB::table('chat_messages')->delete();
        DB::table('chat_conversations')->delete();
        $user->delete();
    }
});

function withLegacyChatDatabase(Closure $scenario): void
{
    $originalConnection = config('database.default');
    $databasePath = tempnam(sys_get_temp_dir(), 'chat-lifecycle-upgrade-');
    config()->set('database.connections.chat_lifecycle_upgrade', [
        'driver' => 'sqlite',
        'database' => $databasePath,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    DB::setDefaultConnection('chat_lifecycle_upgrade');

    try {
        createLegacyChatTables();
        $scenario();
    } finally {
        DB::disconnect('chat_lifecycle_upgrade');
        DB::setDefaultConnection($originalConnection);
        @unlink($databasePath);
    }
}

function withInstalledChatLifecycleDatabase(Closure $scenario): void
{
    withLegacyChatDatabase(function () use ($scenario): void {
        chatLifecycleMigration()->up();
        $scenario();
    });
}

function createLegacyChatTables(): void
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
        $table->timestamps();
    });

    Schema::create('chat_messages', function (Blueprint $table): void {
        $table->id();
        $table->ulid('public_id')->unique();
        $table->unsignedBigInteger('conversation_id');
        $table->string('client_message_id', 64)->nullable();
        $table->string('sender_type', 32);
        $table->string('message_type', 32)->default('text');
        $table->text('content');
        $table->json('metadata')->nullable();
        $table->timestamps();
    });
}

function seedLegacyConversation(?int $userId, ?string $guestKey, DateTimeInterface $lastMessageAt, string $status = 'open'): int
{
    return DB::table('chat_conversations')->insertGetId(legacyConversationAttributes(
        userId: $userId,
        guestKey: $guestKey,
        lastMessageAt: $lastMessageAt,
        status: $status,
    ));
}

/** @return array<string, mixed> */
function legacyConversationAttributes(?int $userId, ?string $guestKey, DateTimeInterface $lastMessageAt, string $status = 'open'): array
{
    return [
        'public_id' => (string) str()->ulid(),
        'user_id' => $userId,
        'guest_key' => $guestKey,
        'status' => $status,
        'locale' => 'ar',
        'last_message_at' => $lastMessageAt,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

function seedLegacyMessage(int $conversationId, string $content, ?int $replyToMessageId = null): int
{
    return DB::table('chat_messages')->insertGetId([
        'public_id' => (string) str()->ulid(),
        'conversation_id' => $conversationId,
        'sender_type' => 'customer',
        'message_type' => 'text',
        'content' => $content,
        'reply_to_message_id' => $replyToMessageId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function chatLifecycleMigration(): object
{
    return require database_path('migrations/2026_08_20_000002_add_chat_conversation_lifecycle.php');
}
