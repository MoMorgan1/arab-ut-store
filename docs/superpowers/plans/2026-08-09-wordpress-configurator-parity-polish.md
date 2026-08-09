# WordPress Configurator Parity and Impeccable Polish Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reproduce the working WordPress Coins configurator literally, apply Mohamed's browser annotations, and then polish that same design without changing the quote contract or Arab UT identity.

**Architecture:** The existing React reducer and quote hook remain the behavioral source of truth. Component markup and storefront-scoped CSS are aligned to the exported WordPress template and `homepage.css`; accessibility-only announcements stay in the DOM but are visually hidden. A second pass applies Frontend Design, UI/UX Pro, and Impeccable checks only to hierarchy, responsiveness, focus, contrast, and touch quality.

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, storefront-scoped CSS, Pest 4, Vitest, Testing Library, in-app browser verification.

## Global Constraints

- Inspect the exported WordPress template and CSS before editing their React/CSS equivalents.
- Use `frontend-design`, `ui-ux-pro-max`, and relevant Impeccable skills for every UI task; use `polish` before delivery.
- WordPress is the primary visual authority. Generic skill output is advisory and cannot replace verified WordPress structure, assets, Thmanyah typography, or warm black/gold styling.
- Keep only the working MVP steps: platform, delivery for PS/Xbox, and amount. Do not add WordPress credential or payment steps.
- Keep exactly two platform choices: combined `PS / Xbox` and `PC`.
- Preserve server-authoritative integer pricing, debounce, abort, stale-response protection, fail-closed parsing, and quantity limits.
- Remove the visible `المحدد: ...` / `Selected: ...` strip, but preserve its screen-reader live announcement.
- Use exact Arabic amount helper copy: `اكتب الكمية اللي تبيها.`
- Remove the visible restart action; retain predictable Back navigation.
- No new npm/Composer dependency and no global WordPress selector copy.
- Verify Arabic RTL and English LTR at 320px, 390px, 768px, and 1440px; targets are at least 44px and no horizontal overflow is allowed.

---

### Task 1: Literal WordPress configurator parity

**Files:**
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/js/components/configurator/coins/coins-configurator.tsx`
- Modify: `resources/js/components/configurator/coins/progress-rail.tsx`
- Modify: `resources/js/components/configurator/coins/delivery-step.tsx`
- Modify: `resources/js/components/configurator/coins/amount-step.tsx`
- Modify: `resources/js/components/configurator/coins/quote-panel.tsx`
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/store/coins-home.test.tsx`
- Modify: `tests/Feature/Store/StoreTranslationParityTest.php`

**Interfaces:**
- Consumes: existing `navigateTo(step: CoinsStep)`, delivery `maximum` and `minutesPerMillion`, reducer-owned quantity, and `CoinsQuoteViewState`.
- Produces: `ProgressRail` prop `onNavigate: (step: CoinsStep) => void`; delivery copy keys `badge`, `maximum`, and concise helper text; a `QuotePanel` without restart props; visually hidden live selection announcements.

- [ ] **Step 1: Write failing parity and annotation tests**

Add exact translation assertions:

```php
expect(data_get($arabic, 'amount_copy.help'))->toBe('اكتب الكمية اللي تبيها.')
    ->and(data_get($arabic, 'delivery.badges.normal'))->toBe('ميزانية أقل')
    ->and(data_get($arabic, 'delivery.badges.fast'))->toBe('موصى به')
    ->and(data_get($english, 'delivery.badges.fast'))->toBe('Recommended');
```

Add React behavior assertions after choosing PS/Xbox:

```tsx
const selectionStatus = screen.getByRole('status');
expect(selectionStatus).toHaveClass('sr-only');
expect(selectionStatus).not.toBeVisible();

const progress = screen.getByRole('list', { name: /Step 1 of 3/ });
expect(within(progress).getByText(store.progress.platform)).toBeVisible();
expect(within(progress).getByText(store.progress.delivery)).toBeVisible();
expect(within(progress).getByText(store.progress.amount)).toBeVisible();
```

After continuing to delivery, assert the exact card content:

```tsx
expect(screen.getByText('Lower cost')).toBeVisible();
expect(screen.getByText('Recommended')).toBeVisible();
expect(screen.getByText(/150/)).toBeVisible();
expect(screen.getByText(/45/)).toBeVisible();
expect(screen.getByText(/2M/)).toBeVisible();
expect(screen.getByText(/20M/)).toBeVisible();
```

After continuing to amount, assert:

```tsx
expect(screen.getByText('Enter the amount you want.')).toBeVisible();
expect(screen.queryByRole('button', { name: store.actions.restart })).not.toBeInTheDocument();
expect(screen.getByRole('region', { name: store.quote.title })).toBeVisible();
```

Add a backward-progress test: after reaching amount, the completed platform and delivery steps are buttons; clicking delivery returns focus to the delivery legend. Current and future steps are not clickable.

- [ ] **Step 2: Run focused tests and capture RED**

Run:

```powershell
$env:PHPRC='C:\Users\hp\Documents\Codex\2026-08-08\hi\work\tools\php.ini'
$env:PHP_INI_SCAN_DIR=''
php artisan test tests/Feature/Store/StoreTranslationParityTest.php
npm test -- resources/js/__tests__/store/coins-home.test.tsx
```

Expected: failures for old helper copy, missing delivery badges/caps, visible selection strip, noninteractive completed steps, and visible restart action.

- [ ] **Step 3: Implement backward-only WordPress progress behavior**

Extend the progress contract:

```ts
type ProgressRailProps = {
    current: CoinsStep;
    includesDelivery: boolean;
    onNavigate: (step: CoinsStep) => void;
    translations: Pick<CoinsStoreTranslations, 'accessibility' | 'progress'>;
};
```

For each completed step, render a real button with the number and label and call `onNavigate(step)`. Render current/future steps as noninteractive content. Keep `aria-current="step"` on the current item and do not expose future steps as disabled fake buttons. Pass `navigateTo` from `CoinsConfigurator`.

- [ ] **Step 4: Hide the visual selection strip without removing accessibility feedback**

Keep the existing conditional live region but change its class to a visually hidden utility:

```tsx
{liveMessage !== '' ? (
    <p className="sr-only" role="status">
        {liveMessage}
    </p>
) : null}
```

Do not use `display: none`, `hidden`, or `aria-hidden`, because the announcement must remain available to assistive technology.

- [ ] **Step 5: Reproduce the WordPress delivery cards**

Add bilingual keys under `delivery`:

```ts
badges: Record<CoinsDeliveryValue, string>;
maximum: string; // contains :maximum
```

Render each card in WordPress order with a badge, name, ETA, and maximum:

```tsx
<span className="coins-delivery-badge">
    {translations.delivery.badges[delivery.value]}
</span>
<strong>{label}</strong>
<small>{interpolate(translations.delivery.eta, { minutes: delivery.minutesPerMillion })}</small>
<span className="coins-delivery-maximum">
    {interpolate(translations.delivery.maximum, {
        maximum: formatCompactCoins(delivery.maximum),
    })}
</span>
```

Use `ميزانية أقل` and `موصى به` in Arabic. Keep the native radio and existing `SelectionCard` focus behavior.

- [ ] **Step 6: Apply the amount and total annotations**

Set Arabic helper to `اكتب الكمية اللي تبيها.` and English to `Enter the amount you want.` Remove `onRestart` from `AmountStep` and `QuotePanel`, delete the visible restart button, and keep the quote status region.

The result markup remains semantic and stable:

```tsx
<div aria-live="polite" className="coins-quote-panel__result">
    <span>{translations.quote.total}</span>
    <strong>{formatHalalah(...)} </strong>
</div>
```

Do not turn the price into a button or CTA.

- [ ] **Step 7: Port the verified WordPress CSS structure**

Use the exported references:

```text
<Codex task root>/work/wordpress-public-html-20260809/wp-content/themes/arabut-child/templates/homepage.php
<Codex task root>/work/wordpress-public-html-20260809/wp-content/themes/arabut-child/assets/css/homepage.css
```

Port only the corresponding storefront rules:

- 44px desktop and 36px compact progress dots, connector lines behind dots, gold current state, and clear completed state;
- two-column delivery cards with WordPress deep warm surface, 16px gap, gold selected border, badge, ETA, maximum, and compact mobile treatment;
- centered amount display, five quick chips above the range, 8px track, exact gold thumb treatment, endpoint labels, and red/green adjustments;
- total row with label and tabular gold price, no nested restart link;
- WordPress spacing rhythm and max-width while retaining existing semantic tokens and focus rings.

- [ ] **Step 8: Verify Task 1 and commit**

Run:

```powershell
php artisan test tests/Feature/Store/StoreTranslationParityTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php
npm test -- resources/js/__tests__/store/coins-home.test.tsx
npm run types:check
npm run lint
npm run format:check
git diff --check
```

Commit:

```powershell
git add -- lang/ar/store.php lang/en/store.php resources/js/types/coins.ts resources/js/components/configurator/coins resources/css/app.css resources/js/__tests__/store/coins-home.test.tsx tests/Feature/Store/StoreTranslationParityTest.php
git commit -m "feat: match the WordPress Coins configurator"
```

---

### Task 2: Impeccable refinement and real-browser proof

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/store/coins-home.test.tsx`
- Modify: `docs/superpowers/specs/2026-08-09-wordpress-hero-coins-parity-design.md`
- Create: `.superpowers/sdd/2026-08-09-wordpress-configurator-parity-polish/task-2-report.md`

**Interfaces:**
- Consumes: Task 1 WordPress-parity markup and behavior.
- Produces: the same component/API contract with verified hierarchy, focus, touch sizing, responsive behavior, and browser evidence.

- [ ] **Step 1: Run the required design passes against the parity build**

Read `.impeccable.md`, then apply `frontend-design`, `ui-ux-pro-max`, `arrange`, `adapt`, `typeset`, and `polish`. Treat WordPress as the source of truth; reject any generated palette, font, layout, or effect that replaces it.

Record only actionable deltas in the task report. Allowed changes are limited to spacing, alignment, legibility, focus, contrast, responsive reflow, stable loading/error geometry, and touch target size.

- [ ] **Step 2: Add regression tests for annotation and hierarchy contracts**

Assert:

- selection announcements exist only as `sr-only` status output;
- amount helper is exact in both locales;
- restart is absent and Back remains;
- completed progress buttons are backward-only;
- quote result appears after the adjustment group in DOM order and is not a link or button;
- exactly two delivery cards remain and include badge, ETA, and maximum;
- all quick chips, adjustment controls, and navigation buttons retain accessible names.

- [ ] **Step 3: Apply the smallest Impeccable CSS refinements**

Use the existing 4/8px-derived spacing rhythm. Ensure:

- progress labels do not collide with connector lines at 320px or 807px;
- the delivery selection state uses border plus radio state, not color alone;
- the amount field is the visual entry point, controls form one readable vertical sequence, and the total is the strongest result after the input without resembling a CTA;
- the total price uses tabular figures and reserves enough width to prevent loading/success layout shift;
- all interactive targets are at least 44px with at least 8px practical separation;
- reduced-motion behavior remains intact and no decorative animation is added.

- [ ] **Step 4: Run the full automated gate**

Run:

```powershell
composer ci:check
git diff --check
```

Expected: Composer validation, Pint, PHPStan, Pest, Vitest, ESLint, Prettier, TypeScript, and Vite build all pass.

- [ ] **Step 5: Verify the real browser in both locales**

Use the existing local preview and verify `/` and `/en` at widths `320`, `390`, `768`, `807`, and `1440`:

- no visible selection strip;
- progress dots, labels, and lines do not overlap;
- delivery cards match WordPress order and styling;
- exact amount helper copy;
- amount input, chips, slider, endpoints, adjustments, and total follow the approved order;
- no restart action; Back works;
- PS/Xbox normal max is 2M, fast max is 20M, and PC max is 2M;
- live price survives unchanged blur, equivalent typed input, and min/max no-op adjustments;
- Thmanyah fonts are loaded, targets are at least 44px, focus is visible, no horizontal overflow exists, and the console has no errors.

- [ ] **Step 6: Update status and commit**

Change the design spec implementation status from pending to verified only after the browser and automated gates pass. Commit:

```powershell
git add -- resources/css/app.css resources/js/__tests__/store/coins-home.test.tsx docs/superpowers/specs/2026-08-09-wordpress-hero-coins-parity-design.md
git commit -m "fix: polish WordPress configurator parity"
```

---

## Final handoff

Run clean-code, test, and documentation guards on the final diff. Update the existing draft PR with both commits and exact gate evidence. Keep the supplied customer/order exports and all PII outside Git; their migration remains in the separate Salla history import plan.
