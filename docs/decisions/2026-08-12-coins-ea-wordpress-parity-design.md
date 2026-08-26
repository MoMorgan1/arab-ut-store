# Coins EA WordPress Parity Design

**Status:** Approved by Mohamed on 2026-08-12

**Complexity:** Ambitious

## Outcome

The Coins EA step matches the useful owned WordPress flow while preserving the stronger Laravel security boundary. It collects EA email, password, exactly three distinct eight-digit backup codes, conditional current coin balance for fast console delivery, an EA Companion transfer-market confirmation, and an explicit policy acknowledgement. All fulfillment data is encrypted at rest, retained without automatic expiry, and available only through the existing owner-scoped no-store credential API for display and editing.

## Fields and rules

- `ea_email`: normalized valid email.
- `ea_password`: preserved byte-for-byte within the existing bounded secret contract.
- `backup_codes`: exactly three distinct ASCII eight-digit strings.
- `current_balance`: required non-negative integer for PlayStation/Xbox fast delivery, absent for other delivery/platform combinations.
- `companion_market_open`: must be `true` for submission.
- `policy_accepted`: must be `true` for submission; persistence records a server-owned policy version and acceptance timestamp rather than trusting browser timestamps.

The browser never places these values in URLs, Inertia props, local/session storage, analytics, logs, or cacheable responses. The add-to-cart fingerprint covers every normalized fulfillment field so an idempotency replay cannot silently change credentials or confirmations.

## Persistence and compatibility

- Extend the encrypted `CartItemSecret` payload rather than placing balance or confirmations in public cart configuration.
- Existing secret rows without the new keys remain readable and editable.
- Safe cart/Inertia projection may expose only presence booleans/counts, never balance, password, email, codes, or accepted policy timestamps.
- The owner-only credential GET returns the complete fulfillment payload with `Cache-Control: no-store`; the owner-only update validates and re-encrypts it transactionally.
- Cart item/user deletion remains the only product deletion path. No automatic expiry is introduced.

## UI

- Preserve the existing five-step configurator and price schedule behavior.
- Reproduce the WordPress field hierarchy, compact trust notice, password reveal, three numbered codes, conditional formatted balance input, Companion help link, policy links, and required confirmation toggles.
- Continue is disabled while invalid/submitting and the first invalid field receives focus with an associated localized error.
- The review step summarizes only safe fulfillment readiness and the selected quote; it never echoes secrets.
- The cart owner can reveal and edit all stored fields through the post-load credential panel.

## Verification

- Backend tests prove conditional validation, exact three-code rules, byte-preserved password, encrypted-at-rest payload, legacy-row compatibility, owner isolation, no-store responses, fingerprint conflict behavior, and safe response/configuration projections.
- Frontend tests prove conditional balance visibility, confirmations, focus/error associations, secret-free summary/URL/storage, loading locks, and successful/retry flows.
- Arabic/English browser checks cover 320/390/768/1075/1440, RTL/LTR, keyboard, 44px targets, reduced motion, and console cleanliness.

