# Decision record

**Lifecycle:** Implemented
**Verified:** 2026-08-20

## 2026-08-20 — Provider and Phase 2 deferred

No model provider, model, prompt runtime, RAG, tool calling, streaming, realtime
support, or admin inbox is selected or implemented in Phase 0. Phase 2 is the
next discovery/design task and requires Mohamed's approval before
implementation.

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
