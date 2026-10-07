import { defineConfig } from '@playwright/test';
import net from 'node:net';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const root = dirname(fileURLToPath(import.meta.url));
const e2eDatabase = resolve(root, 'database/testing.sqlite');

const availablePort = () => new Promise((resolvePort, reject) => {
    const server = net.createServer();

    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => {
        const { port } = server.address();
        server.close(error => error ? reject(error) : resolvePort(String(port)));
    });
});

// An explicit port remains useful for debugging. Otherwise acquire a free port
// per invocation so sibling worktrees cannot block one another. Playwright never
// reuses an existing server, so if another process wins the small bind race the
// run fails rather than exercising an arbitrary application.
const port = process.env.PLAYWRIGHT_PORT ?? await availablePort();
// Playwright evaluates this module again in worker processes. Preserve the
// parent-selected port so every worker targets the one web server.
process.env.PLAYWRIGHT_PORT = port;
const baseURL = `http://localhost:${port}`;
const shellQuote = value => `'${value.replaceAll("'", "'\\''")}'`;

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
        // `seed-e2e-domains` then stamps the two fixture words that
        // tests/e2e/domainFilter.spec.js asserts on: the snapshot predates the
        // .com columns, so every word would otherwise be "unchecked".
        command: `npm run build -- --mode testing && npm run db:e2e && APP_ENV=testing DB_DATABASE=${shellQuote(e2eDatabase)} php artisan migrate --env=testing --force && APP_ENV=testing DB_DATABASE=${shellQuote(e2eDatabase)} php scripts/seed-e2e-domains.php && APP_ENV=testing APP_URL=${shellQuote(baseURL)} DB_DATABASE=${shellQuote(e2eDatabase)} php artisan serve --port=${port} --env=testing`,
        url: baseURL,
        reuseExistingServer: false,
        cwd: root,
    },
});
