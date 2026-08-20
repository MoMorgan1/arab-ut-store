<?php

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('lifecycle migration keeps the newest duplicate owner conversation open', function () {
    withLegacyChatDatabase(function (): void {
        $older = seedLegacyConversation(userId: 7, guestKey: null, lastMessageAt: now()->subHour());
        $newer = seedLegacyConversation(userId: 7, guestKey: null, lastMessageAt: now());

        chatLifecycleMigration()->up();

        expect(DB::table('chat_conversations')->where('id', $newer)->value('active_owner_key'))
            ->toBe('user:7')
            ->and(DB::table('chat_conversations')->where('id', $older)->value('status'))
            ->toBe('closed')
            ->and(DB::table('chat_conversations')->where('id', $older)->value('close_reason'))
            ->toBe('invariant_upgrade_duplicate')
            ->and(DB::table('chat_conversations')->where('id', $older)->value('closed_at'))
            ->not->toBeNull();
    });
});

test('lifecycle migration keeps the higher ID open when duplicate owner timestamps are equal', function () {
    withLegacyChatDatabase(function (): void {
        $lastMessageAt = now();
        $lowerId = seedLegacyConversation(userId: 8, guestKey: null, lastMessageAt: $lastMessageAt);
        $higherId = seedLegacyConversation(userId: 8, guestKey: null, lastMessageAt: $lastMessageAt);

        chatLifecycleMigration()->up();

        expect(DB::table('chat_conversations')->where('id', $higherId)->value('active_owner_key'))
            ->toBe('user:8')
            ->and(DB::table('chat_conversations')->where('id', $lowerId)->value('status'))
            ->toBe('closed')
            ->and(DB::table('chat_conversations')->where('id', $lowerId)->value('close_reason'))
            ->toBe('invariant_upgrade_duplicate');
    });
});

test('lifecycle migration derives guest ownership and permits a historical guest key to be reused', function () {
    withLegacyChatDatabase(function (): void {
        $guestKey = hash('sha256', 'historical-chat-guest-key');
        seedLegacyConversation(userId: null, guestKey: $guestKey, lastMessageAt: now(), status: 'closed');

        chatLifecycleMigration()->up();

        $newConversationId = seedLegacyConversation(
            userId: null,
            guestKey: $guestKey,
            lastMessageAt: now(),
        );

        expect(DB::table('chat_conversations')->where('guest_key', $guestKey)->count())
            ->toBe(2)
            ->and(DB::table('chat_conversations')->where('id', $newConversationId)->value('active_owner_key'))
            ->toBe("guest:{$guestKey}")
            ->and(DB::table('chat_conversations')->where('status', 'closed')->value('active_owner_key'))
            ->toBeNull();
    });
});

test('lifecycle migration rollback restores the legacy chat schema', function () {
    withLegacyChatDatabase(function (): void {
        chatLifecycleMigration()->up();
        chatLifecycleMigration()->down();

        expect(Schema::hasColumn('chat_conversations', 'active_owner_key'))->toBeFalse()
            ->and(Schema::hasColumn('chat_conversations', 'closed_at'))->toBeFalse()
            ->and(Schema::hasColumn('chat_conversations', 'close_reason'))->toBeFalse()
            ->and(Schema::hasColumn('chat_messages', 'reply_to_message_id'))->toBeFalse();
    });
});

test('direct writes cannot create two open conversations for one owner', function () {
    $user = User::factory()->create();
    ChatConversation::factory()->forUser($user)->create();

    expect(fn () => DB::table('chat_conversations')->insert([
        'public_id' => (string) str()->ulid(),
        'user_id' => $user->id,
        'guest_key' => null,
        'status' => 'open',
        'locale' => 'ar',
        'last_message_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a message can receive at most one reply', function () {
    $conversation = ChatConversation::factory()->create();
    $message = ChatMessage::factory()->for($conversation, 'conversation')->create();
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'reply_to_message_id' => $message->id,
    ]);

    expect(fn () => ChatMessage::factory()->for($conversation, 'conversation')->create([
        'reply_to_message_id' => $message->id,
    ]))->toThrow(QueryException::class);
});

test('the lifecycle migration completes a real MariaDB down up and remigration lifecycle', function () {
    if (! in_array(DB::connection()->getDriverName(), ['mariadb', 'mysql'], true)) {
        $this->markTestSkipped('The generated-column lifecycle requires MariaDB/MySQL.');
    }

    DB::table('chat_messages')->delete();
    DB::table('chat_conversations')->delete();
    $migration = chatLifecycleMigration();
    $installed = true;

    try {
        $migration->down();
        $installed = false;

        expect(Schema::hasColumn('chat_conversations', 'active_owner_key'))->toBeFalse();

        $migration->up();
        $installed = true;
        expect(Schema::hasColumn('chat_conversations', 'active_owner_key'))->toBeTrue();

        $migration->down();
        $installed = false;
        $migration->up();
        $installed = true;

        expect(Schema::hasColumn('chat_messages', 'reply_to_message_id'))->toBeTrue();
    } finally {
        if (! $installed) {
            $migration->up();
        }
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
            $table->index(['conversation_id', 'id']);
            $table->unique(['conversation_id', 'client_message_id'], 'uq_chat_messages_client_id');
        });

        $scenario();
    } finally {
        DB::disconnect('chat_lifecycle_upgrade');
        DB::setDefaultConnection($originalConnection);
        @unlink($databasePath);
    }
}

function seedLegacyConversation(
    ?int $userId,
    ?string $guestKey,
    DateTimeInterface $lastMessageAt,
    string $status = 'open',
): int {
    return (int) DB::table('chat_conversations')->insertGetId([
        'public_id' => (string) str()->ulid(),
        'user_id' => $userId,
        'guest_key' => $guestKey,
        'status' => $status,
        'locale' => 'ar',
        'last_message_at' => $lastMessageAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function chatLifecycleMigration(): object
{
    return require database_path('migrations/2026_08_20_000002_add_chat_conversation_lifecycle.php');
}
