import { execFileSync } from 'node:child_process';
import { createHmac, randomUUID } from 'node:crypto';
import { expect } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * The pieces every admin browser test needs: a TOTP code, a promoted local
 * user, the two-factor confirmation, and the runtime observers.
 *
 * Extracted from storefront-smoke.spec.ts when the manual-order drawer needed
 * the same sign-in. One copy, so a change to the admin's two-factor screen does
 * not fix one spec and quietly break the other.
 */

export const TEST_ADMIN_TOTP_SECRET = 'NBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

function base32Decode(base32: string): Buffer {
    const cleaned = base32
        .toUpperCase()
        .replace(/=+$/, '')
        .replace(/[\s-]/g, '');
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = 0;
    let value = 0;
    const bytes: number[] = [];

    for (let i = 0; i < cleaned.length; i++) {
        const index = alphabet.indexOf(cleaned[i]);

        if (index === -1) {
            throw new Error(`Invalid base32 character: ${cleaned[i]}`);
        }

        value = (value << 5) | index;
        bits += 5;

        if (bits >= 8) {
            bytes.push((value >>> (bits - 8)) & 0xff);
            bits -= 8;
        }
    }

    return Buffer.from(bytes);
}

export function generateTotp(secret: string, timestampMs = Date.now()): string {
    const epochSeconds = Math.floor(timestampMs / 1000);
    const counter = Math.floor(epochSeconds / 30);
    const counterBuffer = Buffer.alloc(8);
    counterBuffer.writeBigUInt64BE(BigInt(counter));
    const hmac = createHmac('sha1', base32Decode(secret))
        .update(counterBuffer)
        .digest();
    const offset = hmac[hmac.length - 1] & 0x0f;
    const binary =
        ((hmac[offset] & 0x7f) << 24) |
        ((hmac[offset + 1] & 0xff) << 16) |
        ((hmac[offset + 2] & 0xff) << 8) |
        (hmac[offset + 3] & 0xff);

    return (binary % 1_000_000).toString().padStart(6, '0');
}

export function observeRuntime(page: Page) {
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

        // A cancelled request is a cancellation, not a failure. The pages here
        // abort their own in-flight reads when a control changes or the view
        // unmounts - the manual-order drawer aborts its price suggestion on
        // submit - and calling that a runtime error would punish the correct
        // behaviour while telling us nothing about the page.
        if (error.includes('net::ERR_ABORTED')) {
            return;
        }

        failures.push(
            `requestfailed ${request.resourceType()}: ${request.url()} (${error})`,
        );
    });

    return () => expect(failures).toEqual([]);
}

export function mutateLocalBrowserUser(
    email: string,
    action: 'promote' | 'delete',
) {
    const encodedEmail = Buffer.from(email).toString('base64');
    const lookup = `base64_decode('${encodedEmail}')`;
    const guard = `if (!app()->environment(['local', 'testing']) || config('database.default') !== 'sqlite') { throw new \\RuntimeException('Browser Admin fixtures require a local SQLite environment.'); } `;
    const mutation =
        action === 'promote'
            ? `${guard}$user = \\App\\Models\\User::where('email', ${lookup})->firstOrFail(); $user->forceFill(['role' => \\App\\Enums\\UserRole::Admin, 'two_factor_secret' => \\Laravel\\Fortify\\Fortify::currentEncrypter()->encrypt('${TEST_ADMIN_TOTP_SECRET}'), 'two_factor_confirmed_at' => now()])->save();`
            : `${guard}$user = \\App\\Models\\User::where('email', ${lookup})->firstOrFail(); $user->orders()->each(function ($order) { $order->payments()->delete(); $order->items()->delete(); $order->delete(); }); $user->delete();`;

    execFileSync('php', ['artisan', 'tinker', '--execute', mutation], {
        cwd: process.cwd(),
        stdio: 'pipe',
    });
}

export async function confirmAdminTwoFactor(page: Page) {
    if (!page.url().includes('/admin/confirm-2fa')) {
        await page.goto('/admin');
    }

    await page.waitForURL((url) => url.pathname.includes('/admin/confirm-2fa'));

    const codeInput = page.locator('[data-test="admin-2fa-code-input"]');
    const submitButton = page.locator('[data-test="admin-2fa-submit-button"]');

    await expect(codeInput).toBeVisible();

    // The wait has to cover an Inertia POST plus the redirect target's first
    // render, and the admin overview draws charts at desktop widths. Five
    // seconds is comfortable locally and too tight on a loaded CI runner,
    // where losing the race fires a second submit that then races the first.
    const maxAttempts = 3;

    for (let attempt = 1; attempt <= maxAttempts; attempt++) {
        const code = generateTotp(TEST_ADMIN_TOTP_SECRET);
        await codeInput.fill(code);
        await submitButton.click();

        try {
            await page.waitForURL(
                (url) => !url.pathname.includes('/admin/confirm-2fa'),
                { timeout: 20_000 },
            );
            break;
        } catch (error) {
            if (attempt === maxAttempts) {
                throw error;
            }
        }
    }
}

export function seedLocalBrowserOrder(email: string) {
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

export async function expectNoHorizontalOverflow(page: Page) {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );

    expect(overflow).toBeLessThanOrEqual(1);
}
