# WordPress Header and Footer Parity

**Status:** Approved by Mohamed on 2026-08-10
**Scope:** Storefront header, footer, and simple destinations required to avoid dead navigation  
**Complexity:** Medium

## Outcome

The Laravel/React storefront will reproduce the current Arab UT WordPress header and footer in both Arabic and English. WordPress remains the primary visual and structural reference. Enhancements are limited to accessibility, responsive behavior, alignment, focus states, and removing dead or misleading controls.

## Header

The storefront receives the complete two-row WordPress header:

1. A sticky 64px top bar with the Arab UT crest, localized brand name, display preferences, cart, and account controls.
2. A 48–50px primary navigation row with Home, Coins, SBC, FUT Champions, and WhatsApp support.
3. The Arabic brand remains `عرب التيميت`; English remains `Arab UT`.
4. The display preferences control combines language and display currency in one keyboard-accessible popover. Checkout accounting remains SAR.
5. Account links to the real authenticated dashboard flow. Cart links to a simple branded cart destination until the commerce cart task is implemented.
6. Coins links to `#coins`. SBC and FUT Champions link to simple bilingual branded destinations until their product pages are implemented.
7. Navigation uses the same verified WordPress icons and the `الأكثر طلباً` / `Most requested` SBC badge.
8. WhatsApp uses the public WordPress support destination and opens safely in a new tab.

Desktop retains both rows. Mobile keeps the compact top utilities and a horizontally scrollable navigation row, matching the deployed WordPress behavior; it does not introduce a redundant hamburger drawer.

## Footer

The footer reproduces the WordPress three-column structure:

1. Brand crest, an updated FC 27 Arab UT description, and real social profiles.
2. Important links: privacy, returns, warranty and compensation, EA backup codes, and terms.
3. Customer service: WhatsApp and `info@arab-ut.com`.
4. Payment marks: Mada, Visa, Mastercard, and Apple Pay, using the existing WordPress assets.
5. Bottom copyright row and the English EA independence disclaimer.

Only verified social accounts are interactive:

- X: `https://x.com/fut_fi`
- Instagram: `https://www.instagram.com/arabutcoins/`

TikTok and Snapchat are omitted until Mohamed supplies their Arab UT profile URLs.

## Simple destinations

To avoid dead controls, the implementation adds lightweight bilingual Arab UT pages for destinations that do not yet have full product or policy content:

- Cart
- SBC
- FUT Champions
- Privacy policy
- Returns policy
- Warranty and compensation
- EA backup-code guide
- Terms of service

These pages use the real shared header/footer and clearly state that detailed content or ordering is being prepared. They must not imitate a completed checkout or accept data.

## Visual system and enhancements

- Thmanyah Serif Display is used for the brand and prominent headings; Thmanyah Sans remains the UI/body typeface.
- Warm black, cream, and Arab UT gold match the WordPress tokens.
- All interactive targets are at least 44px, with visible focus and hover/press feedback.
- Header blur, borders, menus, social controls, and footer cards match WordPress, with reduced visual noise where the exported CSS contains conflicting overrides.
- RTL/LTR layout, safe-area spacing, long translations, 200% zoom, and 320px width must remain usable without horizontal page overflow. The mobile navigation itself may scroll horizontally as an intentional navigation surface.
- Motion is limited to short transform/opacity feedback and respects `prefers-reduced-motion`.

## Data and component boundaries

- `StoreLayout` owns the shared header and footer shell.
- Navigation, preferences, footer links, and social destinations are supplied through bilingual translation/config contracts rather than duplicated inline strings.
- Currency changes preserve the current route, query string, and hash.
- Language changes preserve display currency and the current supported destination when an equivalent localized route exists.
- No new JavaScript or Composer dependency is introduced.

## Testing and verification

The implementation uses TDD and verifies:

- exact header/footer landmarks, labels, links, icons, and bilingual copy;
- working language, currency, account, cart, Coins, SBC, FUT, WhatsApp, X, and Instagram destinations;
- absence of TikTok, Snapchat, placeholder `href="#"` links, and dead buttons;
- active navigation and safe external-link attributes;
- keyboard operation and focus restoration for the preferences popover;
- 44px targets, deliberate mobile nav scrolling, no page overflow, and no console errors;
- Arabic and English at 320px, 390px, 768px, 807px, and 1440px;
- full Composer, PHP, TypeScript, lint, format, Vitest, Pest, and production-build gates.
