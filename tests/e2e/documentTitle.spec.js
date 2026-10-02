import { test, expect } from '@playwright/test';

// Records every value document.title takes, from the earliest moment any script
// can run. The interval catches titles that never fire a mutation, and both stop
// at load so the recorder cannot leak into later client-side visits.
const recordTitles = page => page.addInitScript(() => {
    window.__titles = [document.title];
    const record = () => window.__titles.push(document.title);
    new MutationObserver(record).observe(document, {
        subtree: true,
        childList: true,
        characterData: true,
    });
    const tick = setInterval(record, 25);
    window.addEventListener('load', () => clearInterval(tick));
});

test.describe('Document title', () => {
    test('the title is Possible Words and never becomes Laravel', async ({ page }) => {
        await recordTitles(page);

        await page.goto('/', { waitUntil: 'load' });

        await expect(page).toHaveTitle('Possible Words');

        const seen = await page.evaluate(() => window.__titles);
        expect(seen).toContain('Possible Words');
        expect(seen.every(title => title !== 'Laravel')).toBe(true);
        expect(seen.some(title => title.includes('jimtaylor.space'))).toBe(false);
        expect(seen.some(title => title.includes('://'))).toBe(false);
    });

    test('the title stays Possible Words across an Inertia client-side visit', async ({ page }) => {
        await page.goto('/words');

        // A window sentinel survives an Inertia visit but not a document reload,
        // so it proves the visits below never re-fetched the page.
        await page.evaluate(() => { window.__survived = true; });

        const card = page.locator('[role="link"]').first();
        await card.waitFor({ state: 'visible' });
        await card.click();

        await expect(page).toHaveURL(/\/words\/.+/);
        await expect(page).toHaveTitle('Possible Words');

        await page.goBack();

        await expect(page).toHaveURL(/\/words$/);
        await expect(page).toHaveTitle('Possible Words');

        expect(await page.evaluate(() => window.__survived)).toBe(true);
    });
});
