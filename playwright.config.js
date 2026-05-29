import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'html',
    use: {
        baseURL: 'http://localhost:8001',
        trace: 'on-first-retry',
    },
    webServer: {
        command: 'php artisan serve --port=8001 --env=testing 2>/dev/null',
        url: 'http://localhost:8001',
        reuseExistingServer: !process.env.CI,
        cwd: '.',
    },
});
