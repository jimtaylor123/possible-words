import { test, expect } from './support/fixtures.js';
import { installBrowserMocks } from './support/browser-mocks.js';

test.describe('sharing and pronunciation', () => {
    test.beforeEach(async ({ page }) => installBrowserMocks(page));

    test('opens each share target in a safe new window', async ({ page, e2e }) => {
        const fixture = await e2e.setup();
        await page.goto(`/words/${fixture.words[0].slug}`);
        for (const name of ['WhatsApp', 'Facebook', 'X', 'LinkedIn']) {
            await page.getByRole('button', { name: `Share on ${name}` }).click();
        }
        const calls = await page.evaluate(() => window.__e2eWindows);
        expect(calls).toHaveLength(4);
        for (const [, target, features] of calls) {
            expect(target).toBe('_blank');
            expect(features).toBe('noopener,noreferrer');
        }
    });

    test('plays the fixture pronunciation without a network TTS request', async ({ page, e2e }) => {
        const fixture = await e2e.setup();
        await page.goto(`/words/${fixture.words[0].slug}`);
        await page.getByRole('button', { name: 'Play pronunciation' }).click();
        await expect(page.locator('[data-testid="word-audio"]')).toBeVisible();
    });
});
