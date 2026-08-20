# AI Assistant Phase 0 Stabilization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use
> `superpowers:subagent-driven-development` (recommended) or
> `superpowers:executing-plans` to execute this plan task by task.

**Goal:** Close the reliability gap around the shipped Arab UT chat by auditing
its release contracts, blocking blank-screen releases with one lean Chromium
smoke, and establishing canonical AI-assistant documentation without beginning
Phase 2 AI runtime work.

**Architecture:** Keep the existing Laravel 13, Inertia 3, React 19, SQLite CI,
MariaDB production-compatible architecture unchanged. Add Playwright as a
Chromium-only deployment smoke against the locally served production build,
then place the audited product, security, UX, operational, and future-phase
truth under `docs/ai-assistant/`.

**Tech Stack:** PHP 8.3, Laravel 13, Pest 4, React 19, Inertia 3, TypeScript,
Vitest 4, Vite 8, Playwright 1.62.1, GitHub Actions, Hostinger.

**Spec:**
`docs/superpowers/specs/2026-08-20-ai-assistant-phase-0-stabilization-design.md`

## Global Constraints

- Work on `main` in the existing workspace; preserve unrelated user changes.
- Treat `5048d779204a800cede61335ef97eebc43c323f5..main` as the Phase 1 audit
  range.
- Only repair P0 production blockers or P1 security/data-integrity blockers in
  this phase. Record P2/P3 findings; do not silently expand scope.
- If the audit discovers a consequential P0/P1 repair not specified here, stop,
  show Mohamed the evidence and trade-offs, and obtain approval for the repair
  before changing its contract.
- Before the one semantic frontend edit in Task 2, re-inspect the WordPress and
  repository references, announce and load `frontend-design`, `ui-ux-pro-max`,
  `adapt`, and final `polish` as required by `AGENTS.md`. The edit must not alter
  the approved visual identity.
- Keep browser automation deliberately small: Chromium only, one CI worker, no
  screenshots on success, no visual diff, no authenticated fixtures, no message
  send, no checkout, no Firefox/WebKit.
- Run focused checks while iterating. Run the complete repository gate once at
  final handoff rather than repeatedly.
- Commit and push each completed delivery stage to `main`, wait for CI and
  deployment, then let Mohamed perform the visual/device acceptance.
- Do not add a model provider, prompt, agent loop, RAG, tool calling, streaming,
  Reverb, admin inbox, or new commerce behavior.

---

## Task 1: Audit Phase 1 and publish the severity gate

**Files:**

- Create: `docs/ai-assistant/AUDIT.md`
- Inspect: `app/Actions/Chat/*.php`
- Inspect: `app/Http/Controllers/Chat/*.php`
- Inspect: `app/Http/Middleware/EnsureChatEnabled.php`
- Inspect: `app/Http/Presenters/ChatPresenter.php`
- Inspect: `app/Listeners/ClaimGuestChatAfterLogin.php`
- Inspect: `app/Models/ChatConversation.php`
- Inspect: `app/Models/ChatMessage.php`
- Inspect: `app/ValueObjects/Chat/ChatOwner.php`
- Inspect: `database/migrations/2026_08_20_000001_create_chat_tables.php`
- Inspect: `resources/js/components/chat/*.tsx`
- Inspect: `resources/js/hooks/use-chat.ts`
- Inspect: `resources/js/layouts/chat-root-layout.tsx`
- Inspect: `resources/js/lib/chat-api.ts`
- Inspect: `resources/js/lib/chat-grouping.ts`
- Inspect: `tests/Feature/Chat/*.php`
- Inspect: `resources/js/__tests__/chat/*`

- [ ] **Step 1: Capture the exact audited change set**

Run:

```powershell
git diff --stat 5048d779204a800cede61335ef97eebc43c323f5..main
git diff --name-status 5048d779204a800cede61335ef97eebc43c323f5..main
git log --oneline 5048d779204a800cede61335ef97eebc43c323f5..main
```

Expected: only the actual Phase 1 and subsequent stabilization changes are used
as audit evidence; unrelated historical code is not presented as a new chat
finding.

- [ ] **Step 2: Run the focused baseline before judging findings**

Run:

```powershell
php artisan test tests/Feature/Chat
npm test -- resources/js/__tests__/chat
```

Expected: capture the exact pass/fail counts in `AUDIT.md`. Any failing existing
contract must be triaged before proceeding.

- [ ] **Step 3: Audit backend contracts with evidence**

For every item, record file/line evidence, current test evidence, risk, severity,
and disposition:

1. guest/user owner resolution and public-ID authorization;
2. guest HMAC storage, APP_KEY rotation, and claim-after-login continuity;
3. active/open conversation selection under repeated and concurrent requests;
4. `client_message_id` sequential and concurrent idempotency;
5. transaction boundaries and the owner/message database invariants;
6. SQLite and MariaDB migration behavior;
7. bounded cursor pagination and owner-bound history;
8. rate limits, feature flags, no-store responses, and exception shape.

The audit must explicitly decide whether the current check-then-insert path in
`CreateChatMessage` is a release blocker or a recorded concurrency-hardening
item. Do not label it P1 solely because a race is imaginable: prove whether it
can violate confidentiality, integrity, or the idempotent API response contract.

- [ ] **Step 4: Audit frontend and test contracts with evidence**

Record evidence for:

1. Inertia persistent-layout mounting and route navigation;
2. lazy conversation initialization and refresh persistence;
3. FIFO optimistic sends, retries, and stable client IDs;
4. history grouping, cursor loading, scroll anchoring, and unread state;
5. physical customer-right/assistant-left placement in Arabic and English;
6. iOS-safe composer sizing, mixed-language text direction, and send icon;
7. focus restoration, Escape, labels, live regions, and reduced motion;
8. which tests mount isolated components versus the real browser application.

- [ ] **Step 5: Write the canonical audit table and gate result**

Use this schema in `docs/ai-assistant/AUDIT.md`:

```markdown
| ID  | Severity | Contract | Evidence | Disposition |
| --- | -------- | -------- | -------- | ----------- |
```

Include:

- audited base and head SHAs;
- focused test commands and counts;
- confirmed strengths, not only failures;
- a separate `P0/P1 release gate` section;
- a P2/P3 backlog ordered by user impact;
- an explicit `Proceed` or `Stop` decision for Task 2.

Expected: `Proceed` only when no unresolved P0/P1 remains. If `Stop`, do not
continue this plan until the repair is approved, regression-tested, committed,
and reflected in the audit.

- [ ] **Step 6: Review and publish the audit stage**

Run:

```powershell
npx prettier --check docs/ai-assistant/AUDIT.md
git diff --check
git status --short
git add docs/ai-assistant/AUDIT.md
git commit -m "docs(ai): audit phase 1 chat contracts"
git push origin main
```

Wait for the `tests` and deployment workflows for this SHA. Do not interpret a
green HTTP-only deploy as browser validation; that guard arrives in Task 3.

---

## Task 2: Add the lean browser smoke locally

**Files:**

- Modify: `package.json`
- Modify: `package-lock.json`
- Create: `playwright.config.ts`
- Create: `tsconfig.playwright.json`
- Create: `tests/Browser/storefront-smoke.spec.ts`
- Modify: `resources/js/layouts/auth/auth-simple-layout.tsx`
- Modify: `resources/js/__tests__/auth/auth-storefront.test.tsx`

- [ ] **Step 1: Complete the WordPress-first UI gate for the semantic edit**

Before editing `auth-simple-layout.tsx`:

1. inspect the current WordPress login equivalent and the exported theme assets;
2. inspect the repository login at `/login` and `/en/login`;
3. announce and load `frontend-design`, `ui-ux-pro-max`, and `adapt`;
4. record that replacing the outer semantic element does not change spacing,
   typography, copy, responsive behavior, or warm black/gold styling.

This gate authorizes only `<section>` to `<main>` for the existing
`aria-labelledby="auth-page-title"` shell. Any visual deviation stops for owner
approval.

- [ ] **Step 2: Write the failing auth landmark regression**

Add to `resources/js/__tests__/auth/auth-storefront.test.tsx` beside the existing
login shell assertions:

```tsx
expect(screen.getByRole('main')).toHaveAttribute(
    'aria-labelledby',
    'auth-page-title',
);
```

Run:

```powershell
npm test -- resources/js/__tests__/auth/auth-storefront.test.tsx
```

Expected: FAIL because `AuthSimpleLayout` currently renders a `section`, not a
`main` landmark.

- [ ] **Step 3: Make the smallest semantic implementation**

In `resources/js/layouts/auth/auth-simple-layout.tsx`, replace only:

```tsx
<section
    className="auth-shell"
    aria-labelledby="auth-page-title"
    dir={direction}
>
```

with:

```tsx
<main
    className="auth-shell"
    aria-labelledby="auth-page-title"
    dir={direction}
>
```

and replace the matching `</section>` with `</main>`.

Run the focused test again. Expected: PASS with no snapshot or CSS changes.

- [ ] **Step 4: Install the pinned Playwright test runner**

Run:

```powershell
npm install --save-dev @playwright/test@1.62.1
```

Expected: `package.json` and `package-lock.json` contain exactly Playwright
1.62.1 as a development dependency. Do not install Cypress, Dusk, WebKit, or
Firefox.

- [ ] **Step 5: Add scripts and E2E type checking**

Update `package.json` scripts to add:

```json
"test:e2e": "playwright test",
"test:e2e:install": "playwright install chromium",
"types:e2e": "tsc -p tsconfig.playwright.json --noEmit"
```

Extend the existing format paths with `playwright.config.ts`,
`tsconfig.playwright.json`, and `tests/Browser/`, and insert
`npm run types:e2e` in `ci:check` before the Vite build.

Create `tsconfig.playwright.json`:

```json
{
    "extends": "./tsconfig.json",
    "compilerOptions": {
        "noEmit": true,
        "types": ["node"]
    },
    "include": ["playwright.config.ts", "tests/Browser/**/*.ts"]
}
```

Run:

```powershell
npm run types:e2e
```

Expected: FAIL until the Playwright config and smoke spec exist.

- [ ] **Step 6: Create deterministic Playwright configuration**

Create `playwright.config.ts` with:

```ts
import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8010',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
            },
        },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8010 --no-reload',
        url: 'http://127.0.0.1:8010',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: {
            CHAT_ENABLED: 'true',
            CHAT_DEMO_ASSISTANT: 'true',
        },
    },
});
```

If Laravel 13 rejects `--no-reload`, remove only that flag after confirming the
server command help. Do not switch to an extra server dependency.

- [ ] **Step 7: Write the route and mobile smoke first**

Create `tests/Browser/storefront-smoke.spec.ts` with one reusable runtime guard
and two small test groups:

```ts
import { expect, test, type Page } from '@playwright/test';

function observeRuntime(page: Page) {
    const failures: string[] = [];

    page.on('pageerror', (error) =>
        failures.push(`pageerror: ${error.message}`),
    );
    page.on('console', (message) => {
        if (message.type() === 'error') {
            failures.push(`console: ${message.text()}`);
        }
    });
    page.on('response', (response) => {
        const type = response.request().resourceType();

        if (
            response.status() >= 400 &&
            (type === 'script' || type === 'stylesheet')
        ) {
            failures.push(`${response.status()} ${type}: ${response.url()}`);
        }
    });

    return () => expect(failures).toEqual([]);
}

for (const path of ['/', '/en', '/cart']) {
    test(`storefront ${path} mounts`, async ({ page }) => {
        const expectCleanRuntime = observeRuntime(page);

        await page.goto(path);
        await expect(page.locator('#app')).not.toBeEmpty();
        await expect(page.getByRole('banner')).toBeVisible();
        await expect(page.getByRole('main')).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        expectCleanRuntime();
    });
}

for (const path of ['/login', '/en/login']) {
    test(`authentication ${path} mounts`, async ({ page }) => {
        const expectCleanRuntime = observeRuntime(page);

        await page.goto(path);
        await expect(page.locator('#app')).not.toBeEmpty();
        await expect(page.getByRole('main')).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.locator('form.auth-form')).toBeVisible();
        expectCleanRuntime();
    });
}

test('mobile home opens and closes chat without overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const expectCleanRuntime = observeRuntime(page);

    await page.goto('/');
    await expect(page.locator('#app')).not.toBeEmpty();

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);

    const launcher = page.getByRole('button', { name: 'فتح الشات' });
    await expect(launcher).toBeVisible();
    await launcher.click();

    const dialog = page.getByRole('dialog', {
        name: 'شات مساعد عرب التيميت',
    });
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('textarea')).toBeVisible();
    await dialog.getByRole('button', { name: 'إغلاق الشات' }).click();
    await expect(dialog).not.toBeAttached();
    await expect(launcher).toBeFocused();
    expectCleanRuntime();
});
```

Keep the test data-free. If a localized heading is absent on a fresh database,
fix the local test setup or use another existing accessible route contract;
do not seed fake production content or weaken the mount/runtime assertions.

- [ ] **Step 8: Install Chromium locally and make the smoke green**

Run:

```powershell
npm run test:e2e:install
npm run types:e2e
npm run test:e2e
```

Expected: six tests pass in Chromium: five desktop routes and one mobile chat
flow. No message is sent and no screenshot is created on success.

- [ ] **Step 9: Complete the required lean UI verification and polish pass**

Load the required final `polish` skill. Without adding visual changes, check
Arabic and English at 320, 390, 768, and 1440 widths for:

- the login main landmark and existing focus behavior;
- no horizontal overflow;
- unchanged typography, component order, spacing, and colors;
- chat 44px touch targets, reduced motion, open/close focus restoration;
- no browser console error.

Record only the result in the audit/status notes. Mohamed remains the final
visual acceptance owner.

---

## Task 3: Make the browser smoke block release packaging

**Files:**

- Modify: `.github/workflows/tests.yml`
- Modify: `docs/ai-assistant/INCIDENTS.md` (create a minimal incident record now;
  Task 4 expands the canonical docs set)

- [ ] **Step 1: Add the browser gate after existing CI checks**

Insert after `Run CI Checks` and before deployment-script validation/package:

```yaml
- name: Install Playwright Chromium
  run: npx playwright install --with-deps chromium

- name: Run browser smoke
  id: browser-smoke
  run: npm run test:e2e

- name: Upload Playwright report on smoke failure
  if: ${{ failure() && steps.browser-smoke.outcome == 'failure' }}
  uses: actions/upload-artifact@ea165f8d65b6e75b540449e92b4886f43607fa02 # v4
  with:
      name: playwright-report-${{ github.sha }}
      path: playwright-report/
      if-no-files-found: ignore
      retention-days: 7
```

Do not put `continue-on-error` on the smoke. Because packaging remains after
this step, a blank app or fatal JavaScript error must prevent release creation.

- [ ] **Step 2: Prove the workflow ordering statically**

Run:

```powershell
Select-String -Path .github/workflows/tests.yml -Pattern 'Run CI Checks|Install Playwright Chromium|Run browser smoke|Package verified Hostinger release'
git diff --check
```

Expected order: existing checks → Chromium install → browser smoke → package.

- [ ] **Step 3: Document the blank-screen incident and guardrail**

Create `docs/ai-assistant/INCIDENTS.md` with:

- date: 2026-08-19/20;
- symptom: successful HTTP deployment but blank React/Inertia storefront;
- technical cause: invalid persistent-layout resolution reached the production
  JavaScript bundle while server health stayed green;
- corrective commits: `53f8d40` and `29f4fb3`;
- detection gap: no real-browser mount in CI;
- prevention: this Chromium smoke runs before Hostinger packaging;
- remaining limit: it does not prove Safari visuals or checkout behavior.

- [ ] **Step 4: Run the focused stage gates**

Run:

```powershell
npm test -- resources/js/__tests__/auth/auth-storefront.test.tsx resources/js/__tests__/chat
npm run types:e2e
npm run test:e2e
npm run lint:check
npm run format:check
git diff --check
```

Expected: focused unit/component tests and all six browser smokes pass.

- [ ] **Step 5: Commit, push, and verify the real CI gate**

Run:

```powershell
git add package.json package-lock.json playwright.config.ts tsconfig.playwright.json tests/Browser/storefront-smoke.spec.ts resources/js/layouts/auth/auth-simple-layout.tsx resources/js/__tests__/auth/auth-storefront.test.tsx .github/workflows/tests.yml docs/ai-assistant/INCIDENTS.md
git commit -m "test(release): add Chromium storefront smoke"
git push origin main
```

Wait for both GitHub workflows. Confirm the `Run browser smoke` step passed
before the release artifact and Hostinger deployment proceeded. If CI differs
from local Windows behavior, repair only the deterministic runner/config issue,
rerun the focused gate, and push a follow-up commit.

---

## Task 4: Create the canonical AI-assistant documentation

**Files:**

- Create: `docs/ai-assistant/README.md`
- Modify: `docs/ai-assistant/AUDIT.md`
- Create: `docs/ai-assistant/PRODUCT.md`
- Create: `docs/ai-assistant/ARCHITECTURE.md`
- Create: `docs/ai-assistant/SECURITY.md`
- Create: `docs/ai-assistant/UX.md`
- Create: `docs/ai-assistant/AGENT-RUNTIME.md`
- Create: `docs/ai-assistant/TOOLS.md`
- Create: `docs/ai-assistant/RAG.md`
- Create: `docs/ai-assistant/ADMIN-INBOX.md`
- Create: `docs/ai-assistant/EVALS.md`
- Create: `docs/ai-assistant/OPERATIONS.md`
- Create: `docs/ai-assistant/PHASES.md`
- Create: `docs/ai-assistant/STATUS.md`
- Create: `docs/ai-assistant/DECISIONS.md`
- Modify: `docs/ai-assistant/INCIDENTS.md`
- Modify: `docs/README.md`
- Modify: `AGENTS.md`

- [ ] **Step 1: Use one status vocabulary everywhere**

Every canonical document begins with exactly one lifecycle label:

```markdown
**Lifecycle:** Implemented | Planned | Experimental | Deprecated
**Verified:** 2026-08-20
```

For mixed-state documents, label sections individually. Never describe planned
schemas, endpoints, providers, or tools as implemented.

- [ ] **Step 2: Write the index and current product contract**

`README.md` must route future agents to `STATUS.md` first, then to the relevant
domain document.

`PRODUCT.md` must state:

- purpose: website assistant and support entry point for Arab UT customers;
- users: guests and authenticated storefront customers now, support/admin later;
- implemented v1: persistent chat shell, owner-safe history, demo reply;
- excluded now: autonomous order changes, payment actions, AI guarantees;
- success: safe continuity, clear directionality, reliable release, manual owner
  acceptance.

- [ ] **Step 3: Document implemented architecture and security from code**

`ARCHITECTURE.md` maps browser components → Inertia shared config → `/chat`
controllers → actions → models/tables. Include exact implemented routes and
feature flags from the route/config files.

`SECURITY.md` records:

- guest HMAC ownership and APP_KEY rotation;
- authenticated ownership and guest claim;
- public ID authorization boundary;
- validation, rate limits, no-store policy, database constraints;
- audit findings and remaining P2/P3 risks by audit ID;
- explicit prohibition on exposing order credentials or secrets to a future
  model without an approved tool boundary.

- [ ] **Step 4: Document the approved UX and operational truth**

`UX.md` records physical customer-right/assistant-left placement independent of
text direction, Arabic/English copy intent, mobile full sheet, desktop anchored
panel, 16px iOS composer, focus/Escape/reduced-motion behavior, and Mohamed's
manual device checklist.

`OPERATIONS.md` records feature flags, safe disable procedure, focused health
commands, browser smoke command, CI report location, deployment dependency, and
incident/rollback routing. Do not copy credentials or production secrets.

- [ ] **Step 5: Write concise planned documents without fictional contracts**

Each planned file is limited to present decisions, constraints, open questions,
and entry criteria:

- `AGENT-RUNTIME.md`: provider-neutral turn boundary, budget/timeout/failure
  principles; no provider selected.
- `TOOLS.md`: read-before-write, owner authorization, idempotency, audit log, and
  confirmation requirements; no live tool exists.
- `RAG.md`: approved-source/freshness/citation requirements; no schema or vector
  store selected.
- `ADMIN-INBOX.md`: human handoff goals and operator needs; no realtime stack
  selected.
- `EVALS.md`: future safety, retrieval, tool, bilingual, latency, and regression
  categories; only current automated tests are implemented.

- [ ] **Step 6: Write phase, decision, and live status records**

`PHASES.md` distinguishes:

1. Phase 0 stabilization — implemented by this plan;
2. Phase 1 deterministic chat foundation — implemented;
3. Phase 2 AI runtime/Luna — next, not started;
4. later RAG, tools, admin inbox, and realtime phases — planned only.

`DECISIONS.md` records dated decisions including provider deferral, Chromium-only
smoke, manual visual ownership, physical message alignment, and no Phase 2 in
this scope.

`STATUS.md` contains:

- current `main` SHA from `git rev-parse HEAD`;
- last browser-verified application release SHA (the Task 3 commit);
- production deployment workflow URL/status for that application release;
- `CHAT_ENABLED` and `CHAT_DEMO_ASSISTANT` operational state without secrets;
- unresolved audit IDs and their severity;
- exact next task: Phase 2 discovery/design, not implementation;
- owner acceptance state: pending until Mohamed tests the deployed release.

- [ ] **Step 7: Add source-of-truth routing without replacing project docs**

Add an `AI Assistant` row to `docs/README.md` linking to
`ai-assistant/README.md` and `ai-assistant/STATUS.md`.

Append to root `AGENTS.md`:

```markdown
## AI assistant source of truth

Before changing chat, support, model, RAG, tool, or admin-inbox behavior, read
`docs/ai-assistant/STATUS.md`, then the relevant canonical document linked from
`docs/ai-assistant/README.md`. Historical plans and specs do not override the
newest explicit owner decision or canonical status.
```

Do not rewrite the technical co-founder agreement.

- [ ] **Step 8: Run the documentation quality gate**

Load and apply `docs-guard`, then run:

```powershell
npx prettier --check docs/ai-assistant docs/README.md AGENTS.md
rg -n "TBD|TODO|placeholder|lorem|coming soon" docs/ai-assistant
git diff --check
```

Expected: no placeholder language, no invented implemented contract, all links
resolve, and planned/implemented states are unambiguous.

- [ ] **Step 9: Commit, push, and wait for the docs stage**

Run:

```powershell
git add docs/ai-assistant docs/README.md AGENTS.md
git commit -m "docs(ai): establish canonical assistant handbook"
git push origin main
```

Wait for CI and deployment. This is a documentation stage, but it must still use
the normal release path because `main` is the production authority.

---

## Task 5: Final verification, production handoff, and stop before Phase 2

**Files:**

- Modify: `docs/ai-assistant/STATUS.md`

- [ ] **Step 1: Run the complete repository gate once**

Run:

```powershell
composer ci:check
npm run test:e2e
bash -n deploy/*.sh
git diff --check
```

Expected: Pest, PHPStan, Pint, Vitest, ESLint, Prettier, both TypeScript configs,
Vite production build, and all six Chromium smokes pass. MariaDB migration
lifecycle remains proven by the GitHub `mariadb-schema` job.

- [ ] **Step 2: Verify the deployed release without broadening the smoke**

After GitHub Actions succeeds, perform read-only production checks:

- `/`, `/en`, `/login`, `/en/login`, and `/cart` return successful HTML;
- the current production HTML references the newly deployed Vite manifest
  assets;
- no secrets or debug output are present;
- GitHub deployment completed for the verified SHA.

Do not automate a production message send or checkout.

- [ ] **Step 3: Update the status with verified evidence**

Update `STATUS.md` with:

- the application release SHA proven by the browser gate;
- the canonical documentation SHA;
- CI/deployment result and date;
- automated counts from the final gate;
- owner acceptance `Pending Mohamed manual test`;
- exact next action `Wait for Mohamed acceptance; then start Phase 2 discovery`.

This avoids claiming the docs-only status commit itself changed or revalidated
application runtime behavior.

- [ ] **Step 4: Publish the final Phase 0 status**

Run:

```powershell
git add docs/ai-assistant/STATUS.md
git commit -m "docs(ai): record phase 0 release status"
git push origin main
```

Wait for CI/deployment once more and verify the status file is present on
`main`.

- [ ] **Step 5: Hand off to Mohamed and stop**

Report only:

- audit P0/P1 gate result and material P2/P3 items;
- browser-smoke result and deployed application SHA;
- canonical documentation location;
- the short manual checklist: Arabic/English, mobile/desktop, open/close,
  mixed-language outgoing placement, typing indicator side, composer focus,
  navigation persistence, refresh;
- that Phase 2 was not started.

Do not begin Luna/AI runtime work until Mohamed explicitly accepts this release
and approves the Phase 2 design.

---

## Plan self-review

- [x] Every approved spec section maps to a task above.
- [x] Playwright remains a six-test Chromium smoke, not a broad E2E suite.
- [x] Release packaging is structurally blocked by browser failure.
- [x] The only planned UI code edit is semantic and passes the WordPress-first
      gate.
- [x] P0/P1 findings have a stop-and-approve gate; P2/P3 stay documented.
- [x] Canonical docs distinguish implemented from planned behavior.
- [x] Full automated validation runs once at final handoff; Mohamed owns visual
      acceptance.
- [x] Each delivery stage has its own commit, push, CI wait, and deployment
      checkpoint.
- [x] Phase 2 is explicitly out of scope.
