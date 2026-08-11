# Task 7 storefront polish report

## Delivered

- Applied the approved Arabic and English hero/coins copy and kept large display text on `Thmanyah Serif Display`.
- Rendered the Arabic `+30` value in an explicit left-to-right `bdi`, followed by its Arabic unit.
- Made Home/Coins navigation react to direct hashes, clicks, Back, and Popstate/Hashchange updates.
- Fixed the inherited Task 6 account-active P2: `/login`, `/register`, `/forgot-password`, and `/reset-password/*` are active for locale-matched auth routes; ordinary storefront routes remain inactive.
- Moved the linked ExchangeRate-API attribution from the footer into the preferences dialog. The preferences translation is required; the deprecated footer field and compatibility fallback were removed.
- Added three pointer-inert, screen-reader-hidden floating coin decorations behind hero content, with all motion disabled under `prefers-reduced-motion: reduce`.

## WordPress asset provenance

- Source: `work/wordpress-source/wp-content/themes/arabut-child/img/ut-coin.png`
- Source dimensions: 340 x 340, transparent PNG
- Source SHA-256: `CABD45D32D84D9C6278491A186738D8D957409322C1E6308C980412F76307C4B`
- Derived without upscaling: `public/images/store/coins/ut-coin-160.webp` (160 x 160, 8,346 bytes) and `ut-coin-240.webp` (240 x 240, 16,358 bytes)

## TDD evidence

- Initial PHP RED: 6 tests, 3 failed / 3 passed, 520 assertions (missing preferences attribution and old approved copy).
- Initial Vitest RED: 63 tests, 5 failed / 58 passed (missing bidi/decorative assets, attribution still in footer, static hash state, missing account-active state).
- Expanded account-family RED: 19 tests, 3 failed / 16 passed before implementation.
- Compatibility-contract review produced a TypeScript RED for fixtures missing the now-required preferences tree; every affected fixture was migrated.

## Automated verification

- Focused Pest: 6/6 passed, 552 assertions.
- Focused Vitest: 66/66 passed.
- Full `npm run ci:check`: 16 files, 194/194 tests passed; ESLint, Prettier, TypeScript, and Vite build passed.
- `git diff --check`: passed.

## Browser verification

Local server command (working directory was the worktree `public` directory):

```text
C:\Users\hp\scoop\apps\php\current\php.exe -S 127.0.0.1:8017 C:\Users\hp\Documents\Codex\2026-08-08\hi\work\arab-ut-store\.worktrees\instant-coins-guest-cart\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
```

- Capped matrix: 8/8 cases — Arabic and English at 320, 390, 768, and 1440 CSS px.
- All cases: no horizontal overflow; headings resolved to Thmanyah Serif Display; approved copy was present; three decorative coins had empty alt, `aria-hidden=true`, `draggable=false`, `pointer-events:none`, and remained behind content.
- Arabic visual bidi check: `+30` rendered visually before `مليار`.
- Navigation: direct `#coins`, click, and Back each produced the correct single `aria-current` item.
- Preferences: attribution link was visible, keyboard-focusable (`tabIndex=0`), pointed to `https://www.exchangerate-api.com`, and no provider link remained in the footer.
- Reduced motion emulation: all three coin animations and the stats animation computed to `none`.
- Browser console: 0 warnings and 0 errors.
