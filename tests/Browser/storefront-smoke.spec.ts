import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

function observeRuntime(page: Page) {
    const failures: string[] = [];

    page.on('pageerror', (error) =>
        failures.push(`pageerror: ${error.message}`),
    );
    page.on('console', (message) => {
        if (message.type() === 'error') {
            failures.push(`console: ${message.text()}`);
        }
    });
    page.on('response', (response) => {
        const type = response.request().resourceType();

        if (
            response.status() >= 400 &&
            (type === 'script' || type === 'stylesheet')
        ) {
            failures.push(`${response.status()} ${type}: ${response.url()}`);
        }
    });
    page.on('requestfailed', (request) => {
        const error = request.failure()?.errorText ?? 'unknown network error';
        failures.push(
            `requestfailed ${request.resourceType()}: ${request.url()} (${error})`,
        );
    });

    return () => expect(failures).toEqual([]);
}

function mutateLocalBrowserUser(email: string, action: 'promote' | 'delete') {
    const encodedEmail = Buffer.from(email).toString('base64');
    const lookup = `base64_decode('${encodedEmail}')`;
    const guard = `if (!app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite') { throw new \\RuntimeException('Browser Admin fixtures require a local SQLite environment.'); } `;
    const mutation =
        action === 'promote'
            ? `${guard}$user = \\App\\Models\\User::where('email', ${lookup})->firstOrFail(); $user->forceFill(['role' => \\App\\Enums\\UserRole::Admin, 'two_factor_secret' => \\Laravel\\Fortify\\Fortify::currentEncrypter()->encrypt(\\Illuminate\\Support\\Str::random(32)), 'two_factor_confirmed_at' => now()])->save();`
            : `${guard}$user = \\App\\Models\\User::where('email', ${lookup})->firstOrFail(); $user->orders()->each(function ($order) { $order->payments()->delete(); $order->items()->delete(); $order->delete(); }); $user->delete();`;

    execFileSync('php', ['artisan', 'tinker', '--execute', mutation], {
        cwd: process.cwd(),
        stdio: 'pipe',
    });
}

function seedLocalBrowserOrder(email: string) {
    const orderNumber = `BROWSER-${randomUUID().slice(0, 8).toUpperCase()}`;
    const encodedEmail = Buffer.from(email).toString('base64');
    const encodedOrderNumber = Buffer.from(orderNumber).toString('base64');
    const lookup = `base64_decode('${encodedEmail}')`;
    const number = `base64_decode('${encodedOrderNumber}')`;
    const guard = `if (!app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite') { throw new \\RuntimeException('Browser Admin fixtures require a local SQLite environment.'); } `;
    const mutation = `${guard}$user = \\App\\Models\\User::where('email', ${lookup})->firstOrFail(); $order = $user->orders()->create(['order_number' => ${number}, 'status' => \\App\\Enums\\OrderStatus::Received, 'locale' => 'en', 'currency' => 'SAR', 'subtotal_halalah' => 15000, 'discount_halalah' => 0, 'wallet_halalah' => 0, 'payment_halalah' => 15000, 'total_halalah' => 15000, 'placed_at' => now()]); $order->items()->create(['sku' => 'BROWSER-COINS', 'name_ar' => 'كوينز', 'name_en' => 'Coins', 'service_type' => \\App\\Enums\\ServiceType::Coins, 'platform' => \\App\\Enums\\Platform::PlayStation, 'status' => \\App\\Enums\\OrderItemStatus::Received, 'quantity' => 1, 'unit_price_halalah' => 15000, 'subtotal_halalah' => 15000, 'discount_halalah' => 0, 'total_halalah' => 15000]);`;

    execFileSync('php', ['artisan', 'tinker', '--execute', mutation], {
        cwd: process.cwd(),
        stdio: 'pipe',
    });

    return orderNumber;
}

async function expectNoHorizontalOverflow(page: Page) {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );

    expect(overflow).toBeLessThanOrEqual(1);
}

async function effectiveOpacity(locator: Locator) {
    return locator.evaluate((element) => {
        let opacity = 1;
        let current: Element | null = element;

        while (current !== null) {
            opacity *= Number.parseFloat(
                window.getComputedStyle(current).opacity,
            );
            current = current.parentElement;
        }

        return opacity;
    });
}

async function expectMinimumTouchTarget(locator: Locator) {
    const box = await locator.boundingBox();

    expect(box).not.toBeNull();

    if (box === null) {
        throw new Error('Expected rendered touch target');
    }

    expect(box.width).toBeGreaterThanOrEqual(44);
    expect(box.height).toBeGreaterThanOrEqual(44);

    return box;
}

async function expectHitTestable(locator: Locator) {
    expect(
        await locator.evaluate((element) => {
            const box = element.getBoundingClientRect();
            const hit = document.elementFromPoint(
                box.x + box.width / 2,
                box.y + box.height / 2,
            );

            return hit === element || (hit !== null && element.contains(hit));
        }),
    ).toBe(true);
}

// The widget opens on its Home view; chat-specific checks first enter the
// conversation view through the Start call to action.
async function enterChatView(dialog: Locator) {
    const start = dialog.getByRole('button', {
        name: /ابدأ محادثة|Start a conversation/,
    });
    await expect(start).toBeVisible();
    await start.click();
    await expect(start).toHaveCount(0);
    await expect(dialog.locator('textarea')).toBeVisible();
}

async function readSafeAreaInsetBottom(page: Page) {
    return page.evaluate(() => {
        const probe = document.createElement('div');

        probe.style.position = 'fixed';
        probe.style.paddingBottom = 'env(safe-area-inset-bottom)';
        document.body.append(probe);

        const value = Number.parseFloat(
            window.getComputedStyle(probe).paddingBottom,
        );

        probe.remove();

        return value;
    });
}

async function expectMobileAccountLauncherAboveNavigation(
    page: Page,
    accessibleName: string,
    expectedSafeAreaInsetBottom: number,
) {
    const accountNavigation = page.locator('.account-mobile-bottom-nav');
    const chatRoot = page.locator('.chat-widget-root--account');
    const launcher = chatRoot.locator(':scope > button');

    await expect(launcher).toBeVisible();
    await expect(launcher).toBeEnabled();
    await expect(launcher).toHaveAccessibleName(accessibleName);
    await expect(accountNavigation).toBeVisible();

    const launcherBox = await launcher.boundingBox();
    const navBox = await accountNavigation.boundingBox();

    expect(launcherBox).not.toBeNull();
    expect(navBox).not.toBeNull();

    if (launcherBox === null || navBox === null) {
        throw new Error('Expected rendered account launcher and navigation');
    }

    await expectMinimumTouchTarget(launcher);
    expect(await effectiveOpacity(launcher)).toBeGreaterThan(0);
    expect(launcherBox.y + launcherBox.height).toBeLessThan(navBox.y);

    const safeAreaInsetBottom = await readSafeAreaInsetBottom(page);
    const geometry = await page.evaluate(() => {
        const root = document.querySelector<HTMLElement>(
            '.chat-widget-root--account',
        );
        const navigation = document.querySelector<HTMLElement>(
            '.account-mobile-bottom-nav',
        );

        if (root === null || navigation === null) {
            throw new Error('Expected account chat and navigation surfaces');
        }

        const rootStyles = window.getComputedStyle(root);
        const navigationStyles = window.getComputedStyle(navigation);

        return {
            rootBottom: Number.parseFloat(rootStyles.bottom),
            rootZIndex: Number.parseInt(rootStyles.zIndex, 10),
            navigationBottom: Number.parseFloat(navigationStyles.bottom),
            navigationZIndex: Number.parseInt(navigationStyles.zIndex, 10),
        };
    });

    expect(safeAreaInsetBottom).toBeCloseTo(expectedSafeAreaInsetBottom, 1);
    expect(geometry.rootBottom).toBeCloseTo(
        88 + expectedSafeAreaInsetBottom,
        1,
    );
    expect(geometry.navigationBottom).toBeCloseTo(
        10 + expectedSafeAreaInsetBottom,
        1,
    );
    expect(geometry.rootZIndex).toBe(70);
    expect(geometry.navigationZIndex).toBe(60);
    expect(geometry.rootZIndex).toBeGreaterThan(geometry.navigationZIndex);
    await expectHitTestable(launcher);

    await expectNoHorizontalOverflow(page);

    return { accountNavigation, chatRoot, launcher, navBox };
}

async function expectAccountLocale(
    page: Page,
    path: string,
    language: 'ar' | 'en',
    direction: 'rtl' | 'ltr',
) {
    const response = await page.goto(path);

    expect(response?.ok()).toBe(true);
    expect(new URL(page.url()).pathname).toBe(path);
    await expect(page.locator('html')).toHaveAttribute('lang', language);
    await expect(page.locator('html')).toHaveAttribute('dir', direction);
}

async function verifyMobileAccountChat(
    page: Page,
    labels: {
        launcher: string;
        dialog: string;
        close: string;
        restart: string;
        back: string;
    },
    safeAreaInsetBottom: number,
) {
    const { accountNavigation, launcher } =
        await expectMobileAccountLauncherAboveNavigation(
            page,
            labels.launcher,
            safeAreaInsetBottom,
        );

    await launcher.click();

    const dialog = page.getByRole('dialog', { name: labels.dialog });
    const close = dialog.getByRole('button', { name: labels.close });
    const restart = dialog.getByRole('button', { name: labels.restart });
    const composer = dialog.getByRole('textbox');

    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect.poll(() => effectiveOpacity(dialog)).toBeGreaterThan(0);
    await expect(close).toBeFocused();
    await expect(close).toBeEnabled();
    await enterChatView(dialog);
    await expect(restart).toBeEnabled();
    await expect(composer).toBeVisible();
    await close.click({ trial: true });
    await restart.click({ trial: true });
    await expectMinimumTouchTarget(close);
    await expectMinimumTouchTarget(restart);
    await expectMinimumTouchTarget(composer);
    await expectHitTestable(dialog);

    const dialogBox = await dialog.boundingBox();
    const navBox = await accountNavigation.boundingBox();

    expect(dialogBox).not.toBeNull();
    expect(navBox).not.toBeNull();

    if (dialogBox === null || navBox === null) {
        throw new Error('Expected rendered mobile chat and navigation');
    }

    expect(dialogBox.x).toBeCloseTo(0, 0);
    expect(dialogBox.y).toBeCloseTo(0, 0);
    expect(dialogBox.width).toBeCloseTo(
        await page.evaluate(() => innerWidth),
        0,
    );
    expect(dialogBox.height).toBeCloseTo(
        await page.evaluate(() => innerHeight),
        0,
    );

    const layersAndMotion = await page.evaluate(() => {
        const dialogElement = document.querySelector<HTMLElement>(
            '.chat-widget-dialog',
        );
        const navigationElement = document.querySelector<HTMLElement>(
            '.account-mobile-bottom-nav',
        );

        if (dialogElement === null || navigationElement === null) {
            throw new Error('Expected chat dialog and account navigation');
        }

        const dialogStyles = window.getComputedStyle(dialogElement);

        return {
            dialog: Number.parseInt(dialogStyles.zIndex, 10),
            navigation: Number.parseInt(
                window.getComputedStyle(navigationElement).zIndex,
                10,
            ),
            transitionProperty: dialogStyles.transitionProperty,
        };
    });

    expect(layersAndMotion.dialog).toBeGreaterThan(layersAndMotion.navigation);
    expect(layersAndMotion.transitionProperty).toBe('none');
    expect(
        await page.evaluate(
            ({ x, y }) => {
                const dialogElement = document.querySelector<HTMLElement>(
                    '.chat-widget-dialog',
                );
                const hit = document.elementFromPoint(x, y);

                return (
                    dialogElement !== null &&
                    hit !== null &&
                    (hit === dialogElement || dialogElement.contains(hit))
                );
            },
            {
                x: navBox.x + navBox.width / 2,
                y: navBox.y + navBox.height / 2,
            },
        ),
    ).toBe(true);

    const back = dialog.getByRole('button', { name: labels.back });
    await composer.focus();
    await page.keyboard.press('Tab');
    await expect(back).toBeFocused();
    await back.focus();
    await page.keyboard.press('Shift+Tab');
    await expect(composer).toBeFocused();
    await expectNoHorizontalOverflow(page);

    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeAttached();
    await expect(launcher).toBeFocused();
}

async function verifyDesktopAccountChat(
    page: Page,
    labels: {
        launcher: string;
        dialog: string;
        close: string;
        restart: string;
    },
) {
    const accountNavigation = page.locator('.account-mobile-bottom-nav');
    const chatRoot = page.locator('.chat-widget-root--account');
    const launcher = chatRoot.locator(':scope > button');

    await expect(accountNavigation).toBeHidden();
    await expect(launcher).toBeVisible();
    await expect(launcher).toBeEnabled();
    await expect(launcher).toHaveAccessibleName(labels.launcher);
    await expectMinimumTouchTarget(launcher);
    await expectHitTestable(launcher);
    expect(await effectiveOpacity(launcher)).toBeGreaterThan(0);

    const launcherGeometry = await chatRoot.evaluate((element) => {
        const styles = window.getComputedStyle(element);

        return {
            bottom: Number.parseFloat(styles.bottom),
            right: Number.parseFloat(styles.right),
            zIndex: Number.parseInt(styles.zIndex, 10),
        };
    });

    expect(launcherGeometry.bottom).toBeCloseTo(24, 1);
    expect(launcherGeometry.right).toBeCloseTo(24, 1);
    expect(launcherGeometry.zIndex).toBe(50);

    await launcher.click();

    const dialog = page.getByRole('dialog', { name: labels.dialog });
    const close = dialog.getByRole('button', { name: labels.close });
    const restart = dialog.getByRole('button', { name: labels.restart });
    const composer = dialog.getByRole('textbox');

    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'false');
    await expect(launcher).toBeFocused();
    await expect.poll(() => effectiveOpacity(dialog)).toBeGreaterThan(0);
    await enterChatView(dialog);
    await close.click({ trial: true });
    await restart.click({ trial: true });
    await expectMinimumTouchTarget(close);
    await expectMinimumTouchTarget(restart);
    await expectMinimumTouchTarget(composer);
    await expectHitTestable(dialog);

    const dialogGeometry = await dialog.evaluate((element) => {
        const styles = window.getComputedStyle(element);
        const box = element.getBoundingClientRect();

        return {
            bottom: window.innerHeight - box.bottom,
            right: window.innerWidth - box.right,
            height: box.height,
            width: box.width,
            viewportHeight: window.innerHeight,
            viewportWidth: window.innerWidth,
            position: styles.position,
            transitionProperty: styles.transitionProperty,
            zIndex: Number.parseInt(styles.zIndex, 10),
        };
    });

    expect(dialogGeometry.position).toBe('fixed');
    expect(dialogGeometry.bottom).toBeCloseTo(96, 1);
    expect(dialogGeometry.right).toBeCloseTo(24, 1);
    expect(dialogGeometry.width).toBeLessThan(dialogGeometry.viewportWidth);
    expect(dialogGeometry.height).toBeLessThan(dialogGeometry.viewportHeight);
    expect(dialogGeometry.zIndex).toBe(70);
    expect(dialogGeometry.transitionProperty).toBe('none');

    const outsideCandidates = page.locator(
        '.account-shell a[href], .account-shell button:not([disabled])',
    );
    const outsideIndex = await outsideCandidates.evaluateAll((elements) => {
        const dialogElement = document.querySelector<HTMLElement>(
            '.chat-widget-dialog',
        );

        if (dialogElement === null) {
            return -1;
        }

        const dialogBox = dialogElement.getBoundingClientRect();

        return elements.findIndex((element) => {
            const candidate = element as HTMLElement;
            const box = candidate.getBoundingClientRect();
            const x = box.x + box.width / 2;
            const y = box.y + box.height / 2;
            const outsideDialog =
                x < dialogBox.left ||
                x > dialogBox.right ||
                y < dialogBox.top ||
                y > dialogBox.bottom;
            const hit = document.elementFromPoint(x, y);

            return (
                box.width > 0 &&
                box.height > 0 &&
                outsideDialog &&
                (hit === candidate || (hit !== null && candidate.contains(hit)))
            );
        });
    });

    expect(outsideIndex).toBeGreaterThanOrEqual(0);
    const outsideAccountControl = outsideCandidates.nth(outsideIndex);

    await expect(outsideAccountControl).toBeVisible();
    await outsideAccountControl.click({ trial: true });
    await outsideAccountControl.focus();
    await expect(outsideAccountControl).toBeFocused();
    await expect(dialog).toBeVisible();
    await expectNoHorizontalOverflow(page);

    await close.click();
    await expect(dialog).not.toBeAttached();
    await expect(launcher).toBeFocused();
}

// Regression guard for the 2026-08-19 blank-storefront incident.
for (const { path, language, direction } of [
    { path: '/', language: 'ar', direction: 'rtl' },
    { path: '/en', language: 'en', direction: 'ltr' },
    { path: '/cart', language: 'ar', direction: 'rtl' },
] as const) {
    test(`storefront ${path} mounts`, async ({ page }) => {
        const expectCleanRuntime = observeRuntime(page);

        const response = await page.goto(path);

        expect(response?.ok()).toBe(true);
        expect(new URL(page.url()).pathname).toBe(path);
        await expect(page.locator('html')).toHaveAttribute('lang', language);
        await expect(page.locator('html')).toHaveAttribute('dir', direction);
        await expect(page.locator('#app')).not.toBeEmpty();
        await expect(page.getByRole('banner')).toBeVisible();
        await expect(page.getByRole('main')).toBeVisible();
        expect(
            await page
                .locator('meta[name="theme-color"]')
                .evaluateAll((elements) =>
                    elements.map((element) => element.getAttribute('content')),
                ),
        ).toEqual(['#0d0b08']);
        await expect(
            page.locator(
                path === '/cart'
                    ? 'h1#store-cart-title'
                    : 'h1#store-hero-title',
            ),
        ).toBeVisible();
        expectCleanRuntime();
    });
}

for (const { path, language, direction, heading } of [
    {
        path: '/login',
        language: 'ar',
        direction: 'rtl',
        heading: 'تسجيل الدخول إلى حسابك',
    },
    {
        path: '/en/login',
        language: 'en',
        direction: 'ltr',
        heading: 'Log in to your account',
    },
] as const) {
    test(`authentication ${path} mounts`, async ({ page }) => {
        const expectCleanRuntime = observeRuntime(page);

        const response = await page.goto(path);

        expect(response?.ok()).toBe(true);
        expect(new URL(page.url()).pathname).toBe(path);
        await expect(page.locator('html')).toHaveAttribute('lang', language);
        await expect(page.locator('html')).toHaveAttribute('dir', direction);
        await expect(page.locator('#app')).not.toBeEmpty();
        await expect(page.getByRole('main')).toBeVisible();
        const pageTitle = page.locator('h1#auth-page-title');
        await expect(pageTitle).toBeVisible();
        await expect(pageTitle).toHaveText(heading);
        await expect(page.locator('form.auth-form')).toBeVisible();
        expectCleanRuntime();
    });
}

test('desktop login uses the annotated compact credential rhythm', async ({
    page,
}) => {
    // Regression: 2026-08-22 browser annotations require 40px and 71px rows.
    await page.setViewportSize({ width: 916, height: 912 });
    await page.goto('/login');

    const emailInput = page.getByLabel('البريد الإلكتروني');
    const passwordInput = page.getByLabel('كلمة المرور', { exact: true });
    const emailField = page.locator('.auth-form__field').filter({
        has: emailInput,
    });
    const passwordField = page.locator('.auth-form__field').filter({
        has: passwordInput,
    });

    await expect(emailInput).toBeVisible();
    await expect(passwordInput).toBeVisible();
    await expect(
        emailField.getByText('البريد الإلكتروني', { exact: true }),
    ).toBeVisible();
    await expect(emailField).toHaveCSS('height', '40px');
    const passwordFieldHeight = await passwordField.evaluate(
        (element) => element.getBoundingClientRect().height,
    );
    expect(passwordFieldHeight).toBeCloseTo(71, 0);
});

test('mobile login keeps credential controls touch sized', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/login');

    const emailInput = page.getByLabel('البريد الإلكتروني');
    const passwordInput = page.getByLabel('كلمة المرور', { exact: true });
    const forgotPassword = page.getByRole('link', {
        name: 'نسيت كلمة المرور؟',
    });

    await expect(emailInput).toHaveCSS('height', '44px');
    await expect(passwordInput).toHaveCSS('height', '44px');
    const forgotPasswordHeight = await forgotPassword.evaluate(
        (element) => element.getBoundingClientRect().height,
    );
    expect(forgotPasswordHeight).toBeGreaterThanOrEqual(44);
});

test('mobile home opens and closes chat without overflow', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const expectCleanRuntime = observeRuntime(page);

    await page.goto('/');
    await expect(page.locator('#app')).not.toBeEmpty();

    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    expect(overflow).toBeLessThanOrEqual(1);

    const launcher = page.getByRole('button', { name: 'فتح الشات' });
    await expect(launcher).toBeVisible();
    await launcher.click();

    const dialog = page.getByRole('dialog', {
        name: 'شات مساعد عرب التيميت',
    });
    await expect(dialog).toBeVisible();
    await enterChatView(dialog);
    await dialog.getByRole('button', { name: 'إغلاق الشات' }).click();
    await expect(dialog).not.toBeAttached();
    await expect(launcher).toBeFocused();
    expectCleanRuntime();
});

test('authenticated account keeps chat above mobile navigation', async ({
    context,
    page,
}) => {
    test.setTimeout(120_000);
    await page.setViewportSize({ width: 390, height: 844 });
    const expectCleanRuntime = observeRuntime(page);
    const safeAreaInsetBottom = 24;
    const syntheticId = randomUUID();
    const password = `ArabUT-${syntheticId}-Aa1!`;

    await page.goto('/register');
    await page.locator('#first_name').fill('Browser');
    await page.locator('#last_name').fill('Regression');
    await page.locator('#email').fill(`${syntheticId}@example.test`);
    await page.locator('#password').fill(password);
    await page.locator('#password_confirmation').fill(password);

    await Promise.all([
        page.waitForURL((url) => url.pathname === '/my-account'),
        page.locator('[data-test="register-user-button"]').click(),
    ]);

    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    const cdpSession = await context.newCDPSession(page);

    await cdpSession.send('Emulation.setSafeAreaInsetsOverride', {
        insets: {
            bottom: safeAreaInsetBottom,
            left: 0,
            right: 0,
            top: 0,
        },
    });
    await page.emulateMedia({ reducedMotion: 'reduce' });

    const accountLocales = [
        {
            path: '/my-account',
            language: 'ar',
            direction: 'rtl',
            labels: {
                launcher: 'فتح الشات',
                dialog: 'شات مساعد عرب التيميت',
                close: 'إغلاق الشات',
                restart: 'محادثة جديدة',
                back: 'رجوع',
            },
        },
        {
            path: '/en/my-account',
            language: 'en',
            direction: 'ltr',
            labels: {
                launcher: 'Open chat',
                dialog: 'Arab UT Chat Assistant',
                close: 'Close chat',
                restart: 'New conversation',
                back: 'Back',
            },
        },
    ] as const;

    for (const width of [320, 390]) {
        for (const locale of accountLocales) {
            await page.setViewportSize({ width, height: 844 });
            await expectAccountLocale(
                page,
                locale.path,
                locale.language,
                locale.direction,
            );
            await verifyMobileAccountChat(
                page,
                locale.labels,
                safeAreaInsetBottom,
            );
        }
    }

    await cdpSession.send('Emulation.setSafeAreaInsetsOverride', {
        insets: { bottom: 0, left: 0, right: 0, top: 0 },
    });
    expect(await readSafeAreaInsetBottom(page)).toBeCloseTo(0, 1);

    for (const width of [768, 1440]) {
        for (const locale of accountLocales) {
            await page.setViewportSize({ width, height: 844 });
            await expectAccountLocale(
                page,
                locale.path,
                locale.language,
                locale.direction,
            );
            await verifyDesktopAccountChat(page, locale.labels);
        }
    }

    await cdpSession.detach();
    expectCleanRuntime();
});

test('authenticated Admin overview and orders are operable across required widths', async ({
    context,
    page,
}) => {
    test.setTimeout(180_000);
    const expectCleanRuntime = observeRuntime(page);
    const syntheticId = randomUUID();
    const email = `${syntheticId}@example.test`;
    const password = `ArabUT-${syntheticId}-Aa1!`;
    const safeAreaInsetBottom = 24;

    try {
        await page.setViewportSize({ width: 390, height: 844 });
        await page.goto('/register');
        await page.locator('#first_name').fill('Admin');
        await page.locator('#last_name').fill('Browser Acceptance Owner');
        await page.locator('#email').fill(email);
        await page.locator('#password').fill(password);
        await page.locator('#password_confirmation').fill(password);
        await Promise.all([
            page.waitForURL((url) => url.pathname === '/my-account'),
            page.locator('[data-test="register-user-button"]').click(),
        ]);

        mutateLocalBrowserUser(email, 'promote');
        const orderNumber = seedLocalBrowserOrder(email);

        const cdpSession = await context.newCDPSession(page);
        await cdpSession.send('Emulation.setSafeAreaInsetsOverride', {
            insets: {
                bottom: safeAreaInsetBottom,
                left: 0,
                right: 0,
                top: 0,
            },
        });
        await page.emulateMedia({ reducedMotion: 'reduce' });

        const locales = [
            {
                path: '/admin?range=7',
                language: 'en',
                direction: 'ltr',
                heading: 'Operations dashboard',
                open: 'Open Admin navigation',
                close: 'Close Admin navigation',
                dialog: 'Arab UT',
                overview: 'Overview',
                orders: 'Orders',
                security: 'MFA Security',
                range7: 'Last 7 days',
                range30: 'Last 30 days',
            },
        ] as const;

        for (const width of [320, 390, 768, 1440]) {
            await page.setViewportSize({ width, height: 900 });

            for (const locale of locales) {
                const response = await page.goto(locale.path);

                expect(response?.ok()).toBe(true);
                await expect(page.locator('html')).toHaveClass(
                    /admin-document/,
                );
                expect(
                    await page
                        .locator('meta[name="theme-color"]')
                        .evaluateAll((elements) =>
                            elements.map((element) =>
                                element.getAttribute('content'),
                            ),
                        ),
                ).toEqual(['#080705']);
                await expect(page.locator('html')).toHaveAttribute(
                    'lang',
                    locale.language,
                );
                await expect(page.locator('html')).toHaveAttribute(
                    'dir',
                    locale.direction,
                );
                await expect(
                    page.getByRole('heading', {
                        level: 1,
                        name: locale.heading,
                    }),
                ).toBeVisible();
                await expect(page.locator('.admin-kpi-strip dd')).toHaveCount(
                    4,
                );
                await expect(
                    page.getByRole('heading', {
                        level: 2,
                        name: 'Captured revenue trend',
                    }),
                ).toBeVisible();
                await expect(
                    page.getByRole('heading', {
                        level: 2,
                        name: 'Recent placed orders',
                    }),
                ).toBeVisible();
                await expect(
                    page.getByRole('link', { name: locale.range7 }),
                ).toHaveAttribute('aria-current', 'page');
                await expect(page.locator('.chat-widget-root')).toHaveCount(0);
                await expectNoHorizontalOverflow(page);

                await page.evaluate(() => {
                    document.body.style.zoom = '2';
                });
                await expectNoHorizontalOverflow(page);
                await page.evaluate(() => {
                    document.body.style.zoom = '';
                });

                if (width < 768) {
                    const trigger = page.getByRole('button', {
                        name: locale.open,
                    });
                    await expect(trigger).toBeVisible();
                    await expectMinimumTouchTarget(trigger);
                    await expectHitTestable(trigger);
                    await trigger.focus();
                    await expect(trigger).toBeFocused();
                    expect(
                        await trigger.evaluate(
                            (element) =>
                                window.getComputedStyle(element).outlineStyle,
                        ),
                    ).not.toBe('none');

                    await trigger.click();

                    const dialog = page.getByRole('dialog', {
                        name: locale.dialog,
                    });
                    const close = dialog.getByRole('button', {
                        name: locale.close,
                    });
                    await expect(dialog).toBeVisible();
                    await expect(dialog).toHaveAttribute('aria-modal', 'true');
                    await expect(page.locator('#app')).toHaveAttribute(
                        'inert',
                        '',
                    );
                    await expect(close).toBeFocused();
                    await expectMinimumTouchTarget(close);
                    await expectMinimumTouchTarget(
                        dialog.getByRole('link', { name: locale.overview }),
                    );
                    await expectMinimumTouchTarget(
                        dialog.getByRole('link', { name: locale.orders }),
                    );
                    await expectMinimumTouchTarget(
                        dialog.getByRole('link', { name: locale.security }),
                    );
                    await expectMinimumTouchTarget(
                        dialog.getByRole('button', {
                            name: 'Log out',
                        }),
                    );
                    await expect(
                        dialog.getByRole('link', { name: locale.overview }),
                    ).toHaveAttribute('aria-current', 'page');
                    await expect(dialog.getByRole('link')).toHaveCount(4);

                    const sheetBehavior = await dialog.evaluate((element) => {
                        const styles = window.getComputedStyle(element);

                        return {
                            paddingBottom: Number.parseFloat(
                                styles.paddingBottom,
                            ),
                            transitionDuration: styles.transitionDuration,
                        };
                    });
                    expect(sheetBehavior.paddingBottom).toBeGreaterThanOrEqual(
                        safeAreaInsetBottom,
                    );
                    expect(sheetBehavior.transitionDuration).toMatch(
                        /^(0s|0\.0*1ms|1e-0?5s)$/,
                    );

                    for (let index = 0; index < 6; index += 1) {
                        await page.keyboard.press('Tab');
                        expect(
                            await dialog.evaluate((element) =>
                                element.contains(document.activeElement),
                            ),
                        ).toBe(true);
                    }

                    await page.keyboard.press('Escape');
                    await expect(dialog).not.toBeAttached();
                    await expect(trigger).toBeFocused();
                    await expect(page.locator('#app')).not.toHaveAttribute(
                        'inert',
                        '',
                    );

                    const tabbar = page.getByRole('navigation', {
                        name: 'Arab UT quick navigation',
                    });
                    await expect(tabbar).toBeVisible();
                    await expectMinimumTouchTarget(
                        tabbar.getByRole('link', { name: locale.overview }),
                    );
                    await expectMinimumTouchTarget(
                        tabbar.getByRole('link', { name: locale.orders }),
                    );
                    await expectMinimumTouchTarget(
                        tabbar.getByRole('link', { name: locale.security }),
                    );
                } else {
                    await expect(
                        page.getByRole('button', { name: locale.open }),
                    ).toBeHidden();
                    const sidebar = page.locator('.admin-sidebar');
                    await expect(sidebar).toBeVisible();
                    await expect(sidebar.getByRole('link')).toHaveCount(4);
                    await expect(
                        sidebar.getByRole('link', { name: locale.overview }),
                    ).toHaveAttribute('aria-current', 'page');
                    await expectMinimumTouchTarget(
                        sidebar.getByRole('link', { name: locale.overview }),
                    );
                    await expectMinimumTouchTarget(sidebar.getByRole('button'));
                }

                const range30 = page.getByRole('link', {
                    name: locale.range30,
                });
                await expectMinimumTouchTarget(range30);
                await expectHitTestable(range30);
                await Promise.all([
                    page.waitForURL(
                        (url) =>
                            url.pathname === locale.path.split('?')[0] &&
                            url.searchParams.get('range') === '30',
                    ),
                    range30.click(),
                ]);
                await expect(
                    page.getByRole('link', { name: locale.range30 }),
                ).toHaveAttribute('aria-current', 'page');
                await expectNoHorizontalOverflow(page);

                let ordersLink: Locator;

                if (width < 768) {
                    ordersLink = page
                        .getByRole('navigation', {
                            name: 'Arab UT quick navigation',
                        })
                        .getByRole('link', { name: locale.orders });
                } else {
                    ordersLink = page
                        .locator('.admin-sidebar')
                        .getByRole('link', { name: locale.orders });
                }

                await Promise.all([
                    page.waitForURL((url) => url.pathname === '/admin/orders'),
                    ordersLink.click(),
                ]);

                await expect(
                    page.getByRole('heading', { level: 1, name: 'Orders' }),
                ).toBeVisible();
                await expect(page.locator('.chat-widget-root')).toHaveCount(0);

                const search = page.getByRole('searchbox', {
                    name: 'Search orders',
                });
                await expectMinimumTouchTarget(search);
                await search.focus();
                await expect(search).toBeFocused();
                await search.fill(orderNumber);
                await expectMinimumTouchTarget(
                    page.getByRole('button', { name: 'Clear search' }),
                );
                await expectMinimumTouchTarget(
                    page.getByRole('button', { name: 'Search', exact: true }),
                );

                if (width < 768) {
                    const filtersButton = page.getByRole('button', {
                        name: /Filters/i,
                    });
                    await expectMinimumTouchTarget(filtersButton);
                    await filtersButton.click();

                    await expectMinimumTouchTarget(
                        page.getByRole('combobox', { name: 'Per page' }),
                    );

                    const filterSheet = page.getByRole('dialog', {
                        name: 'Filters',
                    });
                    await expect(filterSheet).toBeVisible();

                    for (const label of [
                        'Filter by status',
                        'Filter by service',
                        'Filter by platform',
                        'Filter by payment status',
                    ]) {
                        await expectMinimumTouchTarget(
                            filterSheet.getByRole('combobox', { name: label }),
                        );
                    }

                    await expectMinimumTouchTarget(
                        filterSheet.getByLabel('Date from'),
                    );
                    await expectMinimumTouchTarget(
                        filterSheet.getByLabel('Date to'),
                    );

                    // The filters sheet must honour reduced motion like every
                    // other animated Admin surface.
                    await expect(filterSheet).toHaveClass(
                        /motion-reduce:animate-none/,
                    );
                    await expectMinimumTouchTarget(
                        filterSheet.getByRole('button', { name: 'Apply' }),
                    );
                    await expectMinimumTouchTarget(
                        filterSheet.getByRole('button', { name: 'Clear all' }),
                    );

                    await page.keyboard.press('Escape');
                    await expect(filterSheet).not.toBeAttached();
                } else {
                    for (const label of [
                        'Filter by status',
                        'Filter by service',
                        'Filter by platform',
                        'Filter by payment status',
                        'Per page',
                    ]) {
                        await expectMinimumTouchTarget(
                            page.getByRole('combobox', { name: label }),
                        );
                    }

                    await expectMinimumTouchTarget(
                        page.getByLabel('Date from'),
                    );
                    await expectMinimumTouchTarget(page.getByLabel('Date to'));
                    const columnsButton = page.getByRole('button', {
                        name: 'Toggle columns',
                    });
                    await expectMinimumTouchTarget(columnsButton);
                    await columnsButton.click();
                    await expectMinimumTouchTarget(
                        page.getByRole('menuitemcheckbox', {
                            name: 'Customer',
                        }),
                    );
                    await page.keyboard.press('Escape');
                }

                const selectOrder = page.getByRole('checkbox', {
                    name: `Select row ${orderNumber}`,
                });
                await expectMinimumTouchTarget(selectOrder.locator('..'));
                await selectOrder.click();
                await expect(selectOrder).toBeChecked();
                await expect(
                    page.getByText(/^1 of \d+ row\(s\) selected$/),
                ).toBeVisible();

                if (width < 768) {
                    await expect(
                        page.getByRole('listitem').filter({
                            hasText: orderNumber,
                        }),
                    ).toBeVisible();
                } else {
                    const ordersTable = page.getByRole('region', {
                        name: 'Orders list',
                    });
                    await expect(
                        ordersTable.getByText(orderNumber),
                    ).toBeVisible();
                    await expect(
                        ordersTable.getByRole('columnheader', {
                            name: /Placed at/i,
                        }),
                    ).toHaveAttribute('aria-sort', 'descending');
                }

                await Promise.all([
                    page.waitForResponse((response) => {
                        const url = new URL(response.url());

                        return (
                            response.request().method() === 'GET' &&
                            url.pathname === '/admin/orders' &&
                            url.searchParams.get('search') === orderNumber
                        );
                    }),
                    page.waitForURL(
                        (url) =>
                            url.pathname === '/admin/orders' &&
                            url.searchParams.get('search') === orderNumber,
                    ),
                    page
                        .getByRole('button', { name: 'Search', exact: true })
                        .click(),
                ]);
                await expect(
                    page.locator('main [aria-busy="true"]'),
                ).toHaveCount(0);
                await expect(selectOrder).not.toBeChecked();
                await expectNoHorizontalOverflow(page);

                await page.evaluate(() => {
                    document.body.style.zoom = '2';
                });
                await expectNoHorizontalOverflow(page);
                await page.evaluate(() => {
                    document.body.style.zoom = '';
                });
            }
        }

        await cdpSession.send('Emulation.setSafeAreaInsetsOverride', {
            insets: { bottom: 0, left: 0, right: 0, top: 0 },
        });
        await cdpSession.detach();
        expectCleanRuntime();
    } finally {
        mutateLocalBrowserUser(email, 'delete');
    }
});
