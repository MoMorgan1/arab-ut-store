# Analytics tracking (GA4, Meta Pixel, TikTok Pixel) with consent

Date: 2026-09-02
Status: approved by Mohamed on 2026-09-02 with the wallet amendment below. Reviewed once (Opus,
read-only) and revised.
Owner decisions referenced: discovery record decisions 34 and 38

## Purpose

The store has no analytics at all today: no tag, no pixel, no consent banner, no config keys.
Without it Mohamed cannot see where orders come from or run paid campaigns with conversion
feedback. This design adds the three MVP-scoped vendors behind one small first-party tracking
module, gated by a consent banner, with the commerce events that matter for a store of this
shape.

## v1 scope

In:

- GA4 (gtag.js), Meta Pixel, TikTok Pixel, each enabled only when its id is configured.
- A consent banner on the storefront (Arabic first, Gulf-leaning copy) with two choices:
  accept or decline. The choice is remembered per browser for twelve months.
- **Decline means no vendor request at all.** No Google tag, no Meta pixel, no TikTok pixel
  is loaded until the visitor accepts. Google's cookieless "consent denied" pings are not used:
  Saudi PDPL has no recognised exemption for them, and the simplest defensible position for a
  Saudi merchant is that a refusal sends nothing.
- Five events, one payload feeding all three vendors:
  - `page_view` on the initial load and on every Inertia navigation.
  - `view_item` on the SBC product page and on the Rivals and FUT Champions pages.
  - `add_to_cart` from every place the store adds to the cart (six emitters today).
  - `begin_checkout` when a Paylink checkout has been created, or resumed from the order page.
  - `purchase` once per order, on the account order page, for the amount actually paid
    through Paylink. Wallet credit is not revenue (owner decision, 2026-09-02): a wallet-only
    order sends no `purchase`, and a mixed order reports only the Paylink amount.
- Scripts render on storefront pages and on the account order page (the purchase page). Admin,
  auth, and the rest of the account area send nothing.

Later, not in v1:

- Server-side events (Meta Conversions API, GA4 Measurement Protocol). The client `eventID`
  is generated now so the server path can deduplicate against it later.
- Refund reversal. A refunded order keeps its `purchase`; v1 accepts slightly inflated revenue
  rather than building a refund pipeline for what is today a manual, rare admin action.
- `remove_from_cart` and `add_payment_info`.
- Google Tag Manager. The three vendors load directly; GTM would add a fourth script and an
  external control surface nobody administers yet.
- A Content-Security-Policy header. None exists today; adding one is a separate change.
- Any user identification (`ttq.identify`, advanced matching). No customer PII reaches a
  vendor in v1.

## Existing code this builds on

- `resources/js/app.tsx` creates the Inertia app and is the natural place to initialise the
  module. `router.on('flash')` (`use-flash-toast.ts`) and `router.on('before')`
  (`admin-security-section.tsx`) are already used, so an Inertia `navigate` listener follows an
  established pattern.
- `resources/js/lib/cart-added-event.ts` exports `announceCartAddition(detail)` with the
  `arabut:cart-added` custom event. Six emitters call it: catalog add control, SBC product
  configurator, Coins configurator, FUT Champions configurator, Rivals configurator, and the
  chat cart offer. The listener is `cart-added-notice.tsx`. The payload carries no price or SKU.
  Five emitters hold the quoted price locally; `catalog-add-control.tsx` does not, its parent
  (`category.tsx`, `catalog-product.tsx`) does, so that one needs a price prop threaded in.
- `resources/js/lib/paylink-checkout-api.ts`: `startPaylinkCheckout(...)` from the cart and
  `resumePaylinkCheckout(...)` from the order page both return a promise; navigation to Paylink
  (or straight to the order page when the wallet covers everything, `paymentUrl === null`) is a
  separate step in `cart.tsx` / `live-order.tsx`. A `cart_repriced` failure returns the customer
  to a confirm dialog and the start runs again.
- After a Paylink payment, `PaylinkReturnController` redirects to `store.orders.show`, which
  redirects again (rebuilding the URL, dropping any query string) to `account.orders.show`,
  rendered by `resources/js/pages/account/live-order.tsx` from `ReadLiveOrder`. Wallet-only
  orders never pass through Paylink and land on the same page directly. The page is
  re-openable, and its `status` is already collapsed by `forCustomer()` (Received → InProgress,
  Refunded → Cancelled). `OrderStatus` has no `Paid` case.
- `config/store.php`: checkout currency is SAR; ten display currencies exist, and the emitters
  quote prices in the display currency.
- `config/services.php` holds vendor credentials, with `.env.example` mirrored one to one.
- `resources/views/app.blade.php` already branches on `$isStoreRoute` for store-only head tags.
- Bottom-of-viewport layers that already exist: `.manual-service-panel__bar` (fixed, z-index
  45, Rivals and FUT on phones), `.coins-step__actions` (sticky, Coins on phones),
  `.store-cart-added` (fixed notice), and `.chat-widget-root`, which is hand-shifted up on the
  manual-service pages to clear the bar.

## Technical approach

### Configuration

`config/services.php` gains an `analytics` block read from env, mirrored in `.env.example`:

```
ANALYTICS_GA4_MEASUREMENT_ID=
ANALYTICS_META_PIXEL_ID=
ANALYTICS_TIKTOK_PIXEL_ID=
```

Empty means "vendor off". Nothing is committed; Mohamed sets the values on production and the
deploy runs `config:cache` as it already does. The ids are public by nature (they ship in page
source); the secrets stay out.

### Head bootstrap (Blade)

Rendered on store routes **and** `account/live-order`, and only when at least one id is set:
one inline script that reads the consent cookie, defines `dataLayer`/`gtag`, calls
`gtag('consent', 'default', {...all four signals denied...})`, and exposes the ids and the
stored choice on `window.__arabutAnalytics`. **No vendor script is in the head.** The module
injects each vendor only after acceptance, in the order each vendor documents: Google consent
default already set, then `gtag/js`, then `gtag('config', id, { send_page_view: false })`;
Meta base snippet with `fbq('consent', 'revoke')` before `fbq('init', id)` then
`fbq('consent', 'grant')`; TikTok base snippet. The TikTok snippet is copied by Mohamed from
TikTok Events Manager when he provides the pixel id, never pasted from memory.

### The module: `resources/js/lib/analytics.ts`

One file, no dependencies, safe when a vendor is absent:

- `initAnalytics()` called once from `app.tsx`: if consent is granted, loads the vendors and
  sends the first `page_view`; subscribes to `router.on('navigate')` for later page views;
  listens to `arabut:cart-added` for `add_to_cart`.
- `grantConsent()` / `declineConsent()`: persist the choice in a first-party cookie
  (`arabut_consent`, twelve months, versioned), then either load the vendors and send the
  current page view, or do nothing further.
- `trackViewItem`, `trackAddToCart`, `trackBeginCheckout`, `trackPurchase`: build one
  normalised payload and fan out in each vendor's shape. A random `eventID` per event is passed
  to Meta as the fourth `fbq` argument.
- **Money is always the SAR checkout amount.** The payload carries `currency: 'SAR'` and
  `value` in riyals converted from halalah. Emitters that only hold a display-currency price
  pass the SAR minor amount they already receive from the server (every quote and cart line
  carries it); if a call site truly lacks it, the event is sent without `value` rather than with
  a wrong one.

### Event sources

- `view_item`: the SBC product page, `/rivals`, and `/fut-champions` call `trackViewItem` on
  mount with the product id, name, and base SAR price.
- `add_to_cart`: `CartAddedDetail` gains an optional `analytics` field (`id`, `name`,
  `priceMinorSar`, `quantity`, `serviceType`). Five emitters fill it from local state; the
  catalog add control receives the price from its parent.
- `begin_checkout`: fired **after** `startPaylinkCheckout` or `resumePaylinkCheckout` resolves
  successfully and before navigation, so a re-priced retry does not double count. Payload: the
  cart lines and the payable amount from the response.
- `purchase`: `ReadLiveOrder` adds an `analytics` object (`orderId`, `value`, `currency`,
  `items[]`) computed server-side from the raw status: present when
  `status ∉ {PendingPayment, Cancelled}` and `payment_halalah > 0`, with `value` equal to
  `payment_halalah` in riyals (the wallet portion is excluded). No redirect flag. `live-order.tsx` fires `trackPurchase` when the object is present and the order id is
  not yet in the `localStorage` set of tracked orders, then adds it. Reloads and later visits
  never fire again; a visit from a second browser could fire once more, accepted as the cost of
  having no server-side path in v1.

### Consent banner

A small `ConsentBanner` component mounted in `StoreLayout` and the account order page: glass
surface in the warm-black house style, two buttons (accept / decline), a one-line explanation,
and a link to the privacy page. Shown only when no choice is stored and at least one vendor is
configured. Keyboard reachable, 44px targets, `inset-inline` not `left/right` so RTL and LTR
both work.

Placement is decided on the `/design` canvas, not here, because the bottom edge is already
occupied on three page types: on phones the banner must sit above the fixed Rivals/FUT bar and
the Coins sticky actions and coordinate with the chat launcher. The candidate the canvas will
show: top-anchored slim bar under the header on phones, bottom-start card on desktop.

This is a new interface, so the UI gate applies: the banner goes on a `/design` canvas for
approval before implementation.

## Privacy and safety

- No email, phone, name, or order number is sent to any vendor. Items are identified by
  product slug or SKU and name.
- Decline is honoured completely: no vendor script is fetched and no request leaves the
  browser.
- The choice cookie is first party, twelve months, versioned so the banner can be shown again
  if the vendor set changes. `MY_ACCOUNT_ENABLED` gates the order page's layout; the banner and
  purchase tracking sit inside that gate.

## Testing

- Pest: with ids set, the store head and the order page render the consent bootstrap with the
  Google default before anything else and **no vendor script**; with ids empty, nothing
  renders; admin, auth, and other account routes never render it; `.env.example` and
  `config/services.php` analytics keys match one to one; `ReadLiveOrder` exposes `analytics`
  for a paid Paylink order with the Paylink amount only, and not for wallet-only, pending, or
  cancelled ones.
- Vitest: the module loads vendors only after acceptance, fans out the five events in each
  vendor's shape with SAR values, is a no-op without ids, dedupes `purchase` by order id, fires
  `begin_checkout` once across a re-priced retry; the banner renders, persists the choice, and
  hides.
- Playwright: with a fake GA4 id, the banner appears, declining hides it and no request to any
  vendor host is made; accepting fetches the Google tag and sends a page view; on `/rivals` at
  390px the banner does not overlap the fixed add-to-cart bar.
- Production check after deploy: GA4 DebugView and Meta Events Manager test events for one
  add-to-cart, one begin-checkout, and one purchase.

## Complexity

Medium. Around fifteen files, no schema change, no new dependency.

## Needed from Mohamed

- GA4 measurement id (he already has the account).
- Meta pixel id and TikTok pixel id when those accounts exist; both are added later by setting
  the env value, no code change.
- Approval of the consent banner on the canvas.

## Decisions taken in this design

1. Decline sends nothing to any vendor (no Google consent-mode pings).
2. Wallet credit is not revenue: wallet-only orders send no `purchase`, mixed orders report
   the Paylink amount only. (Confirmed by Mohamed, 2026-09-02.)
3. Refunds are not reversed in v1.
4. `view_item` is in v1; `remove_from_cart` and `add_payment_info` are not.
5. Direct vendor scripts rather than GTM; server-side events deferred with `eventID` ready.
