# Design system

## Source of truth

- Live/current Arab UT WordPress identity and approved local assets.
- Repository `.impeccable.md` and `resources/js/styles/tokens.css`.
- Local Thmanyah Sans and Thmanyah Serif Display fonts.
- Existing Radix/shadcn-derived primitives, refined rather than replaced.

## Direction

The Admin is a compact premium FC service desk: near-black/deep-brown surfaces,
warm gold for current decisions and primary actions, warm ink text, restrained
semantic status colors, and disciplined density. It is not a generic SaaS
dashboard.

Reject advisory output that introduces liquid glass, blue/purple neon, Noto
fonts, generic white analytics cards, decorative charts, or excessive motion.

## Layout

- Desktop/tablet: persistent collapsible sidebar, compact page header, bounded
  content width, and strong table/list rhythm.
- Mobile: accessible sheet navigation and compact record summaries; critical
  actions remain present.
- Use spacing/dividers before cards. Never nest cards.
- App typography uses fixed rem sizes and tabular numerals for money/sequences.

## Interaction

- Visible focus and semantic controls; 44px minimum targets.
- Status uses text/icon plus color.
- Mutation loading is explicit; financial/destructive success is never
  optimistic.
- Respect reduced motion and preserve zoom to 200%.
- The Admin surface is English-only (owner decision, 2026-08-21); validate
  English LTR at 320, 390, 768, and 1440 CSS pixels.

## Copy

- Admin copy is concise, operational English. Arabic remains native for the
  storefront and customer account—not a literal English translation.
- Buttons use verb + object. Confirmations name the exact object and consequence.
- Errors explain what happened and the safe next action without leaking internal
  identifiers or cross-owner existence.
