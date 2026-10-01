import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'html',
    use: {
        baseURL: 'http://localhost:8002',
        trace: 'on-first-retry',
        launchOptions: {
            slowMo: 0,
        },
    },
    webServer: {
        // Seed the throwaway database first so a local run starts from the same
        // fixture CI uses, then serve with .env.testing.
        command: 'npm run db:e2e && php artisan serve --port=8002 --env=testing 2>/dev/null',
        url: 'http://localhost:8002',
        reuseExistingServer: !process.env.CI,
        cwd: '.',
    },
});
