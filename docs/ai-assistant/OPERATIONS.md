# Operations

**Lifecycle:** Runtime deployed and active on the accepted configuration; Support Handoff & Ticketing operational
**Verified:** 2026-08-24

## Maintenance and Retention

`chat:maintain-conversations` is scheduled hourly with `withoutOverlapping()` in
`routes/console.php`. It closes open conversations whose nonnull
`last_message_at` is at or before `chat.auto_close_hours` (24 by default).

### Retention Policy
- **Guest Conversations:** Purged after 48 hours of inactivity.
- **Authenticated Conversations:** Retained for 180 days of last activity.
- Conversation deletion cascades its messages, tickets, turns, and runs.

`agent:recover-stale-turns` is scheduled every minute with
`withoutOverlapping()`. It terminalizes `waiting`/`running` turns whose
`updated_at` is at least 60 seconds old, after a locked recheck, using the safe
`stale_turn_recovered` code.

## Notifications & Operator Telemetry

- **Customer Away Notifications:** Sent synchronously via `SupportReplyNotification` when staff replies to a ticket and the customer has been inactive for >= 5 minutes. Throttled to at most 1 email per hour per ticket.
- **Admin Unread Badge & Chime:** Admin sidebar polls `GET /admin/support/unread-count` every 30 seconds. Audio chime triggers on count increase. Polling pauses on backgrounded tabs (`document.hidden`).
- **Support Inbox Telemetry:** Lists open and resolved tickets, customer/staff message counts, and response latency.

## Emergency Containment (Kill Switch)

Setting `AI_ASSISTANT_ENABLED=false` or `AI_ASSISTANT_ROLLOUT=disabled` returns all incoming turns to deterministic Phase 1 behavior immediately without a redeployment.
Setting `AI_ASSISTANT_KNOWLEDGE_MAX_TOPICS=0` disables RAG knowledge grounding dynamically.
