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
