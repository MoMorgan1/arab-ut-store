# WordPress Hero and Coins Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restore the approved WordPress hero proof, exact Thmanyah typography, and WordPress amount-slider experience without changing the working server-authoritative Coins quote contract.

**Architecture:** Laravel continues to provide localized copy, public catalog data, and quantity limits through Inertia. React keeps one reducer-owned quantity state and renders the editable display, quick chips, range, adjustments, and live quote as synchronized views of that state. CSS and mechanically copied local font files reproduce the WordPress presentation without adding a UI dependency.

**Tech Stack:** Laravel 13, Inertia 3, React 19, TypeScript, CSS, Pest 4, Vitest, Testing Library.

## Global Constraints

- Arabic-first and bilingual: Arabic customer copy uses `كوينز`, never `عملات`; English remains complete and key-for-key equivalent.
- Exact hero Arabic: `كل اللي تحتاجه في FC 27، بمكان واحد`, `كوينز فيفا 27`, `بأفضل الأسعار`, and `اختر كوينزك`.
- Use calculated proof values `+8,877` customers served and `+29,161` completed orders; retain approved fixed values `+30 مليار` / `30B+` Coins delivered and `99.9%` security.
- Use self-hosted `Thmanyah Sans` for storefront body/UI and `Thmanyah Serif Display` for display headings at weights 300, 400, 500, 700, and 900.
- Exactly two platform choices: combined `PS / Xbox` and `PC`; never expose Xbox separately.
- Quantity minimum `50,000`, runtime increment `10,000`, initial `50,000`; normal console and PC max `2,000,000`; fast console max `20,000,000`.
- Quick chips appear above the range in this order: `50K`, `100K`, `500K`, `1M`, `5M`; hide chips above the active maximum.
- The range remains left-to-right in Arabic and English, matching WordPress.
- Preserve integer SAR pricing, debounce, AbortController cancellation, stale-response protection, accessible focus movement, and fail-closed quote handling.
- Do not add credentials, cart, checkout, payment, fake links, or a new npm/Composer dependency.
- Customer/order migration is a separate subsystem and plan based on the audited exports; the current WordPress database contains only nine orders and ten registered accounts, so it is not the migration source of truth.

---

### Task 1: Hero proof and exact Thmanyah typography

**Files:**
- Modify: `lang/ar/store.php`
- Modify: `lang/en/store.php`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/js/pages/store/home.tsx`
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/store/coins-home.test.tsx`
- Modify: `tests/Feature/Store/StoreTranslationParityTest.php`
- Modify: `tests/Feature/Store/HomeCoinsConfiguratorTest.php`
- Create/copy: `public/fonts/thmanyah/thmanyahsans-Light.woff2`
- Create/copy: `public/fonts/thmanyah/thmanyahsans-Medium.woff2`
- Create/copy: `public/fonts/thmanyah/thmanyahsans-Black.woff2`
- Create/copy: `public/fonts/thmanyah/thmanyahserifdisplay-Light.woff2`
- Create/copy: `public/fonts/thmanyah/thmanyahserifdisplay-Regular.woff2`
- Create/copy: `public/fonts/thmanyah/thmanyahserifdisplay-Medium.woff2`
- Create/copy: `public/fonts/thmanyah/thmanyahserifdisplay-Black.woff2`

**Interfaces:**
- Consumes: existing `store` translation prop and the `store-document` class in `resources/views/app.blade.php`.
- Produces: `CoinsStoreTranslations['hero']['stats']` as `Array<{ value: string; label: string }>` and `proof_label: string` for the accessible proof group.

- [x] **Step 1: Write failing translation and hero behavior tests**

Update the exact-copy Pest assertion and add proof parity:

```php
expect(data_get($arabic, 'hero.badge'))->toBe('كل اللي تحتاجه في FC 27، بمكان واحد')
    ->and(data_get($arabic, 'hero.title'))->toBe('كوينز فيفا 27')
    ->and(data_get($arabic, 'hero.accent'))->toBe('بأفضل الأسعار')
    ->and(data_get($arabic, 'hero.cta'))->toBe('اختر كوينزك')
    ->and(data_get($english, 'hero.cta'))->toBe('Choose your Coins')
    ->and(data_get($arabic, 'hero.stats'))->toHaveCount(4)
    ->and(data_get($english, 'hero.stats'))->toHaveCount(4);
```

Replace the old “no proof UI” Vitest expectation with behavior assertions:

```tsx
render(<StoreHome />);

expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent(
    'FIFA 27 Coins At the best prices',
);
const proof = screen.getByRole('group', { name: 'Store proof' });
expect(within(proof).getByText('+8,877')).toBeVisible();
expect(within(proof).getByText('+29,161')).toBeVisible();
expect(within(proof).getByText('30B+')).toBeVisible();
expect(within(proof).getByText('99.9%')).toBeVisible();
expect(screen.getByRole('link', { name: 'Choose your Coins' })).toHaveAttribute(
    'href',
    '#coins',
);
```

In the homepage feature test, assert `store.hero.stats` has four entries and `store.hero.proof_label` exists.

- [x] **Step 2: Run focused tests and verify RED**

Run:

```powershell
php artisan test tests/Feature/Store/StoreTranslationParityTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php
npm test -- resources/js/__tests__/store/coins-home.test.tsx
```

Expected: failures for the old hero strings, missing proof keys, and absent proof group.

- [x] **Step 3: Add the exact bilingual hero contract**

Use this TypeScript shape:

```ts
hero: {
    badge: string;
    title: string;
    accent: string;
    subtitle: string;
    cta: string;
    proof_label: string;
    stats: Array<{ value: string; label: string }>;
};
```

Render semantic proof without icons:

```tsx
<dl
    aria-label={store.hero.proof_label}
    className="store-hero__stats"
    role="group"
>
    {store.hero.stats.map((stat) => (
        <div className="store-hero__stat" key={`${stat.value}-${stat.label}`}>
            <dd>{stat.value}</dd>
            <dt>{stat.label}</dt>
        </div>
    ))}
</dl>
```

Keep the crest, background, `#coins` target, and single real CTA. Do not add the WordPress “How it works” link because its target is outside this MVP.

- [x] **Step 4: Copy and register the exact WordPress font files**

Mechanically copy the seven missing WOFF2 files from the previously extracted WordPress theme:

```text
<Codex task root>/work/wordpress-public-html-20260809/wp-content/themes/arabut-child/assets/fonts/thmanyah
```

Register all five weights for both font families with `font-display: swap`. Scope the body override to the storefront:

```css
html.store-document body,
.store-shell {
    font-family: 'Thmanyah Sans', Tahoma, Arial, sans-serif;
}

.store-hero h1,
.store-section-heading h2 {
    font-family: 'Thmanyah Serif Display', 'Thmanyah Sans', sans-serif;
}
```

Style the proof as the WordPress text-only row with thin separators, cream/gold contrast, 700-weight values, and 400-weight labels. At narrow widths, use a two-column layout only when required to prevent overflow.

- [x] **Step 5: Verify GREEN and commit**

Run:

```powershell
php artisan test tests/Feature/Store/StoreTranslationParityTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php
npm test -- resources/js/__tests__/store/coins-home.test.tsx
npm run types:check
npm run lint
npm run format:check
```

Expected: all focused suites and static checks pass.

Commit:

```powershell
git add -- lang/ar/store.php lang/en/store.php resources/js/types/coins.ts resources/js/pages/store/home.tsx resources/css/app.css resources/js/__tests__/store/coins-home.test.tsx tests/Feature/Store/StoreTranslationParityTest.php tests/Feature/Store/HomeCoinsConfiguratorTest.php public/fonts/thmanyah
git commit -m "feat: restore WordPress hero proof and typography"
```

**Task 1 completion evidence:** The WordPress hero proof and exact Thmanyah typography shipped in the Task 1 implementation/fix range ending at `947fb31`. The final release browser matrix and current full branch gates continue to verify the bilingual hero, four proof values, real `#coins` CTA, and Serif Display/Sans font split.

---

### Task 2: Reducer-owned bounded quantity behavior

**Files:**
- Create: `resources/js/__tests__/store/coins-quantity.test.ts`
- Modify: `resources/js/components/configurator/coins/configurator-state.ts`
- Modify: `resources/js/lib/money.ts`

**Interfaces:**
- Consumes: minimum, maximum, and increment supplied by the existing catalog/selection contract.
- Produces: `clampAndSnapQuantity(value, minimum, maximum, increment): number`, `formatCompactCoins(quantity): string`, reducer state `lastValidQuantity: number`, and action `{ type: 'quantity-committed'; value: number }`.

- [x] **Step 1: Write the pure behavior tests**

Create focused tests:

```ts
import { describe, expect, it } from 'vitest';

import {
    clampAndSnapQuantity,
    coinsConfiguratorReducer,
    createInitialConfiguratorState,
} from '@/components/configurator/coins/configurator-state';
import { formatCompactCoins } from '@/lib/money';

describe('Coins quantity controls', () => {
    it.each([
        [1, 50_000],
        [54_999, 50_000],
        [55_000, 60_000],
        [2_004_999, 2_000_000],
    ])('clamps and snaps %i to %i', (input, expected) => {
        expect(
            clampAndSnapQuantity(input, 50_000, 2_000_000, 10_000),
        ).toBe(expected);
    });

    it('uses the selected delivery maximum', () => {
        expect(
            clampAndSnapQuantity(20_500_000, 50_000, 20_000_000, 10_000),
        ).toBe(20_000_000);
    });

    it.each([
        [50_000, '50K'],
        [500_000, '500K'],
        [1_000_000, '1M'],
        [5_000_000, '5M'],
    ])('formats %i as %s', (input, expected) => {
        expect(formatCompactCoins(input)).toBe(expected);
    });

    it('restores the last valid quantity when a commit follows invalid typing', () => {
        const initial = createInitialConfiguratorState(50_000);
        const typed = coinsConfiguratorReducer(initial, {
            type: 'quantity-changed',
            value: '',
            validQuantity: null,
        });
        const committed = coinsConfiguratorReducer(typed, {
            type: 'quantity-committed',
            value: typed.lastValidQuantity,
        });

        expect(committed.quantityInput).toBe('50000');
        expect(committed.lastValidQuantity).toBe(50_000);
    });
});
```

- [x] **Step 2: Run the new test and verify RED**

Run:

```powershell
npm test -- resources/js/__tests__/store/coins-quantity.test.ts
```

Expected: module export/type failures because the helper, reducer field, and action do not exist.

- [x] **Step 3: Implement bounded quantity helpers and reducer actions**

Implement the helper with safe integers and exact 10K snapping:

```ts
export function clampAndSnapQuantity(
    value: number,
    minimum: number,
    maximum: number,
    increment: number,
): number {
    if (
        !Number.isSafeInteger(value) ||
        !Number.isSafeInteger(minimum) ||
        !Number.isSafeInteger(maximum) ||
        !Number.isSafeInteger(increment) ||
        increment <= 0 ||
        minimum > maximum
    ) {
        throw new RangeError('Invalid Coins quantity bounds.');
    }

    const clamped = Math.min(maximum, Math.max(minimum, value));
    const snapped = Math.round(clamped / increment) * increment;

    return Math.min(maximum, Math.max(minimum, snapped));
}
```

Add `lastValidQuantity` to state. Change `quantity-changed` to carry `validQuantity: number | null`; update `lastValidQuantity` only when non-null. Add `quantity-committed` to set `quantityInput`, `lastValidQuantity`, clear the announcement, and return the quote state to idle. Ensure platform/delivery clamping updates both quantity fields.

Implement compact labels with exact K/M output and reject non-positive or unsafe values:

```ts
export function formatCompactCoins(quantity: number): string {
    if (!Number.isSafeInteger(quantity) || quantity <= 0) {
        throw new RangeError('Coins quantity must be a positive safe integer.');
    }

    if (quantity % 1_000_000 === 0) {
        return `${quantity / 1_000_000}M`;
    }

    return `${quantity / 1_000}K`;
}
```

- [x] **Step 4: Verify GREEN and existing reducer behavior**

Run:

```powershell
npm test -- resources/js/__tests__/store/coins-quantity.test.ts resources/js/__tests__/store/coins-home.test.tsx
npm run types:check
```

Expected: quantity tests and existing homepage lifecycle tests pass.

- [x] **Step 5: Commit**

```powershell
git add -- resources/js/__tests__/store/coins-quantity.test.ts resources/js/components/configurator/coins/configurator-state.ts resources/js/lib/money.ts
git commit -m "feat: add bounded Coins quantity controls"
```

**Task 2 completion evidence:** The bounded quantity helpers and reducer contract shipped in `dae4e23`. Later slider and live-quote fixes retained the safe-integer bounds, selected-mode clamping, last-valid input restoration, and exact compact labels, with the current full branch suites covering the integrated behavior.

---

### Task 3: WordPress-identical slider UI and responsive integration

**Files:**
- Modify: `config/coins.php`
- Modify: `tests/Feature/Store/HomeCoinsConfiguratorTest.php`
- Modify: `resources/js/components/configurator/coins/amount-step.tsx`
- Modify: `resources/js/components/configurator/coins/coins-configurator.tsx`
- Modify: `resources/js/components/configurator/coins/configurator-state.ts`
- Modify: `resources/js/types/coins.ts`
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/store/coins-home.test.tsx`

**Interfaces:**
- Consumes: Task 2 `clampAndSnapQuantity`, `formatCompactCoins`, `lastValidQuantity`, and `quantity-committed`.
- Produces: synchronized editable amount, five quick chips, range, endpoint labels, adjustments, and live quote using the unchanged quote endpoint.

- [x] **Step 1: Write failing server-contract and component tests**

Change the homepage amount prop expectation to:

```php
->where('amount', [
    'minimum' => 50_000,
    'increment' => 10_000,
    'presets' => [50_000, 100_000, 500_000, 1_000_000, 5_000_000],
])
```

Add behavior tests after selecting fast PS/Xbox:

```tsx
const amountInput = screen.getByRole('textbox', {
    name: store.amount_copy.label,
});
const range = screen.getByRole('slider', {
    name: store.amount_copy.slider_label,
});

expect(range).toHaveAttribute('min', '50000');
expect(range).toHaveAttribute('max', '20000000');
expect(range).toHaveAttribute('step', '10000');
expect(range).toHaveValue('50000');
expect(screen.getByRole('button', { name: '5M' })).toBeVisible();
expect(
    screen.getByRole('button', { name: '5M' }).compareDocumentPosition(range) &
        Node.DOCUMENT_POSITION_FOLLOWING,
).toBeTruthy();

fireEvent.change(range, { target: { value: '500000' } });
expect(amountInput).toHaveValue('500000');
expect(screen.getByRole('button', { name: '+1M' })).toBeVisible();
expect(screen.getByRole('button', { name: '-1M' })).toBeVisible();
```

Add separate tests for:

- PC/normal max `2,000,000` and hidden `5M` chip;
- quick-chip, range, typed input, and ± synchronization;
- typed `55,000` normalizes to `60,000` on blur;
- empty input restores the last valid value on blur;
- `+1M` at the maximum and `-1M` at the minimum stay bounded;
- a range drag still results in one debounced quote for the final quantity;
- console-to-PC and fast-to-normal selection clamps before requesting;
- quick chips precede the range in DOM order;
- no cart, checkout, credentials, or payment control appears.

- [x] **Step 2: Run focused suites and verify RED**

Run:

```powershell
php artisan test tests/Feature/Store/HomeCoinsConfiguratorTest.php
npm test -- resources/js/__tests__/store/coins-home.test.tsx
```

Expected: failure for the missing 5M server prop, slider, adjustment controls, new translation keys, and synchronization behavior.

- [x] **Step 3: Extend the localized amount contract and server presets**

Add `5_000_000` to `config/coins.php`. Add these bilingual keys under `amount_copy` and TypeScript:

```ts
slider_label: string;
minimum_label: string;
maximum_label: string;
```

Keep customer-facing copy in locale files; compact numeric button labels remain data labels.

- [x] **Step 4: Implement the synchronized WordPress control**

`CoinsConfigurator` owns four paths into the same reducer state:

```ts
function commitQuantity(value: number) {
    invalidateQuoteRequest();
    dispatch({
        type: 'quantity-committed',
        value: clampAndSnapQuantity(
            value,
            amount.minimum,
            maximum,
            amount.increment,
        ),
    });
}

function adjustQuantity(delta: number) {
    commitQuantity(state.lastValidQuantity + delta);
}
```

Typing sends sanitized ASCII digits through `quantity-changed`; blur commits the parsed value or `lastValidQuantity`. Range and quick chips call `commitQuantity` directly. Keep the existing 300ms quote hook as the only network trigger.

`AmountStep` renders this exact order:

```tsx
<div className="coins-amount-field">...</div>
<div className="coins-quick-amounts">...</div>
<input className="coins-amount-slider" type="range" ... />
<div className="coins-slider-labels">...</div>
<div className="coins-adjustments">...</div>
<QuotePanel ... />
```

Use a local `isEditing` boolean only to switch the textbox between raw edit text and formatted display text; the reducer remains the sole numeric source of truth. Compute a CSS custom property from `(value - minimum) / (maximum - minimum)` for the gold filled track.

- [x] **Step 5: Reproduce the WordPress slider CSS responsively**

Port only the relevant proportions from `assets/css/homepage.css`: warm dark input, compact gold quick chips, a 6–8px track, circular gold thumb, red decrement buttons, green increment buttons, and the bordered live price area. Keep:

```css
.coins-amount-slider {
    direction: ltr;
    min-height: 44px;
    touch-action: pan-y;
}

.coins-quick-amounts {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
}
```

At 320px, preserve one five-chip row, 44px targets, and no page overflow. Do not copy global WordPress selectors or sticky purchase UI.

- [x] **Step 6: Verify focused and full gates**

Run:

```powershell
php artisan test tests/Feature/Store
npm test
composer ci:check
git diff --check
```

Expected: all PHP/React tests, Pint, PHPStan, ESLint, Prettier, TypeScript, and Vite build pass.

- [x] **Step 7: Verify the real browser at four breakpoints and both locales**

Start the existing local preview and verify `/` and `/en` at widths `320`, `390`, `768`, and `1440`:

- computed storefront body font is `Thmanyah Sans` and hero heading is `Thmanyah Serif Display`;
- all four proof values are readable;
- exactly two platform choices exist;
- quick chips are above the range;
- the range is left-to-right in both locales;
- PC/normal show a 2M maximum and fast shows 20M;
- dragging and ± controls produce the matching live quote;
- no horizontal overflow, console error, or dead commerce control exists.

- [x] **Step 8: Commit**

```powershell
git add -- config/coins.php tests/Feature/Store/HomeCoinsConfiguratorTest.php resources/js/components/configurator/coins/amount-step.tsx resources/js/components/configurator/coins/coins-configurator.tsx resources/js/components/configurator/coins/configurator-state.ts resources/js/types/coins.ts resources/css/app.css resources/js/__tests__/store/coins-home.test.tsx lang/ar/store.php lang/en/store.php
git commit -m "feat: restore the WordPress Coins amount selector"
```

**Task 3 completion evidence:** The WordPress amount selector shipped in `7d83785`, with quote-lifecycle hardening in `3de79fd` and `6550e7f`. The implemented control includes the editable grouped amount, five quick chips, left-to-right range, endpoint labels, eight bounded adjustments, localized limits, and server-authoritative live quote. Task 4 later superseded this task's original debounce detail with the approved immediate abortable-request behavior. Final branch verification at `c0e7114` passed 303 Pest tests (300 passed, 3 expected skips, 3,042 assertions), 144 Vitest tests, all static/build gates, and the bilingual responsive browser checks recorded in the later release-gate plan.

---

## Final handoff

After all three tasks and reviews are clean, update the existing draft PR with the implementation commits and fresh local/GitHub gate evidence. Track the customer/order migration under its separate, export-audited design and implementation plan; never commit or print source personal data.
