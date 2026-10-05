import { defineConfig } from '@playwright/test';

const port = process.env.PLAYWRIGHT_PORT ?? '8002';
const baseURL = `http://localhost:${port}`;

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 1 : undefined,
    reporter: 'html',
    use: {
        baseURL,
        trace: 'on-first-retry',
        launchOptions: {
            slowMo: 0,
        },
    },
    webServer: {
        // Seed the throwaway database first so a local run starts from the same
        // fixture CI uses, then serve with .env.testing.
        // `migrate` bridges the fixture to the current schema: the snapshot only
        // carries the migrations that existed when it was captured, so any migration
        // added since then has to be applied before the specs can exercise it. It is
        // a no-op when the schema is already current.
        command: `npm run build -- --mode testing && npm run db:e2e && php artisan migrate --env=testing --force && APP_ENV=testing APP_URL=${baseURL} php artisan serve --port=${port} --env=testing 2>/dev/null`,
        url: baseURL,
        reuseExistingServer: false,
        cwd: '.',
    },
});
