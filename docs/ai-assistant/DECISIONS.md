# Decision record

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## 2026-08-20 — Provider and Phase 2 deferred

No model provider, model, prompt runtime, RAG, tool calling, streaming, realtime
support, or admin inbox is selected or implemented in Phase 0. Phase 2 is the
next discovery/design task and requires Mohamed's approval before
implementation.

This records the Phase 0 boundary at that time. It was superseded for future
work by the approved decisions below; it remains true that Phase 0 implemented
no provider runtime.

## 2026-08-20 — Phase 1 lifecycle completion before AI

Phase 1 is hardened rather than rebuilt. Conversations auto-close after 24
hours, inactivity closures may reopen within seven days, explicit restarts
never reopen, guest history is retained 30 days, and authenticated history 180
days. Closed history is support-only in this version. A unique active-owner
boundary enforces one open conversation. The account launcher is moved above
the mobile account navigation and receives an authenticated browser regression.

## 2026-08-20 — OpenAI Luna through direct Laravel streaming

Phase 2 uses a provider-neutral `AgentModel` boundary with an OpenAI Responses
API adapter configured for `gpt-5.6-luna`, `store: false`, and streaming. The
existing Hostinger deployment has no permanent queue worker, so v1 uses an
owner-scoped POST stream after a server-verified 1.5-second quiet window. A
production-path feasibility gate must prove incremental delivery and disconnect
recovery. If Hostinger buffers the response, rollout stops for a new
infrastructure/product decision; cosmetic streaming is not accepted.

No live tools, RAG, commerce actions, or public AI rollout are included in
Phase 2. Deployment begins disabled, then moves to an authenticated tester
allowlist only after secure API configuration.

## 2026-08-20 — Chromium-only automated smoke

The release smoke is deliberately limited to Playwright Chromium with a small
six-test matrix and one CI worker. It blocks release packaging when the real
Laravel/Vite application does not mount cleanly, but it does not claim Safari,
checkout, or broad end-to-end coverage.

## 2026-08-20 — Mohamed owns visual acceptance

Automated checks guard mounts, runtime errors, core landmarks, mobile chat
open/close, focus restoration, and overflow. Mohamed remains the final owner of
Arabic/English visual and real-device acceptance, including iPhone behavior.

## 2026-08-20 — Physical message alignment

Customer messages remain physically right and assistant messages physically
left in both Arabic and English. Bubble text direction remains automatic so
mixed-language content is readable. Locale direction must not mirror message
ownership.

## 2026-08-20 — Canonical status routing

Future assistant work reads [STATUS.md](STATUS.md) first and then the relevant
document in [README.md](README.md). Historical plans and specs do not override
the newest explicit owner decision, canonical status, or verified
implementation.
