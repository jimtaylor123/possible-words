import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'html',
    use: {
        baseURL: 'http://localhost:8000',
        trace: 'on-first-retry',
    },
    webServer: {
        command: 'php artisan serve --port=8000 --env=testing',
        url: 'http://localhost:8000',
        reuseExistingServer: !process.env.CI,
        cwd: '.',
    },
});
