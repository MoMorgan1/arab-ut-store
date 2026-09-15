# Dropping the exchange-rate provider attribution

**Date:** 2026-09-15. **Decided by:** Mohamed.
**Supersedes:** the attribution section of
[2026-08-10-instant-coins-guest-cart-storefront-design.md](2026-08-10-instant-coins-guest-cart-storefront-design.md).

## Decision

The «Rates By Exchange Rate API» line is removed from the store. It was the last
item in the language and currency panel; nothing replaces it.

Mohamed's reason is the store's own rule: a customer is never shown the shop's
plumbing. Which service supplies a display-only conversion rate is ours to know,
not something a customer needs while picking a currency.

## What was raised first

The rates come from the provider's open endpoint,
`https://open.er-api.com/v6/latest/SAR` (`store.display_exchange_rates`), and its
free terms ask for a linked attribution on pages that show the rates — which is why
the 2026-08-10 decision kept the line and wrote that it "may be removed entirely
only after switching to a provider plan whose contract does not require
attribution."

Three options were put to Mohamed: move the line to the footer where a customer
does not meet it while choosing a currency, switch to a source with no attribution
requirement and then remove it, or remove it now against the free terms. **He chose
to remove it now and said the responsibility is his.** That is recorded here rather
than argued again.

## What this leaves open

The store keeps using the open endpoint. If the provider's terms are ever enforced,
the fix is one of the two options above: a footer line, or a different source. The
conversion itself, its 30-hour freshness window and the SAR-only checkout are
unchanged — only the credit line is gone.
