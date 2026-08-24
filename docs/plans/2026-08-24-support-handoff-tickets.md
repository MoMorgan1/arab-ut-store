# Support handoff, tickets and chat history — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let Mohamed answer customers inside the existing chat, backed by a durable ticket record, while guests stop being retained and customers regain access to their past conversations.

**Architecture:** Staff replies are ordinary `chat_messages` rows with a new `staff` sender type, so pagination, idempotency, cascades and retention keep working unchanged. A `support_tickets` record carries the customer-facing promise; `chat_conversations.handoff_state` caches ticket state for the hot path that decides whether Luna may answer. Luna is silenced at agent-turn **claim** time, not only at message-write time. Delivery is polling plus a synchronous email — this host has no queue worker and no websocket server.

**Tech Stack:** Laravel 13, Inertia + React 19, TypeScript, Tailwind v4, MariaDB (production) / SQLite (tests), Pest, Vitest, Playwright.

**Spec:** [`docs/decisions/2026-08-24-support-handoff-tickets-design.md`](../decisions/2026-08-24-support-handoff-tickets-design.md)

## Global Constraints

- **The orchestrator runs every gate.** Implementer agents are dispatched edit-only; they must not run shell commands, and must not commit. Every "run the test" step is executed by the orchestrator.
- **Gate command:** `npm run ci:check`, then the Playwright suite. Partial runs have historically hidden real failures in this repo.
- **The deployed assistant configuration is frozen.** No change to `resources/ai-assistant/prompts/support-v3.md`, `resources/ai-assistant/knowledge/arab-ut.json`, `config/ai-assistant.php` defaults, thresholds, guard, model, or token budgets. A change to any of them requires a fresh 16-case evaluation batch and a new owner acceptance.
- **Lock order is `conversation → ticket → turn → run`.** Never lock a ticket before its conversation.
- **"Live ticket" means `status = 'open'`** — everywhere: index, badge, unread dot, unread count, auto-close exemption.
- **A staff message leaves `reply_to_message_id` NULL.** That column is UNIQUE and `FinalizeAgentTurn` claims it.
- **`guest_key` is never serialised to any client payload.**
- **Touch targets:** every new interactive control is at least 44×44 CSS px at **all** widths. `tests/Browser/storefront-smoke.spec.ts` measures every admin control; never add `md:min-h-9` / `md:size-9` shrink overrides. If a nav entry is added, update the nav-link counts in that suite.
- **RTL/LTR:** verify Arabic and English at 320px, 390px, 768px and 1440px. Bubbles use `dir="auto"`.
- **Copy must not imply a response time.** No SLA, no working hours, no queue position. "The team will reply here" is the ceiling.
- **Both locales always.** Every user-visible string lands in `lang/ar/*.php` and `lang/en/*.php`. Arabic is authoritative when they disagree.
- **Short-number alphabet:** `23456789ABCDEFGHJKLMNPQRSTUVWXYZ`, six characters, prefix `CHT-` or `TKT-`.

---

# Lane A — schema and policy

Lane A merges first. Lanes B, C and D all depend on it and are independent of each other afterwards.

### Task 1: Short number generators

**Files:**
- Create: `app/Support/ChatNumber.php`
- Create: `app/Support/TicketNumber.php`
- Test: `tests/Unit/Support/ChatNumberTest.php`
- Test: `tests/Unit/Support/TicketNumberTest.php`
- Reference: `app/Checkout/OrderNumber.php` (the pattern being mirrored)

**Interfaces:**
- Consumes: nothing.
- Produces: `ChatNumber::generate(): string`, `ChatNumber::candidate(): string`, `ChatNumber::PREFIX`, `ChatNumber::PATTERN`; the same four on `TicketNumber`.

- [ ] **Step 1: Write the failing test for `ChatNumber`**

```php
<?php

use App\Models\ChatConversation;
use App\Support\ChatNumber;

it('generates a short id matching the documented pattern', function (): void {
    $number = ChatNumber::generate();

    expect($number)->toMatch(ChatNumber::PATTERN)
        ->and($number)->toStartWith('CHT-')
        ->and(strlen($number))->toBe(10);
});

it('never emits ambiguous characters', function (): void {
    for ($i = 0; $i < 200; $i++) {
        expect(ChatNumber::candidate())->not->toContain('0')
            ->and(ChatNumber::candidate())->not->toContain('O')
            ->and(ChatNumber::candidate())->not->toContain('1')
            ->and(ChatNumber::candidate())->not->toContain('I');
    }
});

it('does not reuse a short id already stored', function (): void {
    $taken = ChatNumber::candidate();
    ChatConversation::factory()->create(['short_id' => $taken]);

    // 50 draws is enough to make an accidental pass vanishingly unlikely.
    for ($i = 0; $i < 50; $i++) {
        expect(ChatNumber::generate())->not->toBe($taken);
    }
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Unit/Support/ChatNumberTest.php`
Expected: FAIL — `Class "App\Support\ChatNumber" not found`.

- [ ] **Step 3: Implement `ChatNumber`**

```php
<?php

namespace App\Support;

use App\Models\ChatConversation;
use RuntimeException;

/**
 * Short, human-friendly, non-sequential conversation numbers such as CHT-7K4QXM.
 *
 * Mirrors App\Checkout\OrderNumber: the alphabet omits 0/O and 1/I so numbers
 * survive being read aloud or typed from a screenshot, and uniqueness is
 * verified against the table before use.
 */
final class ChatNumber
{
    public const PREFIX = 'CHT-';

    public const LENGTH = 6;

    public const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    public const PATTERN = '/^CHT-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/';

    private const MAX_ATTEMPTS = 10;

    public static function generate(): string
    {
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = self::candidate();

            if (ChatConversation::query()->where('short_id', $candidate)->doesntExist()) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique conversation number.');
    }

    public static function candidate(): string
    {
        $alphabetLength = strlen(self::ALPHABET);
        $number = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $number .= self::ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return self::PREFIX.$number;
    }
}
```

- [ ] **Step 4: Write and implement `TicketNumber` identically**

Same file shape, with `PREFIX = 'TKT-'`, `PATTERN = '/^TKT-[23456789ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/'`, checking `SupportTicket::query()->where('ticket_number', $candidate)`, and throwing `'Unable to allocate a unique ticket number.'`. Write `tests/Unit/Support/TicketNumberTest.php` as the mirror of the `ChatNumber` test, using `SupportTicket::factory()`.

Note: `TicketNumber` references `SupportTicket`, created in Task 3. Write both files now; the `TicketNumber` test will not pass until Task 3 lands. That is expected and is the only intentional cross-task ordering in Lane A.

- [ ] **Step 5: Run the `ChatNumber` test and confirm it passes**

Run: `./vendor/bin/pest tests/Unit/Support/ChatNumberTest.php`
Expected: PASS. (It needs the `short_id` column from Task 2 — run this step after Task 2's migration if it fails on an unknown column.)

- [ ] **Step 6: Commit**

```bash
git add app/Support/ChatNumber.php app/Support/TicketNumber.php tests/Unit/Support
git commit -m "feat(support): short CHT- and TKT- numbers mirroring OrderNumber"
```

---

### Task 2: Conversation columns and backfill

**Files:**
- Create: `database/migrations/2026_08_24_000001_add_support_handoff_to_chat_conversations.php`
- Modify: `app/Models/ChatConversation.php`
- Create: `app/Enums/Chat/ChatHandoffState.php`
- Modify: `database/factories/ChatConversationFactory.php`
- Test: `tests/Feature/Support/ConversationHandoffColumnsTest.php`

**Interfaces:**
- Consumes: `ChatNumber::generate()` from Task 1.
- Produces: `ChatHandoffState` enum (`None`, `Offered`, `Requested`, `Active`, `Resolved`); `ChatConversation->short_id`, `->handoff_state` (cast to `ChatHandoffState`), `->last_staff_message_at` (cast to `datetime`); `ChatConversation::scopeWithLiveHandoff(Builder $query): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Chat\ChatHandoffState;
use App\Models\ChatConversation;
use App\Support\ChatNumber;

it('gives every conversation a unique short id', function (): void {
    $first = ChatConversation::factory()->create();
    $second = ChatConversation::factory()->create();

    expect($first->short_id)->toMatch(ChatNumber::PATTERN)
        ->and($second->short_id)->toMatch(ChatNumber::PATTERN)
        ->and($first->short_id)->not->toBe($second->short_id);
});

it('defaults handoff state to none', function (): void {
    expect(ChatConversation::factory()->create()->handoff_state)
        ->toBe(ChatHandoffState::None);
});

it('scopes to conversations a human currently owns', function (): void {
    ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::None]);
    ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Offered]);
    $requested = ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Requested]);
    $active = ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Active]);
    ChatConversation::factory()->create(['handoff_state' => ChatHandoffState::Resolved]);

    $ids = ChatConversation::query()->withLiveHandoff()->pluck('id')->all();

    expect($ids)->toHaveCount(2)
        ->and($ids)->toContain($requested->id)
        ->and($ids)->toContain($active->id);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Support/ConversationHandoffColumnsTest.php`
Expected: FAIL — unknown column `short_id`.

- [ ] **Step 3: Create the enum**

```php
<?php

namespace App\Enums\Chat;

enum ChatHandoffState: string
{
    case None = 'none';
    case Offered = 'offered';
    case Requested = 'requested';
    case Active = 'active';
    case Resolved = 'resolved';

    /**
     * States in which a human owns the conversation and Luna must stay silent.
     *
     * @return list<self>
     */
    public static function liveStates(): array
    {
        return [self::Requested, self::Active];
    }

    public function isLive(): bool
    {
        return in_array($this, self::liveStates(), true);
    }
}
```

- [ ] **Step 4: Write the migration**

Three columns, then a backfill, then the not-null tightening — all in one `up()`.

```php
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
        $table->string('short_id', 10)->nullable(false)->change();
        $table->unique('short_id');
        $table->index('handoff_state');
    });
}

public function down(): void
{
    Schema::table('chat_conversations', function (Blueprint $table): void {
        $table->dropUnique(['short_id']);
        $table->dropIndex(['handoff_state']);
        $table->dropColumn(['short_id', 'handoff_state', 'last_staff_message_at']);
    });
}
```

`ChatNumber::generate()` queries `chat_conversations.short_id`, which is nullable-and-empty during the backfill, so `doesntExist()` is only false for rows already backfilled in this loop. That is exactly the collision check we want.

- [ ] **Step 5: Assign `short_id` on creation and cast the new columns**

In `ChatConversation::booted()`, add a `creating` hook alongside the existing `saving` invariant:

```php
static::creating(function (ChatConversation $conversation): void {
    if ($conversation->short_id === null || $conversation->short_id === '') {
        $conversation->short_id = ChatNumber::generate();
    }
});
```

Add to `$casts`: `'handoff_state' => ChatHandoffState::class`, `'last_staff_message_at' => 'datetime'`.

Add the scope:

```php
/** @param Builder<ChatConversation> $query */
public function scopeWithLiveHandoff(Builder $query): void
{
    $query->whereIn('handoff_state', array_map(
        static fn (ChatHandoffState $state): string => $state->value,
        ChatHandoffState::liveStates(),
    ));
}
```

- [ ] **Step 6: Run the test and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Support/ConversationHandoffColumnsTest.php tests/Unit/Support/ChatNumberTest.php`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add database/migrations app/Models/ChatConversation.php app/Enums/Chat/ChatHandoffState.php database/factories/ChatConversationFactory.php tests/Feature/Support
git commit -m "feat(support): conversation short ids and handoff state"
```

---

### Task 3: `support_tickets` table and model

**Files:**
- Create: `database/migrations/2026_08_24_000002_create_support_tickets_table.php`
- Create: `app/Models/SupportTicket.php`
- Create: `app/Enums/Support/SupportTicketStatus.php`
- Create: `app/Enums/Support/SupportTicketPriority.php`
- Create: `database/factories/SupportTicketFactory.php`
- Modify: `app/Models/ChatConversation.php` (add `tickets()` and `liveTicket()`)
- Test: `tests/Feature/Support/SupportTicketSchemaTest.php`

**Interfaces:**
- Consumes: `TicketNumber::generate()` (Task 1), `chat_conversations` (Task 2).
- Produces: `SupportTicket` model; `SupportTicketStatus` (`Open`, `Resolved`, `Closed`); `SupportTicketPriority` (`Low`, `Normal`, `High`); `ChatConversation->tickets(): HasMany`, `ChatConversation->liveTicket(): HasOne`.

- [ ] **Step 1: Write the failing test — the reopen case is the one that matters**

```php
<?php

use App\Enums\Support\SupportTicketStatus;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\QueryException;

it('allows only one open ticket per conversation', function (): void {
    $conversation = ChatConversation::factory()->for(User::factory(), 'user')->create();
    SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    expect(fn () => SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]))->toThrow(QueryException::class);
});

it('frees the slot when a ticket is resolved so the customer can reopen', function (): void {
    $conversation = ChatConversation::factory()->for(User::factory(), 'user')->create();
    $first = SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    $first->update(['status' => SupportTicketStatus::Resolved, 'resolved_at' => now()]);

    $second = SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);

    expect($second->exists)->toBeTrue()
        ->and($second->ticket_number)->not->toBe($first->ticket_number);
});

it('frees the slot when a ticket is closed', function (): void {
    $conversation = ChatConversation::factory()->for(User::factory(), 'user')->create();
    $first = SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ]);
    $first->update(['status' => SupportTicketStatus::Closed, 'closed_at' => now()]);

    expect(SupportTicket::factory()->for($conversation, 'conversation')->create([
        'status' => SupportTicketStatus::Open,
    ])->exists)->toBeTrue();
});

it('cascades away with its conversation', function (): void {
    $conversation = ChatConversation::factory()->for(User::factory(), 'user')->create();
    SupportTicket::factory()->for($conversation, 'conversation')->create();

    $conversation->delete();

    expect(SupportTicket::query()->count())->toBe(0);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Support/SupportTicketSchemaTest.php`
Expected: FAIL — `Class "App\Models\SupportTicket" not found`.

- [ ] **Step 3: Write the migration, including the driver-specific invariant**

Base table first, then the `active_conversation_key` invariant per driver. This mirrors `2026_08_20_000002_add_chat_conversation_lifecycle.php`.

```php
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

    if (DB::getDriverName() === 'sqlite') {
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
```

**The `status = 'open'` condition is load-bearing.** Keying on "not closed" would leave a resolved ticket occupying the slot, making the "Still need help?" reopen a permanent duplicate-key 500 on any conversation that ever had a ticket resolved.

- [ ] **Step 4: Write the enums and the model**

```php
<?php

namespace App\Enums\Support;

enum SupportTicketStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Closed = 'closed';

    public function isLive(): bool
    {
        return $this === self::Open;
    }
}
```

`SupportTicketPriority` is `Low = 'low'`, `Normal = 'normal'`, `High = 'high'` with no helper.

`SupportTicket extends DomainModel` with casts `status => SupportTicketStatus::class`, `priority => SupportTicketPriority::class`, and `datetime` for `last_notified_at`, `resolved_at`, `closed_at`. A `creating` hook assigns `ticket_number` via `TicketNumber::generate()` when absent, matching Task 2's `short_id` hook. Relations: `conversation(): BelongsTo`, `user(): BelongsTo`, `assignedAdmin(): BelongsTo` (`assigned_admin_id`). Add `scopeLive(Builder $query): void` → `$query->where('status', SupportTicketStatus::Open)`.

Check how `public_id` is assigned on `ChatConversation`/`AgentTurn` (both are ULID public ids) and use the identical mechanism rather than inventing a second one.

On `ChatConversation`:

```php
/** @return HasMany<SupportTicket, $this> */
public function tickets(): HasMany
{
    return $this->hasMany(SupportTicket::class, 'conversation_id');
}

/** @return HasOne<SupportTicket, $this> */
public function liveTicket(): HasOne
{
    return $this->hasOne(SupportTicket::class, 'conversation_id')
        ->where('status', SupportTicketStatus::Open);
}
```

- [ ] **Step 5: Run the test and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Support/SupportTicketSchemaTest.php tests/Unit/Support/TicketNumberTest.php`
Expected: PASS — including both "frees the slot" cases.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Models/SupportTicket.php app/Enums/Support database/factories/SupportTicketFactory.php app/Models/ChatConversation.php tests/Feature/Support
git commit -m "feat(support): support_tickets with one-open-ticket-per-conversation"
```

---

### Task 4: Staff messages and internal notes

**Files:**
- Create: `database/migrations/2026_08_24_000003_add_staff_author_to_chat_messages.php`
- Modify: `app/Enums/Chat/ChatSenderType.php`, `app/Enums/Chat/ChatMessageType.php`
- Modify: `app/Models/ChatMessage.php`
- Modify: `app/Http/Presenters/ChatPresenter.php:60-80` (`loadBoundedMessages`)
- Test: `tests/Feature/Support/StaffMessageTest.php`

**Interfaces:**
- Consumes: Task 3.
- Produces: `ChatSenderType::Staff`, `ChatMessageType::InternalNote`, `ChatMessage->staffUser(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Enums\Chat\ChatMessageType;
use App\Enums\Chat\ChatSenderType;
use App\Http\Presenters\ChatPresenter;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;

it('never returns an internal note in the customer payload', function (): void {
    $conversation = ChatConversation::factory()->for(User::factory(), 'user')->create();
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'content' => 'visible to the customer',
    ]);
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Staff,
        'message_type' => ChatMessageType::InternalNote,
        'staff_user_id' => User::factory()->create()->id,
        'content' => 'SECRET-OPERATOR-NOTE',
    ]);

    $loaded = app(ChatPresenter::class)->loadBoundedMessages($conversation);
    $payload = json_encode($loaded['messages']->all(), JSON_THROW_ON_ERROR);

    expect($payload)->not->toContain('SECRET-OPERATOR-NOTE')
        ->and($payload)->toContain('visible to the customer');
});

it('rejects a staff message without a staff author', function (): void {
    $conversation = ChatConversation::factory()->for(User::factory(), 'user')->create();

    expect(fn () => ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Staff,
        'staff_user_id' => null,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a staff author on a non-staff message', function (): void {
    $conversation = ChatConversation::factory()->for($c = User::factory(), 'user')->create();

    expect(fn () => ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'staff_user_id' => User::factory()->create()->id,
    ]))->toThrow(InvalidArgumentException::class);
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Support/StaffMessageTest.php`
Expected: FAIL — unknown column `staff_user_id`.

- [ ] **Step 3: Migration, enum cases, model invariant**

Migration adds `$table->foreignId('staff_user_id')->nullable()->after('sender_type')->constrained('users')->nullOnDelete();` and `$table->index(['conversation_id', 'message_type']);`.

`ChatSenderType` gains `case Staff = 'staff';`. `ChatMessageType` gains `case InternalNote = 'internal_note';`.

`ChatMessage::booted()` gains the paired invariant, mirroring the style of `ChatConversation`'s owner invariant:

```php
static::saving(function (ChatMessage $message): void {
    $isStaff = $message->sender_type === ChatSenderType::Staff;
    $hasStaffUser = $message->staff_user_id !== null;

    if ($isStaff !== $hasStaffUser) {
        throw new \InvalidArgumentException(
            'A staff chat message must have exactly one staff author, and only a staff message may have one.'
        );
    }

    if ($isStaff && $message->reply_to_message_id !== null) {
        throw new \InvalidArgumentException(
            'A staff chat message must not claim reply_to_message_id; that column is reserved for agent turns.'
        );
    }
});
```

The `reply_to_message_id` guard is not cosmetic: the column is UNIQUE and `FinalizeAgentTurn` writes it, so a staff reply claiming it would kill an in-flight Luna turn at finalization and would silently strip customer messages from future claims via `PendingAgentMessages`' `whereDoesntHave('reply')`.

- [ ] **Step 4: Filter notes at the query level**

In `ChatPresenter::loadBoundedMessages`, add to the base query and to the `beforeMessage` lookup:

```php
->where('message_type', '!=', ChatMessageType::InternalNote)
```

Filter in the **query**, not in the `map` step and not in the browser — a note must never be serialised into a customer payload in the first place.

- [ ] **Step 5: Run the test and confirm it passes**

Run: `./vendor/bin/pest tests/Feature/Support/StaffMessageTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations app/Enums/Chat app/Models/ChatMessage.php app/Http/Presenters/ChatPresenter.php tests/Feature/Support
git commit -m "feat(support): staff messages and server-side internal notes"
```

---

### Task 5: Permission, guest retention, and inbox exclusion

**Files:**
- Modify: `app/Enums/AdminPermission.php`
- Modify: `config/chat.php`
- Modify: `.env.example`
- Modify: `app/Console/Commands/MaintainChatConversations.php`
- Modify: `app/Http/Controllers/Admin/ConversationsController.php`
- Modify: `app/Http/Controllers/Admin/ConversationDetailController.php`
- Modify: `app/Http/Requests/Admin/ListAdminConversations.php`
- Test: `tests/Feature/Support/GuestRetentionTest.php`
- Test: `tests/Feature/Support/AdminInboxGuestExclusionTest.php`

**Interfaces:**
- Consumes: Tasks 2 and 3.
- Produces: `AdminPermission::ChatReply`; `config('chat.guest_retention_hours')`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Enums\Chat\ChatConversationStatus;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\AI\AgentTurnStatus;
use App\Models\AgentTurn;
use App\Models\ChatConversation;
use App\Models\SupportTicket;
use App\Models\User;

it('deletes an open guest conversation once it passes the hour window', function (): void {
    config(['chat.guest_retention_hours' => 48]);
    $guest = ChatConversation::factory()->guest()->create([
        'status' => ChatConversationStatus::Open,
        'last_message_at' => now()->subHours(49),
    ]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect(ChatConversation::query()->whereKey($guest->id)->exists())->toBeFalse();
});

it('keeps a guest conversation that is still inside the window', function (): void {
    config(['chat.guest_retention_hours' => 48]);
    $guest = ChatConversation::factory()->guest()->create([
        'last_message_at' => now()->subHours(47),
    ]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect(ChatConversation::query()->whereKey($guest->id)->exists())->toBeTrue();
});

it('refuses to purge a guest conversation with a nonterminal agent turn', function (): void {
    config(['chat.guest_retention_hours' => 48]);
    $guest = ChatConversation::factory()->guest()->create([
        'last_message_at' => now()->subHours(72),
    ]);
    AgentTurn::factory()->for($guest, 'conversation')->create(['status' => AgentTurnStatus::Running]);

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect(ChatConversation::query()->whereKey($guest->id)->exists())->toBeTrue();
});

it('does not auto-close a conversation a human currently owns', function (): void {
    $conversation = ChatConversation::factory()->for(User::factory(), 'user')->create([
        'status' => ChatConversationStatus::Open,
        'handoff_state' => ChatHandoffState::Active,
        'last_message_at' => now()->subHours(48),
    ]);
    SupportTicket::factory()->for($conversation, 'conversation')->create();

    $this->artisan('chat:maintain-conversations')->assertSuccessful();

    expect($conversation->fresh()->status)->toBe(ChatConversationStatus::Open);
});
```

And the inbox test:

```php
it('never lists a guest conversation', function (): void {
    ChatConversation::factory()->guest()->create();
    $customer = ChatConversation::factory()->for(User::factory(), 'user')->create();

    $this->actingAs(adminUser())
        ->get('/admin/conversations')
        ->assertInertia(fn ($page) => $page
            ->has('rows', 1)
            ->where('rows.0.publicId', $customer->public_id));
});

it('404s on a guest transcript', function (): void {
    $guest = ChatConversation::factory()->guest()->create();

    $this->actingAs(adminUser())
        ->get("/admin/conversations/{$guest->public_id}")
        ->assertNotFound();
});
```

Reuse whatever admin-actor helper the existing admin feature tests use (see `tests/Feature/Admin/`) rather than introducing a new `adminUser()` helper.

- [ ] **Step 2: Run them and confirm they fail**

Run: `./vendor/bin/pest tests/Feature/Support/GuestRetentionTest.php tests/Feature/Support/AdminInboxGuestExclusionTest.php`
Expected: FAIL.

- [ ] **Step 3: Config and permission**

In `config/chat.php`, **replace** `'guest_retention_days'` with:

```php
'guest_retention_hours' => (int) env('CHAT_GUEST_RETENTION_HOURS', 48),
```

Remove the old key entirely rather than leaving it dangling. Add `CHAT_GUEST_RETENTION_HOURS=48` to `.env.example` and delete `CHAT_GUEST_RETENTION_DAYS` if present. Grep for `guest_retention_days` across the repo and update every reader.

In `AdminPermission`, add `case ChatReply = 'chat.reply';` after `ChatView`. **Do not** touch `AdminAccess::STAFF` — `Admin => true` already covers it, and adding it to the staff allowlist would hand transcripts to staff.

- [ ] **Step 4: Rework the maintenance command**

Two changes in `MaintainChatConversations`:

`closeInactiveConversations()` — add an exemption alongside the existing nonterminal-turn refusal, in both the outer query and the re-check inside `closeIfInactive`'s lock:

```php
->whereDoesntHave('tickets', fn (Builder $tickets): Builder => $tickets
    ->where('status', SupportTicketStatus::Open))
->where(fn (Builder $q): Builder => $q->whereNotIn('handoff_state', [
    ChatHandoffState::Requested->value,
    ChatHandoffState::Active->value,
]))
```

`purgeExpiredConversations()` — split the two owner branches so the guest branch uses hours and drops the `status = closed` filter:

```php
private function purgeExpiredConversations(): int
{
    $deletedCount = 0;

    // Guests: hours, and an *open* thread is purged too.
    $this->purgeExpiredConversationsForOwner(
        fn (Builder $query): Builder => $query->whereNull('user_id'),
        now()->subHours((int) config('chat.guest_retention_hours')),
        requireClosed: false,
        deletedCount: $deletedCount,
    );

    // Authenticated: unchanged — closed only, 180 days.
    $this->purgeExpiredConversationsForOwner(
        fn (Builder $query): Builder => $query->whereNotNull('user_id'),
        now()->subDays((int) config('chat.user_retention_days')),
        requireClosed: true,
        deletedCount: $deletedCount,
    );

    return $deletedCount;
}
```

Thread `requireClosed` through `purgeExpiredConversationsForOwner`, applying `->where('status', ChatConversationStatus::Closed)` only when true — in **both** the outer query and the locked re-read, exactly as the existing code does for the cutoff. Keep every other guarantee untouched: per-row `lockForUpdate`, cutoff re-check under the lock, and the nonterminal-agent-turn refusal. Add the live-ticket exemption to the authenticated branch too.

- [ ] **Step 5: Exclude guests from the inbox**

In `ConversationsController`, add `->whereNotNull('user_id')` to the base query unconditionally, and delete the `$filters['owner'] === 'guest'` branch. Remove the `guest` entry from `filterOptions.owners`.

In `ListAdminConversations`, change the rule to `Rule::in(['customer'])` and make `normalizedFilters()` normalise anything other than `'customer'` to `null`, so a stale `?owner=guest` bookmark degrades to "all customers" instead of erroring.

In `ConversationDetailController`, add `->whereNotNull('user_id')` to the lookup so a guest transcript 404s.

Update `lang/{ar,en}/admin.php` `conversations.description` — it currently says "with customers and guests"; guests are no longer listed.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support/`
Expected: PASS.

- [ ] **Step 7: Run the full gate — Lane A is a merge point**

Run: `npm run ci:check`
Expected: PASS. Then the Playwright suite.

- [ ] **Step 8: Commit**

```bash
git add -A
git commit -m "feat(support): chat.reply permission, 48h guest retention, customers-only inbox"
```

---

# Lane B — escalation and the assistant's silence

Depends on Lane A being merged.

### Task 6: نواف stays silent at claim time

This is the single most important task in the plan. Do it first in Lane B.

**Files:**
- Modify: `app/Actions/AI/CreateOrRecoverAgentTurn.php` (`claimPendingRange`, ~line 55)
- Modify: `app/Actions/Chat/CreateChatMessage.php:55`
- Test: `tests/Feature/Support/LunaSilentDuringHandoffTest.php`

**Interfaces:**
- Consumes: `ChatHandoffState` (Task 2).
- Produces: no new API; behavioural guarantee only.

- [ ] **Step 1: Write the failing test — cover the backlog path, not just the write path**

```php
<?php

use App\Actions\AI\CreateOrRecoverAgentTurn;
use App\Enums\Chat\ChatHandoffState;
use App\Enums\Chat\ChatSenderType;
use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\User;
use App\ValueObjects\Chat\ChatOwner;

it('does not claim a message that was already eligible when the human took over', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->for($user, 'user')->create();

    // Eligible message written BEFORE takeover — agent_eligible_at is immutable.
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'agent_eligible_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(5),
    ]);

    $conversation->update(['handoff_state' => ChatHandoffState::Active]);

    $claim = app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation->fresh(), ChatOwner::forUser($user));

    expect($claim->isIdle())->toBeTrue();
});

it('claims again once the ticket is resolved', function (): void {
    $user = User::factory()->create();
    $conversation = ChatConversation::factory()->for($user, 'user')->create([
        'handoff_state' => ChatHandoffState::Resolved,
    ]);
    ChatMessage::factory()->for($conversation, 'conversation')->create([
        'sender_type' => ChatSenderType::Customer,
        'agent_eligible_at' => now()->subMinutes(5),
        'created_at' => now()->subMinutes(5),
    ]);

    expect(app(CreateOrRecoverAgentTurn::class)
        ->execute($conversation->fresh(), ChatOwner::forUser($user))
        ->isIdle())->toBeFalse();
});

it('does not mark a message eligible while a human owns the thread', function (): void {
    // ... create the conversation with handoff_state = Active, call CreateChatMessage,
    // assert the persisted message has agent_eligible_at === null.
});
```

Check `AgentTurnClaim`'s real accessor names before writing `isIdle()`; use whatever it exposes.

- [ ] **Step 2: Run it and confirm the first test fails**

Run: `./vendor/bin/pest tests/Feature/Support/LunaSilentDuringHandoffTest.php`
Expected: FAIL — a turn is created, because nothing in the claim path reads `handoff_state`.

- [ ] **Step 3: Add the claim-time guard**

At the very top of `CreateOrRecoverAgentTurn::claimPendingRange`, before the cursor query:

```php
private function claimPendingRange(ChatConversation $conversation): AgentTurnClaim
{
    // A human owns this conversation. The write-time gate in CreateChatMessage is
    // a fast path only: agent_eligible_at is stamped at insert and is immutable,
    // so a message written moments before takeover stays eligible forever, and the
    // widget re-starts turns on backlog resume when a conversation loads. Without
    // this re-check Luna answers on top of the human, mid-ticket.
    if ($conversation->handoff_state->isLive()) {
        return AgentTurnClaim::idle();
    }

    $cursor = (int) (AgentTurn::query()
        // ... unchanged
```

`$conversation` here is already locked by `lockConversation()` in `claimInTransaction`, so this adds no new lock and does not change the lock order.

- [ ] **Step 4: Add the write-time fast path**

In `CreateChatMessage`, change the eligibility expression:

```php
'agent_eligible_at' => $assistantMode === AssistantMode::Agent
    && ! $lockedConversation->handoff_state->isLive()
        ? now()
        : null,
```

Also skip the demo reply while a human owns the thread — a canned demo bubble landing between two human messages is worse than nothing:

```php
if ($assistantMode === AssistantMode::Demo && ! $lockedConversation->handoff_state->isLive()) {
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support/LunaSilentDuringHandoffTest.php tests/Feature/AI/ tests/Integration/`
Expected: PASS, with no regression in the existing agent-turn suites.

- [ ] **Step 6: Commit**

```bash
git add app/Actions/AI/CreateOrRecoverAgentTurn.php app/Actions/Chat/CreateChatMessage.php tests/Feature/Support/LunaSilentDuringHandoffTest.php
git commit -m "fix(support): silence Luna at claim time while a human owns the thread"
```

---

### Task 7: The customer asks for a person

**Owner decision 2026-08-24:** nothing auto-offers. A handoff starts only from a
lexical match on the customer's own words, or the always-available control.
Triggers 2, 3 and 4 from the first draft are cut, and with them the whole
recomputed-knowledge-selection mechanism.

**Files:**
- Create: `app/Support/HandoffPhrases.php`
- Modify: `app/Actions/Chat/CreateChatMessage.php` (one call site)
- Test: `tests/Feature/Support/HandoffPhrasesTest.php`
- Test: `tests/Feature/Support/HandoffRequestTest.php`

**Interfaces:**
- Consumes: `ChatHandoffState` (Task 2), `OpenSupportTicket` (Task 8).
- Produces: `HandoffPhrases::matches(string $text): bool`.

- [ ] **Step 1: Write the failing matcher test**

```php
<?php

use App\Support\HandoffPhrases;

it('matches a customer asking for a person', function (string $text): void {
    expect(HandoffPhrases::matches($text))->toBeTrue();
})->with([
    'أبي أكلم موظف',
    'ودني على خدمة العملاء',
    'أبي أتكلم مع شخص حقيقي',
    'الدعم لو سمحت',
    'الدّعم لو سمحت',
    'ابي احد من الفريق',
    'can I talk to a human',
    'let me speak to someone',
    'I want a real person',
    'connect me to an agent',
]);

it('does not match an ordinary question', function (string $text): void {
    expect(HandoffPhrases::matches($text))->toBeFalse();
})->with([
    'كم سعر ٥٠٠ ألف كوينز؟',
    'متى يوصل طلبي؟',
    'ابي فوت شامبيونز رانك 1',
    'how long does delivery take',
    'is the service available on Xbox',
    'do you have a management page',
]);
```

The `الدّعم` case (shadda) and the `management` case (must not fire `agent`) are
the two that fail on a naive implementation. They are the point of the test.

- [ ] **Step 2: Run it and confirm it fails**

Run: `./vendor/bin/pest tests/Feature/Support/HandoffPhrasesTest.php`
Expected: FAIL — `Class "App\Support\HandoffPhrases" not found`.

- [ ] **Step 3: Implement the matcher**

Two `private const` lists, Arabic and Latin, so the vocabulary is reviewable in
one place. Normalise before comparing: strip tatweel (`\x{0640}`) and the
diacritic range (`\x{064B}-\x{0652}`), fold the alef family (`أإآ` → `ا`), fold
`ة` → `ه`, lowercase. Latin entries match on word boundaries (`\b`) so `agent`
cannot fire inside `management`; Arabic entries match as substrings after
normalisation. Use `preg_quote` on every needle.

Arabic list: `موظف`, `خدمة العملاء`, `بشري`, `شخص حقيقي`, `الدعم`,
`احد من الفريق`.
Latin list: `human`, `agent`, `real person`, `support team`, `representative`,
`talk to someone`, `speak to someone`.

- [ ] **Step 4: Wire the one call site**

In `CreateChatMessage`, after the customer message is persisted and inside the
same transaction: when `HandoffPhrases::matches($content)` is true, the
conversation's `handoff_state` is not already live, **and the owner is
authenticated**, open the ticket through `OpenSupportTicket` and set
`handoff_state = requested`.

A guest never gets a ticket here — the widget shows the login variant and
`POST /chat/conversations/{conversation}/ticket` returns 403 regardless of what
the client sent. Assert both.

Do **not** call this from `FinalizeAgentTurn` or `RecoverStaleAgentTurns`. A
failed or abandoned turn must not conjure a ticket; that is precisely the
auto-offer the owner cut.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support tests/Feature/AI tests/Feature/Chat`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Support/HandoffPhrases.php app/Actions/Chat/CreateChatMessage.php tests/Feature/Support
git commit -m "feat(support): a customer asking for a person opens a ticket"
```

---

### Task 8: Ticket lifecycle actions

**Files:**
- Create: `app/Actions/Support/OpenSupportTicket.php`
- Create: `app/Actions/Support/ResolveSupportTicket.php`
- Create: `app/Http/Controllers/Chat/SupportTicketController.php`
- Modify: `routes/chat.php`
- Test: `tests/Feature/Support/OpenSupportTicketTest.php`
- Test: `tests/Integration/SupportTicketConcurrencyTest.php`

**Interfaces:**
- Consumes: Tasks 2, 3, 6.
- Produces: `OpenSupportTicket::execute(ChatConversation $c, User $customer, ?User $staff, string $openedVia): SupportTicket`; `ResolveSupportTicket::execute(SupportTicket $t, User $staff): SupportTicket`.

- [ ] **Step 1: Write the failing tests**

Cover: a guest owner gets `403` with code `handoff_requires_login`; a customer opens a ticket and the conversation moves to `Requested`; opening twice returns the same ticket; two concurrent opens produce exactly one ticket (integration test, following `tests/Integration/AgentTurnConcurrencyTest.php`); resolving sets `resolved_at`, moves the conversation to `Resolved`, and posts the bilingual "Luna is back" system message; and after resolving, a new ticket can be opened.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Support/OpenSupportTicketTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement the actions with the documented lock order**

Every ticket write opens its transaction by locking the **conversation** first, then re-reads and locks the ticket:

```php
return DB::transaction(function () use ($conversationId, $customer, $staff, $openedVia): SupportTicket {
    // conversation -> ticket -> turn -> run. Never the reverse: a ticket-addressed
    // PATCH that locked the ticket first would deadlock against a concurrent reply.
    $conversation = ChatConversation::query()
        ->whereKey($conversationId)
        ->lockForUpdate()
        ->firstOrFail();

    $existing = SupportTicket::query()
        ->where('conversation_id', $conversation->id)
        ->where('status', SupportTicketStatus::Open)
        ->lockForUpdate()
        ->first();

    if ($existing instanceof SupportTicket) {
        return $existing;
    }

    // ... create, derive subject, set handoff_state in the same transaction
});
```

`handoff_state` and `support_tickets.status` change **together, in one transaction, under the conversation lock**. The ticket status is authoritative; `handoff_state` is a cache for the claim hot path.

Subject derivation: the conversation's first customer message, trimmed, truncated to 160 characters on a word boundary; fall back to the localized `chat.support.defaultSubject` when there is no customer message.

`SupportTicketController::store` resolves the owner via `ResolveChatOwner`, returns `403 handoff_requires_login` when `$owner->userId() === null`, and otherwise delegates. Register the route in `routes/chat.php` inside the existing middleware group:

```php
Route::post('/chat/conversations/{conversation}/ticket', [SupportTicketController::class, 'store'])
    ->middleware([SetChatLocale::class, 'throttle:chat-conversations'])
    ->name('chat.tickets.store');
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support/ tests/Integration/SupportTicketConcurrencyTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Support app/Http/Controllers/Chat/SupportTicketController.php routes/chat.php tests
git commit -m "feat(support): ticket open and resolve with conversation-first locking"
```

---

### Task 9: Login claim must not bury a ticketed thread

**Files:**
- Modify: `app/Actions/Chat/ClaimGuestChatConversations.php:35-55`
- Test: `tests/Feature/Support/ClaimGuestKeepsTicketedThreadTest.php`

**Interfaces:**
- Consumes: Task 2.
- Produces: no new API.

- [ ] **Step 1: Write the failing test**

```php
it('keeps the user thread when it carries a live handoff', function (): void {
    // Given: the user already has an open conversation in handoff_state = Active
    //   with a live ticket, and a guest conversation also exists for the session.
    // When: the guest conversations are claimed at login.
    // Then: the user's conversation is still open, still Active, still has its
    //   ticket, and the guest conversation is the one that gets closed.
});
```

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Support/ClaimGuestKeepsTicketedThreadTest.php`
Expected: FAIL — the user conversation is closed with `SupersededByLoginClaim`.

- [ ] **Step 3: Invert the winner selection for ticketed threads**

Before the existing winner logic, short-circuit:

```php
// A conversation a human is actively handling outranks any guest thread. Closing
// it would leave a live ticket pointing at a closed conversation nobody can post to.
if ($userConversation instanceof ChatConversation && $userConversation->handoff_state->isLive()) {
    // Keep the user conversation as the winner and close the guest threads instead.
}
```

Close the guest conversations with the existing `SupersededByLoginClaim` reason and still rekey their history to the user, so nothing is orphaned.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support/ tests/Feature/Chat/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Chat/ClaimGuestChatConversations.php tests/Feature/Support/ClaimGuestKeepsTicketedThreadTest.php
git commit -m "fix(support): login claim never buries a conversation under handoff"
```

---

# Design gate — required before any frontend task

**Mohamed requires a design canvas for all frontend work (2026-08-24).** Tasks
11, 12, 13, 14 and 16 are blocked until he approves the canvas. Backend tasks
(Lane A, and Lane B tasks 6-9) are not blocked and proceed in parallel.

### Task D0: Design canvas for every new surface

**Files:**
- Create: a multi-artboard design canvas published as an Artifact

**Artboards required:**

| # | Surface | Must show |
| --- | --- | --- |
| 1 | Admin inbox list | `CHT-` short id replacing the ULID, ticket badge, unread dot, unread-first ordering, revised filters with no guest option — desktop table and mobile card |
| 2 | Admin transcript detail | Reply composer, internal-note toggle, Take over, Resolve, ticket panel, staff vs Luna vs customer bubbles |
| 3 | Customer widget — offered | The "Talk to the team" chip in the thread, and the guest variant "Log in to reach the team" |
| 4 | Customer widget — requested/active/resolved | All three banner states, staff bubble with name, "Still need help?" on resolved |
| 5 | Customer widget — home with history | "Previous conversations" list, entry states, and the empty/guest case |
| 6 | Admin nav | Unread badge placement and count treatment |

Every artboard is shown in **Arabic RTL at 390px and English LTR at 1440px**.
Copy is real bilingual copy, not lorem — and must carry no time promise.
The canvas reproduces the existing Arab UT visual language (warm black/gold,
Thmanyah typography, the shipped widget and admin shell), per the AGENTS.md UI
gate; improvements refine that identity rather than replace it.

- [ ] **Step 1: Build the canvas from the shipped UI, not from imagination**

Read the current `resources/js/pages/admin/conversations/*.tsx`,
`resources/js/components/chat/*.tsx` and the admin shell first, and match their
spacing, radii, colour tokens and component vocabulary.

- [ ] **Step 2: Publish the canvas and send Mohamed the link**

- [ ] **Step 3: Wait for his approval, and record any changes he asks for**

Do not start Task 11, 12, 13, 14 or 16 before this step completes. If he
changes a surface, update the canvas before implementing it.

---

# Lane C — admin inbox

Depends on Lane A **and on the approved design canvas (Task D0)**. Independent of Lanes B and D.

### Task 10: Reply, note, take-over and resolve endpoints

**Files:**
- Create: `app/Http/Controllers/Admin/ConversationReplyController.php`
- Create: `app/Http/Controllers/Admin/SupportTicketController.php` (take-over, PATCH)
- Create: `app/Actions/Support/SendStaffReply.php`
- Create: `app/Http/Requests/Admin/SendStaffReplyRequest.php`
- Modify: `routes/admin.php:186-201`
- Test: `tests/Feature/Support/StaffReplyTest.php`

**Interfaces:**
- Consumes: Tasks 3, 4, 8.
- Produces: `SendStaffReply::execute(ChatConversation $c, User $staff, string $content, bool $internal): ChatMessage`.

- [ ] **Step 1: Write the failing tests**

Cover: a `Staff`-role user is denied `chat.reply` (403); an Admin reply creates a `staff` message with `staff_user_id` set and `reply_to_message_id` NULL; **the reply implicitly takes over** — after replying with no prior take-over, the conversation is `Active` and a live ticket exists; `last_staff_message_at` is set; an internal note does not appear in the customer payload; a second admin taking over an `Active` ticket owned by a different admin gets `409`; and a `StaffAuditEvent` is written whose metadata contains no message body.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Support/StaffReplyTest.php`
Expected: FAIL — route not defined.

- [ ] **Step 3: Implement `SendStaffReply` with implicit take-over**

```php
return DB::transaction(function () use ($conversationId, $staff, $content, $internal): ChatMessage {
    $conversation = ChatConversation::query()->whereKey($conversationId)->lockForUpdate()->firstOrFail();

    // Replying IS taking over. If this were left to a separate button, the obvious
    // action — just typing an answer — would leave handoff_state = none, so the
    // customer's next message would be eligible and Luna would answer on top of the
    // human, with no ticket, no banner and no polling to deliver the reply.
    if (! $internal) {
        $ticket = $this->openSupportTicket->execute($conversation, $conversation->user, $staff, 'staff_reply');
        $conversation->forceFill(['handoff_state' => ChatHandoffState::Active])->save();
    }

    $message = $conversation->messages()->create([
        'sender_type' => ChatSenderType::Staff,
        'staff_user_id' => $staff->id,
        'message_type' => $internal ? ChatMessageType::InternalNote : ChatMessageType::Text,
        'content' => $content,
        'reply_to_message_id' => null,
        'agent_eligible_at' => null,
    ]);

    if (! $internal) {
        $conversation->forceFill([
            'last_message_at' => now(),
            'last_staff_message_at' => now(),
        ])->save();
    }

    return $message;
});
```

An internal note must not move `last_message_at`, `last_staff_message_at`, or the handoff state — it is not a reply to the customer.

Validation: content required, string, max `config('chat.max_message_length')` (4000), non-blank after trim.

Routes, inside the existing admin MFA group and registered under both the bare and `/en` prefixes exactly like the current conversation routes (mirror the `$locale !== null` `->defaults('locale', $locale)` pattern at `routes/admin.php:186-201`):

| Method | Path | Middleware |
| --- | --- | --- |
| POST | `/admin/conversations/{publicId}/reply` | `can:chat.reply` |
| POST | `/admin/conversations/{publicId}/note` | `can:chat.reply` |
| POST | `/admin/conversations/{publicId}/take-over` | `can:chat.reply` |
| PATCH | `/admin/tickets/{publicId}` | `can:chat.reply` |
| GET | `/admin/support/unread-count` | `can:chat.view` |

The PATCH handler resolves the ticket by `public_id` **without a lock**, then opens its transaction by locking the conversation first, then re-locks and re-reads the ticket.

Rate limit: register a `support-reply` limiter at 60/min per authenticated actor next to the existing chat limiters.

- [ ] **Step 4: Audit every write**

```php
$this->recordStaffAudit(new StaffAuditEvent(
    action: 'chat.reply.sent',
    metadata: [
        'ticket_number' => $ticket->ticket_number,
        'conversation_short_id' => $conversation->short_id,
        'target_user_id' => $conversation->user_id,
        'content_length' => mb_strlen($content),
    ],
    ipAddress: $request->ip(),
));
```

Never put message bodies in audit metadata. Use whatever recorder the existing admin controllers use (see `app/Admin/Audit/`).

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support/StaffReplyTest.php tests/Feature/Admin/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin app/Actions/Support/SendStaffReply.php app/Http/Requests/Admin routes/admin.php tests/Feature/Support/StaffReplyTest.php
git commit -m "feat(admin): staff reply, internal notes, take-over and resolve"
```

---

### Task 11: Conversation detail UI

**Files:**
- Modify: `resources/js/pages/admin/conversations/show.tsx`
- Modify: `app/Http/Controllers/Admin/ConversationDetailController.php`
- Modify: `resources/js/types/admin.ts`
- Modify: `lang/ar/admin.php`, `lang/en/admin.php`
- Test: `resources/js/__tests__/admin/admin-conversation-reply.test.tsx`

**Interfaces:**
- Consumes: Task 10's routes.
- Produces: nothing other tasks consume.

- [ ] **Step 1: Write the failing Vitest**

Assert: the composer renders for a user with `chat.reply` and is absent without it; submitting posts to the reply route; the internal-note toggle posts to the note route; staff bubbles render with the staff name and are visually distinguishable from assistant bubbles; the ticket panel shows `TKT-…` and status; Resolve is present only when a live ticket exists.

- [ ] **Step 2: Run and confirm failure**

Run: `npx vitest run resources/js/__tests__/admin/admin-conversation-reply.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Extend the controller payload**

Add to the Inertia props: `ticket` (number, status, assignee name, `resolvedAt`) or `null`; `handoffState`; and `canReply` from `Gate::forUser($actor)->allows(AdminPermission::ChatReply->value)`. Add `senderType: 'staff'` and `staffName` to the mapped messages. Keep `guest_key` out of the payload — it already is; do not add it.

- [ ] **Step 4: Build the UI**

Follow the existing admin page conventions in `show.tsx` and the shared admin components. Composer, note toggle, Take over, Resolve. Every control at least 44×44 CSS px at **all** widths — the Playwright admin suite measures every control and will fail on a `md:` shrink override. Bilingual strings in both lang files; Arabic authoritative.

Load and follow the `frontend-design` skill plus the relevant Impeccable skills before writing UI, per AGENTS.md, and finish with a `polish` pass.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `npx vitest run resources/js/__tests__/admin/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js app/Http/Controllers/Admin/ConversationDetailController.php lang
git commit -m "feat(admin): reply composer, notes and ticket panel on the transcript"
```

---

### Task 12: Inbox list — short id, badges, sort

**Files:**
- Modify: `resources/js/pages/admin/conversations/index.tsx`
- Modify: `app/Http/Controllers/Admin/ConversationsController.php`
- Modify: `app/Http/Requests/Admin/ListAdminConversations.php`
- Modify: `resources/js/lib/admin-conversations-query.ts`, `resources/js/types/admin.ts`
- Modify: `lang/ar/admin.php`, `lang/en/admin.php`
- Test: `resources/js/__tests__/admin/admin-conversations-list.test.tsx`
- Test: `tests/Feature/Support/AdminInboxSortTest.php`

**Interfaces:**
- Consumes: Tasks 3, 5, 10.
- Produces: nothing other tasks consume.

- [ ] **Step 1: Write the failing tests**

Assert: rows render `CHT-…` and not the ULID (the list template currently prints `row.publicId` at `index.tsx:407` and `:780`); a row with a live ticket shows its `TKT-…` badge and an unread dot when `lastMessageAt > lastStaffMessageAt`; the `ticketStatus` filter round-trips; the guest owner filter is gone; and `q` matches a short id, a ticket number, and a full public id.

- [ ] **Step 2: Run and confirm failure**

Run: `npx vitest run resources/js/__tests__/admin/admin-conversations-list.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Controller and request**

Add `shortId`, `ticketNumber`, `ticketStatus`, `lastStaffMessageAt` to each row; `->with('liveTicket')` on the query. Widen `q` to match `short_id`, `ticket_number` or `public_id`. Add `ticketStatus` to `ALLOWED_KEYS`, the rules and `normalizedFilters()`.

Default sort — live tickets with unread customer messages first, then last activity:

```php
$query->orderByRaw(
    'CASE WHEN EXISTS (
        SELECT 1 FROM support_tickets t
        WHERE t.conversation_id = chat_conversations.id AND t.status = ?
    ) AND (chat_conversations.last_staff_message_at IS NULL
           OR chat_conversations.last_message_at > chat_conversations.last_staff_message_at)
    THEN 0 ELSE 1 END',
    ['open'],
)->orderByLastActivityDesc();
```

- [ ] **Step 4: Update the table and card layouts**

Replace `<bdi>{row.publicId}</bdi>` with the short id at every occurrence (`index.tsx:407`, `:780`; keep `row.publicId` in the `href` — the route is still keyed by public id). Add the ticket badge and unread dot to both the desktop table and the mobile card layout; the file renders both.

Update `conversations.searchPlaceholder` in both lang files — it currently says "Search by conversation ID…" and now also accepts a ticket number.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `npx vitest run resources/js/__tests__/admin/ && ./vendor/bin/pest tests/Feature/Support/AdminInboxSortTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js app/Http lang tests
git commit -m "feat(admin): short chat ids, ticket badges and unread-first inbox sort"
```

---

# Lane D — customer experience, notifications, documentation

Depends on Lane A. Tasks 13, 14 and 16 also depend on the approved design canvas (Task D0); Task 17 does not. Independent of Lanes B and C. **Lane D owns every documentation file** so the lanes cannot conflict.

### Task 13: Ticket banner and staff bubbles

**Files:**
- Create: `resources/js/components/chat/chat-handoff-banner.tsx`
- Modify: `resources/js/components/chat/chat-message-list.tsx`
- Modify: `resources/js/types/chat.ts`, `app/Http/Presenters/ChatPresenter.php`
- Modify: `lang/ar/chat.php`, `lang/en/chat.php`
- Test: `resources/js/__tests__/chat/chat-handoff-banner.test.tsx`

**Interfaces:**
- Consumes: Tasks 3, 8.
- Produces: `ChatConversation.handoffState` and `.ticket` in the client type.

- [ ] **Step 1: Write the failing Vitest**

Assert each banner state renders its copy; the resolved state renders the "Still need help?" control; staff bubbles carry the staff name and a distinct class from assistant bubbles; and **the copy contains no time promise** — assert the absence of "soon", "shortly", "قريبًا", "خلال".

- [ ] **Step 2: Run and confirm failure**

Run: `npx vitest run resources/js/__tests__/chat/chat-handoff-banner.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Extend `ChatPresenter::conversation`**

Add `handoffState` and a `ticket` object (`number`, `status`, `responderName`) or `null`. Never expose `assigned_admin_id`, `user_id`, or `guest_key`.

- [ ] **Step 4: Build the banner and bubbles**

Bilingual copy, `dir="auto"`, 44px controls, reduced-motion respected. Follow AGENTS.md's UI gate: load `frontend-design` and the relevant Impeccable skills, match the existing widget's visual language, and finish with a `polish` pass.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `npx vitest run resources/js/__tests__/chat/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js lang app/Http/Presenters/ChatPresenter.php
git commit -m "feat(chat): handoff banner and staff bubbles"
```

---

### Task 14: Conversation history

**Files:**
- Modify: `app/Http/Controllers/Chat/ChatConversationController.php` (add `index`)
- Modify: `routes/chat.php`
- Modify: `resources/js/components/chat/chat-home.tsx`, `resources/js/lib/chat-api.ts`, `resources/js/hooks/use-chat.ts`
- Test: `tests/Feature/Support/ConversationHistoryTest.php`
- Test: `resources/js/__tests__/chat/chat-history.test.tsx`

**Interfaces:**
- Consumes: Task 3.
- Produces: `GET /chat/conversations` → `{ conversations: [...], hasMore, oldestCursor }`.

- [ ] **Step 1: Write the failing tests**

Assert: an authenticated customer gets only their own conversations; a second customer's conversation is absent; a **guest gets an empty list**; pagination is bounded; the payload contains no `guest_key`, no `user_id` and no internal note text; and the home view renders the list for a customer but not for a guest.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Support/ConversationHistoryTest.php`
Expected: FAIL — route not defined.

- [ ] **Step 3: Implement `index`**

Resolve the owner via `ResolveChatOwner`; return an empty list immediately when `$owner->userId() === null`. Otherwise scope with `forOwner`, order by last activity descending, cursor-paginate at 10 per page. Each entry: `publicId`, `subject` or a first-message preview, `lastMessageAt`, `status`, and `ticketNumber` when one exists.

```php
Route::get('/chat/conversations', [ChatConversationController::class, 'index'])
    ->middleware([SetChatLocale::class, 'throttle:chat-read'])
    ->name('chat.conversations.index');
```

Register it **before** `/chat/conversations/{conversation}` so the literal path is not swallowed by the parameterised route.

- [ ] **Step 4: Build the home-view list**

A "Previous conversations" section in `chat-home.tsx`, up to 10 entries, opening a thread **read-only** with a "Start a new conversation" control. Do not introduce reopening a closed thread — the accepted lifecycle says an explicitly closed thread never reopens.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support/ConversationHistoryTest.php && npx vitest run resources/js/__tests__/chat/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http routes/chat.php resources/js tests
git commit -m "feat(chat): customer-visible conversation history"
```

---

### Task 15: Polling, state sync and expiry recovery

**Files:**
- Modify: `resources/js/hooks/use-chat.ts`
- Modify: `app/Http/Controllers/Chat/ChatMessageController.php`, `app/Http/Presenters/AgentTurnPresenter.php`
- Test: `resources/js/__tests__/chat/chat-handoff-polling.test.tsx`

**Interfaces:**
- Consumes: Tasks 8, 13.
- Produces: nothing other tasks consume.

- [ ] **Step 1: Write the failing Vitest**

Use fake timers. Assert: polling starts at 5s when `handoffState` is `requested` or `active`; backs off to 15s after two minutes with no new message; pauses on `document.hidden` and resumes on visible; stops when the state becomes `resolved`; **`handoffState` from a message-send response starts polling without a page load**; and a 404 on send starts a fresh conversation rather than surfacing an error.

- [ ] **Step 2: Run and confirm failure**

Run: `npx vitest run resources/js/__tests__/chat/chat-handoff-polling.test.tsx`
Expected: FAIL.

- [ ] **Step 3: Return `handoffState` outside the conversation load**

Add it to the message-store response and to the terminal agent-turn payload. Without this, a take-over initiated from the inbox while the widget is already open is invisible: the client never learns the state changed, never starts polling, and the staff reply does not render until the customer reloads.

- [ ] **Step 4: Implement polling and 404 recovery**

Reuse the existing generation-ownership pattern in `use-chat.ts` so a poll from a superseded generation cannot write state. Clear the interval on unmount, on resolve, and on conversation replacement. Treat a 404 on send exactly like the existing cross-tab-close reacquisition path.

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `npx vitest run resources/js/__tests__/chat/`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add resources/js app/Http
git commit -m "feat(chat): handoff polling, state re-sync and expired-conversation recovery"
```

---

### Task 16: Admin unread badge and chime

**Files:**
- Create: `app/Http/Controllers/Admin/SupportUnreadCountController.php`
- Modify: `app/Admin/Presenters/AdminShell.php`, `resources/js/components/admin/*` (nav)
- Test: `tests/Feature/Support/UnreadCountTest.php`
- Test: `resources/js/__tests__/admin/admin-unread-badge.test.tsx`

**Interfaces:**
- Consumes: Tasks 3, 10.
- Produces: `GET /admin/support/unread-count` → `{ count: number }`.

- [ ] **Step 1: Write the failing tests**

Assert: the count includes only conversations with a **live** ticket and `last_message_at > last_staff_message_at`; a resolved ticket is excluded; `chat.view` is required; the badge renders the count; and the chime fires only on an increase, never on first load.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Support/UnreadCountTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

One count query, no N+1. The nav badge polls every 30s, pausing on `document.hidden`. Reuse `resources/js/lib/chat-sound.ts` and honour the same mute preference. **No sound on first load** — only when the count rises above the previous observed value.

If a nav entry is added, update the nav-link counts in `tests/Browser/storefront-smoke.spec.ts`.

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `./vendor/bin/pest tests/Feature/Support/UnreadCountTest.php && npx vitest run resources/js/__tests__/admin/`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http app/Admin resources/js tests
git commit -m "feat(admin): unread support badge and chime"
```

---

### Task 17: Synchronous customer email, Playwright, and documentation

**Files:**
- Create: `app/Notifications/SupportReplyNotification.php`
- Modify: `app/Actions/Support/SendStaffReply.php`
- Modify: `tests/Browser/storefront-smoke.spec.ts`
- Modify: `docs/ai-assistant/{ADMIN-INBOX,PRODUCT,ARCHITECTURE,SECURITY,UX,OPERATIONS,PHASES,DECISIONS,STATUS,README}.md`
- Test: `tests/Feature/Support/SupportReplyNotificationTest.php`

**Interfaces:**
- Consumes: Task 10.
- Produces: nothing.

- [ ] **Step 1: Write the failing tests**

Assert: no email when the customer was active in the last five minutes; one email when they were not; **no second email within the hour**; `last_notified_at` is written; a mailer exception does not fail the reply (`Mail::fake()` throwing, reply still 200, error logged); and the rendered mail body contains **no transcript content**.

- [ ] **Step 2: Run and confirm failure**

Run: `./vendor/bin/pest tests/Feature/Support/SupportReplyNotificationTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement — synchronous, after commit**

```php
// NOT ShouldQueue. routes/console.php schedules five commands and none of them is
// queue:work or queue:run, and this host has no permanent worker — a queued
// notification would sit in `jobs` forever and the only offline delivery channel in
// this design would silently never fire. PendingEmailChangeNotification, the
// notification this mirrors, is a plain synchronous Notification for the same reason.
final class SupportReplyNotification extends Notification
```

In `SendStaffReply`: write `last_notified_at` **inside** the transaction, then send **after commit**, wrapped in try/catch with logging.

```php
DB::afterCommit(function () use ($conversation, $shouldNotify): void {
    if (! $shouldNotify) {
        return;
    }

    try {
        $conversation->user?->notify(new SupportReplyNotification($conversation));
    } catch (Throwable $exception) {
        report($exception);   // A mail outage must never 500 the staff reply.
    }
});
```

Writing the throttle stamp before sending means a send failure costs one missed email rather than a duplicate storm.

- [ ] **Step 4: Add the Playwright round-trip**

Admin opens a conversation, replies; the customer widget receives the reply via polling. Assert the 44px floor on every new control at 320/390/768/1440 in both locales. Do **not** take full-page screenshots of tall pages.

- [ ] **Step 5: Write the documentation**

`ADMIN-INBOX.md` becomes the canonical operator document: reply, notes, take-over, resolve, the lock order, the audit actions, and what is still not implemented. Update `PRODUCT` (handoff leaves the exclusion list), `ARCHITECTURE` (new routes, tables, lock order), `SECURITY` (`chat.reply`, guest purge, internal-note boundary, account-deletion consequence), `UX` (history, banner, polling), `OPERATIONS` (`CHAT_GUEST_RETENTION_HOURS`, the synchronous email and why it is not queued), `PHASES`, and `STATUS`. Re-stamp every header touched.

`DECISIONS.md` records, dated 2026-08-24: the eight owner decisions; the six v1 cuts and why; the accepted consequence that guest transcripts are unreviewable after 48 hours; and the accepted consequence that deleting an account cascades away a live ticket.

- [ ] **Step 6: Run the full gate**

Run: `npm run ci:check`, then the Playwright suite in full.
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat(support): away-customer email, browser coverage and documentation"
```

---

## Self-review notes

- **Spec coverage.** §1 → Tasks 2-4; §2 → Tasks 5, 8, 9, 15; §3 → Tasks 6, 7; §3.4 lock order → Tasks 8, 10; §4 → Tasks 5, 10-12; §4.4 → Task 5; §5 → Tasks 13-15; §6 → Tasks 16, 17; §7 → Tasks 5, 10; §8 → distributed; §9 → Task 17.
- **Known cross-task dependency.** `TicketNumber` (Task 1) references `SupportTicket` (Task 3); its test passes only after Task 3. Flagged in Task 1 Step 4.
- **Naming consistency.** `handoff_state` is the column everywhere; `ChatHandoffState::isLive()` and `SupportTicketStatus::isLive()` are the two predicates; "live ticket" always means `status = 'open'`.
