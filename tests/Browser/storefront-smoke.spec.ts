import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

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
        const type = request.resourceType();

        if (type === 'script' || type === 'stylesheet') {
            const error =
                request.failure()?.errorText ?? 'unknown network error';
            failures.push(`requestfailed ${type}: ${request.url()} (${error})`);
        }
    });

    return () => expect(failures).toEqual([]);
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
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const expectCleanRuntime = observeRuntime(page);
    const unique = `${Date.now()}-${Math.random().toString(36).slice(2, 10)}`;

    await page.goto('/register');
    await page.locator('#first_name').fill('Chat');
    await page.locator('#last_name').fill('Regression');
    await page.locator('#email').fill(`chat-${unique}@example.test`);
    await page.locator('#password').fill('ArabUT!Chat2026#');
    await page.locator('#password_confirmation').fill('ArabUT!Chat2026#');
    await page.locator('[data-test="register-user-button"]').click();
    await page.waitForURL('**/my-account');

    const assertLauncherAboveNavigation = async () => {
        const launcher = page.getByRole('button', {
            name: /فتح الشات|Open chat/,
        });
        const navigation = page.locator('.account-mobile-bottom-nav');

        await expect(launcher).toBeVisible();
        await expect(navigation).toBeVisible();

        const [launcherBox, navBox] = await Promise.all([
            launcher.boundingBox(),
            navigation.boundingBox(),
        ]);

        expect(launcherBox).not.toBeNull();
        expect(navBox).not.toBeNull();
        expect(launcherBox!.y + launcherBox!.height).toBeLessThan(navBox!.y);

        return launcher;
    };

    const launcher = await assertLauncherAboveNavigation();
    await launcher.click();

    const dialog = page.getByRole('dialog', {
        name: 'شات مساعد عرب التيميت',
    });
    await expect(dialog).toBeVisible();

    const [dialogZIndex, navigationZIndex] = await Promise.all([
        dialog.evaluate((element) =>
            Number.parseInt(getComputedStyle(element).zIndex, 10),
        ),
        page
            .locator('.account-mobile-bottom-nav')
            .evaluate((element) =>
                Number.parseInt(getComputedStyle(element).zIndex, 10),
            ),
    ]);
    expect(dialogZIndex).toBeGreaterThan(navigationZIndex);

    await dialog.getByRole('button', { name: 'إغلاق الشات' }).click();
    await expect(dialog).not.toBeAttached();
    await expect(launcher).toBeFocused();

    await page.goto('/en/my-account');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await assertLauncherAboveNavigation();

    for (const width of [320, 768, 1440]) {
        for (const {
            path,
            language,
            direction,
            launcherLabel,
            dialogLabel,
            inputLabel,
            restartLabel,
            closeLabel,
        } of [
            {
                path: '/my-account',
                language: 'ar',
                direction: 'rtl',
                launcherLabel: 'فتح الشات',
                dialogLabel: 'شات مساعد عرب التيميت',
                inputLabel: 'حقل كتابة الرسالة',
                restartLabel: 'محادثة جديدة',
                closeLabel: 'إغلاق الشات',
            },
            {
                path: '/en/my-account',
                language: 'en',
                direction: 'ltr',
                launcherLabel: 'Open chat',
                dialogLabel: 'Arab UT Chat Assistant',
                inputLabel: 'Message input',
                restartLabel: 'New conversation',
                closeLabel: 'Close chat',
            },
        ] as const) {
            await page.setViewportSize({ width, height: 844 });
            await page.emulateMedia({ reducedMotion: 'reduce' });
            await page.goto(path);

            await expect(page.locator('html')).toHaveAttribute(
                'lang',
                language,
            );
            await expect(page.locator('html')).toHaveAttribute(
                'dir',
                direction,
            );
            expect(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth -
                        window.innerWidth,
                ),
            ).toBeLessThanOrEqual(1);

            const viewportLauncher = page.getByRole('button', {
                name: launcherLabel,
            });
            await expect(viewportLauncher).toBeVisible();
            const launcherTarget = await viewportLauncher.boundingBox();
            expect(launcherTarget).not.toBeNull();
            expect(launcherTarget!.width).toBeGreaterThanOrEqual(44);
            expect(launcherTarget!.height).toBeGreaterThanOrEqual(44);

            const launcherRight = await page
                .locator('.chat-widget-root')
                .evaluate((element) => getComputedStyle(element).right);
            expect(launcherRight).toBe(width < 768 ? '16px' : '24px');

            if (width < 768) {
                await assertLauncherAboveNavigation();
            } else {
                await expect(
                    page.locator('.account-mobile-bottom-nav'),
                ).toBeHidden();
            }

            await viewportLauncher.click();
            const viewportDialog = page.getByRole('dialog', {
                name: dialogLabel,
            });
            await expect(viewportDialog).toBeVisible();
            expect(
                await viewportDialog.evaluate(
                    (element) => getComputedStyle(element).transitionProperty,
                ),
            ).toBe('none');

            const textarea = viewportDialog.getByRole('textbox', {
                name: inputLabel,
            });
            await expect(textarea).toHaveAttribute('dir', 'auto');

            const restart = viewportDialog.getByRole('button', {
                name: restartLabel,
            });
            await expect(restart).toBeEnabled();
            const close = viewportDialog.getByRole('button', {
                name: closeLabel,
            });

            for (const control of [textarea, restart, close]) {
                const box = await control.boundingBox();
                expect(box).not.toBeNull();
                expect(box!.height).toBeGreaterThanOrEqual(44);
            }

            await restart.hover();
            await expect(
                viewportDialog.getByRole('tooltip', {
                    name: restartLabel,
                }),
            ).toBeVisible();

            await close.click();
            await expect(viewportDialog).not.toBeAttached();
            await expect(viewportLauncher).toBeFocused();
            await page.emulateMedia({ reducedMotion: 'no-preference' });
        }
    }

    expectCleanRuntime();
});
