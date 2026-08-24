# Delivery phases

**Lifecycle:** Phases 1-3 and Human Support Handoff & Ticketing implemented and accepted
**Verified:** 2026-08-24

| Phase                                 | State                             | Boundary                                                                                                                                                                                                                                                 |
| ------------------------------------- | --------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Phase 0 stabilization                 | Implemented                       | Release audit, CI/MariaDB workflow, and Chromium smoke are in place.                                                                                                                                                                                     |
| Phase 1 deterministic chat foundation | Implemented                       | Persistent bilingual widget, owner-scoped storage, guest claim/rekey, messages, retry, pagination, and demo reply.                                                                                                                                       |
| Phase 1 Completion                    | Accepted by Mohamed on 2026-08-21 | One-open-owner invariant, lifecycle/retention, normalized errors, maintenance, owner revalidation, account placement, recovery, and browser validation are deployed. hPanel scheduling plus real-account and physical iPhone/Safari checks are complete. |
| Phase 2 AI runtime/Nawaf              | Accepted by Mohamed on 2026-08-23 | Durable provider-neutral turn/run state, prompt guard, direct Nawaf streaming, retries/recovery, usage/cost accounting, and browser integration are implemented and live. The `support-v3` batch passed every mandatory threshold.                        |
| Phase 3 grounding                     | Implemented                       | A curated bilingual knowledge corpus, lexical topic selection, grounding with exact-fact quoting, a `<live_prices>` table in the viewer's currency, and server-derived service cards on replies.                                                       |
| Human handoff and ticketing           | Implemented 2026-08-24            | Staff replies, durable ticket records, pinned ticket banners (`requested`, `active`, `resolved`), paused thread pill, staff initial bubbles, conversation history on home view, 48-hour guest retention, unread badge & chime, and away email notifications. |
| Model tools and realtime              | Not started                       | Separate discovery and owner approval are required.                                                                                                                                                                                                     |

## Phase Delivery Record

- **Lanes A & B (Merged):** Database schema (`support_tickets`, `handoff_state`, `staff_user_id`, `internal_note`), strict lock order (`conversation -> ticket -> turn -> run`), 48h guest retention, and ticket management actions (`OpenSupportTicket`, `ResolveSupportTicket`).
- **Lane C (Admin Reply UI):** Staff reply form, internal note toggle, ticket status changer in admin inbox.
- **Lane D (Customer Experience & Notifications):** Customer ticket banners (`ChatHandoffBanner`), staff bubbles with avatar rows, home view previous conversation history (up to 10 rows), 5s/15s polling with background tab pause, transparent 404 recovery, admin unread count polling & chime, synchronous customer away notification (`SupportReplyNotification`), and complete documentation suite.
