# AI Assistant Phase 1 Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:subagent-driven-development` (recommended) or
> `superpowers:executing-plans` to implement this plan task-by-task. Steps use
> checkbox (`- [ ]`) syntax for tracking.

**Goal:** Finish the deterministic chat foundation with a single-open-thread
invariant, approved lifecycle/retention controls, canonical retry/error
behavior, an account-safe accessible launcher, and authenticated browser
coverage before any Luna runtime code begins.

**Architecture:** A new forward migration derives a unique active owner key for
open chat conversations on SQLite and MariaDB. Focused lifecycle Actions own
create/close/acquire/restart/claim behavior; an hourly command closes and purges
stale records. The existing persistent React widget gains account-aware
placement, New conversation, safe-area/accessibility fixes, and a real
registration-to-account Playwright regression.

**Tech Stack:** Laravel 13, PHP 8.3, MariaDB, SQLite, React 19, Inertia 3,
TypeScript, Tailwind CSS 4, Pest 4, Vitest 4, Playwright 1.62.1, GitHub Actions,
Hostinger.

**Spec:**
`docs/superpowers/specs/2026-08-20-ai-assistant-phases-1-2-design.md`

## Global Constraints

- This plan implements **Phase 1 Completion only**. Do not add OpenAI/Luna,
  agent turns/runs, prompts, SSE, RAG, tools, Reverb, or admin inbox code.
- Preserve the deployed `2026_08_20_000001_create_chat_tables.php` migration;
  schema changes use a new forward migration.
- Approved defaults: auto-close 24 hours, inactivity reopen within 7 days,
  guest retention 30 days, authenticated retention 180 days.
- One owner has at most one `open` conversation. Guest-to-login continuity
  preserves the active guest public ID.
- Explicit `customer_started_new` and login-superseded conversations never
  auto-reopen.
- Closed history remains support-only; do not add a customer history list.
- Do not change production `SESSION_DRIVER` or `SESSION_ENCRYPT`; inspect and
  document the boundary separately.
- Customer messages stay physically right and assistant/typing left in Arabic
  and English; bubble text remains `dir="auto"`.
- The launcher stays physically bottom-right. On mobile account pages it sits
  above the account bottom navigation, while the open sheet layers above it.
- Before frontend edits, announce and load `frontend-design`,
  `ui-ux-pro-max`, `adapt`, and final `polish`; verified WordPress/Arab UT
  identity overrides generic design suggestions.
- Follow TDD: every behavioral production change begins with a failing focused
  test and recorded expected failure.
- CI/browser tests use only synthetic local data. Never add a test-only auth
  route or production credential.
- Run focused checks during tasks. Run the complete repository/MariaDB/browser
  gates only at release checkpoints.
- Workers commit on an isolated branch. After independent review, the
  controller fast-forwards/pushes `main` only at the safe checkpoints defined
  below.
- No existing production migration is edited or rolled back in production.

## File and interface map

### Domain and persistence

- `app/Enums/Chat/ChatConversationCloseReason.php` — exact close-reason enum.
- `database/migrations/2026_08_20_000002_add_chat_conversation_lifecycle.php`
  — lifecycle columns, duplicate reconciliation, derived active-owner
  invariant, reply association.
- `app/Models/ChatConversation.php` — lifecycle casts/scopes/state validation.
- `app/Models/ChatMessage.php` — reply relation.
- `database/factories/ChatConversationFactory.php` — open/closed lifecycle
  factory states.
- `app/Actions/Chat/CreateChatConversation.php` — atomic conversation plus
  onboarding creation.
- `app/Actions/Chat/CloseChatConversation.php` — idempotent typed close.
- `app/Actions/Chat/CreateOrGetActiveConversation.php` — owner acquisition and
  eligible inactivity reopen.
- `app/Actions/Chat/RestartChatConversation.php` — atomic explicit restart.
- `app/Actions/Chat/ClaimGuestChatConversations.php` — guest-public-ID-wins
  claim conflict resolution.
- `app/Actions/Chat/CreateChatMessage.php` — canonical duplicate/reply recovery.
- `app/Console/Commands/MaintainChatConversations.php` — close/purge maintenance.
- `app/Http/Responses/ChatErrorResponse.php` — safe normalized framework error
  responses.
- `lang/ar/chat.php`, `lang/en/chat.php` — exact localized lifecycle/error copy.

### HTTP and frontend

- `app/Http/Controllers/Chat/ChatConversationController.php` — acquire/show/
  restart HTTP boundary.
- `app/Http/Controllers/Chat/ChatMessageController.php` — open-only message
  boundary.
- `routes/chat.php` — restart route.
- `bootstrap/app.php` — chat exception response normalization.
- `config/chat.php`, `.env.example`, `routes/console.php` — lifecycle defaults
  and maintenance schedule.
- `resources/js/lib/chat-api.ts`, `resources/js/types/chat.ts` — restart API and
  lifecycle types.
- `resources/js/hooks/use-chat.ts` — pending-send state and restart behavior.
- `resources/js/layouts/chat-root-layout.tsx` — account component context.
- `resources/js/components/chat/chat-widget.tsx` — account surface and layering.
- `resources/js/components/chat/chat-header.tsx` — New conversation action.
- `resources/js/components/chat/chat-composer.tsx` — explicit accessible name
  and safe-area class.
- `resources/js/components/chat/chat-message-list.tsx` — 44px secondary
  controls.
- `resources/css/app.css` — account offset/full-sheet/safe-area rules.

### Tests and docs

- `tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php`
- `tests/Integration/ChatConversationConcurrencyTest.php`
- `tests/Support/ConcurrentChatAcquire.php` — isolated-process acquisition
  worker.
- `tests/Support/ConcurrentChatMessage.php` — isolated-process duplicate-send
  worker.
- `tests/Feature/Chat/ChatConversationLifecycleTest.php`
- `tests/Feature/Chat/ChatMessageTest.php`
- `tests/Feature/Chat/ChatGuestClaimTest.php`
- `tests/Feature/Chat/ChatCacheHeaderTest.php`
- `tests/Feature/Console/MaintainChatConversationsTest.php`
- existing `resources/js/__tests__/chat/*` plus focused new assertions.
- `tests/Browser/storefront-smoke.spec.ts` — one authenticated account case,
  bringing the suite from six to seven scenarios.
- `.github/workflows/tests.yml` — lifecycle/concurrency MariaDB suites.
- canonical `docs/ai-assistant/*` and operations docs updated with behavior.

---

### Task 1: Lifecycle schema and one-open-owner database invariant

**Files:**

- Create: `app/Enums/Chat/ChatConversationCloseReason.php`
- Create:
  `database/migrations/2026_08_20_000002_add_chat_conversation_lifecycle.php`
- Modify: `app/Models/ChatConversation.php`
- Modify: `app/Models/ChatMessage.php`
- Modify: `database/factories/ChatConversationFactory.php`
- Create: `tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php`
- Modify: `tests/Feature/Chat/ChatConversationModelTest.php`

**Interfaces:**

- Produces enum cases `CustomerStartedNew`, `Inactive`,
  `SupersededByLoginClaim`, `InvariantUpgradeDuplicate`.
- Produces nullable conversation fields `active_owner_key`, `closed_at`, and
  `close_reason`.
- Produces nullable unique message field `reply_to_message_id`.
- Produces `scopeOpen()`, `scopeClosedForInactivity()`, and
  `scopeForOwner()` contracts used by later tasks.
- Produces factory method
  `closed(ChatConversationCloseReason $reason, CarbonInterface $closedAt): static`.
- Produces `ChatMessage::replyTo(): BelongsTo` and
  `ChatMessage::reply(): HasOne`.

- [ ] **Step 1: Add RED migration upgrade tests**

Create the integration test with an isolated legacy SQLite database, matching
the existing active-cart upgrade-test pattern. The core assertions are:

```php
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
```

Also assert guest derivation, historical closed-key reuse, reply uniqueness,
real MariaDB down/up/remigration, and rollback restoration.

- [ ] **Step 2: Run RED tests**

Run:

```powershell
php artisan test tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php tests/Feature/Chat/ChatConversationModelTest.php
```

Expected: FAIL because the enum, migration, columns, and invariant do not exist.

- [ ] **Step 3: Add the close-reason enum**

```php
enum ChatConversationCloseReason: string
{
    case CustomerStartedNew = 'customer_started_new';
    case Inactive = 'inactive';
    case SupersededByLoginClaim = 'superseded_by_login_claim';
    case InvariantUpgradeDuplicate = 'invariant_upgrade_duplicate';
}
```

- [ ] **Step 4: Implement the forward migration**

Add `closed_at`, `close_reason`, `active_owner_key`, and
`reply_to_message_id`. Before installing the unique invariant, group open rows
by this exact derived key:

```php
$ownerKey = $row->user_id !== null
    ? 'user:'.$row->user_id
    : 'guest:'.$row->guest_key;
```

Keep the newest `last_message_at`, then highest `id`; close older rows without
deleting them.

SQLite installs `chat_conversations_derive_active_owner_insert` and
`chat_conversations_derive_active_owner_update` triggers using:

```sql
CASE
  WHEN status = 'open' AND user_id IS NOT NULL THEN 'user:' || user_id
  WHEN status = 'open' AND guest_key IS NOT NULL THEN 'guest:' || guest_key
  ELSE NULL
END
```

MariaDB changes the field to a stored generated column using `CONCAT`, then
both drivers create `chat_conversations_active_owner_key_unique`.

`chat_messages.reply_to_message_id` references `chat_messages.id`, is nullable,
unique, and uses `nullOnDelete()`.

- [ ] **Step 5: Update model/factory contracts**

Cast `closed_at` to datetime and `close_reason` to the enum. Validate:

```php
if ($conversation->status === ChatConversationStatus::Open
    && ($conversation->closed_at !== null || $conversation->close_reason !== null)) {
    throw new InvalidArgumentException('An open conversation cannot have close metadata.');
}
```

Closed factory state sets both fields; open state clears them. Add message
`replyTo()` / `reply()` relations.

- [ ] **Step 6: Run GREEN tests and schema checks**

Run the Step 2 command, then:

```powershell
php artisan migrate:fresh --force
php artisan migrate:rollback --force
php artisan migrate --force
```

Expected: all focused tests pass and lifecycle migration completes all three
commands.

- [ ] **Step 7: Commit Task 1 locally**

```powershell
git add app/Enums/Chat app/Models database/factories database/migrations tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php tests/Feature/Chat/ChatConversationModelTest.php
git commit -m "feat(chat): enforce conversation lifecycle invariant"
```

Do not push yet; Tasks 2–4 make the schema safely deployable.

---

### Task 2: Atomic acquire, restart, and guest-login continuity

**Files:**

- Create: `app/Actions/Chat/CreateChatConversation.php`
- Create: `app/Actions/Chat/CloseChatConversation.php`
- Create: `app/Actions/Chat/RestartChatConversation.php`
- Modify: `app/Actions/Chat/CreateOrGetActiveConversation.php`
- Modify: `app/Actions/Chat/ClaimGuestChatConversations.php`
- Modify: `app/Listeners/ClaimGuestChatAfterLogin.php`
- Modify: `app/Http/Controllers/Chat/ChatConversationController.php`
- Modify: `routes/chat.php`
- Modify: `config/chat.php`
- Create: `tests/Feature/Chat/ChatConversationLifecycleTest.php`
- Modify: `tests/Feature/Chat/ChatConversationTest.php`
- Modify: `tests/Feature/Chat/ChatGuestClaimTest.php`
- Modify: `tests/Feature/Chat/ChatKeyRotationLoginTest.php`
- Create: `tests/Integration/ChatConversationConcurrencyTest.php`
- Create: `tests/Support/ConcurrentChatAcquire.php`

**Interfaces:**

- `CreateChatConversation::execute(ChatOwner $owner, string $locale): ChatConversation`
- `CloseChatConversation::execute(ChatConversation $conversation, ChatConversationCloseReason $reason): ChatConversation`
- `RestartChatConversation::execute(ChatOwner $owner, Request $request, ?string $locale): ChatConversation`
- `ClaimGuestChatConversations::execute(array $guestOwners, User $user, ?string $activePublicId): void`
- Produces `POST /chat/conversations/restart` named
  `chat.conversations.restart`.

- [ ] **Step 1: Write RED lifecycle/acquisition tests**

Cover exact boundaries:

```php
test('inactive thread reopens within seven days but explicit restart never reopens', function () {
    config()->set('chat.reopen_within_days', 7);
    $user = User::factory()->create();
    $inactive = ChatConversation::factory()->forUser($user)->closed(
        ChatConversationCloseReason::Inactive,
        now()->subDays(2),
    )->create(['last_message_at' => now()->subDays(2)]);

    $this->actingAs($user)->postJson(route('chat.conversations.store'))
        ->assertOk()
        ->assertJsonPath('data.publicId', $inactive->public_id);

    $replacement = $this->actingAs($user)
        ->postJson(route('chat.conversations.restart'), ['locale' => 'ar'])
        ->assertOk()
        ->json('data.publicId');

    expect($replacement)->not->toBe($inactive->public_id)
        ->and($inactive->fresh()->close_reason)
        ->toBe(ChatConversationCloseReason::CustomerStartedNew);
});
```

Also cover: after seven days creates new, pointed closed row is ignored, create
plus onboarding rolls back together, unique contention recovers one winner,
restart is owner-scoped, and no close reason other than `Inactive` reopens.

Guest-login test creates an existing user open thread and an active guest
thread, logs in with the guest session pointer, then asserts the guest public ID
stays open/user-owned and the old user thread closes as
`SupersededByLoginClaim`.

- [ ] **Step 2: Run RED lifecycle tests**

```powershell
php artisan test tests/Feature/Chat/ChatConversationLifecycleTest.php tests/Feature/Chat/ChatConversationTest.php tests/Feature/Chat/ChatGuestClaimTest.php tests/Feature/Chat/ChatKeyRotationLoginTest.php
```

Expected: FAIL on missing Actions, route, and lifecycle behavior.

- [ ] **Step 3: Implement atomic create and typed close**

`CreateChatConversation` normalizes locale to `ar|en`, then creates the
conversation and onboarding system message inside one `DB::transaction()`.

`CloseChatConversation` locks the row and performs this idempotent transition:

```php
$conversation->forceFill([
    'status' => ChatConversationStatus::Closed,
    'closed_at' => now(),
    'close_reason' => $reason,
])->save();
```

- [ ] **Step 4: Implement acquisition and restart**

`CreateOrGetActiveConversation` uses owner-scoped queries and transaction
retries. It returns open, reopens only recent `Inactive`, or creates new. Catch
only the named active-owner unique violation; re-query and return its canonical
winner. Update the session pointer after the transaction result exists.

Restart locks/ closes current open, creates new, and updates the pointer in one
transaction.

- [ ] **Step 5: Implement guest-claim conflict order**

Pass the session pointer from the listener. In one transaction:

1. lock guest candidate rows and current user open row;
2. select the pointed open guest row, otherwise latest guest open;
3. close user open and losing guest open rows;
4. claim every guest historical row to the user;
5. leave the winner open so its generated active key becomes `user:<id>`.

Clear guest token only after success as today.

- [ ] **Step 6: Add restart HTTP boundary**

Controller validates only `locale` and bounded `limit`, resolves owner
server-side, invokes restart, loads bounded messages, and returns the existing
conversation presenter contract with `no-store, private`.

- [ ] **Step 7: Add true MariaDB concurrency tests**

Use `Symfony\Component\Process\Process` like
`CoinsCartConcurrencyTest`. Two authenticated and two guest first acquisitions
must return the same public ID and leave exactly one active owner key.

`ConcurrentChatAcquire.php` boots `bootstrap/app.php`, builds either
`ChatOwner::user((int) $argv[2])` or `ChatOwner::guest($argv[2])`, attaches an
`ArraySessionHandler` session to a synthetic `Request`, invokes
`CreateOrGetActiveConversation`, and writes only the public ID to stdout. The
test starts two identical workers with the MariaDB environment from the current
connection, waits, purges/reconnects DB, and compares trimmed outputs.

- [ ] **Step 8: Run GREEN tests**

Run Step 2 plus:

```powershell
php artisan test tests/Integration/ChatConversationConcurrencyTest.php
```

SQLite may skip true-process locking; the MariaDB workflow later must execute
it.

- [ ] **Step 9: Commit Task 2 locally**

```powershell
git add app/Actions/Chat app/Listeners/ClaimGuestChatAfterLogin.php app/Http/Controllers/Chat/ChatConversationController.php routes/chat.php config/chat.php tests/Feature/Chat tests/Integration/ChatConversationConcurrencyTest.php tests/Support/ConcurrentChatAcquire.php
git commit -m "feat(chat): add controlled conversation lifecycle"
```

Do not push yet.

---

### Task 3: Retention maintenance and scheduled cleanup

**Files:**

- Create: `app/Console/Commands/MaintainChatConversations.php`
- Modify: `config/chat.php`
- Modify: `.env.example`
- Modify: `routes/console.php`
- Create: `tests/Feature/Console/MaintainChatConversationsTest.php`

**Interfaces:**

- Produces command `chat:maintain-conversations`.
- Produces config keys `chat.auto_close_hours`, `chat.reopen_within_days`,
  `chat.guest_retention_days`, `chat.user_retention_days`.

- [ ] **Step 1: Write RED boundary and schedule tests**

Use explicit 23/24-hour, 29/30-day, and 179/180-day records. Assert active
records remain, expired closed records cascade-delete messages, output contains
counts but no guest key/message content, and Scheduler has one hourly
`withoutOverlapping` event.

- [ ] **Step 2: Run RED command tests**

```powershell
php artisan test tests/Feature/Console/MaintainChatConversationsTest.php
```

Expected: FAIL because command/config/schedule do not exist.

- [ ] **Step 3: Add exact configuration defaults**

```php
'auto_close_hours' => (int) env('CHAT_AUTO_CLOSE_HOURS', 24),
'reopen_within_days' => (int) env('CHAT_REOPEN_WITHIN_DAYS', 7),
'guest_retention_days' => (int) env('CHAT_GUEST_RETENTION_DAYS', 30),
'user_retention_days' => (int) env('CHAT_USER_RETENTION_DAYS', 180),
```

Mirror the four non-secret defaults in `.env.example`.

- [ ] **Step 4: Implement idempotent chunked maintenance**

Close open records using last activity cutoff and typed `Inactive` reason. Then
`chunkById(200)` owner-specific expired closed rows and delete them; existing
cascade removes messages. Emit only aggregate counts.

Phase 1 has no agent-turn table; the Phase 2 plan must add active-turn skipping
before turn migrations are enabled.

- [ ] **Step 5: Schedule hourly without overlap**

```php
Schedule::command(MaintainChatConversations::class)
    ->hourly()
    ->withoutOverlapping();
```

- [ ] **Step 6: Run GREEN tests and schedule list**

```powershell
php artisan test tests/Feature/Console/MaintainChatConversationsTest.php
php artisan schedule:list
```

- [ ] **Step 7: Commit Task 3 locally**

```powershell
git add app/Console/Commands/MaintainChatConversations.php config/chat.php .env.example routes/console.php tests/Feature/Console/MaintainChatConversationsTest.php
git commit -m "feat(chat): maintain conversation retention"
```

Do not push yet.

---

### Task 4: Canonical message replay and safe error envelope

**Files:**

- Modify: `app/Actions/Chat/CreateChatMessage.php`
- Modify: `app/Http/Controllers/Chat/ChatMessageController.php`
- Create: `app/Http/Responses/ChatErrorResponse.php`
- Modify: `bootstrap/app.php`
- Modify: `tests/Feature/Chat/ChatMessageTest.php`
- Modify: `tests/Feature/Chat/ChatCacheHeaderTest.php`
- Modify: `tests/Integration/ChatConversationConcurrencyTest.php`
- Create: `tests/Support/ConcurrentChatMessage.php`
- Create: `lang/ar/chat.php`
- Create: `lang/en/chat.php`

**Interfaces:**

- `CreateChatMessage::execute()` still returns
  `array{message: ChatMessage, demoReply: ?ChatMessage}` but replay returns the
  original reply.
- `ChatErrorResponse::render(Response $response, Throwable $exception, Request $request): Response`
  handles chat-only framework errors.

- [ ] **Step 1: Write RED replay/closed/error tests**

Add assertions that a sequential and concurrent duplicate returns the same
customer and demo reply public IDs, leaves one of each row, and never changes
`last_message_at` twice.

`ConcurrentChatMessage.php` boots the application, loads the supplied
conversation numeric ID, invokes `CreateChatMessage` with the supplied client
ID, and prints JSON containing only customer/reply public IDs. Two workers use
the same conversation/client ID and their decoded outputs must be identical.

Posting to a closed owned conversation returns 409 `conversation_closed`.
Chat validation/throttle/unexpected failures use exact codes 422
`validation_error`, 429 `rate_limited`, and sanitized 500 `chat_unavailable`,
all with no-store.

- [ ] **Step 2: Run RED tests**

```powershell
php artisan test tests/Feature/Chat/ChatMessageTest.php tests/Feature/Chat/ChatCacheHeaderTest.php
```

- [ ] **Step 3: Associate and recover canonical reply**

Create demo reply with `reply_to_message_id => $customerMessage->id`. On replay:

```php
$existingMessage->load('reply');

return [
    'message' => $existingMessage,
    'demoReply' => $existingMessage->reply,
];
```

Catch only unique client-ID contention outside the failed transaction, re-query
the owner-bound conversation message, load reply, and return it. Re-throw every
other `QueryException`.

- [ ] **Step 4: Enforce open-only sends**

After owner lookup and before content validation, return 409 when status is not
`Open`. Do not reopen on a message endpoint.

- [ ] **Step 5: Normalize framework error output**

`ChatErrorResponse` maps only chat paths. Keep controller-produced 404/409
unchanged. Convert framework responses to:

```json
{
    "error": {
        "code": "validation_error",
        "message": "The submitted chat data is invalid.",
        "details": {}
    }
}
```

for 422 and these exact localized contracts for 409/429/500:

```php
// lang/en/chat.php
'conversation_closed' => 'This conversation is closed. Start a new conversation to continue.',
'validation_error' => 'The submitted chat data is invalid.',
'rate_limited' => 'Too many chat requests. Please try again shortly.',
'unavailable' => 'Chat is temporarily unavailable. Please try again.',

// lang/ar/chat.php
'conversation_closed' => 'المحادثة مقفلة. ابدأ محادثة جديدة للمتابعة.',
'validation_error' => 'بيانات الشات المرسلة غير صالحة.',
'rate_limited' => 'طلبات الشات كثيرة الآن. حاول مرة ثانية بعد قليل.',
'unavailable' => 'الشات غير متاح مؤقتًا. حاول مرة ثانية.',
```

Always set `Cache-Control: no-store, private`. Never return exception text for 500.

- [ ] **Step 6: Run GREEN focused and MariaDB concurrency tests**

Run Step 2 and the concurrency integration test. Confirm output contains no
sentinel message content in errors.

- [ ] **Step 7: Commit Task 4 locally**

```powershell
git add app/Actions/Chat/CreateChatMessage.php app/Http/Controllers/Chat/ChatMessageController.php app/Http/Responses/ChatErrorResponse.php bootstrap/app.php lang/ar/chat.php lang/en/chat.php tests/Feature/Chat/ChatMessageTest.php tests/Feature/Chat/ChatCacheHeaderTest.php tests/Integration/ChatConversationConcurrencyTest.php tests/Support/ConcurrentChatMessage.php
git commit -m "fix(chat): recover canonical messages and errors"
```

### Backend release checkpoint

After independent reviews for Tasks 1–4:

1. run focused Chat Pest plus migration lifecycle;
2. add both new Integration suites to the MariaDB workflow;
3. fast-forward/push the four reviewed commits together to `main`;
4. require CI, MariaDB, Chromium, package, deploy, and read-only production
   health success;
5. do not expose a new UI control until Task 5.

---

### Task 5: Account-safe launcher, restart UI, safe area, and accessibility

**Files:**

- Modify: `resources/js/layouts/chat-root-layout.tsx`
- Modify: `resources/js/components/chat/chat-widget.tsx`
- Modify: `resources/js/components/chat/chat-header.tsx`
- Modify: `resources/js/components/chat/chat-composer.tsx`
- Modify: `resources/js/components/chat/chat-message-list.tsx`
- Modify: `resources/js/hooks/use-chat.ts`
- Modify: `resources/js/lib/chat-api.ts`
- Modify: `resources/js/types/chat.ts`
- Modify: `resources/css/app.css`
- Modify: relevant `resources/js/__tests__/chat/*.tsx`

**Interfaces:**

- `ChatWidgetProps` adds `surface?: 'store' | 'account'`.
- `restartConversation(locale: string): Promise<ChatConversation>` calls the
  restart route.
- `useChat` produces `restartChat`, `canRestart`, and `isRestarting`.

- [ ] **Step 1: Complete the WordPress-first UI gate**

Inspect the live launcher, current account bottom navigation, exported account
reference, and repository code. Announce/load `frontend-design`,
`ui-ux-pro-max`, `adapt`; document that only placement/lifecycle/accessibility
changes are allowed. No palette/font/component-order redesign.

- [ ] **Step 2: Write RED component/geometry contract tests**

Assert account page component passes `surface="account"`, account widget root
has the account modifier, the full dialog layer exceeds navigation layer,
textarea has `aria-label`, restart is 44px and disabled while sending/restarting,
and restart replaces conversation/messages with the returned onboarding state.

- [ ] **Step 3: Run RED chat Vitest**

```powershell
npm test -- resources/js/__tests__/chat
```

- [ ] **Step 4: Add account context and placement classes**

`ChatRootLayout` derives:

```tsx
const surface = page.component.startsWith('account/') ? 'account' : 'store';
```

Widget root uses class `chat-widget-root--account`; dialog uses a layer above
account nav. CSS at `max-width: 47.99rem` places account launcher at:

```css
bottom: calc(88px + env(safe-area-inset-bottom));
z-index: 70;
```

At 48rem and above restore current `1.5rem` desktop offset. Full mobile dialog
uses `z-index: 70` and covers navigation.

- [ ] **Step 5: Add restart API/state/control**

API POSTs `{locale}` and parses the normal conversation contract. Hook clears
error/unread/queue only after success, replaces conversation/messages/cursors,
and announces localized completion. `canRestart` is false while loading,
restarting, assistant typing, or pending sends exist.

Header renders one `MessageSquarePlus` button with localized visible tooltip/
accessible name and 44px target; existing close remains separate.

- [ ] **Step 6: Harden composer and secondary controls**

Textarea receives localized `aria-label`. Composer adds a mobile safe-area
class. Retry/load older/scroll controls receive at least 44px hit areas without
enlarging decorative icons. Preserve `dir="auto"`, reduced motion, and current
physical message ownership.

- [ ] **Step 7: Run GREEN Vitest and required viewport polish**

Run chat tests, TypeScript, focused ESLint/Prettier. Apply final `polish` skill
and verify 320/390/768/1440, RTL/LTR, visible focus, 44px targets, reduced
motion, no overflow, and no console errors without broad visual redesign.

- [ ] **Step 8: Commit Task 5 locally**

```powershell
git add resources/js/layouts/chat-root-layout.tsx resources/js/components/chat resources/js/hooks/use-chat.ts resources/js/lib/chat-api.ts resources/js/types/chat.ts resources/css/app.css resources/js/__tests__/chat
git commit -m "feat(chat): control lifecycle in account-safe UI"
```

Do not push until Task 6 browser coverage is reviewed.

---

### Task 6: Authenticated account browser regression and CI coverage

**Files:**

- Modify: `tests/Browser/storefront-smoke.spec.ts`
- Modify: `.github/workflows/tests.yml`
- Modify: `playwright.config.ts` only if deterministic timeout/setup requires it

**Interfaces:**

- Browser suite becomes seven tests: existing six plus one authenticated mobile
  account regression.

- [ ] **Step 1: Write the authenticated browser test**

At 390×844, register through `/register` using a unique synthetic
`@example.test` email and strong password. Wait for `/my-account`, then assert:

```ts
expect(launcherBox.y + launcherBox.height).toBeLessThan(navBox.y);
```

Open chat, assert dialog visible and its numeric z-index exceeds the account
navigation, close, assert launcher focus. Navigate the same session to
`/en/my-account`; assert `lang=en`, `dir=ltr`, and the same non-overlap.

- [ ] **Step 2: Run browser list and RED test**

```powershell
npx playwright test --list
npm run test:e2e -- --grep "authenticated account keeps chat above mobile navigation"
```

Expected list: seven. Expected test before Task 5 implementation: geometry or
surface assertion fails.

- [ ] **Step 3: Add new MariaDB suites to workflow**

Append both exact paths to the existing focused MariaDB Pest command:

```text
tests/Integration/ChatConversationLifecycleInvariantUpgradeTest.php
tests/Integration/ChatConversationConcurrencyTest.php
```

- [ ] **Step 4: Run GREEN browser and focused gates**

```powershell
npm run types:e2e
npm run test:e2e
npm run lint:check
npm run format:check
```

Expected: seven Chromium tests, one worker in CI, no report on success.

- [ ] **Step 5: Commit Task 6 locally**

```powershell
git add tests/Browser/storefront-smoke.spec.ts .github/workflows/tests.yml playwright.config.ts
git commit -m "test(chat): cover authenticated account launcher"
```

### UI/account release checkpoint

After independent Task 5 and Task 6 reviews, fast-forward/push both commits to
`main`. Require complete CI, MariaDB lifecycle/concurrency, seven-test Chromium,
artifact hygiene, deploy, and production route health. Mohamed then tests the
actual mobile account launcher before Phase 1 is called accepted.

---

### Task 7: Canonical documentation, production evidence, and Phase 1 handoff

**Files:**

- Modify: `docs/ai-assistant/ARCHITECTURE.md`
- Modify: `docs/ai-assistant/SECURITY.md`
- Modify: `docs/ai-assistant/UX.md`
- Modify: `docs/ai-assistant/OPERATIONS.md`
- Modify: `docs/ai-assistant/PHASES.md`
- Modify: `docs/ai-assistant/STATUS.md`
- Modify: `docs/ai-assistant/DECISIONS.md`
- Modify: `docs/ai-assistant/AUDIT.md`
- Modify: `docs/operations/hostinger-deployment.md`

**Interfaces:**

- Records actual lifecycle routes/config/schema, not planned descriptions.
- Sets Phase 1 Completion to implemented only after deployed evidence; owner
  acceptance remains pending until Mohamed explicitly confirms.

- [ ] **Step 1: Inspect production session configuration read-only**

Through the approved secure SSH path print only `config('session.driver')` and
`config('session.encrypt')`; do not print session records or `.env`. Record the
result and options. Do not change configuration in this plan without a separate
explicit approval because encryption may invalidate active sessions.

- [ ] **Step 2: Update canonical docs from implemented source**

Document close/reopen/restart/retention, invariant, maintenance, error codes,
account placement, browser fixture, configuration, and rollback behavior.
Resolve or narrow audit IDs B03/B04/B05/B06/B08/F06/F07 only where evidence
actually closes them. Keep B09 open or resolve it only from the separate
session decision.

- [ ] **Step 3: Run Docs Guard**

Verify every symbol, route, config key, duration, command, migration, link, CI
run, and production claim. Run Prettier, forbidden-term, link/path, lifecycle,
and diff checks.

- [ ] **Step 4: Run one complete final gate**

```powershell
composer ci:check
npm run test:e2e
```

GitHub remains authoritative for MariaDB and release packaging.

- [ ] **Step 5: Push documentation/status and verify production**

Commit the canonical docs, push `main`, wait for CI/deploy, then read-only check
`/`, `/en`, `/login`, `/en/login`, `/cart`, and the authenticated account route
through the synthetic/local browser plus Mohamed's real account manually. Do
not create production synthetic accounts.

- [ ] **Step 6: Stop at the Phase 1 manual gate**

Hand Mohamed this checklist:

- account launcher visible above nav;
- full sheet covers nav and closes to focused launcher;
- Arabic/English and mixed-language messages;
- New conversation creates a new public ID;
- refresh/navigation/login continuity;
- iPhone zoom/safe area/touch targets;
- old explicit thread does not reopen.

Do not write or execute the Phase 2 implementation plan until Mohamed accepts
this deployed Phase 1 release.

---

## Plan self-review

- [x] Every Phase 1 Completion requirement in the design maps to a task.
- [x] Phase 2 implementation is excluded and stays behind Phase 1 acceptance.
- [x] The new migration is forward-only and existing migration history remains
      immutable.
- [x] Schema and claim/acquisition changes are held until one safe backend
      release checkpoint.
- [x] Account UI changes are held until authenticated browser coverage is
      reviewed.
- [x] SQLite and MariaDB prove the direct database invariant and concurrency.
- [x] Conversation lifetime, restart, claim, replay, errors, maintenance,
      accessibility, and production evidence have explicit RED/GREEN gates.
- [x] No real OpenAI credential or external provider call enters CI.
- [x] Canonical docs and status move with implemented behavior.
- [x] Full gates run at release checkpoints rather than after every small edit.
