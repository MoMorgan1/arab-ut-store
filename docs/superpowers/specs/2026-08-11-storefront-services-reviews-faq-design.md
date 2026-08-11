# Storefront Services, Catalog, Reviews, and FAQ Design

**Status:** Approved by Mohamed on 2026-08-11

**Scope:** Homepage service discovery, SBC/Objectives category pages, FUT Champions/Rivals product pages, Sell Coins outbound navigation, n8n-backed catalog and reviews, and the existing FAQ

**Complexity:** Ambitious

## Outcome

The storefront at `store.arab-ut.com` expands beyond Coins without changing the approved WordPress-first identity. The homepage gains an equal-card horizontal services rail, an honest reviews section, and the existing FAQ. Every internal service destination has an Arabic and English page. SBCs and Objectives behave as categories; FUT Champions and Rivals behave as products; Sell Coins opens the existing `https://sell.arab-ut.com/` destination. Every real catalog product can be added to the existing guest cart through a real server-authoritative action.

The existing n8n workflows remain the integration source. Laravel and MariaDB remain the public storefront source of truth. The UI never reads live workflow responses during a customer request and never exposes workflow credentials or source payloads.

## Approved design direction

Three directions were considered:

1. **Literal WordPress transplant.** Fast but carries forward uneven card emphasis, dated motion, and weak failure states.
2. **WordPress structure with a refined flagship system — selected.** Preserve the current content hierarchy, warm black/gold palette, local Thmanyah typography, and recognizable service imagery while making every service card equal in size and interaction weight.
3. **Minimal static links.** Rejected because it would not satisfy real category pages, the n8n catalog source, or the reviews requirement.

The selected design treats the current WordPress theme and `Arab-ut.com` as content references, not as a new visual system. Liquid Glass, blue gradients, Noto fonts, oversized feature cards, and mixed card sizes are rejected.

## Homepage information architecture

The homepage order is:

1. Existing header and primary navigation.
2. Existing hero.
3. Existing Coins configurator.
4. Other services rail.
5. Reviews.
6. FAQ.
7. Existing footer.

### Other services rail

The rail contains exactly five equal cards:

- SBCs — internal category route `/sbc` and `/en/sbc`.
- Objectives and XP — internal category route `/objectives` and `/en/objectives`.
- FUT Champions — internal product route `/fut-champions` and `/en/fut-champions`.
- Rivals — internal product route `/rivals` and `/en/rivals`.
- Sell Coins — external `https://sell.arab-ut.com/` link.

Every card has the same width, height, internal padding, image stage, title line budget, description line budget, CTA position, hover treatment, and focus treatment. No card is a flagship or spans extra columns.

The cards live in a horizontally scrollable snap rail at every viewport. Desktop shows multiple complete cards plus a restrained next-card cue; mobile shows approximately one card plus a partial next card. The rail supports touch dragging, mouse wheel/trackpad horizontal scrolling, keyboard access to every link, RTL-aware snap order, and no forced autoplay. Optional previous/next controls appear only when overflow exists and remain at least 44 by 44 CSS pixels. Reduced motion removes animated translation while preserving native scrolling.

Cards use a local approved asset or safely mirrored n8n image. Missing media falls back to a service-specific local mark; layout never collapses or substitutes an unrelated image.

### Reviews

The homepage displays a compact rating summary and a controlled equal-card review rail. It does not use an inaccessible infinite marquee. The preview includes a representative chronological slice and links to `/reviews` or `/en/reviews`, where all eligible published ratings can be read.

Reviews are not filtered to hide low scores. A verified label appears only when a review is linked to genuine order evidence. The public projection contains only:

- public review identifier;
- rating from 1 to 5;
- review text for the current locale, with safe fallback;
- explicitly public display name, otherwise a generic localized customer label;
- published timestamp;
- derived verified state.

Phone numbers, email addresses, avatars, raw order identifiers, workflow payloads, and any other customer PII never enter Inertia props, HTML, logs, cache values, or analytics.

### FAQ

The FAQ retains the current `Arab-ut.com` questions and answers because payment and order tracking already exist operationally:

1. Store working hours.
2. Coins delivery duration.
3. Account safety level.
4. How Coins are delivered and tracked.

Arabic remains authoritative. English receives meaning-equivalent translations without new promises. The UI uses one-heading native disclosure items with visible focus, generous tap targets, and no JavaScript-only accordion dependency.

## Service and catalog pages

### SBC category

`/sbc` and `/en/sbc` reproduce the useful WordPress hierarchy inside the approved storefront shell:

- breadcrumb and editorial hero;
- SBC mark, title, short explanatory copy, and catalog count;
- search by product name;
- filters: All, Players, Icons, Upgrades, Foundations;
- Challenges map to Upgrades;
- no Swaps filter;
- sorting by recommended, newest, price ascending, and price descending;
- equal product cards with safe image, category, platform availability, and authoritative display price;
- localized pagination or bounded result loading;
- honest empty, stale, and unavailable states;
- product detail route `/sbc/{slug}` and `/en/sbc/{slug}`.

The page does not calculate price in the browser. It reads current active variants and converts their authoritative SAR prices using the latest valid server-side display rate. When no fresh conversion is available, SAR can still render; a non-SAR request fails that price display closed instead of inventing a conversion.

### Objectives category

`/objectives` and `/en/objectives` use the same catalog primitives in a simpler category page: hero, product count, equal cards, platform labels, price, and safe empty state. It does not expose SBC-specific filters.

### FUT Champions and Rivals products

`/fut-champions`, `/en/fut-champions`, `/rivals`, and `/en/rivals` are simple product pages with:

- breadcrumb;
- product image or local service fallback;
- concise value proposition sourced from the approved content;
- available platform and variant summary;
- authoritative display price when available;
- one selected active variant and a real Add to Cart action;
- no fake checkout, delivery promise, or automation status.

The page is truthful when a product is missing or temporarily hidden.

### Catalog add to cart

SBC and Objectives cards, SBC/Objectives detail pages, and FUT Champions/Rivals pages expose a real Add to Cart action. When a product has multiple active variants, the customer selects the platform/variant before submission. The browser submits only the public variant ID plus a one-time idempotency key.

Laravel resolves the guest/authenticated cart owner, locks the authoritative active variant and visible product, reads the current SAR sale price or base price, snapshots service type/platform/market/price version/time, and creates one cart line transactionally. The browser never supplies or calculates the trusted price. A replay returns the same safe result; a mismatched replay returns 409. Hidden, archived, inactive, zero-priced, unknown, or Coins variants fail closed.

The successful action redirects to the real cart. The cart displays the localized product name, service type, selected platform, authoritative SAR total, and a clear `details required` state. This slice does not collect service credentials/configuration for these products and does not expose checkout or payment; those details are completed by the future service-specific configuration step before checkout.

### Reviews page

`/reviews` and `/en/reviews` show the rating distribution, total published count, all eligible reviews in stable newest-first order, and accessible pagination. The page never labels a review verified without order evidence and never exposes source PII.

## Catalog synchronization

The existing n8n product workflow sends a complete versioned snapshot to an authenticated Laravel endpoint. The contract contains:

- schema version;
- unique run ID;
- UTC generation timestamp;
- complete-snapshot flag;
- categories;
- products;
- variants;
- media references;
- stable source IDs;
- bilingual text when available;
- explicit service type, platform, market, price minor units, currency, visibility, and sort order.

The endpoint requires a scoped signature, freshness window, unique event/run ID, exact JSON keys, bounded collection sizes, and idempotency. Laravel validates the complete body before starting a database transaction. A successful run upserts automation-owned rows and archives missing automation-owned rows. It never overwrites unrelated manual catalog rows. A failed or partial run leaves the previous public snapshot untouched and records item-safe diagnostics without raw credentials or customer data.

Media URLs are allowlisted HTTPS sources. Laravel validates type and size, mirrors accepted images to the public disk, and retains the last good local asset if a refresh fails.

## Review synchronization

The approved existing review endpoint remains the source. A Laravel command fetches it outside customer requests using explicit connect/response timeouts and bounded retries. A scheduled task runs without overlap and an atomic lock prevents concurrent imports.

The importer strictly projects safe review fields before persistence, validates rating and text bounds, deduplicates by source identity, and updates the public snapshot transactionally. An unavailable, malformed, or PII-only response keeps the last good public reviews. The public page never waits for n8n.

## Routes and localization

All internal routes exist in default Arabic and under `/en`. Locale, text direction, display currency, header active state, cart count, preferences, and footer remain driven by the existing shared storefront shell.

Canonical deployment references use `store.arab-ut.com`. Documentation references to `shop.arab-ut.com` are corrected. Sell Coins preserves the exact external destination and safe external-link behavior.

## Visual system

- Warm black/deep brown surfaces and restrained gold borders.
- Thmanyah Serif Display for page heroes, section headings, product names, large prices, and large editorial text.
- Thmanyah Sans for body copy, navigation, controls, metadata, filters, and labels.
- Equal card geometry across all service types.
- Service art stays contained and never zooms or crops logos.
- Motion is limited to gentle card lift, image drift, and rail affordance; no autoplay.
- Visible keyboard focus, native RTL/LTR ordering, 44-pixel targets, and reduced-motion support are mandatory.

## Failure and honesty rules

- Empty catalog: show a localized updating/unavailable state, not fake products.
- Stale last-good catalog: continue rendering with a discreet freshness state available for operations; do not show alarming technical copy to customers.
- Failed review refresh: retain last-good reviews.
- Missing product image: use the correct local service fallback.
- Missing product price: show a localized contact/details state, never zero or a guessed amount.
- Add to Cart is disabled when no eligible authoritative variant/price exists.
- Checkout and payment controls remain absent until their real backend contract exists.

## Verification contract

Automated coverage must prove:

- signed snapshot authentication, replay/freshness rejection, exact keys, complete-snapshot atomicity, stable upsert, manual-row protection, missing-row archive, and media validation;
- safe review projection, PII rejection, all-rating honesty, evidence-based verification, idempotency, last-good retention, scheduler overlap prevention, and zero customer-request HTTP calls;
- bilingual routes and correct category/product semantics;
- SBC search/filter/sort/pagination and Challenges-to-Upgrades mapping;
- server-authoritative catalog cart addition, ownership isolation, idempotency, stale/hidden variant rejection, safe cart projection, and no browser-trusted price;
- equal service-card geometry contract, RTL rail order, keyboard navigation, external Sell Coins target, and no autoplay;
- exact FAQ content and native disclosure accessibility;
- Thmanyah font contracts, safe image containment, reduced motion, and no overflow.

Browser verification covers Arabic and English at 320, 390, 768, and 1440 pixels for the homepage, SBC category, one SBC detail, Objectives, FUT Champions, Rivals, and Reviews. It includes touch/trackpad-style rail behavior, keyboard focus, disclosure interaction, filter/search behavior, 200% zoom, reduced motion, image aspect ratio, route/active navigation, and zero console errors or warnings.

## Inputs and launch constraints

- Existing n8n accounts, workflows, and access are available.
- Secrets are configured through the approved environment/credential mechanism and are never pasted into chat or committed.
- Existing WordPress and `Arab-ut.com` assets/copy may be used only when their licensing and public use are already owned by Arab UT.
- The review source currently contains customer PII; the strict safe-projection boundary is mandatory.
- This slice builds working browse/catalog/review pages and real non-Coins cart addition, but does not invent checkout, payment, credentials, or fulfillment behavior.
