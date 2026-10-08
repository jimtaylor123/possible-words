import { test, expect } from '@playwright/test';

const searchInput = page => page.locator('input[placeholder="Search words..."]');
const suggestionMenu = page => page.locator('.n-auto-complete-menu');
const suggestionOptions = page => suggestionMenu(page).locator('.n-base-select-option');
const domainCheckbox = page => page.getByLabel('Only words with a free .com');

// Same helper as browse.spec.js: the advanced panel toggle is the first
// button in the search row.
const openAdvancedPanel = async (page) => {
    const row = page.locator('.flex.items-center.gap-3')
        .filter({ has: page.locator('input[placeholder="Search words..."]') })
        .first();
    await row.locator('button').first().click();
    await expect(page.locator('input[placeholder="Starts with..."]')).toBeVisible();
};

// scripts/seed-e2e-domains.php marks exactly these two fixture words as
// having a free .com; every other word stays "unchecked".
const availableWords = ['bloarp', 'bloblarp'];

test.describe('Free .com filter', () => {
    test('checking the filter narrows the list to the available .com words, unchecking restores it', async ({ page }) => {
        await page.goto('/');

        const cards = page.locator('[role="link"]');
        await expect(cards.first()).toBeVisible();
        const baseline = await cards.count();
        expect(baseline).toBeGreaterThan(availableWords.length);

        await openAdvancedPanel(page);
        await domainCheckbox(page).check();

        await expect(cards).toHaveCount(availableWords.length);
        for (const text of await cards.allInnerTexts()) {
            expect(availableWords).toContain(text.split('\n')[0]);
        }

        await domainCheckbox(page).uncheck();
        await expect(cards).toHaveCount(baseline);
    });

    test('with the filter on, type-ahead suggestions only include free .com words', async ({ page }) => {
        await page.goto('/');

        await openAdvancedPanel(page);
        await domainCheckbox(page).check();
        await expect(page.locator('[role="link"]')).toHaveCount(availableWords.length);

        await searchInput(page).fill('blo');

        await expect(suggestionMenu(page)).toBeVisible();
        const options = suggestionOptions(page);
        await expect(options).toHaveCount(availableWords.length);
        for (const text of await options.allInnerTexts()) {
            expect(availableWords).toContain(text.trim());
        }
    });

    test('an available word shows the register link and an unchecked word does not', async ({ page }) => {
        await page.goto('/words/bloarp');

        const link = page.locator('a', { hasText: 'Register bloarp.com' });
        await expect(link).toBeVisible();
        await expect(link).toHaveAttribute('href', 'https://www.namecheap.com/domains/registration/results/?domain=bloarp.com');
        await expect(link).toHaveAttribute('target', '_blank');
        await expect(link).toHaveAttribute('rel', 'noopener noreferrer');

        await page.goto('/words/bloark');
        await expect(page.locator('h1')).toHaveText('bloark');
        await expect(page.locator('a', { hasText: 'Register' })).toHaveCount(0);
    });
});
