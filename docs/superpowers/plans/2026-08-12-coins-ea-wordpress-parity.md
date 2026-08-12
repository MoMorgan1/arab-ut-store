# Coins EA WordPress Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the missing WordPress fulfillment fields to the Coins EA step and persist them through the existing encrypted owner-only credential boundary.

**Architecture:** Extend the exact request and encrypted secret payload with conditional balance and server-owned confirmation metadata. React owns only transient edits; Laravel validates context, fingerprints normalized fields, encrypts persistence, and serves/updates the full payload only through no-store owner-scoped endpoints.

**Tech Stack:** Laravel 13, Eloquent encrypted casts, Pest, React/Inertia, TypeScript, Vitest, CSS.

## Global Constraints

- Exactly three distinct ASCII eight-digit backup codes.
- Current balance required only for fast console delivery.
- Companion and policy confirmations must be true.
- No secret in Inertia, URLs, storage, logs, analytics, idempotency responses, or cacheable responses.
- Existing secret rows remain readable.
- No production code before failing tests.

---

### Task 1: Extend the encrypted backend contract

**Files:**
- Modify: `app/Http/Requests/Store/CoinsCartRequest.php`
- Modify: `app/Actions/Cart/AddCoinsToCart.php`
- Modify: `app/Actions/Cart/PersistCartItemCredentials.php`
- Modify: `app/Security/CoinsCartFingerprint.php`
- Modify: `app/Http/Controllers/Store/CartItemCredentialsController.php`
- Modify: `tests/Feature/Store/CoinsCartTest.php`
- Modify: `tests/Feature/Store/CartItemCredentialsTest.php`

**Interfaces:**
- Consumes request credentials with `current_balance`, `companion_market_open`, and `policy_accepted`.
- Produces backward-compatible encrypted payload and owner-only JSON projection with server-owned `policy_version`/`policy_accepted_at`.

- [ ] Add failing request/action tests for conditional balance, confirmations, fingerprint mismatch, encrypted storage, and absence from safe responses.
- [ ] Add failing legacy-row/read/update tests for absent new keys and complete owner projection.
- [ ] Run focused Pest and confirm failures are caused by missing fields/contracts.
- [ ] Implement exact conditional validation based on selected platform/delivery and reject unknown fields.
- [ ] Extend fingerprint normalization and encrypted persistence; generate acceptance metadata server-side.
- [ ] Extend owner-only read/update projection with no-store headers and backward-compatible null/default handling.
- [ ] Run focused Pest, PHPStan, Pint, and secret/log sink scans.
- [ ] Commit backend changes.

### Task 2: Reproduce the WordPress EA step

**Files:**
- Modify: `resources/js/components/configurator/coins/credentials-step.tsx`
- Modify: `resources/js/components/configurator/coins/coins-configurator.tsx`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/js/lib/coins-cart-api.ts`
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/store/coins-credentials-flow.test.tsx`
- Modify: `resources/js/__tests__/store/coins-cart-api.test.ts`

**Interfaces:**
- Consumes selected platform/delivery and transient credential fields.
- Produces the exact backend request and safe review-step readiness state.

- [ ] Add failing Vitest cases for conditional balance, required toggles, three-code UI, help/policy links, focus/error association, loading locks, secret-free summary/URL/storage, and exact request body.
- [ ] Run focused Vitest and confirm intended failures.
- [ ] Extend types/reducer/API payload and build the compact WP hierarchy with accessible controls.
- [ ] Keep invalid values in memory for correction; normalize balance only on committed valid input.
- [ ] Add localized AR/EN copy and responsive styles.
- [ ] Run focused/full Vitest, ESLint, Prettier, TypeScript, and Vite build.
- [ ] Verify AR/EN browser matrix, conditional transitions, owner cart read/edit, keyboard, reduced motion, overflow, and console.
- [ ] Commit frontend changes.

