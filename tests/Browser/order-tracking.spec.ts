import { execFileSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

/**
 * The order page's tracking card, at the widths it is actually read at.
 *
 * Two of the owner's requests on this card are geometric, so a rendering
 * assertion is the only kind that can hold them:
 *
 * - the numbers stay inside their box (they had been escaping it, because
 *   `cqi` resolves against the content box rather than the border box)
 * - pressing "show status" lands on the panel's first line, not its middle
 *
 * Both are invisible to a unit test and both regressed once already, so they
 * are pinned here rather than re-checked by eye.
 */

const PASSWORD = 'browser-tracking-password-1A!';

type Seeded = {
    email: string;
    orderNumber: string;
};

const encode = (value: string) =>
    `base64_decode('${Buffer.from(value).toString('base64')}')`;

/**
 * Tinker exits non-zero on a PHP error but says why only on its own streams,
 * and `stdio: 'pipe'` hides them. A seeding failure that reports the command
 * without the reason costs a run per guess, so the reason is re-thrown with it.
 */
function tinker(php: string): void {
    try {
        execFileSync('php', ['artisan', 'tinker', '--execute', php], {
            cwd: process.cwd(),
            stdio: 'pipe',
        });
    } catch (error) {
        const failure = error as { stdout?: Buffer; stderr?: Buffer };
        const output = [
            failure.stdout?.toString() ?? '',
            failure.stderr?.toString() ?? '',
        ]
            .join('\n')
            .trim();

        throw new Error(`Browser fixture failed:\n${output}`);
    }
}

/**
 * The fixture is written straight through tinker, the way the storefront spec
 * seeds its own order. The guard is on the environment only: unlike the admin
 * fixtures this needs no SQLite-specific behaviour, so a developer whose local
 * database is not SQLite can still run it.
 */
function seed(): Seeded {
    const email = `tracking-${randomUUID().slice(0, 8)}@browser.test`;
    const orderNumber = `UT-${Math.floor(Math.random() * 90_000_000 + 10_000_000)}`;

    tinker(
        [
            `if (!app()->environment(['local', 'testing'])) { throw new \\RuntimeException('Browser fixtures are local only.'); }`,
            // The password goes in plain: the model casts it as `hashed`, so
            // hashing it here would hash it twice and nothing would ever log in.
            `$user = \\App\\Models\\User::create(['first_name' => 'Tracking', 'last_name' => 'Browser', 'email' => ${encode(email)}, 'password' => ${encode(PASSWORD)}, 'email_verified_at' => now(), 'preferred_locale' => 'ar', 'is_active' => true]);`,
            `$order = $user->orders()->create(['order_number' => ${encode(orderNumber)}, 'status' => \\App\\Enums\\OrderStatus::InProgress, 'locale' => 'ar', 'currency' => 'SAR', 'subtotal_halalah' => 45000, 'discount_halalah' => 0, 'wallet_halalah' => 0, 'payment_halalah' => 45000, 'total_halalah' => 45000, 'placed_at' => now()]);`,
            // Three items, because the scroll request came from an order with
            // several: with one item the panel cannot be pushed below the fold
            // and the assertion would pass without testing anything.
            `foreach ([1, 2, 3] as $n) { $item = $order->items()->create(['sku' => 'BROWSER-COINS-'.$n, 'name_ar' => 'كوينز '.$n, 'name_en' => 'Coins '.$n, 'service_type' => \\App\\Enums\\ServiceType::Coins, 'platform' => \\App\\Enums\\Platform::PlayStation, 'status' => \\App\\Enums\\OrderItemStatus::InProgress, 'quantity' => 1, 'unit_price_halalah' => 15000, 'subtotal_halalah' => 15000, 'discount_halalah' => 0, 'total_halalah' => 15000]);`,
            // A stored observation, and deliberately no supplier configuration:
            // opening the page triggers a read, the read fails closed on a
            // missing key, and the card must render the stored value anyway.
            // That is the rule this branch added, so the fixture exercises it.
            `\\App\\Models\\FulfillmentJob::create(['order_item_id' => $item->id, 'idempotency_key' => $order->order_number.'-'.$n, 'status' => \\App\\Enums\\FulfillmentStatus::InProgress, 'supplier' => \\App\\Enums\\Supplier::Fft, 'supplier_order_id' => 'browser-'.$n, 'delivery_phase' => \\App\\Enums\\DeliveryPhase::Coins, 'observed_at' => now()->subMinutes(2), 'observed_state' => 'transferring', 'observation_supported' => true, 'presentation' => \\App\\Enums\\TrackingPresentation::Transferring, 'coins_ordered' => 1250000, 'coins_delivered' => 940000, 'next_poll_at' => now()]); }`,
        ].join(' '),
    );

    return { email, orderNumber };
}

function cleanUp(email: string): void {
    tinker(
        `if (!app()->environment(['local', 'testing'])) { throw new \\RuntimeException('Browser fixtures are local only.'); } $user = \\App\\Models\\User::where('email', ${encode(email)})->first(); if ($user !== null) { $user->orders()->each(function ($order) { $order->items()->each(fn ($item) => $item->fulfillmentJob()->delete()); $order->payments()->delete(); $order->items()->delete(); $order->delete(); }); $user->delete(); }`,
    );
}

async function signIn(page: Page, email: string, locale: 'ar' | 'en') {
    await page.goto(locale === 'ar' ? '/login' : '/en/login');
    await page.locator('#email').fill(email);
    await page.locator('#password').fill(PASSWORD);
    await page.locator('form.auth-form button[type="submit"]').click();
    await page.waitForURL((url) => !url.pathname.includes('/login'));
}

async function expectNoHorizontalOverflow(page: Page) {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );

    expect(overflow).toBeLessThanOrEqual(1);
}

/**
 * A number is inside its box when its own rectangle does not cross its
 * container's edge.
 *
 * Both rectangles are read inside one `evaluate`, in the same frame. Measuring
 * them with two round trips - even under `Promise.all` - lets a reflow land
 * between them, and the comparison then holds two different layouts against
 * each other: it reported a four-pixel overflow at one width and sixty-five at
 * another, from a page that was merely still settling.
 */
async function expectContained(container: Locator, childSelector: string) {
    const box = await container.evaluate((stat, selector) => {
        const child = stat.querySelector(selector);

        if (child === null) {
            return null;
        }

        const outer = stat.getBoundingClientRect();
        const inner = child.getBoundingClientRect();

        return {
            text: (child.textContent ?? '').trim(),
            leftSlack: inner.left - outer.left,
            rightSlack: outer.right - inner.right,
            topSlack: inner.top - outer.top,
            bottomSlack: outer.bottom - inner.bottom,
        };
    }, childSelector);

    expect(box).not.toBeNull();

    if (box === null) {
        return;
    }

    // A pixel of slack absorbs sub-pixel layout, which differs by width.
    expect(box.leftSlack, `left of "${box.text}"`).toBeGreaterThanOrEqual(-1);
    expect(box.rightSlack, `right of "${box.text}"`).toBeGreaterThanOrEqual(-1);
    expect(box.topSlack, `top of "${box.text}"`).toBeGreaterThanOrEqual(-1);
    expect(box.bottomSlack, `bottom of "${box.text}"`).toBeGreaterThanOrEqual(
        -1,
    );
}

const WIDTHS = [320, 390, 768, 1440] as const;
const DIRECTIONS = [
    { locale: 'ar' as const, dir: 'rtl' },
    { locale: 'en' as const, dir: 'ltr' },
];

for (const { locale, dir } of DIRECTIONS) {
    for (const width of WIDTHS) {
        test(`the tracking card fits its box at ${width}px in ${dir}`, async ({
            page,
        }) => {
            const { email, orderNumber } = seed();

            try {
                await page.setViewportSize({ width, height: 900 });
                await signIn(page, email, locale);
                await page.goto(
                    locale === 'ar'
                        ? `/my-account/orders/${orderNumber}`
                        : `/en/my-account/orders/${orderNumber}`,
                );

                await expect(page.locator('html')).toHaveAttribute('dir', dir);

                const toggles = page.locator('.account-invoice__item-more');
                await expect(toggles.first()).toBeVisible();
                await toggles.first().click();

                const panel = page
                    .locator('.account-invoice__item-details')
                    .first();
                await expect(panel).toBeVisible();

                // Every stat number, in every card the page rendered.
                const stats = page.locator('.track-stat');
                const count = await stats.count();
                expect(count).toBeGreaterThan(0);

                for (let index = 0; index < count; index++) {
                    await expectContained(stats.nth(index), '.track-stat__val');
                }

                await expectNoHorizontalOverflow(page);
            } finally {
                cleanUp(email);
            }
        });
    }
}

test('pressing show status lands on the panel first line, not its middle', async ({
    page,
}) => {
    const { email, orderNumber } = seed();

    try {
        // A phone is where this was asked for: the panel is tallest relative to
        // the viewport there, so a centred scroll hides its opening lines.
        await page.setViewportSize({ width: 390, height: 780 });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await signIn(page, email, 'ar');
        await page.goto(`/my-account/orders/${orderNumber}`);

        // The last item's row sits furthest down the invoice, so opening it is
        // the case that has to scroll.
        const toggle = page.locator('.account-invoice__item-more').last();
        await toggle.scrollIntoViewIfNeeded();
        await toggle.click();

        const panel = page.locator('.account-invoice__item-details').last();
        await expect(panel).toBeVisible();

        const header = page.locator('.store-header');
        const headerHeight = (await header.count())
            ? ((await header.boundingBox())?.height ?? 0)
            : 0;

        await expect
            .poll(
                async () => {
                    const box = await panel.boundingBox();

                    return box === null ? null : Math.round(box.y);
                },
                { timeout: 5_000 },
            )
            .not.toBeNull();

        const box = await panel.boundingBox();
        expect(box).not.toBeNull();

        if (box === null) {
            return;
        }

        // Its first line is just under the sticky header: on screen, close to
        // the top, and above all not scrolled past.
        expect(box.y).toBeGreaterThanOrEqual(0);
        expect(box.y).toBeLessThanOrEqual(headerHeight + 40);
    } finally {
        cleanUp(email);
    }
});
