import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';
import type { Locator, Page } from '@playwright/test';

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

        // Ignore aborted requests during page reload / navigation
        if (error.includes('ERR_ABORTED') || error.includes('aborted')) {
            return;
        }

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

test.describe('Agent Turn Streaming & Recovery Browser Suite', () => {
    test('Arabic RTL: four rapid sends coalesce into one streamed turn and reload recovers without second start', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await page.setViewportSize({ width: 1440, height: 900 });
        const expectCleanRuntime = observeRuntime(page);

        const syntheticId = randomUUID();
        const password = `ArabUT-${syntheticId}-Aa1!`;

        // Register synthetic tester user
        await page.goto('/register');
        await page.locator('#first_name').fill('Stream');
        await page.locator('#last_name').fill('Tester');
        await page.locator('#email').fill(`${syntheticId}@example.test`);
        await page.locator('#password').fill(password);
        await page.locator('#password_confirmation').fill(password);

        await Promise.all([
            page.waitForURL((url) => url.pathname === '/my-account'),
            page.locator('[data-test="register-user-button"]').click(),
        ]);

        await page.goto('/');
        await expect(page.locator('#app')).not.toBeEmpty();

        const agentTurnRequests: string[] = [];
        page.on('request', (request) => {
            if (
                request.url().includes('/chat/conversations/') &&
                request.url().includes('/agent-turns') &&
                request.method() === 'POST' &&
                !request.url().includes('/retry')
            ) {
                agentTurnRequests.push(request.url());
            }
        });

        const launcher = page.getByRole('button', { name: 'فتح الشات' });
        await expect(launcher).toBeVisible();
        await launcher.click();

        const dialog = page.getByRole('dialog', {
            name: 'شات مساعد عرب التيميت',
        });
        await expect(dialog).toBeVisible();
        await enterChatView(dialog);

        const composer = dialog.locator('textarea');
        const sendBtn = dialog.getByRole('button', { name: 'إرسال الرسالة' });

        // Rapidly dispatch four customer messages through FIFO
        const testMessages = [
            'الرسالة الأولى كوينز',
            'الرسالة الثانية 2 مليون',
            'الرسالة الثالثة بلايستيشن',
            'الرسالة الرابعة سريع',
        ];

        for (const msg of testMessages) {
            await composer.fill(msg);
            await sendBtn.click();
        }

        // All 4 customer messages appear in the DOM
        for (const msg of testMessages) {
            await expect(dialog.getByText(msg)).toBeVisible();
        }

        // Wait for streaming bubble to appear
        const streamingBubble = dialog.locator(
            '[data-stream-status="streaming"]',
        );
        await expect(streamingBubble).toBeVisible({ timeout: 15_000 });

        // Exactly one agent turn POST was dispatched
        expect(agentTurnRequests).toHaveLength(1);

        // Wait for streaming to finish and final durable message to replace partial bubble
        await expect(streamingBubble).toBeHidden({ timeout: 15_000 });

        // Send a fifth message to start a second stream
        await composer.fill('رسالة اختبار الاستعادة بعد التحديث');
        await sendBtn.click();

        // Wait for second stream to start
        await expect(
            dialog.locator('[data-stream-status="streaming"]'),
        ).toBeVisible({ timeout: 15_000 });
        expect(agentTurnRequests).toHaveLength(2);

        // Reload page during the stream
        await page.reload();
        await expect(page.locator('#app')).not.toBeEmpty();

        // Reopen chat after reload
        const reopenedLauncher = page.getByRole('button', {
            name: 'فتح الشات',
        });
        await expect(reopenedLauncher).toBeVisible();
        await reopenedLauncher.click();

        const reopenedDialog = page.getByRole('dialog', {
            name: 'شات مساعد عرب التيميت',
        });
        await expect(reopenedDialog).toBeVisible();

        // Wait for the turn to finalize and verify no extra start was dispatched
        await page.waitForTimeout(3000);
        expect(agentTurnRequests).toHaveLength(2);

        await expectNoHorizontalOverflow(page);
        expectCleanRuntime();
    });

    test('English LTR: four rapid sends coalesce into one streamed turn', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await page.setViewportSize({ width: 1440, height: 900 });
        const expectCleanRuntime = observeRuntime(page);

        const syntheticId = randomUUID();
        const password = `ArabUT-${syntheticId}-Aa1!`;

        // Register synthetic tester user
        await page.goto('/en/register');
        await page.locator('#first_name').fill('Stream');
        await page.locator('#last_name').fill('Tester');
        await page.locator('#email').fill(`${syntheticId}@example.test`);
        await page.locator('#password').fill(password);
        await page.locator('#password_confirmation').fill(password);

        await Promise.all([
            page.waitForURL((url) => url.pathname === '/en/my-account'),
            page.locator('[data-test="register-user-button"]').click(),
        ]);

        await page.goto('/en');
        await expect(page.locator('#app')).not.toBeEmpty();

        const agentTurnRequests: string[] = [];
        page.on('request', (request) => {
            if (
                request.url().includes('/chat/conversations/') &&
                request.url().includes('/agent-turns') &&
                request.method() === 'POST' &&
                !request.url().includes('/retry')
            ) {
                agentTurnRequests.push(request.url());
            }
        });

        const launcher = page.getByRole('button', { name: 'Open chat' });
        await expect(launcher).toBeVisible();
        await launcher.click();

        const dialog = page.getByRole('dialog', {
            name: 'Arab UT Chat Assistant',
        });
        await expect(dialog).toBeVisible();
        await enterChatView(dialog);

        const composer = dialog.locator('textarea');
        const sendBtn = dialog.getByRole('button', { name: 'Send message' });

        const testMessages = [
            'First English message',
            'Second message 2 million',
            'Third message PlayStation',
            'Fourth message quick delivery',
        ];

        for (const msg of testMessages) {
            await composer.fill(msg);
            await sendBtn.click();
        }

        for (const msg of testMessages) {
            await expect(dialog.getByText(msg)).toBeVisible();
        }

        // Wait for streaming bubble to appear
        const streamingBubble = dialog.locator(
            '[data-stream-status="streaming"]',
        );
        await expect(streamingBubble).toBeVisible({ timeout: 15_000 });

        // Exactly one agent turn POST was dispatched
        expect(agentTurnRequests).toHaveLength(1);

        // Wait for streaming to complete
        await expect(streamingBubble).toBeHidden({ timeout: 15_000 });

        await expectNoHorizontalOverflow(page);
        expectCleanRuntime();
    });
});
