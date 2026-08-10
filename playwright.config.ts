import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.PLAYWRIGHT_PORT ?? 8000);
const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? `http://127.0.0.1:${port}`;

export default defineConfig({
    testDir: './tests/Browser',
    // Accessibility and hold-countdown evidence (L4-03) is captured here later.
    // Keep runs deterministic: no retries hiding a flaky correctness result.
    retries: 0,
    forbidOnly: !!process.env.CI,
    reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${port}`,
        url: baseURL,
        reuseExistingServer: !process.env.CI,
        timeout: 60_000,
    },
});
