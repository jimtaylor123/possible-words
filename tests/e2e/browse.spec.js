import { test, expect } from '@playwright/test';

test.describe('Browsing and filtering', () => {
    test('home page shows a search input', async ({ page }) => {
        await page.goto('/');

        await expect(page.locator('input[placeholder="Search words..."]')).toBeVisible();
    });

    test('search filters the word list', async ({ page }) => {
        await page.goto('/');

        const firstCard = page.locator('[role="link"]').first();
        await expect(firstCard).toBeVisible();
        const firstText = (await firstCard.innerText()).trim();
        const word = firstText.split('\n')[0];

        const search = page.locator('input[placeholder="Search words..."]');
        await search.fill(word);

        await expect(page.locator('[role="link"]').first()).toContainText(word);
    });

    test('the advanced filter panel opens and shows filter controls', async ({ page }) => {
        await page.goto('/');

        const row = page.locator('.flex.items-center').filter({ has: page.locator('input[placeholder="Search words..."]') }).first();
        await row.locator('button').first().click();

        await expect(page.locator('.n-select').first()).toBeVisible();
        await expect(page.locator('input[placeholder="Starts with..."]')).toBeVisible();
    });
});
