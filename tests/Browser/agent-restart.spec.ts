import { randomUUID } from 'node:crypto';
import { expect, test } from '@playwright/test';

test.describe('Agent restart after completed reply', () => {
    test('new chat opens cleanly right after a streamed reply completes', async ({
        page,
    }) => {
        test.setTimeout(120_000);
        await page.setViewportSize({ width: 1440, height: 900 });

        const syntheticId = randomUUID();
        const password = `ArabUT-${syntheticId}-Aa1!`;

        await page.goto('/register');
        await page.locator('#first_name').fill('Restart');
        await page.locator('#last_name').fill('Probe');
        await page.locator('#email').fill(`${syntheticId}@example.test`);
        await page.locator('#password').fill(password);
        await page.locator('#password_confirmation').fill(password);

        await Promise.all([
            page.waitForURL((url) => url.pathname === '/my-account'),
            page.locator('[data-test="register-user-button"]').click(),
        ]);

        await page.goto('/');
        const launcher = page.getByRole('button', { name: 'فتح الشات' });
        await expect(launcher).toBeVisible();
        await launcher.click();

        const dialog = page.getByRole('dialog', {
            name: 'شات مساعد عرب التيميت',
        });
        await expect(dialog).toBeVisible();

        const composer = dialog.locator('textarea');
        const sendBtn = dialog.getByRole('button', { name: 'إرسال الرسالة' });

        await composer.fill('مرحبا');
        await sendBtn.click();

        // Typing dots must appear immediately on send, before any turn starts.
        await expect(dialog.locator('span.animate-bounce').first()).toBeVisible(
            {
                timeout: 2_000,
            },
        );

        const streamingBubble = dialog.locator(
            '[data-stream-status="streaming"]',
        );
        await expect(streamingBubble).toBeVisible({ timeout: 15_000 });
        await expect(streamingBubble).toBeHidden({ timeout: 20_000 });

        const restartButton = dialog.getByRole('button', {
            name: /محادثة جديدة/,
        });

        await expect(restartButton).toBeEnabled({ timeout: 10_000 });
        await expect(restartButton).not.toHaveAttribute('aria-busy', 'true');

        await restartButton.click();

        // Restart must complete: a fresh conversation greets again.
        await expect(
            dialog.getByText(/مساعد عرب التيميت\. اكتب رسالتك/).first(),
        ).toBeVisible({ timeout: 15_000 });

        await expect(restartButton).toBeEnabled();
        await expect(restartButton).toHaveAttribute('aria-busy', 'false');
    });
});
