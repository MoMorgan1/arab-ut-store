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

    return () => expect(failures).toEqual([]);
}

// Regression guard for the 2026-08-19 blank-storefront incident.
for (const path of ['/', '/en', '/cart']) {
    test(`storefront ${path} mounts`, async ({ page }) => {
        const expectCleanRuntime = observeRuntime(page);

        await page.goto(path);
        await expect(page.locator('#app')).not.toBeEmpty();
        await expect(page.getByRole('banner')).toBeVisible();
        await expect(page.getByRole('main')).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        expectCleanRuntime();
    });
}

for (const path of ['/login', '/en/login']) {
    test(`authentication ${path} mounts`, async ({ page }) => {
        const expectCleanRuntime = observeRuntime(page);

        await page.goto(path);
        await expect(page.locator('#app')).not.toBeEmpty();
        await expect(page.getByRole('main')).toBeVisible();
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
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
