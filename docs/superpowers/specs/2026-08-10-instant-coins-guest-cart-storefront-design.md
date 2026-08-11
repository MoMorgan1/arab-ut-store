# Instant Coins, Guest Cart, and Storefront Continuity

**Status:** Approved by Mohamed on 2026-08-10

**Scope:** Coins homepage/configurator, guest cart ownership, authentication handoff, and the approved storefront polish batch

**Complexity:** Ambitious

## Outcome

The Coins price changes immediately for every valid 10K quantity change without a loading gap or a per-change browser request. Guests can finish the platform, delivery, amount, EA credentials, summary, and add-to-cart flow without signing in. Credentials remain encrypted server-side and never enter URLs, sessions, Inertia props, logs, analytics, or browser storage. Authentication becomes a checkout-boundary concern; because payment and checkout do not exist yet, this batch does not add a fake checkout button.

The storefront remains WordPress-first: warm black/gold materials, official Arab UT assets, Thmanyah Sans for UI, Thmanyah Serif Display for headings and large editorial text, native Arabic RTL, and the current responsive hierarchy. The supplied login and product-heading screenshots are inspiration for refinement, not a replacement design.

## Considered approaches

1. **Server-generated quote schedule plus guest cart — selected.** The server loads the validated catalog, pricing rules, and display rate once, calculates every legal quote in memory with the same integer-safe `CoinsPriceCalculator`, serializes compact indexed schedules, and still re-quotes through `QuoteCoins` when adding to cart. This gives exact instant prices without repeating database reads or maintaining a second browser pricing implementation.
2. **Duplicate the pricing formula in TypeScript.** Rejected because tier, override, currency, and rounding changes could make the browser disagree with the server.
3. **Keep one HTTP request per slider movement and hide the loading text.** Rejected because it only hides latency and does not make pricing instant.

## Instant quote schedule

### Server contract

The homepage receives one compact schedule for each available mode:

- `playstation:normal`, 50K–2M in 10K increments;
- `playstation:fast`, 50K–20M in 10K increments;
- `pc`, 50K–2M in 10K increments.

Each schedule contains the minimum, maximum, increment, authoritative SAR minor-unit totals, display-currency minor-unit totals, currency code, variant public ID, price version, and one server timestamp. Array position maps to quantity using `(quantity - minimum) / increment`; quantities are not repeated as JSON keys. The schedule builder loads each required catalog/rule/rate record once, reuses the existing integer-safe calculator and fixed-point conversion arithmetic in memory, and fails the affected homepage mode closed when any entry cannot be priced exactly. Generating all three schedules must stay within 10 database queries and one second under the focused performance fixture.

The existing GET quote endpoint remains available as a compatibility and diagnostic surface, but the homepage amount interaction does not call it for schedule-covered values.

### Browser behavior

- Input, chips, range, and adjustments synchronously select the exact schedule entry.
- The visible total changes in the same render as the quantity.
- No `Refreshing price…` / `نحدّث السعر…` state appears for local schedule changes.
- Changing platform or delivery selects the matching schedule and clamps before lookup.
- The add-to-cart request never trusts the schedule total. The server re-runs `QuoteCoins`, stores the authoritative SAR amount, and returns the safe result.

This makes the customer experience instant while preserving the server as the only pricing authority.

## Guest cart and authentication boundary

### Ownership

An anonymous cart is owned by a server-issued session cookie. The database stores only an HMAC-derived session owner key; it does not store a raw session identifier. The active-cart invariant expands to exactly one active SAR cart per authenticated user or per guest owner on both SQLite and MariaDB.

Cart lookup becomes one boundary that resolves either:

- `user:<id>` for authenticated customers; or
- `guest:<hmac>` for the current anonymous session.

Idempotency scope and request fingerprints use the same opaque owner identity. Cross-user and cross-session replays remain conflicts.

### Secure credentials

Guests submit the same exact credential contract: EA email, opaque password, and five distinct eight-digit backup codes. The JSON-only, CSRF, no-store, throttle, idempotency, transaction, encrypted `cart_item_secrets`, expiry, purge, and safe-response protections remain mandatory. No secret or recoverable identity is added to cart summaries.

### Claim on authentication

After a successful login or registration, an idempotent transactional action claims the current guest cart:

- when the user has no active cart, ownership moves to the user;
- when the user already has an active cart, guest items and their encrypted secret relations move to the user cart and the empty guest cart is removed;
- row locks and the database uniqueness invariant prevent duplicate active carts or double claims.

The claim action never decrypts credentials.

### UI flow

- The amount Continue action always advances to EA details for guests and members.
- Summary adds to the cart directly for both.
- Cart is readable only by its current session owner or authenticated owner.
- Login/register remain available from the header.
- Authentication is not forced by the configurator. A future real checkout route will require login before payment; this batch does not invent checkout or payment behavior.

## Branded authentication

Authentication pages render inside the same functional Arab UT storefront shell: header, navigation, footer, currencies, language switch, and active account state. The inner auth area follows the approved inspiration:

- a compact login/register form card;
- an adjacent value panel on desktop using real account benefits only;
- form first and value panel second on mobile;
- Thmanyah Serif Display for the main statement and page title;
- Thmanyah Sans for fields, labels, help, and actions;
- warm black/deep-brown surfaces, restrained gold borders, and existing crest assets;
- no Google login, membership claims, checkout promises, or other controls that are not implemented.

Localized intended destinations remain allowlisted, safe, and secret-free.

## Approved content and visual refinements

### Copy

Arabic homepage copy becomes:

- Coins section heading: `اشتري كوينز فيفا 27`
- Coins section intro: `اختر المنصة ونوع التوصيل والكمية، وأكمل طلبك خلال دقائق — توصيل آمن وضمان كامل.`
- Hero subtitle: `نوصل كوينز فيفا 27 لحسابك بسرعة وأمان — مع ضمان كامل.`

English receives meaning-equivalent copy without inventing additional guarantees. The guarantee wording is product-owner-approved for the preview, but the published legal guarantee policy must be finalized before a production launch.

### Typography

- Hero H1, section H2, auth H1, simple-page H1, configurator step headings, large quantities, and large totals use the local Thmanyah Serif Display family.
- Body, navigation, controls, stats labels, and supporting copy use Thmanyah Sans.
- All five existing local weights remain available with `font-display: swap`.

### Hero and stats

- The Arabic `+30 مليار` proof item is composed from isolated numeric and unit spans so the numeric value is visually first in RTL and stable under bidi rendering.
- Two or three decorative coin images reuse the approved `ut-coin-80.webp` asset around the hero. They are pointer-inert, `aria-hidden`, outside content hit areas, responsive, and use subtle float/rotate motion.
- Reduced-motion disables the floating transforms and nonessential reveal motion.

### Navigation

The Coins destination becomes active whenever the current homepage hash is `#coins`. The active state updates after clicking the anchor, browser back/forward, and direct hash navigation without incorrectly leaving Home active. Server rendering does not guess the hash; the client safely synchronizes it after hydration.

### Exchange-rate attribution

The current open ExchangeRate-API endpoint contract requires linked attribution on pages using its rates. The standalone footer line is removed, but the same verified link moves into the language/currency preferences dialog beside the selected display-currency explanation. It stays discreet, reachable by keyboard, and present on every page that displays converted rates. It may be removed entirely only after switching to a provider plan whose contract does not require attribution.

## Error and lifecycle behavior

- Missing, malformed, stale, or zero-output currency conversion still fails closed for non-SAR display currency.
- A malformed quote schedule never falls back to a guessed client calculation.
- Invalid quantity keeps the last exact visible price only while editing; commit restores a bounded schedule value.
- Guest cart expiry or missing session ownership renders an empty cart and never exposes another cart.
- Credential expiry keeps the safe cart item but marks credentials as needing re-entry.
- Failed guest-cart claim rolls back without losing either cart.

## Component boundaries

- A backend quote-schedule builder owns exhaustive exact quote generation and compact serialization.
- `HomeController` coordinates availability and emits the schedule but does not implement pricing math.
- The configurator reducer owns selection and schedule lookup state; network/cart submission remains in dedicated hooks/helpers.
- A cart-owner value object/service resolves user or guest identity consistently.
- `AddCoinsToCart` consumes an owner instead of requiring a concrete user.
- A claim action owns guest-to-user transfer and is invoked at successful authentication boundaries.
- The auth layout composes existing storefront shell components rather than duplicating header/footer markup.
- Locale files remain the only customer-copy source.

## Verification contract

### Automated RED/GREEN coverage

- exact schedule length, indexing, boundary totals, overrides, tiers, currency minor units, overflow, and malformed/fresh-rate failure;
- no quote HTTP request for every legal slider/chip/input/adjustment change and immediate exact visible totals;
- server re-quote on cart addition and mismatch resistance;
- one active guest cart per session and isolation across sessions;
- JSON/CSRF/throttle/idempotency/encryption/no-leak protections for guest additions;
- SQLite and MariaDB active-owner invariant, migration lifecycle, same-owner concurrency, and guest-to-user claim with and without an existing user cart;
- localized guest cart read, header count, login/register claim, and secret-free redirects;
- exact Arabic/English copy, RTL stat composition, `#coins` active navigation, font-family contract, floating-coins semantics, reduced motion, and attribution relocation;
- auth pages keep localized actions, safe intended destination, storefront landmarks, keyboard focus, and no fake controls.

### Browser verification

Arabic RTL and English LTR are verified at 320, 390, 768, and 1440 pixels for homepage, configurator, cart, login, and registration. The pass includes direct hash navigation, every amount control, immediate totals, guest add, cart claim after login, visible focus, 44px touch targets, reduced motion, font loading, bidi ordering, no horizontal overflow, no secret-bearing URL/storage/DOM content, and zero console errors/warnings.

## Inputs, accounts, and launch constraints

- No new JavaScript library or pricing service is required.
- Existing WordPress export, official assets, and local Thmanyah fonts remain authoritative.
- The current open ExchangeRate-API remains usable only with its required attribution.
- No provider credentials are requested in chat.
- Checkout and payment remain outside this batch until a real provider and launch policy are approved.
