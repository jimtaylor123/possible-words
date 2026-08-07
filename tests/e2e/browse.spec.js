import { test, expect } from '@playwright/test';

const searchInput = page => page.locator('input[placeholder="Search words..."]');

const openAdvancedPanel = async (page) => {
    const row = page.locator('.flex.items-center.gap-4')
        .filter({ has: page.locator('input[placeholder="Search words..."]') })
        .first();
    await row.locator('button').first().click();
    await expect(page.locator('input[placeholder="Starts with..."]')).toBeVisible();
};

const selectOption = async (page, text) => {
    await page.locator('.n-base-select-option', { hasText: text }).first().click();
};

test.describe('Browsing and filtering', () => {
    test('home page shows a search input', async ({ page }) => {
        await page.goto('/');

        await expect(searchInput(page)).toBeVisible();
    });

    test('search filters the word list to matching words only', async ({ page }) => {
        await page.goto('/');

        const cards = page.locator('[role="link"]');
        await expect(cards.first()).toBeVisible();
        await expect(cards).not.toHaveCount(3);

        await searchInput(page).fill('prai');

        await expect(cards).toHaveCount(3);
        for (const text of await cards.allInnerTexts()) {
            expect(text.split('\n')[0]).toMatch(/prai/);
        }
    });

    test('search with no matches shows the empty state', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('zzzz');

        await expect(page.getByText('No words found matching your criteria.')).toBeVisible();
        await expect(page.getByRole('button', { name: /Clear Filters/i })).toBeVisible();
    });

    test('clear filters restores the full word list', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('zzzz');
        await expect(page.getByText('No words found matching your criteria.')).toBeVisible();

        await page.getByRole('button', { name: /Clear Filters/i }).click();

        await expect(page.locator('[role="link"]').first()).toBeVisible();
        await expect(searchInput(page)).toHaveValue('');
    });

    test('starts-with filter narrows the word list', async ({ page }) => {
        await page.goto('/');

        await openAdvancedPanel(page);

        await page.locator('input[placeholder="Starts with..."]').fill('pr');

        const cards = page.locator('[role="link"]');
        await expect.poll(async () => {
            const texts = await cards.allInnerTexts();
            return texts.every(text => /^pr/.test(text.split('\n')[0]));
        }).toBe(true);
    });

    test('syllable filter narrows the word list', async ({ page }) => {
        await page.goto('/');

        await openAdvancedPanel(page);

        await page.locator('.n-select').first().click();
        await selectOption(page, '2 syllables');

        const cards = page.locator('[role="link"]');
        await expect.poll(async () => {
            const texts = await cards.allInnerTexts();
            return texts.length > 0 && texts.every(text => text.includes('2 syllables'));
        }).toBe(true);
    });

    test('length filter narrows the word list', async ({ page }) => {
        await page.goto('/');

        await openAdvancedPanel(page);

        await page.locator('.n-select').nth(1).click();
        await selectOption(page, '4 letters');

        const cards = page.locator('[role="link"]');
        await expect.poll(async () => {
            const texts = await cards.allInnerTexts();
            return texts.length > 0 && texts.every(text => text.includes('4 letters'));
        }).toBe(true);
    });

    test('sorting alphabetically (A-Z) reorders the word list', async ({ page }) => {
        await page.goto('/');

        await openAdvancedPanel(page);

        await page.locator('.n-select').nth(2).click();
        await selectOption(page, 'Alphabetical (A-Z)');

        const firstWord = page.locator('[role="link"]').first().locator('.text-xl');
        await expect(firstWord).toHaveText('baigorp');
    });

    test('the advanced filter panel opens and shows filter controls', async ({ page }) => {
        await page.goto('/');

        await openAdvancedPanel(page);

        await expect(page.locator('.n-select').first()).toBeVisible();
        await expect(page.locator('input[placeholder="Starts with..."]')).toBeVisible();
    });
});
