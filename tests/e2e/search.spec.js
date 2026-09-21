import { test, expect } from '@playwright/test';

const searchInput = page => page.locator('input[placeholder="Search words..."]');
const suggestionMenu = page => page.locator('.n-auto-complete-menu');
const suggestionOptions = page => suggestionMenu(page).locator('.n-base-select-option');

test.describe('Search suggestions', () => {
    test('typing at least two characters shows matching suggestions', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('blo');

        await expect(suggestionMenu(page)).toBeVisible();
        const options = suggestionOptions(page);
        await expect(options.first()).toBeVisible();
        await expect(options).not.toHaveCount(0);
        for (const text of await options.allInnerTexts()) {
            expect(text).toMatch(/blo/i);
        }
    });

    test('typing a single character shows no suggestions', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('b');

        await expect(suggestionMenu(page)).toHaveCount(0);
    });

    test('clearing the search hides the suggestions', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('blo');
        await expect(suggestionOptions(page).first()).toBeVisible();

        await searchInput(page).fill('');

        await expect(suggestionMenu(page)).toHaveCount(0);
    });

    test('no matches shows an empty state', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('zzz');

        await expect(suggestionMenu(page)).toContainText('No matching words');
    });

    test('clicking a suggestion navigates to the word page', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('blo');
        const firstText = await suggestionOptions(page).first().innerText();

        await suggestionOptions(page).first().click();

        await expect(page).toHaveURL(/\/words\/.+/);
        await expect(page.locator('h1')).toHaveText(firstText.trim());
    });

    test('pressing enter on a highlighted suggestion navigates to the word page', async ({ page }) => {
        await page.goto('/');

        await searchInput(page).fill('blo');
        await expect(suggestionOptions(page).first()).toBeVisible();

        await searchInput(page).press('ArrowDown');
        await searchInput(page).press('Enter');

        await expect(page).toHaveURL(/\/words\/.+/);
    });
});
