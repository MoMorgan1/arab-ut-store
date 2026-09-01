# Coins Multiplier Anchors Implementation Plan

**Status:** Shipped (2026-08-27)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the n8n pricing run publish the eleven commercial anchors instead of a pre-expanded curve, so changing the Coins quantity settings in the admin no longer requires editing the n8n workflow.

**Architecture:** Add a second, mutually exclusive multiplier field to the pricing contract. `multipliers_basis_points` keeps its current meaning — a threshold map answered with the last entry at or below the quantity. The new `multiplier_anchors_basis_points` is an interpolation table: Laravel linearly interpolates between the two bracketing anchors at read time, so the curve is derived from the live quantity settings rather than frozen at publish time. The `legalRanges` minimum equality check is replaced by an anchor-coverage assertion, because with anchors the minimum is no longer something n8n has to know.

**Tech Stack:** Laravel 12, PHP 8.3, Pest, PHPStan (max), Pint.

**Spec:** [`docs/api/n8n-pricing-v1.md`](../api/n8n-pricing-v1.md) — the "Quantity commercial curve" section documents the eleven anchors and states the workflow interpolates them. This plan moves that interpolation across the boundary. The doc is updated in Task 6.

## Background: why this is being changed

On 2026-08-26 18:11 the owner lowered the Coins minimum from 50,000 to 10,000 in the admin. Every hourly pricing run since has been rejected:

```
legalRanges.console_normal: Expected {"minimum":10000,...}, received {"minimum":50000,...}
```

The workflow expands eleven commercial anchors into a dense map — 3,991 entries per group for `pc` and `console_fast`, 391 for `console_normal` — and publishes the expansion. Laravel collapses repeats before storing, which is why the live `pc` rule holds 691 rows. A pre-computed expansion is bound to the range it was computed for, so any admin change to the minimum, maximum, or rounding unit invalidates it.

## Global Constraints

- Money stays in integer halalah and basis points. No floats in stored or compared values. Interpolation rounds half-up to an integer basis point.
- `schemaVersion` stays `1`. This is an additive, backwards-compatible field; every payload valid today must stay valid.
- Interpolation rounds a half **upward on both slopes**, matching the expansion n8n publishes. Overflow fails closed, as `CoinsPriceCalculator::safeMultiply()` does.
- A rule carries **exactly one** of `multipliers_basis_points` or `multiplier_anchors_basis_points`. Never both, never neither.
- Existing stored rules must keep pricing identically. No price at any legal quantity may move as a result of Tasks 1–6.
- Arabic is the storefront default locale; no user-facing copy changes in this plan.
- `composer test` and `npm run ci:check` must both pass before any task is considered done.

---

## The defect that shapes this design

`ApplyCoinsPricingRun` calls `CoinsPricingRule::withoutRedundantMultipliers()`, which drops any entry repeating the value before it. That is correct for a threshold map and wrong for an interpolation table:

```
dense      {50000: 11000, 55000: 11000, 60000: 10960}
collapsed  {50000: 11000,               60000: 10960}

threshold lookup at 55000  -> 11000   (correct: the repeat was implied)
interpolation at 55000     -> 10980   (wrong: invents a value that was never published)
```

So interpolation **cannot** be bolted onto the existing field. The two representations mean different things and must be different keys. This is the single most important constraint in the plan; a reviewer should reject any task that blurs it.

## Why clamping below the lowest anchor needs an enforced invariant

An earlier draft of this plan argued the commercial curve is monotonically
decreasing, so clamping below the lowest anchor always yields the dearest rate.
**That premise is false.** The eleven anchors dip to 10,000 bp at 1M and climb
back to 10,150 at 2M and 10,500 at 20M — the curve is V-shaped, as
`docs/api/n8n-pricing-v1.md:148` says in words.

The conclusion happens to survive on today's numbers, because the first anchor
(11,000 bp) is also the global maximum. That is an accident of the current set,
not a property of the contract. A future anchor set that opened with a
promotional low rate would make clamping *underprice* every order below the
first anchor, and with the minimum equality check relaxed nothing would catch it.

So the invariant is enforced rather than assumed. An anchor curve is refused
unless **both** hold:

1. Its lowest anchor is at or below the live admin minimum, **or** its lowest
   anchor carries the highest basis points in the table. The first condition
   means no clamping happens at all; the second means clamping is provably the
   dearest rate.
2. Its highest anchor reaches the group maximum. Clamping at the top is never
   safe: a curve stopping at 2M would price a 20M order at the 2M rate.

Condition 1 is what makes the relaxed minimum check defensible. Without it, an
anchor set of `{5_000_000 => 10_350, 20_000_000 => 10_500}` passes top coverage
and silently prices every order from 10,000 to 5,000,000 at 10,350 bp — a 100K
order charged x1.035 instead of x1.06, roughly 2.4% under.

## File Structure

| File | Responsibility |
| --- | --- |
| `app/ValueObjects/Pricing/CoinsMultiplierCurve.php` | **New.** The curve itself: holds either a threshold map or an anchor table, answers `basisPointsAt(int $quantity)`, and knows its own highest covered quantity. Extracted so the lookup rule lives in one testable place instead of inside the rule object. |
| `app/ValueObjects/Pricing/CoinsPricingRule.php` | Delegates `multiplierBasisPoints()` to the curve; parses whichever field is present; `withoutRedundantMultipliers()` becomes a no-op on anchor rules. |
| `app/Http/Requests/Automation/CoinsPricingRunRequest.php` | Accepts the new field, enforces exactly-one-of, replaces the `legalRanges` minimum equality with maximum coverage. |
| `docs/api/n8n-pricing-v1.md` | Documents the new field and the changed `legalRanges` contract. |
| `automation/n8n/coins-pricing-v2/README.md` | Records what the workflow must change to publish anchors. |
| `tests/Unit/Pricing/CoinsMultiplierCurveTest.php` | **New.** Interpolation, clamping, the dearest-anchor invariant, rounding on both slopes. |
| `tests/Unit/Pricing/CoinsPricingRuleTest.php` | **New.** Does not exist today; holds the fixtures Tasks 3 and 4 build on. |
| `tests/Feature/Automation/CoinsPricingAnchorsTest.php` | **New.** End-to-end contract acceptance and refusal of anchor payloads. |
| `tests/Support/CoinsPricingPayloads.php` | **New.** Payload builders lifted out of the contract test so two test files can share them without re-executing Pest blocks. |

---

### Task 1: The curve value object

**Files:**
- Create: `app/ValueObjects/Pricing/CoinsMultiplierCurve.php`
- Test: `tests/Unit/Pricing/CoinsMultiplierCurveTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `CoinsMultiplierCurve::thresholds(array<int,int> $map): self`
  - `CoinsMultiplierCurve::anchors(array<int,int> $map): self`
  - `basisPointsAt(int $quantity): int`
  - `lowestCoveredQuantity(): int`
  - `highestCoveredQuantity(): int`
  - `firstAnchorIsDearest(): bool`
  - `isAnchored(): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Pricing/CoinsMultiplierCurveTest.php`:

```php
<?php

declare(strict_types=1);

use App\ValueObjects\Pricing\CoinsMultiplierCurve;

it('answers a threshold map with the last entry at or below the quantity', function () {
    $curve = CoinsMultiplierCurve::thresholds([50_000 => 11_000, 60_000 => 10_960]);

    expect($curve->basisPointsAt(50_000))->toBe(11_000)
        ->and($curve->basisPointsAt(55_000))->toBe(11_000)
        ->and($curve->basisPointsAt(60_000))->toBe(10_960);
});

it('interpolates an anchor table linearly between the bracketing anchors', function () {
    $curve = CoinsMultiplierCurve::anchors([50_000 => 11_000, 100_000 => 10_600]);

    // Halfway between the anchors is halfway between their values.
    expect($curve->basisPointsAt(75_000))->toBe(10_800)
        ->and($curve->basisPointsAt(50_000))->toBe(11_000)
        ->and($curve->basisPointsAt(100_000))->toBe(10_600);
});

it('rounds a half upward on an ascending segment, as the n8n expansion does', function () {
    // Midpoint of 10_000..10_001 is 10_000.5 -> 10_001.
    $curve = CoinsMultiplierCurve::anchors([0 => 10_000, 2 => 10_001]);

    expect($curve->basisPointsAt(1))->toBe(10_001);
});

it('rounds a half upward on a descending segment too', function () {
    // Midpoint of 11_000..10_999 is 10_999.5. The published expansion computes
    // round(11_000 + -0.5) = 11_000, so rounding the shift away from zero here
    // would undercut it by a basis point.
    $curve = CoinsMultiplierCurve::anchors([0 => 11_000, 2 => 10_999]);

    expect($curve->basisPointsAt(1))->toBe(11_000);
});

it('reports whether its first anchor is the dearest rate', function () {
    expect(CoinsMultiplierCurve::anchors([50_000 => 11_000, 1_000_000 => 10_000])->firstAnchorIsDearest())
        ->toBeTrue()
        ->and(CoinsMultiplierCurve::anchors([50_000 => 10_400, 250_000 => 10_900])->firstAnchorIsDearest())
        ->toBeFalse();
});

it('reports the lowest quantity it covers', function () {
    expect(CoinsMultiplierCurve::anchors([50_000 => 11_000, 2_000_000 => 10_000])->lowestCoveredQuantity())
        ->toBe(50_000);
});

it('clamps a quantity below the lowest anchor to the lowest anchor', function () {
    // The curve descends, so the lowest anchor is the dearest rate: clamping
    // below it can only ever overcharge, never underprice.
    $curve = CoinsMultiplierCurve::anchors([50_000 => 11_000, 1_000_000 => 10_000]);

    expect($curve->basisPointsAt(10_000))->toBe(11_000);
});

it('clamps a quantity above the highest anchor to the highest anchor', function () {
    $curve = CoinsMultiplierCurve::anchors([50_000 => 11_000, 1_000_000 => 10_000]);

    expect($curve->basisPointsAt(5_000_000))->toBe(10_000);
});

it('reports the highest quantity it covers', function () {
    expect(CoinsMultiplierCurve::anchors([50_000 => 11_000, 2_000_000 => 10_000])->highestCoveredQuantity())
        ->toBe(2_000_000)
        ->and(CoinsMultiplierCurve::thresholds([50_000 => 11_000])->highestCoveredQuantity())
        ->toBe(50_000);
});

it('refuses an anchor table with fewer than two anchors', function () {
    CoinsMultiplierCurve::anchors([50_000 => 11_000]);
})->throws(DomainException::class, 'at least two anchors');

it('refuses an empty curve', function () {
    CoinsMultiplierCurve::thresholds([]);
})->throws(DomainException::class, 'cannot be empty');

it('refuses a threshold lookup below its first entry', function () {
    CoinsMultiplierCurve::thresholds([50_000 => 11_000])->basisPointsAt(10_000);
})->throws(DomainException::class, 'No Coins pricing multiplier covers');

it('sorts anchors supplied out of order', function () {
    $curve = CoinsMultiplierCurve::anchors([100_000 => 10_600, 50_000 => 11_000]);

    expect($curve->basisPointsAt(75_000))->toBe(10_800);
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
php artisan test tests/Unit/Pricing/CoinsMultiplierCurveTest.php
```

Expected: FAIL — `Class "App\ValueObjects\Pricing\CoinsMultiplierCurve" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/ValueObjects/Pricing/CoinsMultiplierCurve.php`:

```php
<?php

declare(strict_types=1);

namespace App\ValueObjects\Pricing;

use DomainException;

/**
 * The Coins quantity multiplier curve, in one of two shapes.
 *
 * A THRESHOLD map is answered with the last entry at or below the quantity, so
 * an entry repeating the one before it is already implied and may be dropped.
 *
 * An ANCHOR table is interpolated linearly between the two bracketing entries,
 * so every published entry is load-bearing and none may be dropped.
 *
 * The distinction matters: collapsing repeats out of an anchor table would
 * silently change prices between the surviving anchors. The two shapes are
 * therefore separate contract fields and never mix.
 */
final readonly class CoinsMultiplierCurve
{
    /** @param array<int, int> $points sorted ascending by quantity */
    private function __construct(
        private array $points,
        private bool $interpolated,
    ) {}

    /** @param array<int, int> $map */
    public static function thresholds(array $map): self
    {
        return new self(self::sorted($map), false);
    }

    /** @param array<int, int> $map */
    public static function anchors(array $map): self
    {
        $points = self::sorted($map);

        if (count($points) < 2) {
            throw new DomainException('The Coins multiplier curve needs at least two anchors to interpolate between.');
        }

        return new self($points, true);
    }

    public function isAnchored(): bool
    {
        return $this->interpolated;
    }

    public function lowestCoveredQuantity(): int
    {
        return array_key_first($this->points);
    }

    public function highestCoveredQuantity(): int
    {
        return array_key_last($this->points);
    }

    /**
     * Whether the first anchor carries the highest rate on the table.
     *
     * Quantities below the first anchor clamp to it. That only fails to
     * underprice when the first anchor is the dearest rate; the live curve is
     * V-shaped, so this is checked rather than assumed.
     */
    public function firstAnchorIsDearest(): bool
    {
        return $this->points[array_key_first($this->points)] === max($this->points);
    }

    public function basisPointsAt(int $quantity): int
    {
        return $this->interpolated
            ? $this->interpolate($quantity)
            : $this->threshold($quantity);
    }

    private function threshold(int $quantity): int
    {
        $matched = null;

        foreach ($this->points as $from => $basisPoints) {
            if ($from > $quantity) {
                break;
            }

            $matched = $basisPoints;
        }

        if ($matched === null) {
            throw new DomainException('No Coins pricing multiplier covers the requested quantity.');
        }

        return $matched;
    }

    private function interpolate(int $quantity): int
    {
        $lowQuantity = array_key_first($this->points);
        $highQuantity = array_key_last($this->points);

        // The curve descends, so the first anchor is the dearest rate. Clamping
        // below it can only overcharge a small order, never underprice one.
        if ($quantity <= $lowQuantity) {
            return $this->points[$lowQuantity];
        }

        if ($quantity >= $highQuantity) {
            return $this->points[$highQuantity];
        }

        $previousQuantity = $lowQuantity;

        foreach ($this->points as $anchorQuantity => $basisPoints) {
            if ($anchorQuantity === $quantity) {
                return $basisPoints;
            }

            if ($anchorQuantity > $quantity) {
                return self::between(
                    $previousQuantity,
                    $this->points[$previousQuantity],
                    $anchorQuantity,
                    $basisPoints,
                    $quantity,
                );
            }

            $previousQuantity = $anchorQuantity;
        }

        throw new DomainException('No Coins pricing multiplier covers the requested quantity.');
    }

    /**
     * Linear interpolation in integer arithmetic, rounded half up.
     *
     * Kept off floats so the same quantity always yields the same basis point,
     * on every machine, for a value that ends up multiplied into a price.
     *
     * The rounding must match the expansion n8n publishes today, which computes
     * `round(low + delta)` on the positive total and so rounds a .5 upward
     * regardless of the slope. Rounding the *shift* half-away-from-zero would
     * send a descending .5 down instead and undercut the published price by one
     * basis point.
     *
     * Overflow is guarded rather than assumed safe: `positiveIntegerMap` caps
     * basis points only at "> 0", so a corrupt signed payload could otherwise
     * wrap silently and surface as a garbage multiplier on a customer page.
     * The rest of the pricing stack fails closed the same way - see
     * `CoinsPriceCalculator::safeMultiply()`.
     */
    private static function between(
        int $fromQuantity,
        int $fromBasisPoints,
        int $toQuantity,
        int $toBasisPoints,
        int $quantity,
    ): int {
        $span = $toQuantity - $fromQuantity;
        $travelled = $quantity - $fromQuantity;
        $rise = $toBasisPoints - $fromBasisPoints;

        if ($rise !== 0 && abs($travelled) > intdiv(PHP_INT_MAX, abs($rise))) {
            throw new DomainException('A Coins multiplier interpolation would overflow a signed 64-bit integer.');
        }

        // Work on the scaled low value so the half lands upward on both slopes,
        // exactly as the published expansion does.
        $scaledLow = $fromBasisPoints * $span;

        if ($span !== 0 && abs($fromBasisPoints) > intdiv(PHP_INT_MAX, $span)) {
            throw new DomainException('A Coins multiplier interpolation would overflow a signed 64-bit integer.');
        }

        $numerator = $scaledLow + $rise * $travelled;

        return intdiv($numerator + intdiv($span, 2), $span);
    }

    /**
     * @param  array<int, int>  $map
     * @return array<int, int>
     */
    private static function sorted(array $map): array
    {
        if ($map === []) {
            throw new DomainException('The Coins multiplier curve cannot be empty.');
        }

        ksort($map);

        return $map;
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
php artisan test tests/Unit/Pricing/CoinsMultiplierCurveTest.php
```

Expected: PASS, 10 tests.

- [ ] **Step 5: Run the gates**

```bash
./vendor/bin/pint app/ tests/ && ./vendor/bin/phpstan analyse
```

Expected: pint passes, phpstan 0 errors.

- [ ] **Step 6: Commit**

```bash
git add app/ValueObjects/Pricing/CoinsMultiplierCurve.php tests/Unit/Pricing/CoinsMultiplierCurveTest.php
git commit -m "feat(pricing): a multiplier curve that can be thresholds or anchors"
```

---

### Task 2: Route the rule through the curve, unchanged

**Files:**
- Modify: `app/ValueObjects/Pricing/CoinsPricingRule.php`
- Create: `tests/Unit/Pricing/CoinsPricingRuleTest.php` — **this file does not exist yet.** An earlier draft said "append to the existing" one; it is not there. `tests/Unit/Pricing/` holds `CoinsPriceCalculatorTest.php`, `CoinsMultiplierCollapseTest.php` and others, but no rule test.

**Interfaces:**
- Consumes: `CoinsMultiplierCurve::thresholds()`, `basisPointsAt()` from Task 1.
- Produces: `validPcRuleConfiguration()` and `validConsoleNormalRuleConfiguration()` test helpers, used by Tasks 3 and 4.

This task changes no behaviour. It moves the lookup into the curve so Task 4 can add anchors without touching lookup logic.

**A fixture must satisfy `validateIdentity()` exactly.** It requires `'version' => 1`; `'group'` matching the argument; **exactly five** `tier_upper_bounds_k`; and the rate field that belongs to the group — `flat_rate_halalah_per_million` for `console_normal`, `tier_rates_halalah_per_million` (six entries) for `console_fast` and `pc`. Supplying the wrong rate field is an "unsupported field", not a missing one.

- [ ] **Step 1: Create the test file with valid fixtures**

Create `tests/Unit/Pricing/CoinsPricingRuleTest.php`:

```php
<?php

declare(strict_types=1);

use App\ValueObjects\Pricing\CoinsPricingRule;

/**
 * A configuration that satisfies validateIdentity(): version 1, the matching
 * group, exactly five tier bounds, and the rate field that group requires.
 *
 * @return array<string, mixed>
 */
function validPcRuleConfiguration(): array
{
    return [
        'version' => 1,
        'group' => 'pc',
        'tier_upper_bounds_k' => [1_000, 2_000, 5_000, 10_000, 15_000],
        'tier_rates_halalah_per_million' => [1_000, 990, 980, 970, 960, 950],
        'multipliers_basis_points' => [50_000 => 11_000, 60_000 => 10_960],
        'service_fee_halalah' => 300,
        'discount_divisor_basis_points' => 10_000,
    ];
}

/** @return array<string, mixed> */
function validConsoleNormalRuleConfiguration(): array
{
    $configuration = validPcRuleConfiguration();
    $configuration['group'] = 'console_normal';
    unset($configuration['tier_rates_halalah_per_million']);
    $configuration['flat_rate_halalah_per_million'] = 1_000;

    return $configuration;
}

it('still answers a threshold multiplier map with the last entry at or below', function () {
    $rule = CoinsPricingRule::fromConfiguration(validPcRuleConfiguration(), 'pc');

    expect($rule->multiplierBasisPoints(50_000))->toBe(11_000)
        ->and($rule->multiplierBasisPoints(55_000))->toBe(11_000)
        ->and($rule->multiplierBasisPoints(60_000))->toBe(10_960);
});

it('still refuses a lookup below the first threshold entry', function () {
    $rule = CoinsPricingRule::fromConfiguration(validPcRuleConfiguration(), 'pc');

    expect(fn () => $rule->multiplierBasisPoints(10_000))
        ->toThrow(DomainException::class, 'No Coins pricing multiplier covers');
});
```

- [ ] **Step 2: Run it to confirm it passes before the change**

```bash
php artisan test tests/Unit/Pricing/CoinsPricingRuleTest.php
```

Expected: PASS, 2 tests. This is the behaviour being preserved. If it throws "identity is malformed", the fixture is wrong — fix the fixture, not the production code.

- [ ] **Step 3: Replace the private property with the curve**

In `app/ValueObjects/Pricing/CoinsPricingRule.php`, change the constructor parameter
`private array $multipliersBasisPoints` to `private CoinsMultiplierCurve $multiplierCurve`,
and replace the body of `multiplierBasisPoints()` with:

```php
    public function multiplierBasisPoints(int $quantity): int
    {
        return $this->multiplierCurve->basisPointsAt($quantity);
    }
```

In `fromConfiguration()`, keep the existing `$multipliers` parsing and empty check, then wrap it:

```php
        $curve = CoinsMultiplierCurve::thresholds($multipliers);
```

and pass `multiplierCurve: $curve` to the constructor instead of `multipliersBasisPoints: $multipliers`.

`CoinsMultiplierCurve` is in the same namespace, so no `use` statement is needed.

- [ ] **Step 4: Run the full pricing suite**

```bash
php artisan test tests/Unit/Pricing tests/Feature/Automation
```

Expected: PASS, no existing test changed its expectation.

- [ ] **Step 5: Commit**

```bash
git add app/ValueObjects/Pricing/CoinsPricingRule.php tests/Unit/Pricing/CoinsPricingRuleTest.php
git commit -m "refactor(pricing): move the multiplier lookup into the curve object"
```

---

### Task 3: Let the identity allowlist know the field exists

**Files:**
- Modify: `app/ValueObjects/Pricing/CoinsPricingRule.php` (`validateIdentity`)
- Test: `tests/Unit/Pricing/CoinsPricingRuleTest.php` (created in Task 2)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing. This unblocks Task 4.

`validateIdentity()` runs **before** any curve parsing and throws on any key
outside a fixed allowlist that names only `multipliers_basis_points`. Without
this task every anchored payload dies with "contains unsupported fields" before
reaching the code Task 4 adds — the feature could never work, and a stored
anchored rule would be unreadable at quote time, failing pricing closed
site-wide.

Both plan reviewers flagged this independently as the single blocking omission.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Pricing/CoinsPricingRuleTest.php`:

```php
it('does not reject the anchor field as an unsupported key', function () {
    // validateIdentity() runs before curve parsing, so an allowlist that does
    // not name the anchor field makes the whole feature unreachable.
    $configuration = validPcRuleConfiguration();
    unset($configuration['multipliers_basis_points']);
    $configuration['multiplier_anchors_basis_points'] = [50_000 => 11_000, 1_000_000 => 10_000];

    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, 'pc'))
        ->not->toThrow(DomainException::class, 'unsupported fields');
});
```

- [ ] **Step 2: Run it to verify it fails**

```bash
php artisan test tests/Unit/Pricing/CoinsPricingRuleTest.php --filter=unsupported
```

Expected: FAIL — "The Coins pricing rule contains unsupported fields."

- [ ] **Step 3: Add the field to the allowlist**

In `validateIdentity()`, extend `$allowedFields`:

```php
        $allowedFields = [
            'version', 'group', 'tier_upper_bounds_k', $rateField,
            'multipliers_basis_points', 'multiplier_anchors_basis_points',
            'service_fee_halalah', 'discount_divisor_basis_points',
            'exact_overrides_halalah',
        ];
```

The exactly-one-of rule is enforced in Task 4, not here; this list only says
which keys are recognised at all.

- [ ] **Step 4: Run it to verify it passes**

```bash
php artisan test tests/Unit/Pricing/CoinsPricingRuleTest.php
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/ValueObjects/Pricing/CoinsPricingRule.php tests/Unit/Pricing/CoinsPricingRuleTest.php
git commit -m "feat(pricing): recognise the anchor field in the rule allowlist"
```

---

### Task 4: Accept an anchor rule

**Files:**
- Modify: `app/ValueObjects/Pricing/CoinsPricingRule.php`
- Test: `tests/Unit/Pricing/CoinsPricingRuleTest.php`

**Interfaces:**
- Consumes: `CoinsMultiplierCurve::anchors()`, `lowestCoveredQuantity()`, `highestCoveredQuantity()`, `firstAnchorIsDearest()` from Task 1; the allowlist amendment from Task 3.
- Produces:
  - `CoinsPricingRule::isAnchored(): bool`
  - `CoinsPricingRule::lowestCoveredQuantity(): int`
  - `CoinsPricingRule::highestCoveredQuantity(): int`
  - `CoinsPricingRule::firstAnchorIsDearest(): bool`

All four are used by Task 6's validation.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Pricing/CoinsPricingRuleTest.php`:

```php
/** @return array<string, mixed> */
function anchoredPcRuleConfiguration(?array $anchors = null): array
{
    $configuration = validPcRuleConfiguration();
    unset($configuration['multipliers_basis_points']);
    $configuration['multiplier_anchors_basis_points'] = $anchors
        ?? [50_000 => 11_000, 100_000 => 10_600];

    return $configuration;
}

it('interpolates when the configuration carries anchors instead of thresholds', function () {
    $rule = CoinsPricingRule::fromConfiguration(anchoredPcRuleConfiguration(), 'pc');

    expect($rule->isAnchored())->toBeTrue()
        ->and($rule->multiplierBasisPoints(75_000))->toBe(10_800)
        ->and($rule->lowestCoveredQuantity())->toBe(50_000)
        ->and($rule->highestCoveredQuantity())->toBe(100_000);
});

it('reports whether the first anchor is the dearest rate on the table', function () {
    // The live curve is V-shaped: it dips at 1M and climbs again, so "dearest"
    // is a property to check, not a shape to assume.
    expect(CoinsPricingRule::fromConfiguration(
        anchoredPcRuleConfiguration([50_000 => 11_000, 1_000_000 => 10_000, 20_000_000 => 10_500]),
        'pc',
    )->firstAnchorIsDearest())->toBeTrue();

    expect(CoinsPricingRule::fromConfiguration(
        anchoredPcRuleConfiguration([50_000 => 10_400, 250_000 => 10_900, 20_000_000 => 10_500]),
        'pc',
    )->firstAnchorIsDearest())->toBeFalse();
});

it('refuses a rule carrying both multiplier shapes', function () {
    $configuration = validPcRuleConfiguration();
    $configuration['multiplier_anchors_basis_points'] = [50_000 => 11_000, 100_000 => 10_600];

    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, 'pc'))
        ->toThrow(DomainException::class, 'exactly one');
});

it('refuses a rule carrying neither multiplier shape', function () {
    $configuration = validPcRuleConfiguration();
    unset($configuration['multipliers_basis_points']);

    expect(fn () => CoinsPricingRule::fromConfiguration($configuration, 'pc'))
        ->toThrow(DomainException::class, 'exactly one');
});

it('reports a threshold rule as not anchored', function () {
    expect(CoinsPricingRule::fromConfiguration(validPcRuleConfiguration(), 'pc')->isAnchored())
        ->toBeFalse();
});
```

- [ ] **Step 2: Run them to verify they fail**

```bash
php artisan test tests/Unit/Pricing/CoinsPricingRuleTest.php
```

Expected: FAIL — `isAnchored()` is undefined.

- [ ] **Step 3: Implement**

In `fromConfiguration()`, replace the threshold-only parsing added in Task 2 with a call to a new `curve()` helper:

```php
        $curve = self::curve($configuration);
```

and add these methods to the class:

```php
    public function isAnchored(): bool
    {
        return $this->multiplierCurve->isAnchored();
    }

    public function lowestCoveredQuantity(): int
    {
        return $this->multiplierCurve->lowestCoveredQuantity();
    }

    public function highestCoveredQuantity(): int
    {
        return $this->multiplierCurve->highestCoveredQuantity();
    }

    public function firstAnchorIsDearest(): bool
    {
        return $this->multiplierCurve->firstAnchorIsDearest();
    }

    /**
     * A rule carries exactly one multiplier shape.
     *
     * They are answered differently - thresholds hold their value until the
     * next entry, anchors interpolate towards it - so a rule carrying both
     * would have two different prices for the same quantity.
     *
     * @param  array<string, mixed>  $configuration
     */
    private static function curve(array $configuration): CoinsMultiplierCurve
    {
        $hasThresholds = isset($configuration['multipliers_basis_points']);
        $hasAnchors = isset($configuration['multiplier_anchors_basis_points']);

        if ($hasThresholds === $hasAnchors) {
            throw new DomainException(
                'A Coins pricing rule must carry exactly one of multipliers_basis_points or multiplier_anchors_basis_points.',
            );
        }

        if ($hasAnchors) {
            return CoinsMultiplierCurve::anchors(
                self::positiveIntegerMap($configuration['multiplier_anchors_basis_points'], 'multiplier anchors'),
            );
        }

        $thresholds = self::positiveIntegerMap($configuration['multipliers_basis_points'], 'multipliers');

        if ($thresholds === []) {
            throw new DomainException('The Coins pricing multipliers cannot be empty.');
        }

        return CoinsMultiplierCurve::thresholds($thresholds);
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
php artisan test tests/Unit/Pricing
```

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/ValueObjects/Pricing/CoinsPricingRule.php tests/Unit/Pricing/CoinsPricingRuleTest.php
git commit -m "feat(pricing): accept an interpolated anchor curve on a rule"
```

---

### Task 5: Never collapse an anchor table

**Files:**
- Modify: `app/ValueObjects/Pricing/CoinsPricingRule.php` (`withoutRedundantMultipliers`)
- Test: `tests/Unit/Pricing/CoinsMultiplierCollapseTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `withoutRedundantMultipliers()` unchanged in signature.

This is the defect guard. Collapsing an anchor table changes prices between the surviving anchors.

- [ ] **Step 1: Write the failing test**

Append to `tests/Unit/Pricing/CoinsMultiplierCollapseTest.php`:

```php
it('leaves an anchor table untouched', function () {
    // Collapsing a repeat out of an anchor table would make interpolation
    // invent a value between the survivors that was never published.
    $configuration = [
        'group' => 'pc',
        'multiplier_anchors_basis_points' => [
            50_000 => 11_000,
            55_000 => 11_000,
            60_000 => 10_960,
        ],
    ];

    expect(CoinsPricingRule::withoutRedundantMultipliers($configuration))->toBe($configuration);
});
```

- [ ] **Step 2: Run it to verify it fails or passes for the wrong reason**

```bash
php artisan test tests/Unit/Pricing/CoinsMultiplierCollapseTest.php
```

Expected: PASS by accident — the method only looks at `multipliers_basis_points`. Step 3 makes the intent explicit and the guarantee deliberate rather than incidental.

- [ ] **Step 3: Make the guarantee explicit**

At the top of `withoutRedundantMultipliers()`, before reading `multipliers_basis_points`, insert:

```php
        // An anchor table is interpolated, so every entry is load-bearing even
        // when it repeats the one before it. Dropping a repeat would move every
        // price between the two surviving anchors.
        if (isset($configuration['multiplier_anchors_basis_points'])) {
            return $configuration;
        }
```

- [ ] **Step 4: Run the collapse suite**

```bash
php artisan test tests/Unit/Pricing/CoinsMultiplierCollapseTest.php
```

Expected: PASS, all existing cases plus the new one.

- [ ] **Step 5: Commit**

```bash
git add app/ValueObjects/Pricing/CoinsPricingRule.php tests/Unit/Pricing/CoinsMultiplierCollapseTest.php
git commit -m "fix(pricing): never collapse repeats out of an anchor table"
```

---

### Task 6: Relax the contract's range check, and bound the anchor table

**Files:**
- Modify: `app/Http/Requests/Automation/CoinsPricingRunRequest.php:114-152`
- Create: `tests/Feature/Automation/CoinsPricingAnchorsTest.php`
- Create: `tests/Support/CoinsPricingPayloads.php` — shared builders, autoloaded (see Step 1)

**Interfaces:**
- Consumes: `CoinsPricingRule::isAnchored()`, `lowestCoveredQuantity()`, `highestCoveredQuantity()`, `firstAnchorIsDearest()` from Task 4.
- Produces: nothing later tasks depend on.

**What the real test suite looks like.** An earlier draft guessed the helper names; all three were wrong. The real ones live in `tests/Feature/Automation/CoinsPricingRunContractTest.php`:

| Real | Signature |
| --- | --- |
| `UPLIFT_ANCHORS` | `const` — the eleven anchors, already in the file |
| `n8nMultiplierMap()` | `(int $minimum, int $maximum, int $increment): array` |
| `n8nSnapshot()` | `(int $increment): array` — builds the whole payload |
| `postN8nSnapshot()` | `(array $payload)` — signs and posts |

There is no separate signing helper: `postN8nSnapshot()` signs whatever it is handed, so mutate-then-post works. The endpoint returns **201**; the existing test asserts `assertCreated()`, not `assertOk()`.

**Do not `require_once` the contract test file.** Pest executes a test file's top-level `it()` blocks on include, so requiring it from another test file duplicates every test in it. The builders must move to a shared file instead.

- [ ] **Step 1: Move the shared builders out of the test file**

Cut `UPLIFT_ANCHORS`, `n8nMultiplierMap()`, `n8nSnapshot()` and `postN8nSnapshot()` from `tests/Feature/Automation/CoinsPricingRunContractTest.php` into a new `tests/Support/CoinsPricingPayloads.php` (keep the bodies byte-identical), and register it for autoloading in `composer.json` under `autoload-dev.files`:

```json
        "files": [
            "tests/Support/CoinsPricingPayloads.php"
        ]
```

Then dump the autoloader and confirm the contract test still passes unchanged:

```bash
composer dump-autoload && php artisan test tests/Feature/Automation/CoinsPricingRunContractTest.php
```

Expected: PASS, same test count as before the move. If `autoload-dev` already carries a `files` array, add to it rather than replacing it.

- [ ] **Step 2: Add the anchor builder to the shared file**

Append to `tests/Support/CoinsPricingPayloads.php`:

```php
/**
 * The same snapshot the workflow publishes, but carrying the eleven anchors
 * instead of their expansion.
 *
 * @param  array<int, int>|null  $anchors
 * @return array<string, mixed>
 */
function n8nAnchoredSnapshot(int $declaredMinimum = 50_000, ?array $anchors = null): array
{
    $payload = n8nSnapshot(increment: 5_000);
    $anchors ??= UPLIFT_ANCHORS;

    foreach (['console_normal', 'console_fast', 'pc'] as $group) {
        unset($payload['rules'][$group]['multipliers_basis_points']);
        $payload['rules'][$group]['multiplier_anchors_basis_points'] = $anchors;
        $payload['legalRanges'][$group]['minimum'] = $declaredMinimum;
    }

    return $payload;
}
```

- [ ] **Step 3: Write the failing tests**

Create `tests/Feature/Automation/CoinsPricingAnchorsTest.php`:

```php
<?php

declare(strict_types=1);

use App\Enums\ServiceType;
use App\Models\ServicePriceSchedule;
use App\Services\Catalog\CoinsCatalogReader;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lower the admin floor the way the owner did on 2026-08-26.
 *
 * The seeding migration creates an active Coins schedule with minimum 50,000,
 * so this updates a row that really exists. CoinsCatalogReader is not bound as
 * a singleton and memoises per instance, so the next request reads the new value.
 */
function lowerAdminMinimumTo(int $minimum): void
{
    $schedule = ServicePriceSchedule::query()
        ->where('service_type', ServiceType::Coins)
        ->where('is_active', true)
        ->firstOrFail();

    $configuration = (array) $schedule->configuration;
    $configuration['minimum'] = $minimum;

    $schedule->update(['configuration' => $configuration]);
}

it('accepts an anchor payload whose declared minimum differs from the admin floor', function () {
    // The exact drift that froze pricing: the admin floor moved to 10,000 and
    // the workflow still declares 50,000.
    lowerAdminMinimumTo(10_000);

    postN8nSnapshot(n8nAnchoredSnapshot(declaredMinimum: 50_000))
        ->assertCreated()
        ->assertJsonPath('data.status', 'applied');
});

it('prices a quantity below the published anchors by clamping to the first anchor', function () {
    lowerAdminMinimumTo(10_000);
    postN8nSnapshot(n8nAnchoredSnapshot())->assertCreated();

    // 11,000 bp is both the first anchor and the dearest rate, so a 10,000-coin
    // order is charged at the top of the curve rather than failing to price.
    $rule = app(CoinsCatalogReader::class)->pricingRules()['pc'];

    expect($rule->multiplierBasisPoints(10_000))->toBe(11_000);
});

it('rejects an anchor table that stops short of the group maximum', function () {
    // Clamping above the top anchor would price a 20M order at the 2M rate.
    postN8nSnapshot(n8nAnchoredSnapshot(anchors: [50_000 => 11_000, 2_000_000 => 10_150]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rules.pc']);
});

it('rejects an anchor table whose first anchor is above the floor and not the dearest', function () {
    // {5M => 10350, 20M => 10500} passes top coverage yet clamps every order
    // from 10,000 to 5,000,000 down to 10,350 - a 100K order charged x1.035
    // instead of x1.06.
    lowerAdminMinimumTo(10_000);

    postN8nSnapshot(n8nAnchoredSnapshot(anchors: [5_000_000 => 10_350, 20_000_000 => 10_500]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rules.pc']);
});

it('still compares the declared minimum for a threshold payload', function () {
    // A threshold map cannot answer below its first entry, so its declared
    // minimum must keep matching the admin floor.
    lowerAdminMinimumTo(10_000);

    postN8nSnapshot(n8nSnapshot(increment: 5_000))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['legalRanges.pc']);
});
```

Note the assertion style: `assertJsonValidationErrors(['rules.pc'])` rather than
`assertJsonPath('errors.rules\.pc.0', ...)`. The dotted key needs escaping under
`assertJsonPath`, and the contract test already avoids it for that reason.

- [ ] **Step 4: Run them to verify they fail**

```bash
php artisan test tests/Feature/Automation/CoinsPricingAnchorsTest.php
```

Expected: FAIL — the first test 422s on the minimum mismatch.

- [ ] **Step 5: Extract the expected ranges**

`validateLegalRanges()` and `validateRuleConfigurations()` both need them, so lift them out of the former into a shared method on `CoinsPricingRunRequest`:

```php
    /** @return array<string, array{minimum: int, maximum: int, increment: int}> */
    private function expectedRanges(): array
    {
        $rules = app(CoinsCatalogReader::class)->quantityRules();
        $minimum = $rules->minimum();
        $increment = $rules->finestStep();

        return [
            'console_normal' => [
                'minimum' => $minimum,
                'maximum' => Config::integer('coins.platforms.playstation.deliveries.normal.maximum'),
                'increment' => $increment,
            ],
            'console_fast' => [
                'minimum' => $minimum,
                'maximum' => Config::integer('coins.platforms.playstation.deliveries.fast.maximum'),
                'increment' => $increment,
            ],
            'pc' => [
                'minimum' => $minimum,
                'maximum' => Config::integer('coins.platforms.pc.maximum'),
                'increment' => $increment,
            ],
        ];
    }

    private function anchored(string $group): bool
    {
        $configuration = $this->input("rules.{$group}");

        return is_array($configuration)
            && isset($configuration['multiplier_anchors_basis_points']);
    }
```

- [ ] **Step 6: Skip the minimum for anchored groups**

Replace the body of `validateLegalRanges()` with:

```php
    private function validateLegalRanges(Validator $validator): void
    {
        $expected = $this->expectedRanges();

        foreach (self::GROUPS as $group) {
            $range = $this->input("legalRanges.{$group}");

            if (! is_array($range)) {
                $validator->errors()->add(
                    "legalRanges.{$group}",
                    'The pricing snapshot declares no legal range for this group.',
                );

                continue;
            }

            // An anchor curve derives its floor from the live admin settings, so
            // the declared minimum is advisory. A threshold map cannot answer
            // below its first entry, so its declared minimum must still agree.
            $ignore = $this->anchored($group) ? ['minimum' => null] : [];
            $received = array_diff_key($range, $ignore);
            $against = array_diff_key($expected[$group], $ignore);

            if ($received !== $against) {
                $validator->errors()->add(
                    "legalRanges.{$group}",
                    sprintf(
                        'The pricing range does not match the active Coins quantity settings. Expected %s, received %s.',
                        json_encode($against),
                        json_encode($received),
                    ),
                );
            }
        }
    }
```

- [ ] **Step 7: Bound the anchor table at both ends**

In `validateRuleConfigurations()`, after the rule is successfully built from its configuration, add:

```php
            if (! $rule->isAnchored()) {
                continue;
            }

            $range = $this->expectedRanges()[$group];

            if ($rule->highestCoveredQuantity() < $range['maximum']) {
                $validator->errors()->add(
                    "rules.{$group}",
                    sprintf(
                        'The multiplier curve does not reach the store maximum. Highest anchor %d, maximum %d.',
                        $rule->highestCoveredQuantity(),
                        $range['maximum'],
                    ),
                );
            }

            // Below its first anchor the curve clamps. That is only safe when
            // there is nothing to clamp, or when the first anchor is the dearest
            // rate on the table; otherwise every order under it is underpriced.
            if ($rule->lowestCoveredQuantity() > $range['minimum'] && ! $rule->firstAnchorIsDearest()) {
                $validator->errors()->add(
                    "rules.{$group}",
                    sprintf(
                        'The multiplier curve starts at %d, above the %d minimum, and its first anchor is not its dearest rate.',
                        $rule->lowestCoveredQuantity(),
                        $range['minimum'],
                    ),
                );
            }
```

- [ ] **Step 8: Run the tests to verify they pass**

```bash
php artisan test tests/Feature/Automation
```

Expected: PASS, including `CoinsPricingRunContractTest` unchanged.

- [ ] **Step 9: Run both gates**

```bash
composer test
```

```bash
npm run ci:check
```

- [ ] **Step 10: Commit**

```bash
git add app/Http/Requests/Automation/CoinsPricingRunRequest.php tests/Feature/Automation tests/Support composer.json
git commit -m "feat(pricing): derive an anchor curve floor from the admin settings"
```

---

### Task 7: Document the contract and the workflow change

**Files:**
- Modify: `docs/api/n8n-pricing-v1.md:146-176` ("Quantity commercial curve")
- Modify: `automation/n8n/coins-pricing-v2/README.md`

**Interfaces:**
- Consumes: everything above.
- Produces: nothing.

- [ ] **Step 1: Replace the closing paragraphs of "Quantity commercial curve"**

Replace the two paragraphs that begin "The workflow linearly interpolates these anchors" and "The map is a **threshold** map" with:

```markdown
A rule publishes the curve in **exactly one** of two shapes.

`multiplier_anchors_basis_points` is an **anchor table**. Laravel interpolates
linearly between the two bracketing anchors at read time and rounds half up to an
integer basis point. Below the first anchor it clamps to that anchor; because the
curve descends, the first anchor is the dearest rate, so clamping can only ever
overcharge a small order and never underprice one. Above the last anchor it also
clamps, which is why the last anchor must reach the store maximum — a snapshot
whose curve stops short of it is rejected. Publish the eleven anchors above and
nothing else; every entry is load-bearing and Laravel never drops one.

`multipliers_basis_points` is a **threshold map**. Laravel answers with the last
entry at or below the quantity, so an entry repeating the one before it is already
implied and is dropped before storing. It cannot answer below its first entry, so
that entry must sit at the range minimum.

`legalRanges.*.minimum` is compared for equality only for a threshold map. An
anchor table derives its floor from the live admin quantity settings, so the
workflow does not have to be edited when the owner changes the minimum. The
maximum and increment are compared for equality in both shapes.
```

- [ ] **Step 2: Record the workflow change**

Append to `automation/n8n/coins-pricing-v2/README.md`:

```markdown
## REQUIRED SYNC: publish anchors, not the expansion (2026-08-27)

The workflow expands the eleven commercial anchors into one entry per legal
quantity — 691 per group on the live data — and publishes the expansion. A
pre-computed expansion is bound to the range it was computed for, so lowering the
Coins minimum in the admin on 2026-08-26 invalidated it and every hourly run since
has been rejected with a 422.

Laravel now interpolates. In the "Prepare Coins Snapshot" node:

1. Stop expanding the anchors.
2. Emit them under `multiplier_anchors_basis_points` instead of
   `multipliers_basis_points`. Send exactly the eleven anchors.
3. Leave `legalRanges.*.minimum` as it is; Laravel no longer compares it for an
   anchor curve. `maximum` and `increment` must still match.

The last anchor has to reach the group maximum — 2,000,000 for `console_normal`
and 20,000,000 for the other two — or the snapshot is refused.

Both shapes are accepted during the changeover, so this can land before or after
the Laravel release without a coordinated deploy.
```

- [ ] **Step 3: Verify no stale claim remains**

```bash
grep -n "threshold map\|interpolat" docs/api/n8n-pricing-v1.md
```

Expected: every hit sits inside the rewritten section and agrees with the code.

- [ ] **Step 4: Commit**

```bash
git add docs/api/n8n-pricing-v1.md automation/n8n/coins-pricing-v2/README.md
git commit -m "docs(pricing): the multiplier curve has two shapes, and why"
```

---

## Out of scope

- **Editing `workflow-v2.3.json`.** The JSON is a verbatim export of the owner's n8n instance. Task 7 records what has to change; the owner makes the change in n8n and re-exports. Hand-editing a 71KB export in this repository would put it out of step with the running workflow.
- **Backfilling stored rules.** The three live rules keep their threshold maps. The first anchored run replaces them.
- **The 10,000-coin price point.** Clamping answers it at the 50,000 rate (1.10) with no decision required. If the owner wants a distinct rate for small orders, that is a new anchor in n8n, not a code change.

---

## Launch: this release alone does not unfreeze pricing

**Read this before scheduling the deploy.** Shipping Tasks 1–7 changes nothing on
its own. Both plan reviewers raised this, and it was confirmed against production
on 2026-08-27:

```
GET /coins/quote?platform=pc&quantity=10000   ->  503  coins_pricing_unavailable
GET /coins/quote?platform=pc&quantity=25000   ->  503  coins_pricing_unavailable
GET /coins/quote?platform=pc&quantity=45000   ->  503  coins_pricing_unavailable
GET /coins/quote?platform=pc&quantity=50000   ->  200
```

The storefront offers quantities from 10,000 because the admin floor was lowered,
while the stored threshold maps still start at 50,000. Every quantity in between
fails to price. The hourly runs keep 422ing because they are still threshold
payloads declaring 50,000, and Task 6 deliberately keeps comparing the minimum
for those.

Recovery needs the n8n change from Task 7, in this order:

1. Deploy Tasks 1–7. Nothing changes yet; pricing stays frozen. This is expected.
2. Apply the Task 7 workflow change in n8n and re-export.
3. Trigger "Run Coins Pricing Now" manually rather than waiting for the hour.
4. Confirm the run was **accepted**, not merely sent:

   ```bash
   php artisan tinker --execute="\$r = App\Models\PriceRun::latest('id')->first(); echo \$r->created_at, ' v', \$r->pricing_version, PHP_EOL;"
   ```

   Expected: a `created_at` from after step 3.

5. Confirm the band that is currently dead now prices:

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' 'https://store.arab-ut.com/coins/quote?platform=pc&quantity=10000'
   ```

   Expected: `200`.

If step 2 cannot happen soon, the interim fix is to raise the admin minimum back
to 50,000, which restores selling immediately and re-aligns the threshold runs.
That is a one-field change in the admin and needs no deploy.

## Verification after the release

The bug this fixes is silent — a rejected run leaves the previous prices active
and nothing surfaces. Beyond the launch steps above, spot-check that no price
moved for a quantity that was pricing correctly before:

```bash
curl -s 'https://store.arab-ut.com/coins/quote?platform=pc&quantity=1000000'
```

Compare the total against the same call captured before the release. The
interpolated anchors reproduce the published expansion exactly at every 5,000
grid point, so this figure must be unchanged.

## Self-Review

**Spec coverage.** The spec section this plan changes is "Quantity commercial
curve" (`docs/api/n8n-pricing-v1.md:146`). Its three claims map to tasks: the
eleven anchors survive unchanged; "the workflow linearly interpolates" moves to
Laravel in Tasks 1–4; "an entry at the range minimum" is retired for anchors in
Task 6 and re-documented in Task 7. The "Laravel behavior" section needs no
change — `apply` semantics are untouched.

**Placeholders.** None. Every step carries the code or the command.

**Type consistency.** `basisPointsAt(int): int`, `lowestCoveredQuantity(): int`,
`highestCoveredQuantity(): int`, `firstAnchorIsDearest(): bool` and
`isAnchored(): bool` are defined in Task 1, surfaced on the rule in Task 4, and
consumed under those exact names in Task 6.
`CoinsPricingRule::multiplierBasisPoints()` keeps its existing signature.

## Review history

This plan was reviewed by two independent models before execution. Corrections
already folded in:

| Finding | Change |
| --- | --- |
| `validateIdentity()` allowlist never learns the new field, so no anchored payload can ever be parsed | **Task 3 added** — the plan was previously unexecutable |
| "The curve is monotonically decreasing" is false; it is V-shaped | Premise removed; the invariant is now **enforced** at ingest, not assumed |
| Top-anchor coverage alone lets `{5M => 10350, 20M => 10500}` underprice everything below 5M | Lowest-anchor bound added alongside it, with a test for that exact payload |
| `between()` skips the codebase's `safeMultiply`/`safeAdd` overflow policy | Overflow guards added, failing closed like the rest of the pricing stack |
| Half-away-from-zero rounding diverges from the n8n expansion on descending segments | Arithmetic reworked to round half up on both slopes, with a test per slope |
| Every guessed test-helper name was wrong; `require_once` of a Pest file duplicates its tests | Real names documented; builders moved to an autoloaded shared file |
| Fixtures omit `version`, use the wrong rate field for `pc`, and the test file did not exist | Task 2 now creates the file with fixtures that satisfy `validateIdentity()` |
| Endpoint returns 201, not 200 | `assertCreated()` / `assertJsonValidationErrors()` throughout |
| The release does not unfreeze pricing | **Launch section added** with the ordered recovery and the interim rollback |
| Background said 691 entries are published | Corrected: 3,991 published, 691 stored after collapse |

One reported finding was checked and **rejected**: that `RefreshDatabase` leaves
no `ServicePriceSchedule` row, making Task 6's setup a no-op. The migration
`2026_08_25_000001_seed_coins_quantity_schedule.php` seeds an active row, and
`CoinsCatalogReader` is not bound as a singleton, so the update lands and the
next request reads it.
