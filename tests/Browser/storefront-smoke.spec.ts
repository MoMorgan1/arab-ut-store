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

    for (const width of [320, 390, 768, 1440]) {
        for (const {
            path,
            language,
            direction,
            launcherLabel,
            dialogLabel,
            inputLabel,
            restartLabel,
            closeLabel,
            sendLabel,
            retryLabel,
            loadOlderLabel,
            scrollLabel,
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
                sendLabel: 'إرسال الرسالة',
                retryLabel: 'إعادة المحاولة',
                loadOlderLabel: 'تحميل الرسائل السابقة',
                scrollLabel: 'الانتقال لأسفل',
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
                sendLabel: 'Send message',
                retryLabel: 'Retry',
                loadOlderLabel: 'Load older messages',
                scrollLabel: 'Scroll to bottom',
            },
        ] as const) {
            const exercisesSecondaryControls =
                width === 390 && language === 'en';

            if (exercisesSecondaryControls) {
                await page.route('**/chat/conversations', async (route) => {
                    const request = route.request();

                    if (
                        request.method() === 'POST' &&
                        new URL(request.url()).pathname ===
                            '/chat/conversations'
                    ) {
                        await route.fulfill({
                            status: 200,
                            contentType: 'application/json',
                            body: JSON.stringify({
                                data: {
                                    publicId: 'browser-secondary-controls',
                                    status: 'open',
                                    locale: 'en',
                                    messages: [
                                        ...Array.from(
                                            { length: 24 },
                                            (_, index) => ({
                                                publicId: `browser-assistant-${index}`,
                                                conversationPublicId:
                                                    'browser-secondary-controls',
                                                senderType: 'assistant',
                                                messageType: 'text',
                                                content: `Browser assistant message ${index + 1}`,
                                                createdAt: `2026-08-20T${String(
                                                    8 + Math.floor(index / 4),
                                                ).padStart(2, '0')}:${String(
                                                    (index % 4) * 10,
                                                ).padStart(2, '0')}:00.000Z`,
                                            }),
                                        ),
                                        {
                                            publicId: 'browser-failed-message',
                                            tempId: 'browser-failed-message',
                                            conversationPublicId:
                                                'browser-secondary-controls',
                                            clientMessageId:
                                                'browser-failed-client',
                                            senderType: 'customer',
                                            messageType: 'text',
                                            content: 'Browser failed message',
                                            createdAt:
                                                '2026-08-20T14:00:00.000Z',
                                            clientStatus: 'error',
                                        },
                                    ],
                                    hasMore: true,
                                    oldestCursor: 'browser-assistant-0',
                                },
                            }),
                        });

                        return;
                    }

                    await route.continue();
                });
            }

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
                const [rootZIndex, navigationZIndex] = await Promise.all([
                    page
                        .locator('.chat-widget-root')
                        .evaluate((element) =>
                            Number.parseInt(
                                getComputedStyle(element).zIndex,
                                10,
                            ),
                        ),
                    page
                        .locator('.account-mobile-bottom-nav')
                        .evaluate((element) =>
                            Number.parseInt(
                                getComputedStyle(element).zIndex,
                                10,
                            ),
                        ),
                ]);
                expect(rootZIndex).toBeGreaterThan(navigationZIndex);
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
            await expect(viewportDialog).toHaveAttribute(
                'aria-modal',
                width < 768 ? 'true' : 'false',
            );
            expect(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth -
                        window.innerWidth,
                ),
            ).toBeLessThanOrEqual(1);
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
            const send = viewportDialog.getByRole('button', {
                name: sendLabel,
            });

            const requiredControls = [textarea, restart, close, send];

            if (exercisesSecondaryControls) {
                const loadOlder = viewportDialog.getByRole('button', {
                    name: loadOlderLabel,
                });
                const retry = viewportDialog.getByRole('button', {
                    name: retryLabel,
                });
                requiredControls.push(loadOlder, retry);

                const log = viewportDialog.getByRole('log');
                await log.evaluate((element) => {
                    element.scrollTop = 0;
                    element.dispatchEvent(new Event('scroll'));
                });
                const scroll = viewportDialog.getByRole('button', {
                    name: scrollLabel,
                });
                await expect(scroll).toBeVisible();
                requiredControls.push(scroll);
            } else {
                for (const suggestion of await viewportDialog
                    .locator('button:not([aria-label])')
                    .all()) {
                    requiredControls.push(suggestion);
                }
            }

            for (const control of requiredControls) {
                const box = await control.boundingBox();
                expect(box).not.toBeNull();
                expect(box!.width).toBeGreaterThanOrEqual(44);
                expect(box!.height).toBeGreaterThanOrEqual(44);
            }

            if (width < 768) {
                await expect(viewportDialog).toBeFocused();
                expect(
                    await page
                        .getByRole('main')
                        .evaluate(
                            (element) => element.closest('[inert]') !== null,
                        ),
                ).toBe(true);

                await page.keyboard.press('Tab');
                await expect(restart).toBeFocused();
                expect(
                    await restart.evaluate(
                        (element) => getComputedStyle(element).outlineStyle,
                    ),
                ).not.toBe('none');
                await page.keyboard.press('Shift+Tab');
                await expect(textarea).toBeFocused();
                await page.keyboard.press('Tab');
                await expect(restart).toBeFocused();

                const navigation = page.locator('.account-mobile-bottom-nav');
                const navBox = await navigation.boundingBox();
                expect(navBox).not.toBeNull();
                expect(
                    await viewportDialog.evaluate(
                        (dialogElement, { x, y }) => {
                            const hit = document.elementFromPoint(x, y);

                            return hit !== null && dialogElement.contains(hit);
                        },
                        {
                            x: navBox!.x + navBox!.width / 2,
                            y: navBox!.y + navBox!.height / 2,
                        },
                    ),
                ).toBe(true);
            } else {
                expect(
                    await page
                        .getByRole('main')
                        .evaluate(
                            (element) => element.closest('[inert]') === null,
                        ),
                ).toBe(true);
                await restart.focus();
                await page.keyboard.press('Tab');
                await page.keyboard.press('Shift+Tab');
                await expect(restart).toBeFocused();
                expect(
                    await restart.evaluate(
                        (element) => getComputedStyle(element).outlineStyle,
                    ),
                ).not.toBe('none');
            }

            await restart.hover();
            await expect(
                viewportDialog.getByRole('tooltip', {
                    name: restartLabel,
                }),
            ).toBeVisible();
            expect(
                await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth -
                        window.innerWidth,
                ),
            ).toBeLessThanOrEqual(1);

            await close.click();
            await expect(viewportDialog).not.toBeAttached();
            await expect(viewportLauncher).toBeFocused();
            expect(
                await page
                    .getByRole('main')
                    .evaluate((element) => element.closest('[inert]') === null),
            ).toBe(true);
            await page.emulateMedia({ reducedMotion: 'no-preference' });

            if (exercisesSecondaryControls) {
                await page.unroute('**/chat/conversations');
            }
        }
    }

    expectCleanRuntime();
});
