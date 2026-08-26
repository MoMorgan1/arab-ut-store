# Coins Pricing v1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Salla-bound Coins price updater with a signed, tier-driven pricing snapshot that Laravel can evaluate for every legal quantity, including PC quantities up to 20M.

**Architecture:** n8n will probe FFT and UTT through credentialed HTTP Request nodes, normalize supplier observations into configurable tiers, and publish one versioned Coins pricing snapshot. Laravel will authenticate and validate that snapshot, persist an idempotent `PriceRun`, activate three `PriceRule` configurations atomically, bump Coins variant price versions, and continue calculating authoritative quotes through the existing integer-safe `CoinsPriceCalculator`. The n8n workflow will contain no Salla catalog reads, Salla IDs, WordPress/Salla imports, or plaintext secrets.

**Tech Stack:** Laravel/PHP 13 application with MariaDB-compatible migrations and Pest tests; n8n workflow JSON using built-in Schedule, HTTP Request, Loop/Code, and Code nodes; Node.js built-in `node:test` for pure pricing/snapshot tests; no new runtime dependencies.

## Global Constraints

- Legal quantity minimum is `50,000` and increment is `10,000`.
- Console Normal maximum is `2,000,000`; Console Fast maximum is `20,000,000`; PC maximum is `20,000,000`.
- Pricing groups are exactly `console_normal`, `console_fast`, and `pc`.
- Tier boundaries and multipliers are configuration data, not scattered constants.
- Large-quantity tiers must be monotonic non-decreasing at the cost layer and must preserve the fast-console floor above normal-console pricing.
- A failed or malformed supplier run must preserve the last applied rules; no partial pricing activation is allowed.
- The first production deployment runs in dry-run mode until a real result is reviewed.
- Supplier credentials and Laravel signing secrets live only in n8n Credentials/Hostinger environment storage; exported JSON may contain credential names/stubs but never values.
- The current Salla JSON and legacy handoff are read-only references for business rules. They are not inputs to the new Coins workflow.
- Reviews, cart logic, WordPress/Salla product imports, SBC catalog publication, and storefront UI are out of scope for this plan.

---

### Task 1: Lock the Laravel pricing-run contract with failing tests

**Files:**
- Create: `tests/Feature/Automation/CoinsPricingRunTest.php`
- Create: `tests/Unit/Pricing/CoinsPricingSnapshotTest.php`
- Create: `docs/api/n8n-pricing-v1.md`

**Interfaces:**
- The signed endpoint will accept `POST /api/automation/v1/pricing/coins/runs`.
- The body will contain `schemaVersion`, `eventId`, `runId`, `generatedAt`, `mode`, `serviceType`, `legalRanges`, and exactly three `rules` entries.
- Each rule will match the existing `CoinsPricingRule` configuration shape, including `tier_upper_bounds_k`, rate field, `multipliers_basis_points`, `service_fee_halalah`, `discount_divisor_basis_points`, and `exact_overrides_halalah`.
- The response will be `201` with `{data:{runId,status,pricingVersion,groups}}`; replayed `eventId` or `runId` will return `409`.

The canonical body shape is:

```json
{
  "schemaVersion": 1,
  "eventId": "01K2EXAMPLE000000000000001",
  "runId": "01K2EXAMPLE000000000000002",
  "generatedAt": "2026-08-12T12:00:00.000000Z",
  "mode": "dry_run",
  "serviceType": "coins",
  "legalRanges": {
    "console_normal": {"minimum": 50000, "maximum": 2000000, "increment": 10000},
    "console_fast": {"minimum": 50000, "maximum": 20000000, "increment": 10000},
    "pc": {"minimum": 50000, "maximum": 20000000, "increment": 10000}
  },
  "rules": {
    "console_normal": {"version": 1, "group": "console_normal", "tier_upper_bounds_k": [1000, 2000, 5000, 10000, 15000], "flat_rate_halalah_per_million": 1, "multipliers_basis_points": {"50000": 10000}, "service_fee_halalah": 0, "discount_divisor_basis_points": 10000, "exact_overrides_halalah": {}},
    "console_fast": {"version": 1, "group": "console_fast", "tier_upper_bounds_k": [1000, 2000, 5000, 10000, 15000], "tier_rates_halalah_per_million": [1, 1, 1, 1, 1, 1], "multipliers_basis_points": {"50000": 10000}, "service_fee_halalah": 0, "discount_divisor_basis_points": 10000, "exact_overrides_halalah": {}},
    "pc": {"version": 1, "group": "pc", "tier_upper_bounds_k": [1000, 2000, 5000, 10000, 15000], "tier_rates_halalah_per_million": [1, 1, 1, 1, 1, 1], "multipliers_basis_points": {"50000": 10000}, "service_fee_halalah": 0, "discount_divisor_basis_points": 10000, "exact_overrides_halalah": {}}
  },
  "observations": {"source": "fft+utt", "ratioEuroUsd": 1}
}
```

- [ ] **Step 1: Write failing feature tests for a valid dry-run and valid apply payload**

  Assert that a signed dry-run is accepted without changing active rules, and a signed apply request activates all three groups and returns a positive pricing version.

- [ ] **Step 2: Write failing tests for the PC 20M boundary and tier coverage**

  Build an applied rule set whose PC schedule includes `20,000,000`, and assert that `BuildCoinsQuoteSchedule` returns `1,996` legal quantities from `50,000` through `20,000,000`.

- [ ] **Step 3: Write failing tests for malformed rules, missing groups, duplicate groups, invalid tier order, and unsupported fields**

  Each case must return `422` and leave the currently active pricing rules unchanged.

- [ ] **Step 4: Write failing tests for invalid signature, stale timestamp, replay, and no configured pricing credentials**

  Assert `401` for authentication failures, `409` for stale/replayed requests, and no database writes for all rejected requests.

- [ ] **Step 5: Run only the new tests and verify they fail for missing endpoint/implementation behavior**

  Run from the Laravel repository:

  ```powershell
  php artisan test tests/Feature/Automation/CoinsPricingRunTest.php tests/Unit/Pricing/CoinsPricingSnapshotTest.php
  ```

  Expected: failures caused by the missing route, request, and application action; environment-only PDO driver errors must be reported separately rather than hidden.

### Task 2: Add signed request validation and durable run storage

**Files:**
- Create: `database/migrations/2026_08_12_000001_add_snapshot_fields_to_price_runs.php`
- Create: `app/Http/Middleware/VerifyN8nPricingSignature.php`
- Create: `app/Http/Requests/Automation/CoinsPricingRunRequest.php`
- Modify: `app/Models/PriceRun.php`
- Modify: `config/services.php`
- Modify: `.env.example`
- Modify: `routes/api.php`

**Interfaces:**
- `VerifyN8nPricingSignature::handle(Request $request, Closure $next): Response` will sign `timestamp + "\\n" + event + "\\n" + rawBody` with `N8N_PRICING_SECRET` and compare `X-ArabUT-Key` to `N8N_PRICING_KEY`.
- `CoinsPricingRunRequest::rules(): array` will validate exact JSON shape, ULIDs, UTC timestamps with six fractional digits, `mode` (`dry_run|apply`), all three rule groups, integer money, and legal ranges.
- `PriceRun` will cast `payload` to array and expose `mode`, `status`, `reason`, `started_at`, and `completed_at`.

- [ ] **Step 1: Add the migration for `price_runs.mode`, `price_runs.payload`, and `price_runs.reason`**

  Keep the existing unique `run_id` constraint. Store only sanitized observations and rule data; no credentials or request headers.

- [ ] **Step 2: Implement the pricing signature middleware by mirroring the catalog signature freshness behavior**

  Use separate config keys so catalog and pricing credentials are independently rotatable.

- [ ] **Step 3: Implement exact-shape request validation**

  Use `CoinsPricingRule::fromConfiguration` in an `after()` validator to reject malformed configuration and validate that all submitted groups are present exactly once.

- [ ] **Step 4: Register the route and environment/config keys**

  Add:

  ```php
  Route::post('/automation/v1/pricing/coins/runs', CoinsPricingRunController::class)
      ->middleware(['throttle:automation-pricing', VerifyN8nPricingSignature::class]);
  ```

  Add `N8N_PRICING_KEY` and `N8N_PRICING_SECRET` to `.env.example` and `config/services.php`.

- [ ] **Step 5: Run the contract tests and confirm the expected failures have moved to the missing controller/action**

### Task 3: Apply Coins rules atomically and idempotently

**Files:**
- Create: `app/Actions/Pricing/ApplyCoinsPricingRun.php`
- Create: `app/Exceptions/CoinsPricingRunReplay.php`
- Create: `app/Http/Controllers/Automation/CoinsPricingRunController.php`
- Modify: `app/Models/ProductVariant.php` only if a focused helper is required for version bumping
- Test: `tests/Feature/Automation/CoinsPricingRunTest.php`

**Interfaces:**
- `ApplyCoinsPricingRun::execute(array $payload): array` will return `runId`, `status`, `pricingVersion`, and group names.
- Dry-run creates a `PriceRun` with `mode=dry_run` and `status=proposed` but does not deactivate or activate pricing rules.
- Apply creates one `PriceRun` with `mode=apply`, deactivates the previous active global Coins rules, creates exactly three new active `PriceRule` records, increments active Coins variant `price_version`, and commits in one transaction.

- [ ] **Step 1: Add a failing test that a dry-run preserves active rules and does not bump versions**

- [ ] **Step 2: Add a failing test that an apply run activates exactly three groups and bumps every active Coins variant once**

- [ ] **Step 3: Add a failing test that a database exception rolls back the run and leaves the previous active rules intact**

- [ ] **Step 4: Implement the action with a transaction and a unique `run_id` replay check**

  Store the sanitized payload on `PriceRun`; use the submitted `runId` as the idempotency key and never invent a new run ID during retry.

- [ ] **Step 5: Implement the controller response and replay handling**

  Return `201` on first accepted run, `409` on a committed replay, and never expose the signed secret or raw provider response.

- [ ] **Step 6: Run the focused Laravel tests again and verify the new behavior passes**

### Task 4: Align Laravel quote configuration with PC 20M and tier-driven rules

**Files:**
- Modify: `config/coins.php`
- Modify: `tests/Unit/Pricing/BuildCoinsQuoteScheduleTest.php`
- Modify: `tests/Feature/Store/HomeCoinsConfiguratorTest.php` only where the expected PC schedule bound is explicit
- Modify: `tests/Feature/Store/CoinsQuoteTest.php` only where PC maximum validation is explicit

- [ ] **Step 1: Change `coins.platforms.pc.maximum` from `2_000_000` to `20_000_000`**

- [ ] **Step 2: Update the schedule test to expect `1,996` PC entries and a 20M final quantity**

- [ ] **Step 3: Add tests at tier boundaries `1M`, `2M`, `5M`, `10M`, `15M`, and `20M`**

- [ ] **Step 4: Run the pricing and storefront tests that do not require an unavailable local database driver**

  Record any SQLite-driver limitation explicitly; do not weaken tests to accommodate the local environment.

### Task 5: Extract pure tier and rule-snapshot logic for n8n

**Files:**
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/lib/tier-engine.mjs`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/lib/rule-snapshot.mjs`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/tests/tier-engine.test.mjs`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/tests/rule-snapshot.test.mjs`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/package.json`

**Interfaces:**
- `buildTierCosts(observations, settings)` returns slow costs, six PS fast tier costs, six PC tier costs, and a validation summary.
- `buildCoinsRules(tierCosts, settings)` returns exactly the Laravel `CoinsPricingRule` configurations for `console_normal`, `console_fast`, and `pc`.
- The engine uses `MIN(FFT, UTT) + supplierMarkup`, carries missing tiers forward, applies the slow-cost floor, and enforces non-decreasing tier costs.
- `buildCoinsRules` converts SAR/M rates to integer halalah/M, multipliers to basis points, service fee to halalah, and discount divisor to basis points.

- [ ] **Step 1: Write failing Node tests for MIN source selection, carry-forward, slow floor, and monotonic tier enforcement**

- [ ] **Step 2: Run `node --test` and verify the tests fail because the modules do not exist**

- [ ] **Step 3: Implement the smallest pure engine satisfying the tests**

- [ ] **Step 4: Write failing tests proving `pc` rules cover the 20M tier and every group has configurable multipliers/bounds**

- [ ] **Step 5: Implement rule serialization with stable ordering and no provider credentials**

- [ ] **Step 6: Run all Node tests and verify they pass**

### Task 6: Build the credential-safe Coins n8n workflow

**Files:**
- Create: `C:/Users/hp/Downloads/SBC N8N/Coins Pricing v1.json`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/nodes/config.js`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/nodes/prepare-snapshot.js`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/nodes/validate-snapshot.js`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/README.md` only if the directory README is not covered by the existing README

**Workflow requirements:**
- Schedule/manual trigger -> configurable settings -> FFT/UTT credentialed HTTP Request nodes -> batched probe normalization -> tier engine -> snapshot validation -> signed Laravel POST -> success/failure alert.
- Every provider HTTP Request node uses a named n8n credential stub (`FFT API`, `UTT API`, `ArabUT Pricing API`, and `Whapi Alerts` where alerts are enabled); no Code node contains secret literals.
- The workflow has no Salla node type, Salla ID, Salla product lookup, Google Sheet write, WordPress input, or cart logic.
- The final success item is one `coinsPricingSnapshot` object suitable for a later SBC workflow fetch.

- [ ] **Step 1: Write a validator test/script that rejects embedded secret-looking values and Salla dependencies in the new JSON**

- [ ] **Step 2: Create the workflow JSON with credential references and explicit dry-run/apply setting**

- [ ] **Step 3: Wire probe batches and preserve raw supplier observations only in sanitized execution data**

- [ ] **Step 4: Add signed-request construction using the exact raw JSON body and timestamp/event headers**

- [ ] **Step 5: Add fail-closed routing and a single operator alert for probe/validation/application failure**

- [ ] **Step 6: Parse the JSON, inspect node/connection counts, scan for secrets/Salla IDs, and run the Node validation suite**

### Task 7: Document operations and verify the end-to-end artifact

**Files:**
- Modify: `docs/api/n8n-pricing-v1.md`
- Create: `C:/Users/hp/Downloads/SBC N8N/salla-pricing/coins-pricing-v1/README.md`
- Test: `tests/Feature/Automation/CoinsPricingRunTest.php`

- [ ] **Step 1: Document credential names, rotation steps, dry-run/apply behavior, retry rules, and last-good preservation**

- [ ] **Step 2: Document the editable settings boundary for the future Admin UI**

  The documented settings include legal ranges, tier upper bounds, supplier markups, margins, commissions, multipliers, service fee, discount divisor, and safety thresholds.

- [ ] **Step 3: Run the focused Laravel tests, Node tests, JSON parse/secret scan, and PHP syntax checks**

- [ ] **Step 4: Run the complete available validation and report environment blockers separately**

- [ ] **Step 5: Hand off the verified Coins artifact before starting the SBC workflow**
