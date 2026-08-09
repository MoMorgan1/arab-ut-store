# WordPress Header and Footer Parity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reproduce the Arab UT WordPress two-row header and three-column footer in the bilingual Laravel/React storefront, with working destinations and no placeholder controls.

**Architecture:** `StoreLayout` will remain the shared shell and compose focused `StoreHeader`, `StorePreferences`, and `StoreFooter` components. Laravel will own the bilingual copy, external destination configuration, locale-aware simple-page routes, and shared Inertia shell props; React will own interaction and presentation without introducing a new dependency.

**Tech Stack:** Laravel 13, Inertia Laravel/React 3, React 19, TypeScript 5.7, Vitest 4, Testing Library 16, Pest 4, existing Thmanyah fonts, existing CSS tokens, and mechanically copied WordPress assets.

## Global Constraints

- WordPress files under `work/wordpress-public-html-20260809/wp-content/themes/arabut-child` are the visual and asset authority.
- Use `frontend-design`, `ui-ux-pro-max`, `arrange`, `adapt`, `typeset`, and `polish` before changing UI; reproduce WordPress first and refine second.
- Review current implementation and current official Laravel, Inertia, React, Testing Library, and Vitest documentation before production code.
- Arabic brand is exactly `عرب التيميت`; English brand is exactly `Arab UT`.
- X is `https://x.com/fut_fi`; Instagram is `https://www.instagram.com/arabutcoins/`; TikTok and Snapchat are absent.
- WhatsApp is `https://wa.me/966537998099`; customer-service email is `info@arab-ut.com`.
- Header is two rows: 64px top utilities and 48–50px navigation. Mobile navigation scrolls horizontally; no hamburger drawer is added.
- Footer contains Mada, Visa, Mastercard, and Apple Pay marks and the EA independence disclaimer.
- Display currencies remain configurable; checkout accounting remains SAR and is not promoted to customers.
- No placeholder `href="#"`, no dead button, no fake checkout, and no data collection on simple destination pages.
- Use Thmanyah Serif Display for brand/prominent headings and Thmanyah Sans for UI/body.
- Interactive targets are at least 44px and must support keyboard, focus-visible, RTL/LTR, reduced motion, safe areas, 200% zoom, and 320px width.
- Do not add JavaScript or Composer dependencies.

## File Structure

- Create `app/Http/Controllers/Store/SimpleStorePageController.php`: validates the route-default page key and renders one shared simple-page Inertia view.
- Create `resources/js/components/store/store-header.tsx`: two-row WordPress header and active navigation.
- Create `resources/js/components/store/store-preferences.tsx`: language/currency popover with Escape, outside-click, and focus restoration.
- Create `resources/js/components/store/store-footer.tsx`: WordPress footer structure, verified social/contact links, payments, and legal disclaimer.
- Create `resources/js/pages/store/simple-page.tsx`: bilingual non-transactional destination page.
- Create `resources/js/types/store-shell.ts`: exact shell translation/config/page interfaces shared by the components.
- Create `tests/Feature/Store/StoreShellRoutesTest.php`: route, prop, locale, and destination contract.
- Create `resources/js/__tests__/store/store-header.test.tsx`: header structure, links, popover lifecycle, and active state.
- Create `resources/js/__tests__/store/store-footer.test.tsx`: footer links, omissions, payments, and legal copy.
- Create `resources/js/__tests__/store/store-simple-page.test.tsx`: simple-page title/body/link safety.
- Modify `config/store.php`: verified contact/social destinations and page-key allowlist.
- Modify `lang/ar/ui.php` and `lang/en/ui.php`: parity-checked shell/header/footer/simple-page copy.
- Modify `app/Http/Middleware/HandleInertiaRequests.php`: shared `storeShell` configuration and locale-aware destination URLs.
- Modify `routes/web.php`: default-Arabic and `/en` simple destinations.
- Modify `resources/js/layouts/store-layout.tsx`: compose header, main, and footer while preserving the skip link.
- Modify `resources/css/app.css`: WordPress header/footer/simple-page styles and responsive refinements.
- Modify `tests/Feature/Store/StoreTranslationParityTest.php`: UI tree parity and prohibited-copy/link assertions.
- Modify `resources/js/__tests__/store-layout.test.tsx`: shared-shell composition and URL-state preservation.
- Copy verified assets into `public/images/store/navigation/` and `public/images/store/payments/`.

---

### Task 1: Laravel Shell Contract and Safe Destinations

**Files:**
- Create: `app/Http/Controllers/Store/SimpleStorePageController.php`
- Create: `resources/js/pages/store/simple-page.tsx`
- Create: `resources/js/types/store-shell.ts`
- Create: `tests/Feature/Store/StoreShellRoutesTest.php`
- Create: `resources/js/__tests__/store/store-simple-page.test.tsx`
- Modify: `config/store.php`
- Modify: `lang/ar/ui.php`
- Modify: `lang/en/ui.php`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php`
- Modify: `routes/web.php`
- Modify: `tests/Feature/Store/StoreTranslationParityTest.php`

**Interfaces:**
- Produces: shared Inertia prop `storeShell: StoreShellConfig` with `homeUrl`, `coinsUrl`, `cartUrl`, `sbcUrl`, `futChampionsUrl`, `accountUrl`, `whatsappUrl`, `email`, `socials`, and `payments`.
- Produces: `SimpleStorePageController::__invoke(Request $request): Response` reading a route default `storePage` from the allowlist `cart|sbc|fut_champions|privacy|returns|warranty|ea_backup_codes|terms`.
- Produces: `StoreShellTranslations` and `SimpleStorePageProps` in `resources/js/types/store-shell.ts`.

Define the cross-task contract once:

```ts
export type StoreLocale = 'ar' | 'en';
export type SimpleStorePageKey =
    | 'cart' | 'sbc' | 'fut_champions' | 'privacy'
    | 'returns' | 'warranty' | 'ea_backup_codes' | 'terms';

export type StoreShellConfig = {
    homeUrl: string;
    coinsUrl: string;
    cartUrl: string;
    sbcUrl: string;
    futChampionsUrl: string;
    accountUrl: string;
    privacyUrl: string;
    returnsUrl: string;
    warrantyUrl: string;
    eaBackupCodesUrl: string;
    termsUrl: string;
    whatsappUrl: string;
    email: string;
    socials: { x: string; instagram: string };
    payments: Array<{ name: string; imageUrl: string; width: number; height: number }>;
};

export type StoreShellTranslations = {
    brand: string;
    language: string;
    currency_selector: string;
    home_title: string;
    skip_to_content: string;
    store_tools: string;
    header: {
        primary_navigation: string;
        preferences: string;
        home: string;
        coins: string;
        sbc: string;
        fut_champions: string;
        most_requested: string;
        whatsapp: string;
        cart: string;
        account: string;
    };
    footer: {
        description: string;
        important_links: string;
        privacy: string;
        returns: string;
        warranty: string;
        ea_backup_codes: string;
        terms: string;
        customer_service: string;
        whatsapp: string;
        payment_methods: string;
        legal_navigation: string;
        copyright: string;
        ea_disclaimer: string;
    };
    simple_pages: {
        eyebrow: string;
        back_home: string;
    } & Record<SimpleStorePageKey, { title: string; body: string }>;
};

export type SimpleStorePageProps = {
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: StoreLocale;
    page: { key: SimpleStorePageKey; title: string; body: string };
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};
```

- [ ] **Step 1: Read current official framework documentation**

Open and record the current recommended patterns used by this task:

```text
https://laravel.com/docs/13.x/routing#named-routes
https://laravel.com/docs/13.x/localization
https://inertiajs.com/shared-data
https://inertiajs.com/pages
```

Confirm no API selected in the plan is deprecated or changed in the installed Laravel/Inertia versions.

- [ ] **Step 2: Write failing Laravel route and shared-prop tests**

Add datasets for every destination and both locales. Assert the component, exact page key, localized title/body, and shell destinations:

```php
it('renders every simple storefront destination in both locales', function (
    string $path,
    string $locale,
    string $page,
) {
    $this->get($path)->assertOk()->assertInertia(fn (Assert $inertia) => $inertia
        ->component('store/simple-page')
        ->where('locale', $locale)
        ->where('page.key', $page)
        ->where('storeShell.socials.x', 'https://x.com/fut_fi')
        ->where('storeShell.socials.instagram', 'https://www.instagram.com/arabutcoins/')
        ->missing('storeShell.socials.tiktok')
        ->missing('storeShell.socials.snapchat'));
})->with([
    'cart' => ['/cart', 'ar', 'cart'],
    'explicit Arabic cart' => ['/ar/cart', 'ar', 'cart'],
    'English cart' => ['/en/cart', 'en', 'cart'],
    'SBC' => ['/sbc', 'ar', 'sbc'],
    'English SBC' => ['/en/sbc', 'en', 'sbc'],
    'FUT Champions' => ['/fut-champions', 'ar', 'fut_champions'],
    'English FUT Champions' => ['/en/fut-champions', 'en', 'fut_champions'],
    'privacy' => ['/privacy', 'ar', 'privacy'],
    'English privacy' => ['/en/privacy', 'en', 'privacy'],
    'returns' => ['/returns', 'ar', 'returns'],
    'English returns' => ['/en/returns', 'en', 'returns'],
    'warranty' => ['/warranty', 'ar', 'warranty'],
    'English warranty' => ['/en/warranty', 'en', 'warranty'],
    'EA codes' => ['/ea-backup-codes', 'ar', 'ea_backup_codes'],
    'English EA codes' => ['/en/ea-backup-codes', 'en', 'ea_backup_codes'],
    'terms' => ['/terms', 'ar', 'terms'],
    'English terms' => ['/en/terms', 'en', 'terms'],
]);
```

- [ ] **Step 3: Run Laravel RED**

Run:

```powershell
php artisan test tests/Feature/Store/StoreShellRoutesTest.php tests/Feature/Store/StoreTranslationParityTest.php
```

Expected: FAIL because the routes, `storeShell` props, and UI translation trees do not exist.

- [ ] **Step 4: Write failing simple-page React tests**

Test that the page renders its localized heading/body inside the existing layout without introducing a form or commerce button. Footer composition belongs to Task 3:

```tsx
it('renders a non-transactional branded destination', () => {
    render(<SimpleStorePage />);

    expect(screen.getByRole('heading', { name: 'SBC' })).toBeVisible();
    expect(screen.getByRole('banner')).toBeVisible();
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /pay|checkout/i })).not.toBeInTheDocument();
    expect(document.querySelector('a[href="#"]')).not.toBeInTheDocument();
});
```

- [ ] **Step 5: Run React RED**

Run:

```powershell
npm test -- resources/js/__tests__/store/store-simple-page.test.tsx
```

Expected: FAIL because `store/simple-page` and its props do not exist.

- [ ] **Step 6: Implement the configuration and translations**

Add deterministic configuration; do not store labels in configuration:

```php
'support' => [
    'whatsapp_url' => 'https://wa.me/966537998099',
    'email' => 'info@arab-ut.com',
],
'socials' => [
    'x' => 'https://x.com/fut_fi',
    'instagram' => 'https://www.instagram.com/arabutcoins/',
],
'simple_pages' => [
    'cart', 'sbc', 'fut_champions', 'privacy', 'returns',
    'warranty', 'ea_backup_codes', 'terms',
],
'payments' => [
    ['name' => 'Mada', 'image_url' => '/images/store/payments/mada.png', 'width' => 120, 'height' => 41],
    ['name' => 'Visa', 'image_url' => '/images/store/payments/visa.png', 'width' => 120, 'height' => 39],
    ['name' => 'Mastercard', 'image_url' => '/images/store/payments/mastercard.png', 'width' => 120, 'height' => 75],
    ['name' => 'Apple Pay', 'image_url' => '/images/store/payments/apple-pay.png', 'width' => 120, 'height' => 50],
],
```

Add identical leaf keys to both `ui.php` files under `header`, `footer`, and `simple_pages`. Use this approved copy contract exactly:

| Key | Arabic | English |
|---|---|---|
| `header.primary_navigation` | التنقل الرئيسي | Primary navigation |
| `header.preferences` | اللغة والعملة | Language and currency |
| `header.home` | الرئيسية | Home |
| `header.coins` | كوينز | Coins |
| `header.sbc` | SBC | SBC |
| `header.fut_champions` | FUT Champions | FUT Champions |
| `header.most_requested` | الأكثر طلباً | Most requested |
| `header.whatsapp` | تواصل معنا | Contact us |
| `header.cart` | السلة | Cart |
| `header.account` | حسابي | My account |
| `footer.description` | متجر عرب التيميت، فريق متخصص في خدمات FC 27. نوصل لك الكوينز بأمان وضمان كامل وبأسعار منافسة. | Arab UT specializes in FC 27 services, delivering Coins safely with a full guarantee and competitive prices. |
| `footer.important_links` | روابط تهمك | Important links |
| `footer.customer_service` | خدمة العملاء | Customer service |
| `footer.payment_methods` | طرق الدفع المقبولة | Accepted payment methods |
| `footer.whatsapp` | واتساب | WhatsApp |
| `footer.ea_disclaimer` | All EA FC assets are the property of EA Sports. Arab UT is an independent service and is not affiliated with EA Sports or Electronic Arts Inc. | All EA FC assets are the property of EA Sports. Arab UT is an independent service and is not affiliated with EA Sports or Electronic Arts Inc. |
| `simple_pages.eyebrow` | عرب التيميت | Arab UT |
| `simple_pages.back_home` | ارجع للرئيسية | Back to home |

Use these simple-page titles and bodies; none promises an activation date:

| Page | Arabic title/body | English title/body |
|---|---|---|
| `cart` | السلة — السلة بتكون جاهزة مع مرحلة الطلب والدفع. حاليًا تقدر تختار خدمتك من الرئيسية. | Cart — The cart will be enabled with the ordering and payment stage. You can choose your service from the home page now. |
| `sbc` | خدمات SBC — نجهز صفحة SBC وربط المنتجات الآلي. بتلقى كل الخيارات هنا بعد اكتمال الربط. | SBC Services — We are preparing the SBC catalog and automated product connection. All options will appear here when the connection is complete. |
| `fut_champions` | FUT Champions — نجهز صفحة الخدمة وتفاصيل الطلب. تقدر تتواصل معنا لو تحتاج مساعدة الآن. | FUT Champions — We are preparing the service page and order details. Contact us if you need help now. |
| `privacy` | سياسة الخصوصية — ننقل ونراجع سياسة الخصوصية للمتجر الجديد. ما نجمع أي بيانات من هذه الصفحة. | Privacy Policy — We are migrating and reviewing the policy for the new store. This page does not collect any data. |
| `returns` | سياسة الاسترجاع — ننقل شروط الاسترجاع بصياغة واضحة قبل تفعيل الدفع. | Returns Policy — We are migrating the return terms in clear language before payments are enabled. |
| `warranty` | سياسة الضمان والتعويض — ننقل تفاصيل الضمان والتعويض قبل تفعيل الطلبات. | Warranty and Compensation — We are migrating the warranty and compensation details before orders are enabled. |
| `ea_backup_codes` | أكواد EA الاحتياطية — نجهز شرح بسيط وآمن لطريقة استخراج الأكواد. | EA Backup Codes — We are preparing a simple, secure guide for obtaining backup codes. |
| `terms` | شروط الخدمة — ننقل ونراجع شروط الخدمة قبل إطلاق الطلب والدفع. | Terms of Service — We are migrating and reviewing the terms before ordering and payments launch. |

Add the remaining legal labels (`privacy`, `returns`, `warranty`, `ea_backup_codes`, `terms`), legal-navigation label, and `copyright` with the same meaning and exact `:year` placeholder in both locales. Arabic uses light Gulf phrasing without slang that harms clarity.

- [ ] **Step 7: Implement locale-aware routes, controller, and shared props**

Register every route from one fixed map using the existing unprefixed default-Arabic plus supported `/{locale}` convention. The `/ar` routes remain supported because `config('store.locales')` contains `ar` and `en`, while generated Arabic navigation URLs use the canonical unprefixed route. Use route defaults rather than accepting an arbitrary page key:

```php
$simpleStorePages = [
    'cart' => '/cart',
    'sbc' => '/sbc',
    'fut_champions' => '/fut-champions',
    'privacy' => '/privacy',
    'returns' => '/returns',
    'warranty' => '/warranty',
    'ea_backup_codes' => '/ea-backup-codes',
    'terms' => '/terms',
];

foreach ($simpleStorePages as $page => $uri) {
    Route::get($uri, SimpleStorePageController::class)
        ->defaults('storePage', $page)->name("store.{$page}");
}

Route::prefix('{locale}')->whereIn('locale', config('store.locales'))
    ->group(function () use ($simpleStorePages): void {
        foreach ($simpleStorePages as $page => $uri) {
            Route::get($uri, SimpleStorePageController::class)
                ->defaults('storePage', $page)->name("localized.store.{$page}");
        }
    });
```

The controller must reject a non-string or non-allowlisted route default with `LogicException`, translate `ui.simple_pages.<key>`, and render:

```php
return Inertia::render('store/simple-page', [
    'page' => [
        'key' => $page,
        'title' => $translations['title'],
        'body' => $translations['body'],
    ],
]);
```

`HandleInertiaRequests` computes localized internal URLs with named routes, maps configured `image_url` keys to the TypeScript `imageUrl` contract, and sets `accountUrl` to the dashboard for an authenticated user and login for a guest.

- [ ] **Step 8: Implement the typed simple page**

Create `SimpleStorePage` with `Head`, `StoreLayout`, one labelled section, and no forms:

```tsx
const inertia = usePage<SimpleStorePageProps>();
const { direction, displayCurrencies, displayCurrency, locale, page, storeShell, ui } = inertia.props;

return <StoreLayout
    currentUrl={inertia.url}
    direction={direction}
    displayCurrency={displayCurrency}
    displayCurrencies={displayCurrencies}
    locale={locale}
    ui={ui}
>
    <Head title={page.title} />
    <section className="store-simple-page" aria-labelledby="simple-page-title">
        <p>{ui.simple_pages.eyebrow}</p>
        <h1 id="simple-page-title">{page.title}</h1>
        <p>{page.body}</p>
        <a href={storeShell.homeUrl}>{ui.simple_pages.back_home}</a>
    </section>
</StoreLayout>;
```

- [ ] **Step 9: Run focused GREEN and commit**

Run:

```powershell
php artisan test tests/Feature/Store/StoreShellRoutesTest.php tests/Feature/Store/StoreTranslationParityTest.php
npm test -- resources/js/__tests__/store/store-simple-page.test.tsx
npm run types:check
```

Expected: all pass.

```powershell
git add app/Http/Controllers/Store/SimpleStorePageController.php app/Http/Middleware/HandleInertiaRequests.php config/store.php lang/ar/ui.php lang/en/ui.php routes/web.php resources/js/pages/store/simple-page.tsx resources/js/types/store-shell.ts tests/Feature/Store/StoreShellRoutesTest.php tests/Feature/Store/StoreTranslationParityTest.php resources/js/__tests__/store/store-simple-page.test.tsx
git commit -m "feat: add bilingual storefront destinations"
```

---

### Task 2: WordPress-Parity Header and Preferences

**Files:**
- Create: `resources/js/components/store/store-header.tsx`
- Create: `resources/js/components/store/store-preferences.tsx`
- Create: `resources/js/__tests__/store/store-header.test.tsx`
- Modify: `resources/js/layouts/store-layout.tsx`
- Modify: `resources/js/pages/store/home.tsx`
- Modify: `resources/js/pages/store/simple-page.tsx`
- Modify: `resources/js/__tests__/store-layout.test.tsx`
- Modify: `resources/css/app.css`
- Copy: `public/images/store/navigation/logo-sbc-96.webp`
- Copy: `public/images/store/navigation/logo-champions-80.webp`

**Interfaces:**
- Consumes: `StoreShellConfig` and `StoreShellTranslations` from Task 1.
- Produces: `StoreHeader({ currentUrl, locale, direction, displayCurrency, displayCurrencies, shell, translations }: StoreHeaderProps)`.
- Produces: `StorePreferences({ currentUrl, locale, displayCurrency, displayCurrencies, translations }: StorePreferencesProps)`.
- Produces: `activeState(key: 'home' | 'coins' | 'sbc' | 'fut_champions', currentUrl: string): 'page' | 'location' | undefined` as a private pure header helper.
- Changes: `StoreLayoutProps` gains required `storeShell: StoreShellConfig`; both `home.tsx` and `simple-page.tsx` pass the shared prop explicitly.

- [ ] **Step 1: Verify authoritative asset hashes and copy only exact files**

Verify source SHA-256 values before copying:

```text
logo-sbc-96.webp: 21c57618b40ffdf6595c01a93919f5d432f2d64f43fbdbe05301acfcb2d009b2
logo-champions-80.webp: a090463aaddf7cb4ae6ab5dc476212fd3c57befd766ea0f7fcb1acfb4ec80791
```

Copy from `arabut-child/assets/images/` into `public/images/store/navigation/`, then verify destination hashes match.

- [ ] **Step 2: Read current official interaction testing documentation**

```text
https://react.dev/reference/react/useEffect
https://testing-library.com/docs/user-event/intro
https://vitest.dev/guide/mocking
```

Use the installed Testing Library APIs; do not add `@testing-library/user-event` if it is not already locked.

- [ ] **Step 3: Write failing header and popover tests**

Cover the two landmarks/rows, exact nav order, icons, active states, guest account link, cart count zero, currency URL preservation, language equivalent-path preservation, Escape/outside-click close, and focus restoration:

```tsx
expect(banner.querySelector('.store-header__top')).toBeVisible();
expect(within(banner).getByRole('navigation', { name: 'Primary navigation' })).toBeVisible();
expect(within(primaryNav).getAllByRole('link').map((link) => link.textContent)).toEqual([
    'Home', 'Coins', 'SBCMost requested', 'FUT Champions', 'WhatsApp',
]);
expect(screen.getByRole('link', { name: /SBC/ }).querySelector('img')).toHaveAttribute(
    'src', '/images/store/navigation/logo-sbc-96.webp',
);
expect(document.querySelector('a[href="#"]')).not.toBeInTheDocument();

fireEvent.click(screen.getByRole('button', { name: 'Display preferences' }));
fireEvent.keyDown(document, { key: 'Escape' });
expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
expect(screen.getByRole('button', { name: 'Display preferences' })).toHaveFocus();
```

- [ ] **Step 4: Run header RED**

```powershell
npm test -- resources/js/__tests__/store/store-header.test.tsx resources/js/__tests__/store-layout.test.tsx
```

Expected: FAIL because the full header, active nav, and managed preferences popover do not exist.

- [ ] **Step 5: Implement URL helpers and preferences lifecycle**

Keep helpers pure and exported for direct tests:

```ts
export function localizedStoreHref(currentUrl: string, target: 'ar' | 'en'): string;
export function currencyHref(currentUrl: string, currency: string): string;
```

`localizedStoreHref('/en/privacy?currency=USD#details', 'ar')` returns `/privacy?currency=USD#details`; the inverse returns `/en/privacy?currency=USD#details`.

Implement a button/popover rather than nested `<nav>` elements. On open, set `aria-expanded=true`; on Escape or outside pointer-down, close; Escape restores focus to the trigger. Currency links keep the path, unrelated query values, and hash.

- [ ] **Step 6: Implement the two-row header**

Build the navigation from this fixed order and render the full two-row hierarchy. `NavigationIcon` is a private component in the same file that returns the inline Home/WhatsApp SVG or an `<img>` for Coins/SBC/FUT:

```tsx
const navigation = [
    { key: 'home', href: shell.homeUrl, label: translations.header.home, badge: null },
    { key: 'coins', href: shell.coinsUrl, label: translations.header.coins, badge: null },
    { key: 'sbc', href: shell.sbcUrl, label: translations.header.sbc, badge: translations.header.most_requested },
    { key: 'fut_champions', href: shell.futChampionsUrl, label: translations.header.fut_champions, badge: null },
] as const;

<header className="store-header">
    <div className="store-header__top">
        <a className="store-wordmark" href={shell.homeUrl} aria-label={translations.brand}>
            <img src="/images/arabut-logo-header.webp" width="40" height="40" alt="" />
            <span>{translations.brand}</span>
        </a>
        <div className="store-header__actions">
            <StorePreferences
                currentUrl={currentUrl}
                locale={locale}
                displayCurrency={displayCurrency}
                displayCurrencies={displayCurrencies}
                translations={translations}
            />
            <a href={shell.cartUrl} aria-label={translations.header.cart}>
                <CartIcon /><span aria-hidden="true">0</span>
            </a>
            <a href={shell.accountUrl} aria-label={translations.header.account}><AccountIcon /></a>
        </div>
    </div>
    <nav className="store-primary-nav" aria-label={translations.header.primary_navigation}>
        <ul>
            {navigation.map((item) => <li key={item.key}>
                <a href={item.href} aria-current={activeState(item.key, currentUrl)}>
                    <NavigationIcon item={item.key} />
                    <span>{item.label}</span>
                    {item.badge === null ? null : <small>{item.badge}</small>}
                </a>
            </li>)}
            <li><a href={shell.whatsappUrl} target="_blank" rel="noopener noreferrer">
                <WhatsAppIcon /><span>{translations.header.whatsapp}</span>
            </a></li>
        </ul>
    </nav>
</header>
```

Use the existing crest and Coins image, the copied SBC/FUT images, inline accessible SVG for account/cart/preferences/WhatsApp, `target="_blank" rel="noopener noreferrer"` for WhatsApp, `aria-current="page"` for matching simple destinations, and `aria-current="location"` for Coins only when the current hash is `#coins`.

- [ ] **Step 7: Reproduce WordPress header CSS, then refine safely**

Implement the approved geometry and responsive behavior:

```css
.store-header { position: sticky; inset-block-start: 0; z-index: 40; }
.store-header__top { min-height: 4rem; }
.store-primary-nav { min-height: 3rem; }
.store-primary-nav ul { display: flex; min-width: max-content; }

@media (max-width: 40rem) {
    .store-primary-nav { overflow-x: auto; scrollbar-width: none; }
    .store-primary-nav a { min-height: 3.125rem; white-space: nowrap; }
}
```

Match WordPress warm-black surfaces, line borders, gold active pill/badge, blur, and 44px controls. Preserve existing skip-link behavior and add `scroll-margin-top` for `#coins` so the sticky header does not cover the section heading.

- [ ] **Step 8: Run focused GREEN and commit**

```powershell
npm test -- resources/js/__tests__/store/store-header.test.tsx resources/js/__tests__/store-layout.test.tsx
npm run lint:check
npm run format:check
npm run types:check
```

Expected: all pass.

```powershell
git add public/images/store/navigation resources/js/components/store/store-header.tsx resources/js/components/store/store-preferences.tsx resources/js/layouts/store-layout.tsx resources/js/pages/store/home.tsx resources/js/pages/store/simple-page.tsx resources/js/__tests__/store/store-header.test.tsx resources/js/__tests__/store-layout.test.tsx resources/css/app.css
git commit -m "feat: reproduce WordPress storefront header"
```

---

### Task 3: WordPress-Parity Footer

**Files:**
- Create: `resources/js/components/store/store-footer.tsx`
- Create: `resources/js/__tests__/store/store-footer.test.tsx`
- Modify: `resources/js/layouts/store-layout.tsx`
- Modify: `resources/css/app.css`
- Copy: `public/images/store/payments/mada.png`
- Copy: `public/images/store/payments/visa.png`
- Copy: `public/images/store/payments/mastercard.png`
- Copy: `public/images/store/payments/apple-pay.png`

**Interfaces:**
- Consumes: `StoreShellConfig` and `StoreShellTranslations` from Task 1.
- Produces: `StoreFooter({ locale, shell, translations }: StoreFooterProps)` and the single page `contentinfo` landmark.

- [ ] **Step 1: Verify and copy exact WordPress payment assets**

```text
pay-mada.png: e1885eaaf226de64a8cc346d2531b096be20b3b6158291dfbba2525b3e36a1ce
pay-visa.png: bb3bde9760062092d460ee1fb1bae038dcca3c419dc79ec475fb34464e49c272
pay-mastercard.png: 2cf822a50d4c6e277de630bf9827d831dde087601365d15495007d458692e86f
pay-applepay.png: 6e8a9fddb817b21293a3225b0749d2ebe18004b6d96b7aa4f40a1a68157d2314
```

Copy and rename only at the destination names listed above; verify destination hashes.

- [ ] **Step 2: Write failing footer tests**

Assert the three columns, legal links, contact destinations, exact social omissions, four payment images, copyright year, and disclaimer:

```tsx
const footer = screen.getByRole('contentinfo');

expect(within(footer).getByRole('link', { name: 'X' })).toHaveAttribute(
    'href', 'https://x.com/fut_fi',
);
expect(within(footer).getByRole('link', { name: 'Instagram' })).toHaveAttribute(
    'href', 'https://www.instagram.com/arabutcoins/',
);
expect(within(footer).queryByRole('link', { name: /TikTok|Snapchat/i })).not.toBeInTheDocument();
expect(within(footer).getByRole('link', { name: 'info@arab-ut.com' })).toHaveAttribute(
    'href', 'mailto:info@arab-ut.com',
);
expect(within(footer).getAllByRole('img')).toEqual(expect.arrayContaining([
    expect.objectContaining({ alt: 'Mada' }),
    expect.objectContaining({ alt: 'Visa' }),
    expect.objectContaining({ alt: 'Mastercard' }),
    expect.objectContaining({ alt: 'Apple Pay' }),
]));
expect(document.querySelector('a[href="#"]')).not.toBeInTheDocument();
```

- [ ] **Step 3: Run footer RED**

```powershell
npm test -- resources/js/__tests__/store/store-footer.test.tsx resources/js/__tests__/store-layout.test.tsx
```

Expected: FAIL because no footer is rendered.

- [ ] **Step 4: Implement the footer component**

Render the WordPress hierarchy exactly once. Define `legalLinks` from the five typed shell URLs, `year` once, and private inline `XIcon`/`InstagramIcon` SVG components in the same file. Payment data comes from the server-owned shell configuration:

```tsx
const legalLinks = [
    [translations.footer.privacy, shell.privacyUrl],
    [translations.footer.returns, shell.returnsUrl],
    [translations.footer.warranty, shell.warrantyUrl],
    [translations.footer.ea_backup_codes, shell.eaBackupCodesUrl],
    [translations.footer.terms, shell.termsUrl],
] as const;
const year = new Date().getFullYear();

<footer className="store-footer">
    <div className="store-footer__grid">
        <section aria-labelledby="store-footer-brand">
            <a href={shell.homeUrl}><img src="/images/arabut-logo-header.webp" width="100" height="100" alt="" /></a>
            <h2 id="store-footer-brand">{translations.brand}</h2>
            <p>{translations.footer.description}</p>
            <div className="store-footer__socials">
                <a href={shell.socials.x} target="_blank" rel="noopener noreferrer" aria-label="X"><XIcon /></a>
                <a href={shell.socials.instagram} target="_blank" rel="noopener noreferrer" aria-label="Instagram"><InstagramIcon /></a>
            </div>
        </section>
        <nav aria-label={translations.footer.important_links}>
            <h2>{translations.footer.important_links}</h2>
            <ul>{legalLinks.map(([label, href]) => <li key={href}><a href={href}>{label}</a></li>)}</ul>
        </nav>
        <section aria-labelledby="store-footer-service">
            <h2 id="store-footer-service">{translations.footer.customer_service}</h2>
            <a href={shell.whatsappUrl} target="_blank" rel="noopener noreferrer">{translations.footer.whatsapp}</a>
            <a href={`mailto:${shell.email}`}>{shell.email}</a>
            <h3>{translations.footer.payment_methods}</h3>
            <div>{shell.payments.map((payment) => <img key={payment.name} src={payment.imageUrl} alt={payment.name} width={payment.width} height={payment.height} loading="lazy" />)}</div>
        </section>
    </div>
    <div className="store-footer__bottom">
        <p>{translations.footer.copyright.replace(':year', String(year))}</p>
        <nav aria-label={translations.footer.legal_navigation}>
            {legalLinks.slice(0, 2).map(([label, href]) => <a key={href} href={href}>{label}</a>)}
        </nav>
    </div>
    <p className="store-footer__disclaimer">{translations.footer.ea_disclaimer}</p>
</footer>
```

Use accessible labels for icon-only social links, safe external-link attributes, lazy-loaded payment images with explicit dimensions, and the current year from one `new Date().getFullYear()` value.

- [ ] **Step 5: Reproduce and refine WordPress footer CSS**

```css
.store-footer__grid {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(12rem, .8fr) minmax(0, 1fr);
    gap: clamp(2rem, 5vw, 4rem);
}

@media (max-width: 53.75rem) {
    .store-footer__grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}

@media (max-width: 35rem) {
    .store-footer__grid { grid-template-columns: 1fr; }
}
```

Use warm-black layering, gold headings, cream text, 44px social/contact controls, restrained hover lift, and wrapping legal/payment groups. Footer content must not create page overflow at 320px.

- [ ] **Step 6: Run focused GREEN and commit**

```powershell
npm test -- resources/js/__tests__/store/store-footer.test.tsx resources/js/__tests__/store-layout.test.tsx
npm run lint:check
npm run format:check
npm run types:check
```

Expected: all pass.

```powershell
git add public/images/store/payments resources/js/components/store/store-footer.tsx resources/js/layouts/store-layout.tsx resources/js/__tests__/store/store-footer.test.tsx resources/js/__tests__/store-layout.test.tsx resources/css/app.css
git commit -m "feat: reproduce WordPress storefront footer"
```

---

### Task 4: Responsive Polish, Accessibility, and Release Gates

**Files:**
- Modify: `resources/css/app.css`
- Modify: `resources/js/__tests__/store/store-header.test.tsx`
- Modify: `resources/js/__tests__/store/store-footer.test.tsx`
- Modify: `resources/js/__tests__/store/store-simple-page.test.tsx`
- Modify: `docs/superpowers/plans/2026-08-10-wordpress-header-footer-parity.md`

**Interfaces:**
- Consumes: completed shell, header, preferences, footer, routes, and assets from Tasks 1–3.
- Produces: browser-verified bilingual storefront shell with no P0–P2 accessibility, responsive, console, or dead-destination defect.

- [ ] **Step 1: Strengthen semantic regression coverage before visual refinements**

Add assertions for landmark order, active state on simple pages, exact nav order, all external `rel` values, and no placeholder links across the complete rendered shell:

```tsx
expect([
    screen.getByRole('banner'),
    screen.getByRole('main'),
    screen.getByRole('contentinfo'),
]).toEqual([
    document.querySelector('header'),
    document.querySelector('main'),
    document.querySelector('footer'),
]);

for (const link of document.querySelectorAll('a')) {
    expect(link.getAttribute('href')).not.toBe('#');
}
```

- [ ] **Step 2: Run regression RED or characterization GREEN**

```powershell
npm test -- resources/js/__tests__/store/store-header.test.tsx resources/js/__tests__/store/store-footer.test.tsx resources/js/__tests__/store/store-simple-page.test.tsx resources/js/__tests__/store-layout.test.tsx
```

Expected: any newly exposed semantic gap fails before its minimal fix; otherwise record the tests as characterization GREEN and do not invent a code change.

- [ ] **Step 3: Run required design and code guards**

Apply `arrange`, `adapt`, `typeset`, and `polish` against the WordPress reference. Then run `clean-code-guard` on production changes and `test-guard` on new tests. Fix only concrete findings; preserve WordPress parity.

- [ ] **Step 4: Build production assets and run browser verification**

```powershell
npm run build
```

Verify `/`, `/en`, `/cart`, `/en/cart`, `/sbc`, `/en/sbc`, `/fut-champions`, `/en/fut-champions`, and one legal page at 320px, 390px, 768px, 807px, and 1440px. At each applicable size/locale verify:

```text
- no document-level horizontal overflow;
- mobile nav is intentionally horizontally scrollable and keyboard reachable;
- sticky header does not obscure #coins;
- header/footer use Thmanyah fonts and exact WordPress assets;
- preferences open/close/restore focus and preserve path/query/hash;
- all targets are at least 44px;
- RTL/LTR order is intentional;
- 200% zoom remains usable;
- reduced motion removes nonessential transform/opacity animation;
- no console error or warning;
- X, Instagram, WhatsApp, email, account, cart, SBC, FUT, and legal destinations are valid;
- TikTok, Snapchat, placeholder href="#", dead buttons, and fake checkout controls are absent.
```

- [ ] **Step 5: Run full verification gates**

```powershell
php work/tools/composer.phar ci:check
git diff --check
git status --short
```

Expected: Composer validation, Pint, PHPStan, Pest, Vitest, ESLint, Prettier, TypeScript, and Vite build all pass; diff check is clean; only planned files are changed.

- [ ] **Step 6: Update plan evidence and commit final polish**

Mark only genuinely completed checkboxes, add exact test/browser evidence under this task, then commit:

```powershell
git add resources/css/app.css resources/js/__tests__/store/store-header.test.tsx resources/js/__tests__/store/store-footer.test.tsx resources/js/__tests__/store/store-simple-page.test.tsx docs/superpowers/plans/2026-08-10-wordpress-header-footer-parity.md
git commit -m "fix: polish bilingual storefront shell"
```

Do not push, merge, or deploy without Mohamed's explicit instruction.
