import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: 'tests/e2e',
    reporter: process.env.CI ? [['line'], ['html', { open: 'never' }]] : 'list',
    webServer: {
        command: 'php artisan serve --host=127.0.0.1 --port=8000',
        env: {
            ...process.env,
            APP_ENV: 'e2e',
            MEDICAL_ENCRYPTION_KEY_V1:
                process.env.MEDICAL_ENCRYPTION_KEY_V1 ??
                'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
        },
        url: 'http://127.0.0.1:8000/health',
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
    },
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://127.0.0.1:8000',
        trace: 'on-first-retry',
    },
    projects: [
        { name: 'desktop', use: { ...devices['Desktop Chrome'] } },
        { name: 'mobile', use: { ...devices['iPhone 13'] } },
    ],
});
