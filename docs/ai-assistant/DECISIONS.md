# Decision record

**Lifecycle:** Active record

**Verified:** 2026-08-20

## 2026-08-20 — Phase 1 Completion lifecycle policy

The approved deterministic-chat policy is now implemented locally:

- one owner has one open conversation;
- inactivity closes after 24 hours and only `inactive` closures may reopen within seven days;
- an explicit New conversation close never auto-reopens;
- closed guest history is retained for 30 days and authenticated history for 180 days;
- account-mobile chat sits above account navigation, with the full sheet above that navigation;
- lifecycle/reply recovery is transactional and chat failures return a safe localized error contract.

The implementation is a pre-deployment handoff, not a production decision record. Deployment, production route health, MariaDB CI evidence, and Mohamed's manual acceptance remain pending.

## 2026-08-20 — Production session confidentiality needs a separate decision

The raw guest token remains in Laravel session storage. Repository defaults are database sessions without session encryption, but the production driver and encryption values are unverified. Inspecting them is read-only and requires the approved secure path. Any encryption or driver change is separate because it may invalidate active sessions. `AI-B09` remains open until then.

## 2026-08-20 — Phase 2 remains deferred

No model provider, model, prompt runtime, RAG, tool calling, streaming, realtime support, or admin inbox is implemented in the current release candidate. The Phase 2 design requires deployed Phase 1 evidence and Mohamed's manual acceptance before implementation or release.

## 2026-08-20 — Mohamed owns visual and device acceptance

The local Chromium fixture covers the account launcher, layer ordering, focus return, and Arabic/English route fixture. Mohamed remains the final owner of the deployed Arabic/English visual, keyboard, iPhone safe-area, touch-target, and Safari acceptance.

## 2026-08-20 — Canonical status routing

Future assistant work reads [STATUS.md](STATUS.md) first and then the relevant document in [README.md](README.md). Historical plans and specs do not override the newest explicit owner decision, canonical status, or verified implementation.
