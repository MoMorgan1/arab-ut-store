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

    expect(launcherBox.width).toBeGreaterThanOrEqual(44);
    expect(launcherBox.height).toBeGreaterThanOrEqual(44);
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
    expect(
        await launcher.evaluate((element) => {
            const box = element.getBoundingClientRect();
            const hit = document.elementFromPoint(
                box.x + box.width / 2,
                box.y + box.height / 2,
            );

            return hit === element || (hit !== null && element.contains(hit));
        }),
    ).toBe(true);

    await expectNoHorizontalOverflow(page);

    return { accountNavigation, chatRoot, launcher, navBox };
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
    await expect(dialog.locator('textarea')).toBeVisible();
    await dialog.getByRole('button', { name: 'إغلاق الشات' }).click();
    await expect(dialog).not.toBeAttached();
    await expect(launcher).toBeFocused();
    expectCleanRuntime();
});

test('authenticated account keeps chat above mobile navigation', async ({
    context,
    page,
}) => {
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

    const { accountNavigation, launcher } =
        await expectMobileAccountLauncherAboveNavigation(
            page,
            'فتح الشات',
            safeAreaInsetBottom,
        );

    await launcher.click();

    const dialog = page.getByRole('dialog', {
        name: 'شات مساعد عرب التيميت',
    });
    const close = dialog.getByRole('button', { name: 'إغلاق الشات' });

    await expect(dialog).toBeVisible();
    await expect(dialog).toHaveAttribute('aria-modal', 'true');
    await expect.poll(() => effectiveOpacity(dialog)).toBeGreaterThan(0);
    await expect(accountNavigation).toBeVisible();
    await expect(close).toBeFocused();
    await expect(close).toBeVisible();
    await expect(close).toBeEnabled();
    await close.click({ trial: true });

    const dialogBox = await dialog.boundingBox();
    const openNavBox = await accountNavigation.boundingBox();
    const closeBox = await close.boundingBox();

    expect(dialogBox).not.toBeNull();
    expect(openNavBox).not.toBeNull();
    expect(closeBox).not.toBeNull();

    if (dialogBox === null || openNavBox === null || closeBox === null) {
        throw new Error(
            'Expected rendered mobile chat dialog, navigation, and close control',
        );
    }

    expect(closeBox.width).toBeGreaterThanOrEqual(44);
    expect(closeBox.height).toBeGreaterThanOrEqual(44);
    expect(await effectiveOpacity(close)).toBeGreaterThan(0);
    expect(dialogBox.x).toBeLessThanOrEqual(openNavBox.x);
    expect(dialogBox.y).toBeLessThanOrEqual(openNavBox.y);
    expect(dialogBox.x + dialogBox.width).toBeGreaterThanOrEqual(
        openNavBox.x + openNavBox.width,
    );
    expect(dialogBox.y + dialogBox.height).toBeGreaterThanOrEqual(
        openNavBox.y + openNavBox.height,
    );

    const layers = await page.evaluate(() => ({
        dialog: Number.parseInt(
            window.getComputedStyle(
                document.querySelector<HTMLElement>('.chat-widget-dialog')!,
            ).zIndex,
            10,
        ),
        navigation: Number.parseInt(
            window.getComputedStyle(
                document.querySelector<HTMLElement>(
                    '.account-mobile-bottom-nav',
                )!,
            ).zIndex,
            10,
        ),
    }));

    expect(layers.dialog).toBeGreaterThan(layers.navigation);
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
                x: openNavBox.x + openNavBox.width / 2,
                y: openNavBox.y + openNavBox.height / 2,
            },
        ),
    ).toBe(true);

    const restart = dialog.getByRole('button', { name: 'محادثة جديدة' });
    const composer = dialog.getByRole('textbox');

    await expect(restart).toBeEnabled();
    await expect(composer).toBeVisible();

    await composer.focus();
    await page.keyboard.press('Tab');
    await expect(restart).toBeFocused();

    await restart.focus();
    await page.keyboard.press('Shift+Tab');
    await expect(composer).toBeFocused();

    await expectNoHorizontalOverflow(page);
    await page.keyboard.press('Escape');
    await expect(dialog).not.toBeAttached();
    await expect(launcher).toBeFocused();

    await page.goto('/en/my-account');

    expect(new URL(page.url()).pathname).toBe('/en/my-account');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');

    const englishAccount = await expectMobileAccountLauncherAboveNavigation(
        page,
        'Open chat',
        safeAreaInsetBottom,
    );

    await cdpSession.send('Emulation.setSafeAreaInsetsOverride', {
        insets: { bottom: 0, left: 0, right: 0, top: 0 },
    });
    expect(await readSafeAreaInsetBottom(page)).toBeCloseTo(0, 1);
    await page.setViewportSize({ width: 768, height: 844 });
    await expect(englishAccount.accountNavigation).toBeHidden();
    await expect(englishAccount.launcher).toBeVisible();

    const desktopGeometry = await englishAccount.chatRoot.evaluate(
        (element) => {
            const styles = window.getComputedStyle(element);
            const box = element.getBoundingClientRect();

            return {
                bottom: Number.parseFloat(styles.bottom),
                launcherBottom: window.innerHeight - box.bottom,
                zIndex: Number.parseInt(styles.zIndex, 10),
            };
        },
    );

    expect(desktopGeometry.bottom).toBeCloseTo(24, 1);
    expect(desktopGeometry.launcherBottom).toBeCloseTo(24, 1);
    expect(desktopGeometry.zIndex).toBe(50);

    await englishAccount.launcher.click();

    const englishDialog = page.getByRole('dialog', {
        name: 'Arab UT Chat Assistant',
    });

    await expect(englishDialog).toBeVisible();
    await expect(englishDialog).toHaveAttribute('aria-modal', 'false');
    await expect(englishAccount.launcher).toBeFocused();
    await expectNoHorizontalOverflow(page);

    const outsideAccountControl = page
        .locator('.account-shell__sidebar')
        .locator('.account-navigation__link[aria-current="page"]');

    await expect(outsideAccountControl).toBeVisible();
    await outsideAccountControl.click({ trial: true });
    await outsideAccountControl.focus();
    await expect(outsideAccountControl).toBeFocused();
    await expect(englishDialog).toBeVisible();

    await englishDialog.getByRole('button', { name: 'Close chat' }).click();
    await expect(englishAccount.launcher).toBeFocused();
    await cdpSession.detach();
    expectCleanRuntime();
});
