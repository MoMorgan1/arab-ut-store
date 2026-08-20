# Architecture

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## Request and rendering flow

1. `resources/js/app.tsx` asks `resources/js/lib/page-layout.ts` for the
   application layout. `ChatRootLayout` is the outer layout for storefront and
   authentication pages, so the widget state survives Inertia page navigation.
2. `app/Http/Middleware/HandleInertiaRequests.php` shares
   `chat.enabled` and `chat.demoAssistant` from `config/chat.php` on every
   Inertia response.
3. `ChatRootLayout` passes those values and the current locale to `ChatWidget`.
   A disabled widget renders nothing. Opening an enabled widget lazily starts or
   reloads the active conversation through `useChat` and `chat-api.ts`.
4. The three chat routes pass through `EnsureChatEnabled`, `NoStore`, and their
   named rate limiters before the chat controllers run.
5. Controllers resolve the current owner, validate input, scope conversation
   access by owner and public ID, invoke chat actions, and serialize bounded
   results with `ChatPresenter`.
6. Actions create or recover the active conversation, persist customer and demo
   messages, rekey rotated guest ownership, and claim guest conversations after
   login.
7. `ChatConversation` and `ChatMessage` persist to `chat_conversations` and
   `chat_messages` as defined by
   `database/migrations/2026_08_20_000001_create_chat_tables.php`.

## Routes

| Method | Path                                          | Name                       | Controller action                  |
| ------ | --------------------------------------------- | -------------------------- | ---------------------------------- |
| `POST` | `/chat/conversations`                         | `chat.conversations.store` | `ChatConversationController@store` |
| `GET`  | `/chat/conversations/{conversation}`          | `chat.conversations.show`  | `ChatConversationController@show`  |
| `POST` | `/chat/conversations/{conversation}/messages` | `chat.messages.store`      | `ChatMessageController@store`      |

These routes are registered once from `routes/web.php`; there is no separate
locale-prefixed chat route.

## Configuration

| Environment flag      | Config key            | Default | Implemented effect                                                                                                   |
| --------------------- | --------------------- | ------- | -------------------------------------------------------------------------------------------------------------------- |
| `CHAT_ENABLED`        | `chat.enabled`        | `false` | Shows the widget through shared Inertia data and permits the routes; disabled routes return a no-store 404 response. |
| `CHAT_DEMO_ASSISTANT` | `chat.demo_assistant` | `false` | Persists and returns the canned assistant reply after a new customer message.                                        |

`chat.max_message_length` is `4000`, and `chat.default_page_size` is `50`.
The controller accepts a bounded history limit from `1` through `100`.

## Persistence boundary

`chat_conversations.public_id` and `chat_messages.public_id` are external
identifiers. Internal numeric IDs drive relations and message ordering. A
conversation has exactly one authenticated `user_id` or guest HMAC
`guest_key`. Messages cascade with their conversation, and
`(conversation_id, client_message_id)` is unique for retry idempotency.

No model provider, prompt runtime, retrieval store, live tool, streaming
transport, realtime operator channel, or assistant-admin API is implemented.
