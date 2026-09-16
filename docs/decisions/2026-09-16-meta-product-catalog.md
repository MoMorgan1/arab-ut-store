# The Meta product catalogue

**Date:** 2026-09-16
**Status:** accepted
**Owner decision:** Mohamed, 2026-09-16

## Context

The Meta pixel went live the same day (`ANALYTICS_META_PIXEL_ID`, and the fix
that let it initialise at all). A pixel on its own can retarget a visitor; it
cannot show them the thing they looked at. That needs a catalogue, and a
catalogue needs a feed: a file Meta fetches on a schedule listing every item,
its price, its page and its picture.

Three ids were in play before this. `view_item` on a challenge page sent the
product id, the same event on a manual service page sent the slug, and
`purchase` sent the order item SKU. Meta matches events to catalogue rows by
id, so with three id spaces nothing would have matched.

## Decision

**One feed, one id, at `/feeds/meta-catalog.csv`.**

- **The id is the variant SKU.** The purchase event already sent it, so the
  feed adopted it and both product pages now send it too. The SKU reaches the
  page in the catalogue payload (`variants[].sku`, and `variantSkus` per
  platform for a manual service).
- **One row per variant a customer can buy.** `item_group_id` is the product
  slug, so Meta groups the platforms of one product together.
- **Every price is the cheapest a customer could actually pay** (Mohamed,
  2026-09-16): one completion for a challenge, the smallest coin order at
  today's rate, the cheapest rung of a manual service. Meta compares the feed
  price against the page it links to, and the page opens on its cheapest
  option.
- **A variant whose price cannot be resolved is left out.** When no published
  rate can price the smallest coin order, coins are absent from the feed
  rather than present at a guess.
- **Everything the storefront sells is in scope**, not only the challenges
  (Mohamed, 2026-09-16, chosen over a challenges-only feed).

## Why

The alternative was a challenges-only feed, which is the safest thing to show
an ad network: 38 products with fixed prices and real pictures. Mohamed chose
the wider feed, so the "cheapest rung" rule carries the risk instead. A
customer who clicks an ad priced at the cheapest option lands on a page whose
opening price is that same option.

The feed is public because Meta fetches it unauthenticated. It carries only
what the storefront already shows: names, prices, pages, pictures. It is
cached for ten minutes, excluded from `robots.txt`, and served `noindex`.

## How to apply

In Commerce Manager: create a catalogue, add a **scheduled data feed** at
`https://store.arab-ut.com/feeds/meta-catalog.csv` (daily is enough; the file
is small), then connect the pixel to that catalogue so events match rows.

When adding a service the feed does not yet know how to price, add its case to
`MetaCatalogFeed::priceHalalah()`. A service left to the default is priced
from the variant row, which is zero for a configured service, and a zero price
is dropped.
