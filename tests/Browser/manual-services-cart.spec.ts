import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

// Rivals and FUT Champions are provisioned by a migration, so a migrated
// database already serves both pages with their live price schedules. These
// specs cover the customer path the unit tests cannot: the configurator in a
// real browser, the guest cart it feeds, and the phone layout of the panel.

const ADD_TO_CART = 'أضف الخدمة إلى السلة';
const ADDED = 'تمت إضافة الخدمة إلى السلة';
const RIVALS_NAME = 'خدمة الرايفلز';

// A 1x1 opaque PNG: the smallest file the squad-image dropzone accepts.
const SQUAD_IMAGE = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
    'base64',
);

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

async function expectNoHorizontalOverflow(page: Page) {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );

    expect(overflow).toBeLessThanOrEqual(1);
}

test('FUT Champions switches between options and guide, and the rank slider re-prices the panel', async ({
    page,
}) => {
    const assertRuntimeClean = observeRuntime(page);
    const response = await page.goto('/fut-champions');

    expect(response?.ok()).toBe(true);
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    const options = page.getByRole('tab', { name: 'الخيارات' });
    const guide = page.getByRole('tab', { name: 'الشرح' });
    const rank = page.getByRole('slider', { name: 'اختر الرانك المطلوب' });
    const total = page.locator('.manual-service-panel__total-amount');

    await expect(options).toHaveAttribute('aria-selected', 'true');
    await expect(rank).toBeVisible();

    const defaultTotal = (await total.textContent())?.trim() ?? '';

    expect(defaultTotal).not.toBe('');
    expect(defaultTotal).not.toBe('—');

    // Rank 1 is the dearest option, so the total has to move.
    await rank.fill('1');
    await expect(total).not.toHaveText(defaultTotal);

    // The guide holds the notes and both tutorial links; the configurator
    // stays mounted but hidden, so the chosen rank survives the round trip.
    await guide.click();
    await expect(guide).toHaveAttribute('aria-selected', 'true');
    await expect(rank).toBeHidden();
    await expect(
        page.getByRole('link', { name: /شرح أكواد EA/ }),
    ).toBeVisible();
    await expect(
        page.getByRole('link', { name: /شرح أكواد بلايستيشن/ }),
    ).toBeVisible();

    await options.click();
    await expect(rank).toBeVisible();
    await expect(rank).toHaveValue('1');

    assertRuntimeClean();
});

test('Rivals on a phone keeps the service image on top and the add-to-cart bar pinned while scrolling', async ({
    page,
}) => {
    await page.setViewportSize({ width: 390, height: 844 });

    const assertRuntimeClean = observeRuntime(page);
    const response = await page.goto('/rivals');

    expect(response?.ok()).toBe(true);

    const heroImage = page.locator('.manual-service-hero__media img');
    const panelImage = page.locator('.manual-service-panel__media');
    const bar = page.locator('.manual-service-panel__bar');
    const dock = page.locator('.manual-service-dock');
    const submit = bar.getByRole('button', { name: ADD_TO_CART });

    await expect(heroImage).toBeVisible();
    await expect(panelImage).toBeHidden();
    // The dock stays away while the hero is on screen.
    await expect(dock).toBeHidden();
    await expect(bar).toHaveCSS('position', 'static');

    await page.mouse.wheel(0, 900);
    await expect
        .poll(() => page.evaluate(() => window.scrollY))
        .toBeGreaterThan(600);

    await expect(dock).toBeVisible();
    await expect(dock).toHaveCSS('position', 'fixed');
    await expect(dock.getByRole('button', { name: ADD_TO_CART })).toBeVisible();

    const viewportHeight = 844;
    const box = await dock.boundingBox();

    expect(box).not.toBeNull();

    if (box === null) {
        throw new Error('Expected the add-to-cart dock to render');
    }

    expect(box.y + box.height).toBeLessThanOrEqual(viewportHeight + 1);
    expect(box.y).toBeGreaterThan(viewportHeight * 0.6);

    // Reaching the panel itself hides the dock: one total, one button.
    await submit.scrollIntoViewIfNeeded();
    await expect(submit).toBeVisible();
    await expect(dock).toBeHidden();
    await expectNoHorizontalOverflow(page);

    assertRuntimeClean();
});

test('a guest can add Rivals to the cart, is sent to login at checkout, and can remove the line', async ({
    page,
}) => {
    const assertRuntimeClean = observeRuntime(page);
    const response = await page.goto('/rivals');

    expect(response?.ok()).toBe(true);

    const total = page.locator('.manual-service-panel__total-amount');

    await expect(page.getByRole('radio', { name: 'بلايستيشن' })).toBeChecked();
    await expect(total).not.toHaveText('—');

    await page
        .getByLabel('بريد بلايستيشن الإلكتروني')
        .fill('player@example.com');
    await page
        .getByLabel('كلمة مرور بلايستيشن', { exact: true })
        .fill('correct-horse');

    for (const [index, code] of [
        '11111111',
        '22222222',
        '33333333',
    ].entries()) {
        await page.locator(`input[name="ea-code-${index + 1}"]`).fill(code);
    }

    for (const [index, code] of ['ABC123', 'DEF456', 'GHI789'].entries()) {
        await page
            .locator(`input[name="playstation-code-${index + 1}"]`)
            .fill(code);
    }

    await page.locator('input[name="squad-image"]').setInputFiles({
        name: 'squad.png',
        mimeType: 'image/png',
        buffer: SQUAD_IMAGE,
    });

    await page.getByRole('button', { name: ADD_TO_CART }).click();
    await expect(
        page.getByRole('status').filter({ hasText: ADDED }),
    ).toBeVisible();

    await page.goto('/cart');

    const items = page.getByRole('region', { name: 'خدماتك' });
    const line = items.locator('li').filter({ hasText: RIVALS_NAME });

    await expect(line).toHaveCount(1);

    // Checkout is behind login for guests: the summary offers the login
    // link instead of the Paylink button.
    const login = page.getByRole('link', { name: 'سجّل الدخول للمتابعة' });

    await expect(login).toBeVisible();
    expect(
        new URL(await login.evaluate((a: HTMLAnchorElement) => a.href))
            .pathname,
    ).toContain('/login');

    // Removal is hold-to-confirm: the line goes only once the pointer has
    // stayed down for the full 900 ms fill.
    const remove = line.getByRole('button', { name: 'حذف المنتج' });
    const removeBox = await remove.boundingBox();

    expect(removeBox).not.toBeNull();

    if (removeBox === null) {
        throw new Error('Expected the remove control to render');
    }

    await page.mouse.move(
        removeBox.x + removeBox.width / 2,
        removeBox.y + removeBox.height / 2,
    );
    await page.mouse.down();
    await page.waitForTimeout(1_200);
    await page.mouse.up();
    await expect(
        page.getByRole('heading', { name: 'السلة فارغة' }),
    ).toBeVisible();

    assertRuntimeClean();
});
