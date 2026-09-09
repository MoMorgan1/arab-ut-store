# Automatic wallet cashback stays

**Date:** 2026-09-10
**Owner decision:** Mohamed, during the project cleanup review.

## Context

`docs/product/v1-blueprint.md` and the 2026-08-15 my-account decision excluded an
automatic cashback engine from v1. The code ships one anyway:
`App\Loyalty\Actions\AccrueOrderCashback` runs from `TransitionAdminOrder` when an
order completes and credits the customer's wallet at the tier's
`cashback_basis_points` (seeded by `loyalty:seed-tiers`).

## Decision

Keep it. The behaviour is intended and live. The product docs are corrected to
describe it rather than the code being removed.

## Consequences

- Tier thresholds and cashback rates are admin-editable; the seeded defaults are
  Silver SAR 500, Gold SAR 2,000, Platinum SAR 10,000.
- Wallet credit is not revenue in analytics (see the 2026-09-02 analytics
  tracking decision).
- `docs/product/discovery-record.md` keeps its original 2026-08 observations
  (display-only loyalty, SAR 5,000 Platinum) as history; the blueprint and this
  decision carry the current rule.
