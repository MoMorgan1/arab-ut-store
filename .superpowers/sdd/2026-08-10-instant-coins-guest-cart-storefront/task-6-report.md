# Task 6 report — storefront authentication shell

## Outcome

Arabic and English authentication now render inside the existing storefront shell. Login and registration use one form-first two-panel composition with a truthful account-benefits panel. Forgot-password and reset-password use one focused card while retaining the same `StoreHeader`, `StoreFooter`, locale routes, validation, status, reset, and password-reveal behavior.

The implementation does not add a Google sign-in control, checkout/payment prompt, social proof, guest token, guest HMAC, or other dead/fake authentication control.

## Design process

The implementation applied the required design skills after reading the WordPress account shell, project design contract, approved reference image, and `.impeccable.md`:

- `frontend-design`: used the approved asymmetric form/benefits composition and preserved the existing warm black, gold, and cream storefront identity.
- `ui-ux-pro-max`: retained its accessibility guidance for visible labels, keyboard focus, 44px controls, readable contrast, and responsive behavior. Its suggested generic Liquid Glass/Noto direction was rejected because WordPress and project authority require the existing Arab UT materials and Thmanyah typography.
- `typeset`: uses Thmanyah Serif Display at weight 700 for page and benefit headings, and Thmanyah Sans for body copy, labels, fields, and controls. No auth heading synthesizes weight 800.
- `arrange`: keeps the form card first in the DOM, places it on the left of the desktop composition, and follows it with the benefit panel on stacked layouts.
- `adapt`: stacks at 768px and below, switches to two panels above 768px so the 807px reference width retains the intended desktop hierarchy, and adds a tighter 480px treatment for 320px and 390px devices.
- `polish`: aligns borders, radii, gold focus treatment, restrained shadows, status states, and benefit icons with the existing storefront shell.

No Task 7 header/footer redesign, cart behavior, checkout behavior, credential projection, or security contract changed.

## Implemented contracts

- `AuthLayout` composes the existing `StoreLayout`, which owns the single shared `StoreHeader` and `StoreFooter`.
- Login/register render one `.auth-shell__form-card` before one `.auth-shell__benefits` in source order.
- Forgot/reset render `.auth-shell__grid--focused` with no benefits panel.
- Exact localized benefits are delivered from `lang/ar/auth_ui.php` and `lang/en/auth_ui.php`.
- Form errors are associated through `aria-describedby` and `aria-invalid`; error content uses `role="alert"`; successful status content uses a polite status region.
- Positive `tabIndex` values were removed so keyboard order follows the form-first DOM order.
- The password-reveal button no longer forces `tabIndex=-1`, so it participates in the natural keyboard order for every current `PasswordInput` consumer.
- Email/password controls retain appropriate autocomplete values; required fields remain required; submit controls retain their processing state.
- The auth shell honors `prefers-reduced-motion: reduce` and limits animation/transition durations.

## TDD evidence

### RED

Before production edits:

- Frontend storefront-auth selection: 3 tests, 3 failed. Failures proved the auth pages lacked the complete storefront landmarks, form/benefit hierarchy, and focused recovery layout.
- Backend localized-auth selection: 12 tests, 10 passed and 2 failed. Both failures proved the exact localized benefit contract was absent from the Inertia payload.

During GREEN verification, the focused recovery test caught a real modifier-class concatenation defect (`auth-shell__gridauth-shell__grid--focused`). The production class composition was corrected and the regression remained in the focused suite.

The accessibility guard then added one focused password-reveal regression before changing the shared input. Its targeted RED produced 1 failed test: the reveal button was expected at `tabIndex` 0 and actually reported `-1`. Removing only the forced negative tab index made the targeted test pass.

The final browser review also found that the remember-me row was 44px tall while its associated label was only about 35x14px. A focused RED proved the label lacked the required expanded hit-area classes. The label now fills the remaining row width with `min-h-11 flex-1`, preserving the compact 16px checkbox visual while making the labeled pointer target at least 44px tall.

### GREEN

- Focused frontend auth selection: 5 tests passed.
- Focused backend localized-auth selection: 12 tests passed, 304 assertions.
- Full frontend suite: 188 tests passed across 16 files.
- Full backend suite: 344 tests, 341 passed, 3 expected skips, 17,789 assertions.

The frontend integration test renders the actual `StoreLayout`, `StoreHeader`, and `StoreFooter`, and verifies one banner, one named primary navigation, one contentinfo landmark, form-first source order, localized benefits, correct account route, and the absence of fake auth controls.

## Live route verification

A migrated local SQLite runtime returned HTTP 200 for all eight auth surfaces:

| Route | Component | Locale/direction | Shell/UI | Benefits | Secret props |
| --- | --- | --- | --- | --- | --- |
| `/login` | `auth/login` | `ar` / `rtl` | present | 3 | absent |
| `/register` | `auth/register` | `ar` / `rtl` | present | 3 | absent |
| `/forgot-password` | `auth/forgot-password` | `ar` / `rtl` | present | 3 | absent |
| `/reset-password/test-token?email=player@example.test` | `auth/reset-password` | `ar` / `rtl` | present | 3 | absent |
| `/en/login` | `auth/login` | `en` / `ltr` | present | 3 | absent |
| `/en/register` | `auth/register` | `en` / `ltr` | present | 3 | absent |
| `/en/forgot-password` | `auth/forgot-password` | `en` / `ltr` | present | 3 | absent |
| `/en/reset-password/test-token?email=player@example.test` | `auth/reset-password` | `en` / `ltr` | present | 3 | absent |

## Browser matrix and visual evidence

The root verifier completed the fresh in-app-browser matrix after this agent's original tab reached a Chromium connection-error document before the isolated server was ready:

- Arabic `/login` and English `/en/register` passed at 320, 390, 768, 807, and 1440 CSS pixels.
- Every case had the correct `lang`/`dir`, exactly one header/banner, named primary navigation, main, footer/contentinfo, and zero document overflow (`scrollWidth === clientWidth`).
- The form card remained DOM child 0 and the benefits aside DOM child 1. The grid stayed one column through 768px and became two columns at 807px and 1440px.
- Computed typography was Thmanyah Sans for the auth body and Thmanyah Serif Display at weight 700 for the primary heading.
- No Google, payment, or checkout copy appeared inside the auth shell.
- The 320px Arabic login and 1440px English registration visual captures were clean, readable, and matched the approved form-first hierarchy.
- Arabic `/forgot-password` and English tokenized `/en/reset-password` at 390px each rendered one form card, zero benefits panels, one column, zero overflow, and 44–48px interactive targets.
- Keyboard focus produced the intended visible 3px focus treatment.
- The password reveal control is now a 44px, `tabIndex=0` button in natural DOM order after the password field. The remember-me label is a 44px-tall clickable target rather than relying on the inert row wrapper.
- With reduced motion emulated, `matchMedia('(prefers-reduced-motion: reduce)')` was true, with zero active animations and zero transitions longer than 20ms.
- Console warning/error output was empty.

## Static and guard gates

- Strict Composer validation passed.
- Pint passed.
- PHPStan passed with zero errors.
- ESLint passed.
- Prettier passed.
- TypeScript passed with no emit.
- Vite production build passed.
- `git diff --check` passed.
- The build emitted only the existing Vite runtime-public-path notices for project fonts and imagery; assets remain available from `public/` and the build completed successfully.
- Clean Code guard: no broad catches, speculative abstraction, dead production branches, copied shell component, or mock fallback was introduced.
- Test guard: new assertions cover user-visible landmarks, copy, route, source order, recovery composition, and secret absence; no snapshots or production fixture fallbacks were added.
- Docs guard: every route, class, prop, test count, and command claim in this report was checked against the implementation or fresh command output.

## Files changed

Created:

- `resources/js/__tests__/auth/auth-storefront.test.tsx`
- `.superpowers/sdd/2026-08-10-instant-coins-guest-cart-storefront/task-6-report.md`

Modified:

- `lang/ar/auth_ui.php`
- `lang/en/auth_ui.php`
- `resources/css/app.css`
- `resources/js/__tests__/auth/localized-auth.test.tsx`
- `resources/js/components/password-input.tsx`
- `resources/js/layouts/auth-layout.tsx`
- `resources/js/layouts/auth/auth-simple-layout.tsx`
- `resources/js/pages/auth/login.tsx`
- `resources/js/pages/auth/register.tsx`
- `resources/js/pages/auth/forgot-password.tsx`
- `resources/js/pages/auth/reset-password.tsx`
- `resources/js/types/auth.ts`
- `tests/Feature/Auth/LocalizedAuthTest.php`
