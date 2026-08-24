# Admin and support inbox

**Lifecycle:** Human support handoff, ticketing, and unread notifications implemented
**Verified:** 2026-08-24

The support inbox is fully implemented with human support handoff and ticketing:
- `ConversationsController` lists conversations with status, locale, owner, ticket filters, and pagination.
- `ConversationDetailController` shows full transcripts with message sender distinction (customer, assistant, staff, internal notes), ticket details, and agent turn runtime metrics.
- `SupportUnreadCountController` (`GET /admin/support/unread-count`) feeds live unread badges and audio chimes to staff across the admin sidebar on a 30s polling cycle.
- `SendStaffReply` allows authorized staff to send replies directly to customer threads, setting conversation handoff state to `active` and notifying away customers (>= 5 min inactive) via synchronous email with 1-hour throttling.
- `ResolveSupportTicket` cleanly resolves tickets, updates conversation state to `resolved`, and appends the system message indicating Nawaf has resumed.
- All endpoints sit behind `can:chat.view` in the admin MFA group under both bare and `/en` prefixes.
- `guest_key` and customer/admin IDs are never leaked to client payloads.

## Security & Access Invariants

- Protected by `can:chat.view` permission.
- Internal notes (`message_type: 'internal_note'`) are visible only to admin operators and are filtered out of all customer-facing endpoints.
- Strict database locking order: `conversation -> ticket -> turn -> run`.
- Guest conversations are excluded from the operator inbox and purged after 48 hours of inactivity.
- Away-customer emails contain no message transcripts or sensitive data.
