import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

import {
    confirmAdminTwoFactor,
    expectNoHorizontalOverflow,
    mutateLocalBrowserUser,
    observeRuntime,
} from './support/admin-session';

/**
 * The manual-order drawer, at the two widths the project requires.
 *
 * 390 is the primary viewport (owner rule since 2026-09-03) and the one that
 * matters most here: the drawer is a long form, and every control on it is
 * something a finger presses.
 */
const REQUIRED_TOUCH_TARGET = 44;

/** A customer for the picker to find. The admin itself is not one. */
function seedManualOrderCustomer(): { email: string; name: string } {
    const id = randomUUID().slice(0, 8);
    const name = `Drawer${id}`;
    const email = `${name.toLowerCase()}@example.test`;
    const encodedEmail = Buffer.from(email).toString('base64');
    const encodedName = Buffer.from(name).toString('base64');
    const guard =
        "if (!app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite') { throw new \\RuntimeException('Browser Admin fixtures require a local SQLite environment.'); } ";
    const mutation = `${guard}\\App\\Models\\User::factory()->create(['role' => \\App\\Enums\\UserRole::Customer, 'first_name' => base64_decode('${encodedName}'), 'last_name' => 'Acceptance', 'email' => base64_decode('${encodedEmail}')]);`;

    execFileSync('php', ['artisan', 'tinker', '--execute', mutation], {
        cwd: process.cwd(),
        stdio: 'pipe',
    });

    return { email, name };
}

async function signInAsAdmin(page: Page): Promise<void> {
    const id = randomUUID();
    const email = `${id}@example.test`;
    const password = `ArabUT-${id}-Aa1!`;

    await page.goto('/register');
    await page.locator('#first_name').fill('Drawer');
    await page.locator('#last_name').fill('Acceptance Owner');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('#password_confirmation').fill(password);
    await Promise.all([
        page.waitForURL((url) => url.pathname === '/my-account'),
        page.locator('[data-test="register-user-button"]').click(),
    ]);

    mutateLocalBrowserUser(email, 'promote');
    await confirmAdminTwoFactor(page);
}

/**
 * Measures what a finger actually has to hit. Reads the rendered box rather
 * than the class, because a `min-h-11` inside a flex row that shrinks it is
 * still too small and only the measurement says so.
 */
async function expectTouchTargets(page: Page, within: string): Promise<void> {
    const undersized = await page
        .locator(
            `${within} button:visible, ${within} input:visible, ${within} textarea:visible, ${within} [role="combobox"]:visible`,
        )
        .evaluateAll(
            (elements, minimum) =>
                elements
                    .map((element) => {
                        // A radio is 16px of ink inside a label that is the
                        // whole row, and the label is what a finger presses.
                        // So the target is the clickable box, not the control.
                        const target = element.closest('label') ?? element;

                        return {
                            height: Math.round(
                                target.getBoundingClientRect().height,
                            ),
                            label: (
                                element.getAttribute('aria-label') ??
                                target.textContent ??
                                element.id ??
                                element.tagName
                            )
                                .trim()
                                .slice(0, 40),
                        };
                    })
                    .filter((box) => box.height > 0 && box.height < minimum),
            REQUIRED_TOUCH_TARGET,
        );

    expect(undersized).toEqual([]);
}

test('the manual order drawer opens, branches and creates a gift at both required widths', async ({
    page,
}) => {
    test.setTimeout(240_000);
    const expectCleanRuntime = observeRuntime(page);
    const customer = seedManualOrderCustomer();

    await page.setViewportSize({ width: 390, height: 844 });
    await signInAsAdmin(page);
    await page.emulateMedia({ reducedMotion: 'reduce' });

    for (const width of [390, 1440]) {
        await page.setViewportSize({ width, height: 900 });

        const response = await page.goto('/admin/orders');
        expect(response?.ok()).toBe(true);

        const open = page.getByRole('button', { name: 'New order' });
        await expect(open).toBeVisible();
        await open.click();

        const drawer = page.getByRole('dialog');
        await expect(
            drawer.getByRole('heading', { name: 'New order' }),
        ).toBeVisible();

        // A bank transfer is the default, so the payment group is present and
        // the gift notice is not.
        await expect(drawer.getByText('Payment received')).toBeVisible();
        await expect(drawer.getByText('No payment on a gift')).toHaveCount(0);

        // The branching is the design: a gift has no payment group at all
        // rather than a greyed-out one.
        await drawer.getByRole('button', { name: 'Gift', exact: true }).click();
        await expect(drawer.getByText('No payment on a gift')).toBeVisible();
        await expect(drawer.getByText('Payment received')).toHaveCount(0);
        await expect(drawer.getByText('Order total')).toBeVisible();

        // Placed by hand is the default, and it is the only delivery choice
        // that asks for a supplier reference.
        await expect(
            drawer.getByText('I already placed it by hand'),
        ).toBeVisible();
        await expect(drawer.getByRole('radio', { checked: true })).toHaveCount(
            1,
        );

        await expectNoHorizontalOverflow(page);

        if (width === 390) {
            await expectTouchTargets(page, '[role="dialog"]');
        }

        // The customer must already exist, so the picker only searches.
        await drawer
            .getByPlaceholder('Phone, email, name or customer number')
            .fill(customer.name);
        const match = drawer.getByRole('button', {
            name: new RegExp(customer.name),
        });
        await expect(match).toBeVisible({ timeout: 15_000 });
        await match.click();
        await expect(drawer.getByText(customer.email)).toBeVisible();

        // Objectives is the service with nothing to configure, which is the
        // case an empty configuration array has to survive.
        await drawer.getByRole('combobox').first().click();
        await page.getByRole('option', { name: 'Objectives' }).click();
        await drawer.getByRole('combobox').nth(1).click();
        await page.getByRole('option', { name: 'PC' }).click();

        // A person delivers Objectives, so no supplier reference is offered.
        await expect(
            drawer.getByText(
                'This service is delivered by a person, so it carries no supplier reference.',
            ),
        ).toBeVisible();

        await drawer.getByLabel('EA email').fill(customer.email);

        const submit = drawer.getByRole('button', { name: 'Create order' });
        await expect(submit).toBeEnabled();
        await Promise.all([
            page.waitForURL((url) =>
                /\/admin\/orders\/[^/]+$/.test(url.pathname),
            ),
            submit.click(),
        ]);

        // It landed on a real order, at the order number rather than a ULID.
        expect(new URL(page.url()).pathname).toMatch(/\/admin\/orders\/AUT-/);
    }

    expectCleanRuntime();
});

test('a supplier reference appears only for a service a supplier delivers', async ({
    page,
}) => {
    test.setTimeout(180_000);

    await page.setViewportSize({ width: 1440, height: 900 });
    await signInAsAdmin(page);
    await page.emulateMedia({ reducedMotion: 'reduce' });
    await page.goto('/admin/orders');
    await page.getByRole('button', { name: 'New order' }).click();

    const drawer = page.getByRole('dialog');
    await expect(
        drawer.getByRole('heading', { name: 'New order' }),
    ).toBeVisible();

    // Coins is delivered by a bot, so the reference is asked for.
    await expect(drawer.getByLabel('Supplier order reference')).toBeVisible();

    // Choosing the other delivery answers withdraws the whole group: the store
    // does not dispatch yet, so the order is created and waits.
    await drawer.getByText('Not placed yet').click();
    await expect(drawer.getByLabel('Supplier order reference')).toHaveCount(0);

    await drawer.getByText('I already placed it by hand').click();
    await expect(drawer.getByLabel('Supplier order reference')).toBeVisible();

    // The challenge phase asks for the ids, because a challenge job carrying
    // none is untrackable the moment it lands.
    await expect(drawer.getByLabel('Challenge IDs')).toHaveCount(0);
    await drawer.getByLabel('What was placed').click();
    await page.getByRole('option', { name: 'Challenge' }).click();
    await expect(drawer.getByLabel('Challenge IDs')).toBeVisible();
});
