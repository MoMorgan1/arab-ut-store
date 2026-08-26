# AI Assistant Phase 0 Stabilization Design

**Date:** 2026-08-20

**Status:** Approved by Mohamed on 2026-08-20

**Complexity:** Medium

## Purpose

Finish the trust foundation for the existing Arab UT website chat before any
AI runtime work begins. This phase audits the shipped Phase 1 chat, repairs only
release-blocking findings, adds one small real-browser deployment gate, and
creates canonical project documentation that future agents can trust.

This phase does not add a model provider, RAG, tools, streaming, realtime human
support, or new commerce behavior.

## Current verified state

- Production and `main` are on the working Laravel 13, React 19, Inertia 3 chat
  foundation.
- Guest conversations, authenticated conversations, guest-to-login claim,
  APP_KEY rotation support, bounded history, optimistic sends, retry, FIFO
  client sends, grouping, responsive UI, and feature flags exist.
- Pest and Vitest coverage exists, but no browser or E2E runner is installed.
- The current CI runs PHP tests, MariaDB schema checks, Vitest, linting, static
  analysis, formatting, TypeScript, and a Vite production build.
- The 2026-08-19 blank-screen incident passed HTTP health checks because no
  existing gate mounted the real React/Inertia application in a browser.
- `docs/ai-assistant/` does not exist yet.

## Phase boundaries

### In scope

1. Audit the complete chat change range from pre-chat baseline
   `5048d779204a800cede61335ef97eebc43c323f5` through current `main`.
2. Classify findings as P0, P1, P2, or P3.
3. Repair P0 production blockers and P1 security/data-integrity blockers only.
4. Record P2 and P3 findings without expanding this phase to polish them.
5. Add a minimal Chromium Playwright smoke gate before release packaging.
6. Document the blank-screen incident and the new guardrail.
7. Create the canonical `docs/ai-assistant/` documentation foundation from the
   audited state.
8. Update root agent guidance so later agents read the canonical status before
   changing the assistant.

### Out of scope

- Luna, OpenAI, Gemini, or any model-provider integration.
- Agent turns, prompts, streaming, token accounting, or tool calling.
- RAG schema, embeddings, ingestion, or retrieval.
- Live order, price, wallet, cart, or payment tools.
- Reverb, realtime human support, or an admin inbox.
- Visual screenshot comparisons, pixel-diff testing, or a broad E2E suite.
- Firefox, WebKit, Safari emulation, or authenticated browser fixtures.
- P2/P3 cleanup unless a finding blocks the browser gate itself.

## Audit design

The audit covers the real ownership and user-visible contracts, not only code
shape.

### Backend

- conversation owner resolution for guests and users;
- public ID authorization boundaries;
- guest token handling and APP_KEY rotation;
- guest-to-login claim continuity;
- active conversation creation races;
- message idempotency and duplicate-request concurrency;
- transaction and database invariants;
- SQLite/MariaDB compatibility;
- bounded pagination and cursor ownership;
- rate limiting, feature flags, no-store policy, and exception responses.

### Frontend

- Inertia persistent layout integration;
- lazy initialization and navigation persistence;
- FIFO optimistic send behavior and stable retries;
- grouping, pagination, scroll anchoring, and unread state;
- Arabic/English message direction contracts;
- mobile composer behavior and reduced motion;
- keyboard, focus restoration, labels, and live announcements.

### Tests

Each existing test is checked for whether it proves a real contract or merely a
mocked implementation. Missing P0/P1 regression coverage is added with the
repair. P2/P3 test ideas are documented rather than implemented in this phase.

## Minimal browser smoke gate

### Dependency and browser

- Add `@playwright/test@1.62.1` as a development dependency and lock it in
  `package-lock.json`.
- Install Chromium only in CI.
- Use one worker in CI for deterministic execution.
- Do not add Cypress, Dusk, WebKit, Firefox, visual snapshots, or a second E2E
  framework.

### Local application

Playwright uses its `webServer` configuration to start the built Laravel
application on a dedicated local port. The server uses the CI-created
production Vite build, the disposable CI database, and explicit chat demo
feature flags. It never targets production and never requires production
credentials.

### Automated matrix

#### Desktop Chromium at 1440px

- `/`
- `/en`
- `/login`
- `/en/login`
- `/cart`

For each route, assert only that:

- the Inertia application mounts;
- storefront pages expose their accessible banner and main region;
- authentication pages expose their main region and login form;
- the cart exposes its main region and localized cart heading;
- no uncaught page error occurs;
- no console error occurs;
- no required JavaScript or stylesheet response fails.

Locators use accessible roles, labels, and route-specific visible headings. They
do not depend on Tailwind class names, generated asset hashes, or DOM depth.

#### Mobile Chromium at 390px

- open `/`;
- confirm the document has no catastrophic horizontal overflow;
- confirm the chat launcher is visible;
- open the chat and confirm the dialog and composer appear;
- close the chat and confirm focus returns to the launcher;
- assert no uncaught page or console error.

### Deliberate limitations

The smoke does not send messages, seed accounts, test checkout, compare pixels,
or claim Safari correctness. Mohamed remains the primary visual and real-device
acceptance tester. The automated gate exists only to catch blank screens,
broken assets, fatal JavaScript errors, and catastrophic mobile mounting issues.

## CI integration

The existing `ci` job remains the release authority.

1. Install application dependencies as today.
2. Run the existing complete PHP and frontend gates.
3. Install Chromium and its Linux dependencies.
4. Run the small Playwright smoke with one worker.
5. On smoke failure, upload the Playwright HTML report with seven-day
   retention; do not retain reports from successful runs.
6. Package the Hostinger release only after the smoke succeeds.

The MariaDB schema job remains unchanged and continues in parallel.

## Canonical documentation

Create concise documents under `docs/ai-assistant/`:

- `README.md`
- `PRODUCT.md`
- `ARCHITECTURE.md`
- `SECURITY.md`
- `UX.md`
- `AGENT-RUNTIME.md`
- `TOOLS.md`
- `RAG.md`
- `ADMIN-INBOX.md`
- `EVALS.md`
- `OPERATIONS.md`
- `PHASES.md`
- `STATUS.md`
- `DECISIONS.md`
- `INCIDENTS.md`

Every document distinguishes `Implemented`, `Planned`, `Experimental`, and
`Deprecated`. Planned documents stay concise and do not invent schema or APIs.
`STATUS.md` records the verified `main` and production SHAs, feature flags,
known findings, open decisions, exact next task, and required manual acceptance.

## Delivery stages

To keep every push reviewable:

1. **Audit and blocker repair:** publish findings and any P0/P1 fixes.
2. **Browser smoke gate:** add Playwright, the minimal spec, and CI integration.
3. **Canonical documentation:** create `docs/ai-assistant/`, incident history,
   and root-agent routing.
4. **Final Phase 0/1 status:** run all gates, deploy through the normal path,
   verify production, update `STATUS.md`, and stop before Phase 2.

If a stage has no code change, its evidence is recorded in the documentation
stage instead of creating an empty commit.

## Verification

### Automated

- focused Chat Pest;
- full Pest;
- focused chat Vitest;
- full Vitest;
- PHPStan;
- Pint;
- ESLint;
- Prettier;
- TypeScript;
- Vite production build;
- MariaDB migration/schema workflow;
- minimal Playwright Chromium smoke.

### Owner manual acceptance

Mohamed performs the final visual check on real mobile and desktop devices,
including Arabic and English, chat open/close, mixed-language outgoing messages,
typing indicator placement, composer focus, navigation persistence, and refresh.

## Risks and controls

- **Longer CI:** Chromium only, one worker, and a deliberately small matrix.
- **Flaky E2E:** no third-party calls, no visual snapshots, web-first assertions,
  and a disposable local application.
- **Scope expansion from audit findings:** only P0/P1 is repaired now; P2/P3 is
  recorded.
- **Documentation fiction:** docs describe the audited implementation and label
  future architecture as planned.
- **False confidence about Safari:** real iPhone acceptance remains manual.

## Accounts, services, and credentials

No new account, hosted service, production secret, or external credential is
required. Playwright and Chromium are development/CI dependencies only.

## Completion gate

Phase 0/1 is complete only when:

- no unresolved P0/P1 finding remains;
- the browser smoke blocks release packaging on a fatal frontend failure;
- the canonical docs describe the audited state;
- all existing and new gates pass;
- the verified SHA is deployed;
- Mohamed completes the manual acceptance checklist;
- `STATUS.md` identifies Phase 2 as next but Phase 2 has not started.
