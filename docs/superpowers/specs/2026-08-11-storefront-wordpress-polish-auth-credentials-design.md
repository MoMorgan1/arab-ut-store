# Storefront WordPress Polish, Authentication, and EA Credentials Design

**Status:** Approved by Mohamed on 2026-08-11

**Direction:** WordPress مصقول (refined WordPress)

**Complexity:** Ambitious

## Outcome

The public storefront keeps the recognizable Arab UT WordPress hierarchy, warm black and restrained gold palette, local Thmanyah typography, and service imagery, while improving motion, mobile parity, catalog browsing, authentication, and cart feedback. The customer can browse and configure without losing state, authenticate with email, Google, or a verified WhatsApp number, and manage Coins EA credentials from the owner-only cart.

## Approved scope

### Homepage and shared shell

- Keep the same primary navigation entries on mobile and desktop; mobile scrolls horizontally instead of hiding entries.
- Keep all four hero metrics in one row at every supported width. Animate each from zero to its published value once when the metrics enter the viewport; render the final value immediately under reduced motion.
- Add a restrained number of decorative floating coin marks to the hero. They remain hidden from assistive technology and stop under reduced motion.
- Autoplay the equal-size service-card rail from the inline start toward the inline end. Pause on hover, keyboard focus, pointer/touch interaction, and when the document is hidden. Reduced motion disables autoplay while native scrolling and controls remain available.
- Use contained local or mirrored high-resolution service images; never upscale, crop, or hotlink unapproved media.
- Use exact Arabic card names: `تحديات بناء التشكيلات`, `المهام`, `فوت تشامبيونز`, and `الرايفلز`.
- Use an authored SVG chevron for FAQ disclosure state, never a text glyph or emoji.

### SBC

- Preserve the useful WordPress structure: strong editorial hero, count, search, categories, sorting, honest empty/unavailable states, and equal product cards.
- Every eligible authoritative variant exposes a real Add to Cart action. No fake contact/details CTA replaces it.
- Product images use the same contained high-resolution treatment as the homepage.

### Currency and form continuity

- Changing display currency updates the URL and Inertia shared display currency without a full document reload.
- The current Coins step, platform, delivery, amount, EA email, password, and backup-code edits remain mounted and unchanged.
- Re-render totals from the already supplied server-authoritative schedules; never calculate price from a browser-owned formula.

### Authentication

- Keep the auth pages inside the normal storefront header and footer.
- Fix password reveal placement for both RTL and LTR without overlapping entered text.
- Add Google login with Laravel Socialite using the official OAuth authorization-code flow. Store the stable provider ID and email identity, not access/refresh tokens that the storefront does not use.
- Add phone login with an international country-code picker. Normalize phone numbers to E.164 with libphonenumber semantics.
- Whapi sends one-time codes through the existing connected channel. Codes are hashed, short-lived, single-use, attempt-limited, rate-limited, and never logged.
- WhatsApp OTP signs in only an existing phone-linked account. Email/password or Google-created accounts must add and verify a WhatsApp number before checkout when checkout is introduced.

### EA credentials and cart

- Collect exactly three distinct eight-digit backup codes, not five, in Arabic and English.
- Remove the customer-facing claim that credentials are deleted after 24 hours.
- EA credentials remain encrypted at rest and are retained without automatic expiry, per Mohamed's decision.
- The initial cart HTML/Inertia props contain only safe credential presence metadata. Plain credentials are fetched after page load from an owner-scoped same-origin JSON endpoint with `Cache-Control: no-store`.
- The cart always displays EA email, password, and all three backup codes to the verified cart owner. Password visibility is explicit and accessible.
- An Edit action updates all credential fields transactionally, re-encrypts the payload, and returns only the new safe projection.
- Credentials never enter logs, analytics, URLs, local/session storage, idempotency responses, or cacheable responses.
- Deleting the cart item or user account is the only product path that deletes the associated credentials.
- Add-to-cart success immediately updates the count and produces restrained visual confirmation; it never blocks navigation.

## Technical approach

- Laravel remains the trust boundary for catalog price, owner resolution, Socialite callbacks, OTP verification, and encrypted secrets.
- React/Inertia owns transient configurator and dialog state. Currency changes use Inertia client navigation with state preservation.
- Existing n8n catalog/review workflows remain unchanged; the public request path continues reading the Laravel database only.
- Whapi credentials and Google OAuth credentials live only in the approved environment configuration. No secret is committed or pasted in chat.
- Existing guest-cart ownership, post-auth claim, locking, and idempotency contracts remain authoritative.

## Success criteria

- Arabic and English render correctly at 320, 390, 768, 1075, and 1440 CSS pixels with no document overflow.
- Keyboard, touch, RTL/LTR, 200% zoom, reduced motion, and clean-console checks pass.
- Currency changes preserve all configurator inputs and issue no full document navigation.
- Three-code validation is enforced client and server side.
- Cross-owner credential reads and writes return fail-closed responses without revealing whether another secret exists.
- Google and WhatsApp flows have boundary-faked automated tests and never call third parties from unrelated customer requests.
- Full PHP, TypeScript, lint, formatting, test, build, security-sink, and database lifecycle gates pass before release.

## Official references

- Laravel Socialite 13.x: https://laravel.com/docs/13.x/socialite
- Whapi developer documentation: https://whapi.cloud/docs

