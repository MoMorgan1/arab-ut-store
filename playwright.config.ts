import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/Browser',
    fullyParallel: false,
    forbidOnly: Boolean(process.env.CI),
    retries: 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL: 'http://127.0.0.1:8010',
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
        video: 'off',
    },
    projects: [
        {
            name: 'chromium',
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
            },
        },
    ],
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8010 --no-reload',
        url: 'http://127.0.0.1:8010',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: {
            CHAT_ENABLED: 'true',
            CHAT_DEMO_ASSISTANT: 'true',
            AI_ASSISTANT_ENABLED: 'true',
            AI_ASSISTANT_ROLLOUT: 'public',
            AI_MODEL_PROVIDER: 'fake',
            AI_FAKE_DELTA_DELAY_MS: '400',
            OPENAI_API_KEY: '',
        },
    },
});
