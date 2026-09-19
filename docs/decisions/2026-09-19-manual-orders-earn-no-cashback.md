# A manual order earns no cashback and no tier

**Date**: 2026-09-19
**Status**: accepted, owner decision

## The decision

An order created through the admin's manual-order drawer — a bank transfer taken outside the
gateway, an order already placed at the supplier by hand, or a gift — pays the customer no
cashback and adds nothing to their lifetime spend.

The plan for slice G1 left this open: *"Still open, because money is involved and the owner has
not ruled on it: whether a manual order earns cashback and loyalty spend. It should not, by the
same reasoning that excludes `salla_import`, but that is a recommendation and not yet a
decision."* This records the ruling and closes it.

## It reverses an earlier ruling

The plan was not the whole story. An owner rule already existed, recorded only in a test comment
in `CreateManualOrderTest`: **"cashback on a transfer, none on a gift"**. Under it a manual bank
transfer accrued cashback and raised the tier, and only the gift's zero total stopped a gift from
doing the same — by arithmetic, not by any rule.

That rule was put to the owner again on 2026-09-19 as one of three options, in those words, and
rejected in favour of none on either. So this supersedes it rather than overlooking it. It is
written down here because the earlier rule lived in a comment on a test that no longer asserts it,
and a reader finding the old wording in this file's history should know which way it went.

## Why

Cashback is a reward for a purchase the store made. A manual order is staff writing down an order
the store did not sell, and a gift is not a purchase at all — a zero total would earn zero cashback
anyway, but the tier is what matters: lifetime spend sets the customer's rate on every future
order. Counting manual orders would let anybody holding `orders.create` — which, since
2026-09-13, includes support staff — raise a customer's cashback rate permanently by writing
orders. The staff audit row records who did it, but only after the fact.

It is the same reasoning that excludes `salla_import`, with one difference worth stating: an
imported order **does** count toward lifetime spend, because that money was really spent, just on
the old platform. A manual order's money was really received too, in the bank-transfer case — but
the store chose not to let a hand-written order move a tier, because the hand is the problem, not
the money.

## What it changed

Before this, nothing excluded the channel. `CreateManualOrder` said so in a comment — *"Every rule
that today asks whether an order is imported therefore treats this one as an ordinary order:
cashback accrues"* — and it was accurate. A completed manual bank transfer accrued cashback and
raised the tier.

Three places name the channel now, because the loyalty rules ask two different questions:

- `AccrueOrderCashback` — the accrual gate, beside the existing `salla_import` check.
- `EligibleOrderSpend::lifetime()` — the order contributes 0, where an imported one contributes
  its total.
- `EligibleOrderSpend::fullySettled()` — never settled, so nothing downstream of settlement fires.
- `CountCustomersPerTier` — the admin tier report restates the lifetime rule in SQL, so it
  restates this too. A report that disagreed with the ledger would be worse than no report.

Four tests pin it, and each was checked against a reverted build: they fail when the exclusion is
removed, rather than passing whatever the code does.

## What this does NOT change

A manual order still behaves like an ordinary order everywhere the money is not at stake: staff
can transition it, it gets a tracking page and a signed link, and it can be invited to leave a
review. A review invite on a gift is a little odd and was not part of this ruling; if it should
stop, that is a separate decision.
