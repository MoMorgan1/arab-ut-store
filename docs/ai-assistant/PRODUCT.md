# Product contract

**Lifecycle:** Phases 1-3 and Support Handoff & Ticketing live and accepted
**Verified:** 2026-08-24

## Purpose and users

The assistant, named **نواف** (Nawaf) in Arabic and English, is the website assistant and support entry point for **عرب التيميت** (Arab Ultimate) customers. It serves guests and authenticated storefront customers. When a customer needs human support, seamless handoff connects them to authorized store staff through an integrated ticketing system.

## Implemented and accepted

- A persistent chat shell mounted across Inertia storefront navigation.
- Conversation continuity tied to the current guest or authenticated owner, with guest conversation claiming after login.
- Home view with quick topics, start/continue CTA, and a "Previous conversations" list for authenticated customers (up to 10 rows with ticket badges and relative timestamps).
- **Phase 2 AI Runtime:** Owner-scoped durable agent turns and runs, prompt guard, bilingual streaming, cost accounting, and direct OpenAI Responses adapter for Nawaf (`gpt-5.6-luna`).
- **Phase 3 Knowledge Grounding:** Curated bilingual knowledge corpus, lexical topic selection, grounding with exact-fact quoting, server-derived service cards with live rendered prices, and server-derived add-to-cart offers.
- **Human Support Handoff & Ticketing:**
  - Pinned ticket banner with three distinct states: `requested` (gold band + clock chip + ticket number), `active` (names responder, e.g. "Mohamed from the team is replying" + gold avatar), and `resolved` (green check + "Still need help?" button reopening ticket on same thread).
  - Centered paused pill in thread: "نواف متوقف مؤقتًا — الفريق يتابع محادثتك" / "Nawaf is paused — the team is following your chat".
  - Staff message bubbles with white background, solid `#d4a843` gold border, soft gold shadow, and staff initial avatar row.
  - Handoff polling lifecycle (5s start, 15s backoff after 2 min, pause on background tab, stop on resolved).
  - Transparent 404 conversation recovery without surfacing raw errors.
  - Admin inbox support with live 30s unread badge polling and sound chime on new tickets.
  - Synchronous away-customer email notification (`SupportReplyNotification`, 5-min inactivity check, 1-hr throttle) linking directly to chat without exposing transcripts.
  - 48-hour guest retention window.

## Non-Negotiable Copy Rule

No banner, notification, or system string may promise a response time. Words such as "soon", "shortly", "within", "قريبًا", and "خلال" are prohibited. "The team will reply here" / "طلبك وصل للفريق" is the ceiling.

## Excluded from the current product

- Autonomous order changes, cancellations, refunds, or fulfillment actions by AI.
- Payment initiation, capture, credential access, or other financial actions by AI.
- Model tool calling. Service cards and add-to-cart offers are derived server-side.
- Realtime WebSockets transport.
